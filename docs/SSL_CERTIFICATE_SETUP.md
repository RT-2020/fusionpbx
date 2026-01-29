# FusionPBX 内网 SSL 证书配置指南

本文档记录如何在内网环境中配置受信任的 SSL 证书，解决 WebSocket (WSS) 连接失败和浏览器证书警告问题。

## 问题背景

在内网部署 FusionPBX 时，调度控制面板的 SIP 注册功能使用 WSS (WebSocket Secure) 协议连接 FreeSWITCH。如果 SSL 证书不被浏览器信任，会导致：

- 浏览器显示"您的连接不是私密连接"警告
- WSS 连接静默失败（不弹窗，直接断开）
- SIP 注册失败

## 解决方案

使用 [mkcert](https://github.com/FiloSottile/mkcert) 工具生成本地受信任的 SSL 证书。

## 环境信息

| 组件 | 说明 |
|------|------|
| FreeSWITCH WSS 端口 | 7443 |
| Nginx HTTPS 端口 | 443 |
| 证书目录 (FreeSWITCH) | `/etc/freeswitch/tls/` |
| 证书目录 (Nginx) | `/etc/ssl/private/` |

## 配置步骤

### 1. 下载 mkcert 工具

在 Windows 客户端电脑上下载 mkcert：

- 下载地址：https://github.com/FiloSottile/mkcert/releases
- Windows 版本：`mkcert-v1.4.4-windows-amd64.exe`

### 2. 安装本地 CA 根证书

```powershell
# 进入 mkcert 目录
cd E:\mkcert

# 安装 CA 到系统信任列表（需要管理员权限）
.\mkcert-v1.4.4-windows-amd64.exe -install
```

成功后显示：
```
The local CA is now installed in the system trust store! ⚡️
```

### 3. 生成服务器证书

```powershell
# 生成包含 IP 地址的证书
.\mkcert-v1.4.4-windows-amd64.exe 192.168.2.227 localhost 127.0.0.1
```

生成的文件：
- `192.168.2.227+2.pem` - 证书文件
- `192.168.2.227+2-key.pem` - 私钥文件

### 4. 上传证书到服务器

将以下文件上传到 FusionPBX 服务器：

| 文件 | 目标路径 |
|------|----------|
| `192.168.2.227+2.pem` | `/etc/ssl/private/` |
| `192.168.2.227+2-key.pem` | `/etc/ssl/private/` |

### 5. 配置 Nginx

```bash
# 备份原配置
cp /etc/nginx/sites-available/fusionpbx /etc/nginx/sites-available/fusionpbx.bak

# 修改证书路径
sed -i 's|/etc/ssl/certs/nginx.crt|/etc/ssl/private/192.168.2.227+2.pem|g' /etc/nginx/sites-available/fusionpbx
sed -i 's|/etc/ssl/private/nginx.key|/etc/ssl/private/192.168.2.227+2-key.pem|g' /etc/nginx/sites-available/fusionpbx

# 设置权限
chown root:root /etc/ssl/private/192.168.2.227+2*.pem
chmod 600 /etc/ssl/private/192.168.2.227+2-key.pem
chmod 644 /etc/ssl/private/192.168.2.227+2.pem

# 测试并重启 Nginx
nginx -t && systemctl restart nginx
```

### 6. 配置 FreeSWITCH

FreeSWITCH 需要将私钥和证书合并为单个 PEM 文件：

```bash
# 合并私钥和证书
cat /etc/ssl/private/192.168.2.227+2-key.pem /etc/ssl/private/192.168.2.227+2.pem > /etc/freeswitch/tls/agent.pem

# 复制为 wss.pem（部分配置可能引用此文件名）
cp /etc/freeswitch/tls/agent.pem /etc/freeswitch/tls/wss.pem

# 设置权限
chown freeswitch:freeswitch /etc/freeswitch/tls/*.pem
chmod 640 /etc/freeswitch/tls/*.pem

# 重启 FreeSWITCH
systemctl restart freeswitch
```

### 7. 确认 FusionPBX SIP Profile 配置

在 FusionPBX 管理界面中确认 WSS 已启用：

1. 登录 FusionPBX：`https://192.168.2.227`
2. 进入 **Advanced** → **SIP Profiles** → **internal**
3. 确认以下设置：
   - `wss-binding` = `:7443`，启用 = `true`
   - `ws-binding` = `:5066`，启用 = `true`（可选）
   - `tls-cert-dir` = `$${internal_ssl_dir}`，启用 = `true`
4. 点击 **保存**
5. 执行重载：
   ```bash
   fs_cli -x "sofia profile internal restart"
   ```

### 8. 验证配置

```bash
# 检查 WSS 端口监听
ss -tlnp | grep 7443

# 验证证书
openssl s_client -connect 192.168.2.227:7443 2>/dev/null | openssl x509 -noout -subject -issuer
```

### 9. 客户端测试

1. **完全关闭浏览器**（包括后台进程）
2. 重新打开浏览器
3. 访问 `https://192.168.2.227` - 应无证书警告
4. 访问 `https://192.168.2.227:7443` - 应无证书警告
5. 打开调度面板，测试 SIP 注册

## 多客户端部署

如果有其他客户端电脑需要访问系统，需要在每台电脑上安装 CA 根证书：

### 方法一：使用 mkcert 安装

将 mkcert 工具复制到其他电脑，执行：

```powershell
.\mkcert-v1.4.4-windows-amd64.exe -install
```

### 方法二：手动安装 CA 证书

1. 从原电脑复制 CA 证书：
   - 路径：`%LOCALAPPDATA%\mkcert\rootCA.pem`
   - 重命名为 `rootCA.crt`

2. 在目标电脑上双击 `rootCA.crt`
3. 点击 **安装证书**
4. 选择 **本地计算机**
5. 选择 **将所有证书放入下列存储** → **受信任的根证书颁发机构**
6. 完成安装

## 故障排查

### 问题：WSS 连接失败

1. 检查端口是否监听：
   ```bash
   ss -tlnp | grep 7443
   ```

2. 检查证书是否正确加载：
   ```bash
   openssl s_client -connect 192.168.2.227:7443 2>/dev/null | openssl x509 -noout -fingerprint -sha256
   ```

3. 检查 FreeSWITCH 日志：
   ```bash
   tail -f /var/log/freeswitch/freeswitch.log | grep -i tls
   ```

### 问题：浏览器仍显示证书警告

1. 确认 CA 已安装到系统信任列表：
   - Windows：运行 `certmgr.msc`，检查 **受信任的根证书颁发机构**

2. 确认服务器证书与 CA 匹配：
   ```bash
   # 查看服务器证书的颁发者
   openssl x509 -in /etc/freeswitch/tls/agent.pem -noout -issuer
   ```

3. 完全重启浏览器（检查任务管理器确保无后台进程）

### 问题：FreeSWITCH 未加载新证书

1. 确认文件权限：
   ```bash
   ls -la /etc/freeswitch/tls/
   ```

2. 确认文件格式（必须先私钥后证书）：
   ```bash
   head -1 /etc/freeswitch/tls/agent.pem
   # 应显示：-----BEGIN PRIVATE KEY-----
   ```

3. 完全重启 FreeSWITCH：
   ```bash
   systemctl restart freeswitch
   ```

## 相关配置文件

| 文件 | 用途 |
|------|------|
| `/etc/nginx/sites-available/fusionpbx` | Nginx SSL 配置 |
| `/etc/freeswitch/tls/agent.pem` | FreeSWITCH TLS 证书 |
| `/etc/freeswitch/tls/wss.pem` | FreeSWITCH WSS 证书 |
| `/etc/freeswitch/vars.xml` | FreeSWITCH 变量配置 |

## 参考链接

- [mkcert GitHub](https://github.com/FiloSottile/mkcert)
- [FreeSWITCH TLS 配置](https://developer.signalwire.com/freeswitch/FreeSWITCH-Explained/Security/TLS_9634135/)
- [FusionPBX SIP Profiles](https://docs.fusionpbx.com/en/latest/advanced/sip_profiles.html)

---

*文档创建日期：2026-01-29*
*适用环境：FusionPBX 5.x + FreeSWITCH + Debian/Ubuntu*
