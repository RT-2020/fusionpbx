#!/bin/bash
# FreeSWITCH WSS 快速修复脚本
# 用于自动配置 WebSocket Secure 支持

set -e

echo "========================================="
echo "FreeSWITCH WSS 快速修复脚本"
echo "========================================="
echo ""

# 颜色定义
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# 检查是否以 root 运行
if [ "$EUID" -ne 0 ]; then 
    echo -e "${RED}错误: 请使用 root 权限运行此脚本${NC}"
    echo "使用: sudo $0"
    exit 1
fi

echo -e "${BLUE}步骤 1/6: 检查 FreeSWITCH 状态${NC}"
if systemctl is-active --quiet freeswitch; then
    echo -e "${GREEN}✓ FreeSWITCH 正在运行${NC}"
else
    echo -e "${YELLOW}! FreeSWITCH 未运行，正在启动...${NC}"
    systemctl start freeswitch
    sleep 2
fi

echo ""
echo -e "${BLUE}步骤 2/6: 查找 SIP Profile 配置文件${NC}"
SIP_PROFILE="/etc/freeswitch/sip_profiles/internal.xml"
if [ ! -f "$SIP_PROFILE" ]; then
    echo -e "${RED}错误: 找不到 SIP Profile: $SIP_PROFILE${NC}"
    exit 1
fi
echo -e "${GREEN}✓ 找到配置文件: $SIP_PROFILE${NC}"

echo ""
echo -e "${BLUE}步骤 3/6: 检查 WSS 配置${NC}"
if grep -q "wss-binding" "$SIP_PROFILE"; then
    echo -e "${GREEN}✓ WSS 绑定已配置${NC}"
else
    echo -e "${YELLOW}! WSS 绑定未配置，正在添加...${NC}"
    
    # 备份原配置
    cp "$SIP_PROFILE" "${SIP_PROFILE}.backup.$(date +%Y%m%d_%H%M%S)"
    
    # 在 </settings> 标签前添加 WSS 配置
    sed -i '/<\/settings>/i\    <param name="wss-binding" value=":7443"/>' "$SIP_PROFILE"
    sed -i '/<\/settings>/i\    <param name="tls-cert-dir" value="/etc/freeswitch/tls"/>' "$SIP_PROFILE"
    sed -i '/<\/settings>/i\    <param name="tls-version" value="tlsv1.2"/>' "$SIP_PROFILE"
    
    echo -e "${GREEN}✓ WSS 配置已添加${NC}"
fi

echo ""
echo -e "${BLUE}步骤 4/6: 检查 SSL 证书${NC}"
TLS_DIR="/etc/freeswitch/tls"
CERT_FILE="$TLS_DIR/wss.pem"

if [ ! -d "$TLS_DIR" ]; then
    echo -e "${YELLOW}! TLS 目录不存在，正在创建...${NC}"
    mkdir -p "$TLS_DIR"
    chown freeswitch:freeswitch "$TLS_DIR"
fi

if [ ! -f "$CERT_FILE" ]; then
    echo -e "${YELLOW}! SSL 证书不存在，正在生成自签名证书...${NC}"
    
    # 获取服务器 IP
    SERVER_IP=$(hostname -I | awk '{print $1}')
    
    openssl req -x509 -newkey rsa:4096 -keyout "$CERT_FILE" -out "$CERT_FILE" \
        -days 365 -nodes \
        -subj "/C=CN/ST=State/L=City/O=FusionPBX/CN=$SERVER_IP" 2>/dev/null
    
    chmod 600 "$CERT_FILE"
    chown freeswitch:freeswitch "$CERT_FILE"
    
    echo -e "${GREEN}✓ 自签名证书已生成: $CERT_FILE${NC}"
    echo -e "${YELLOW}  注意: 浏览器会显示证书警告，需要手动信任${NC}"
else
    echo -e "${GREEN}✓ SSL 证书已存在: $CERT_FILE${NC}"
fi

echo ""
echo -e "${BLUE}步骤 5/6: 配置防火墙${NC}"
if command -v ufw &> /dev/null; then
    # UFW (Ubuntu/Debian)
    if ufw status | grep -q "7443.*ALLOW"; then
        echo -e "${GREEN}✓ 防火墙规则已存在 (UFW)${NC}"
    else
        echo -e "${YELLOW}! 添加防火墙规则 (UFW)...${NC}"
        ufw allow 7443/tcp
        echo -e "${GREEN}✓ 防火墙规则已添加${NC}"
    fi
elif command -v firewall-cmd &> /dev/null; then
    # Firewalld (CentOS/RHEL)
    if firewall-cmd --list-ports | grep -q "7443/tcp"; then
        echo -e "${GREEN}✓ 防火墙规则已存在 (Firewalld)${NC}"
    else
        echo -e "${YELLOW}! 添加防火墙规则 (Firewalld)...${NC}"
        firewall-cmd --permanent --add-port=7443/tcp
        firewall-cmd --reload
        echo -e "${GREEN}✓ 防火墙规则已添加${NC}"
    fi
else
    echo -e "${YELLOW}! 未检测到防火墙管理工具，请手动开放端口 7443/tcp${NC}"
fi

echo ""
echo -e "${BLUE}步骤 6/6: 重启 FreeSWITCH SIP Profile${NC}"
echo -e "${YELLOW}正在重启 internal profile...${NC}"
fs_cli -x "sofia profile internal restart" &>/dev/null || true
sleep 3

echo ""
echo -e "${BLUE}验证配置...${NC}"
SOFIA_STATUS=$(fs_cli -x "sofia status" 2>/dev/null)

if echo "$SOFIA_STATUS" | grep -q "7443"; then
    echo -e "${GREEN}✓ WSS 配置成功！${NC}"
    echo ""
    echo -e "${GREEN}=========================================${NC}"
    echo -e "${GREEN}配置完成！${NC}"
    echo -e "${GREEN}=========================================${NC}"
    echo ""
    echo "下一步操作："
    echo ""
    echo "1. 在浏览器中访问: https://$(hostname -I | awk '{print $1}')/app/basic_operator_panel/wss_diagnostic.html"
    echo "   运行诊断工具验证配置"
    echo ""
    echo "2. 如果使用自签名证书，需要先信任证书："
    echo "   在新标签页访问: https://$(hostname -I | awk '{print $1}'):7443"
    echo "   接受证书警告并添加例外"
    echo ""
    echo "3. 在调度终端注册时使用："
    echo "   WebSocket URL: wss://$(hostname -I | awk '{print $1}'):7443"
    echo ""
    echo "配置详情："
    echo "  - WSS 端口: 7443"
    echo "  - 证书位置: $CERT_FILE"
    echo "  - SIP Profile: $SIP_PROFILE"
    echo "  - 配置备份: ${SIP_PROFILE}.backup.*"
    echo ""
else
    echo -e "${RED}✗ WSS 配置可能失败${NC}"
    echo ""
    echo "请检查："
    echo "1. FreeSWITCH 日志: tail -f /var/log/freeswitch/freeswitch.log"
    echo "2. Sofia 状态: fs_cli -x 'sofia status'"
    echo "3. 端口监听: netstat -tuln | grep 7443"
    echo ""
fi

echo ""
echo "详细配置指南: cat /path/to/WSS_SETUP.md"
echo "诊断工具: wss_diagnostic.html"
echo ""

