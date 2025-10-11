# WSS (WebSocket Secure) 配置指南

## 问题说明

当您通过 HTTPS 访问 FusionPBX 时，浏览器要求所有 WebSocket 连接也必须使用安全的 WSS 协议。如果 FreeSWITCH 的 WSS 未正确配置，会出现以下错误：

- `Mixed Content: ... must be available over WSS`
- `WebSocket connection to 'wss://...' failed`

## FreeSWITCH WSS 配置步骤

### 1. 检查 WSS 是否启用

```bash
# 检查 FreeSWITCH 是否监听 7443 端口（WSS 默认端口）
netstat -an | grep 7443

# 或者
ss -tuln | grep 7443
```

### 2. 配置 WSS 支持

#### 方法 1：使用 FusionPBX 界面配置（推荐）

1. 登录 FusionPBX 管理界面
2. 进入 **Advanced** → **SIP Profiles**
3. 编辑 `internal` 配置文件
4. 找到或添加以下设置：

```xml
<!-- WSS 绑定配置 -->
<param name="wss-binding" value=":7443"/>
<param name="tls-cert-dir" value="/etc/freeswitch/tls"/>
<param name="tls-version" value="tlsv1.2"/>
```

5. 保存并重启 SIP Profile：
   - Advanced → SIP Status → 点击 `Flush Memcache` → 点击 `Rescan`
   - 或在 FreeSWITCH CLI 执行：`sofia profile internal restart`

#### 方法 2：直接编辑配置文件

1. 编辑 SIP Profile 配置文件：

```bash
# FusionPBX 默认路径
nano /etc/freeswitch/sip_profiles/internal.xml
```

2. 在 `<settings>` 部分添加：

```xml
<param name="wss-binding" value=":7443"/>
<param name="tls-cert-dir" value="/etc/freeswitch/tls"/>
<param name="tls-version" value="tlsv1.2"/>
```

3. 重启 FreeSWITCH：

```bash
systemctl restart freeswitch
# 或
service freeswitch restart
```

### 3. 配置 SSL/TLS 证书

FreeSWITCH 需要有效的 SSL 证书才能建立 WSS 连接：

#### 方法 1：使用自签名证书（测试环境）

```bash
# 进入 FreeSWITCH TLS 目录
cd /etc/freeswitch/tls

# 生成自签名证书
openssl req -x509 -newkey rsa:4096 -keyout wss.pem -out wss.pem -days 365 -nodes \
  -subj "/C=CN/ST=State/L=City/O=Organization/CN=192.168.2.200"

# 设置权限
chmod 600 wss.pem
chown freeswitch:freeswitch wss.pem
```

**注意**：使用自签名证书时，浏览器会显示证书警告，需要手动信任。

#### 方法 2：使用 Let's Encrypt 证书（生产环境）

```bash
# 如果已有 Let's Encrypt 证书
cat /etc/letsencrypt/live/yourdomain.com/fullchain.pem \
    /etc/letsencrypt/live/yourdomain.com/privkey.pem \
    > /etc/freeswitch/tls/wss.pem

# 设置权限
chmod 600 /etc/freeswitch/tls/wss.pem
chown freeswitch:freeswitch /etc/freeswitch/tls/wss.pem
```

### 4. 配置防火墙

确保 WSS 端口可访问：

```bash
# UFW (Ubuntu/Debian)
ufw allow 7443/tcp

# Firewalld (CentOS/RHEL)
firewall-cmd --permanent --add-port=7443/tcp
firewall-cmd --reload

# IPTables
iptables -A INPUT -p tcp --dport 7443 -j ACCEPT
service iptables save
```

**Windows 防火墙**：

```powershell
New-NetFirewallRule -DisplayName "FreeSWITCH WSS" -Direction Inbound -LocalPort 7443 -Protocol TCP -Action Allow
```

### 5. 验证 WSS 配置

#### 检查 FreeSWITCH 日志

```bash
# 实时查看日志
tail -f /var/log/freeswitch/freeswitch.log | grep -i wss

# 检查 Sofia 状态
fs_cli -x "sofia status"
```

应该看到类似输出：

```
                 internal    profile      sip:mod_sofia@192.168.2.200:5060
                                         wss-bind-url   sip:mod_sofia@192.168.2.200:7443;transport=wss
```

#### 使用测试工具验证连接

```bash
# 使用 wscat 测试 WSS 连接（需先安装：npm install -g wscat）
wscat -c wss://192.168.2.200:7443 --no-check
```

## 客户端配置

确保调度终端配置使用正确的 WSS 地址：

### 在注册界面填写：

- **WebSocket URL**: `wss://192.168.2.200:7443`
- **SIP 服务器**: `192.168.2.200`
- **分机号**: `1000`
- **密码**: `您的分机密码`
- **显示名称**: `调度员`

### 或直接修改 `dispatcher_api.php` 中的配置：

```php
return [
    'sip' => [
        'wsServer' => 'wss://192.168.2.200:7443', // 使用 WSS
        'realm' => '192.168.2.200',
        'extension' => '1000',
        'password' => 'your_password',
        'displayName' => '调度员'
    ]
];
```

## 常见问题排查

### 1. 自签名证书浏览器警告

**症状**：WSS 连接失败，控制台显示证书错误

**解决方案**：

1. 在新标签页打开 `https://192.168.2.200:7443`
2. 接受证书警告并添加例外
3. 返回调度终端页面，重新注册

### 2. 证书域名不匹配

**症状**：证书错误 - "Certificate is not valid for IP"

**解决方案**：

- 使用域名访问而非 IP
- 或重新生成证书，在 CN（Common Name）中使用正确的 IP 或域名

### 3. WSS 端口无法访问

**症状**：连接超时，无任何响应

**检查清单**：

```bash
# 1. 检查 FreeSWITCH 是否监听端口
netstat -tuln | grep 7443

# 2. 检查防火墙
iptables -L -n | grep 7443  # Linux
Get-NetFirewallRule | Where-Object {$_.LocalPort -eq 7443}  # Windows

# 3. 检查 SELinux（如果启用）
setenforce 0  # 临时禁用测试
```

### 4. STUN/TURN 配置问题

**症状**：注册成功但无法建立媒体连接

**解决方案**：
在注册表单中配置 STUN 服务器：

```javascript
// 修改 jssip-client.js 或在注册时添加
pcConfig: {
  iceServers: [
    { urls: 'stun:stun.l.google.com:19302' },
    { urls: 'stun:stun1.l.google.com:19302' },
  ]
}
```

## 快速故障排查命令

```bash
#!/bin/bash
echo "=== FreeSWITCH WSS 诊断 ==="

echo -e "\n1. 检查 WSS 端口监听："
netstat -tuln | grep 7443

echo -e "\n2. 检查 FreeSWITCH 进程："
ps aux | grep freeswitch

echo -e "\n3. 检查 TLS 证书："
ls -lh /etc/freeswitch/tls/

echo -e "\n4. 检查 Sofia Profile："
fs_cli -x "sofia status profile internal"

echo -e "\n5. 最近的 WSS 相关日志："
tail -50 /var/log/freeswitch/freeswitch.log | grep -i wss

echo -e "\n6. 测试 WSS 连接："
timeout 5 openssl s_client -connect 192.168.2.200:7443 2>&1 | head -20
```

将此脚本保存为 `wss_check.sh`，执行 `chmod +x wss_check.sh && ./wss_check.sh`

## 针对您当前的错误

根据控制台日志，您需要：

### 立即操作：

1. **检查 FreeSWITCH WSS 是否启用**：

   ```bash
   fs_cli -x "sofia status" | grep wss
   ```

2. **如果未启用，添加 WSS 配置**：

   - 进入 FusionPBX：Advanced → SIP Profiles → internal
   - 添加参数：`wss-binding` 值为 `:7443`
   - 保存并重启 Profile

3. **生成自签名证书**（如果没有）：

   ```bash
   cd /etc/freeswitch/tls
   openssl req -x509 -newkey rsa:4096 -keyout wss.pem -out wss.pem -days 365 -nodes \
     -subj "/C=CN/ST=State/L=City/O=YourOrg/CN=192.168.2.200"
   chmod 600 wss.pem
   chown freeswitch:freeswitch wss.pem
   ```

4. **重启 FreeSWITCH**：

   ```bash
   systemctl restart freeswitch
   ```

5. **信任自签名证书**：

   - 在浏览器新标签页访问 `https://192.168.2.200:7443`
   - 接受证书警告

6. **更新调度终端配置**：
   - WebSocket URL: `wss://192.168.2.200:7443`
   - 点击注册

## 参考资源

- FreeSWITCH WSS 官方文档: https://freeswitch.org/confluence/display/FREESWITCH/WebRTC
- JsSIP 配置指南: https://jssip.net/documentation/
- WebRTC 疑难解答: https://webrtc.github.io/samples/

---

如有问题，请提供：

1. `fs_cli -x "sofia status"` 的完整输出
2. `/var/log/freeswitch/freeswitch.log` 的最新 100 行
3. 浏览器控制台的完整错误信息
