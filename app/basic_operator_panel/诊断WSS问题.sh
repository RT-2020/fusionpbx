#!/bin/bash
# WSS 连接问题诊断脚本

echo "========================================="
echo "WSS 连接诊断"
echo "========================================="
echo ""

# 1. 检查 FreeSWITCH 是否运行
echo "1. 检查 FreeSWITCH 状态："
systemctl status freeswitch | grep -E "Active|running" || echo "FreeSWITCH 未运行"

echo ""
echo "2. 检查端口监听："
netstat -tuln | grep 7443 || echo "端口 7443 未监听"

echo ""
echo "3. 检查 Sofia WSS 配置："
fs_cli -x "sofia status" | grep -i wss || echo "未找到 WSS 配置"

echo ""
echo "4. 检查 TLS 证书："
ls -lh /etc/freeswitch/tls/ 2>/dev/null || echo "证书目录不存在"

echo ""
echo "5. 检查防火墙："
iptables -L -n | grep 7443 || firewall-cmd --list-ports | grep 7443 || echo "防火墙可能未开放"

echo ""
echo "6. 测试 WSS 连接："
timeout 3 openssl s_client -connect 192.168.2.200:7443 2>&1 | head -10

echo ""
echo "========================================="
echo "常见问题："
echo "1. WebSocket URL 可能需要路径，尝试："
echo "   wss://192.168.2.200:7443/ws"
echo ""
echo "2. 检查 SIP Profile 配置："
echo "   fs_cli -x 'sofia status profile internal'"
echo ""
echo "3. 查看实时日志："
echo "   tail -f /var/log/freeswitch/freeswitch.log | grep -i wss"
echo "========================================="

