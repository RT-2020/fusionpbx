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
| 根 CA 推荐存放目录 (Debian) | `/etc/ssl/mkcert-rootCA/` |

> 说明：本文以下示例统一使用 `192.168.2.200`。实际部署时，必须替换为浏览器真实访问的 IP 或域名，并确保该地址包含在服务器证书的 SAN 中。

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
.\mkcert-v1.4.4-windows-amd64.exe 192.168.2.200 localhost 127.0.0.1
```

生成的文件：
- `192.168.2.200+2.pem` - 证书文件
- `192.168.2.200+2-key.pem` - 私钥文件

### 4. 上传证书到服务器

将以下文件上传到 FusionPBX 服务器：

| 文件 | 目标路径 |
|------|----------|
| `192.168.2.200+2.pem` | `/etc/ssl/private/` |
| `192.168.2.200+2-key.pem` | `/etc/ssl/private/` |

> 注意：`/etc/ssl/private/` 只放站点证书和私钥。`rootCA.pem` 不建议放在该目录，建议单独存放到 `/etc/ssl/mkcert-rootCA/`。

### 5. 配置 Nginx

```bash
# 备份原配置
cp /etc/nginx/sites-available/fusionpbx /etc/nginx/sites-available/fusionpbx.bak

# 修改证书路径
sed -i 's|/etc/ssl/certs/nginx.crt|/etc/ssl/private/192.168.2.200+2.pem|g' /etc/nginx/sites-available/fusionpbx
sed -i 's|/etc/ssl/private/nginx.key|/etc/ssl/private/192.168.2.200+2-key.pem|g' /etc/nginx/sites-available/fusionpbx

# 设置权限
chown root:root /etc/ssl/private/192.168.2.200+2*.pem
chmod 600 /etc/ssl/private/192.168.2.200+2-key.pem
chmod 644 /etc/ssl/private/192.168.2.200+2.pem

# 测试并重启 Nginx
nginx -t && systemctl restart nginx
```

### 6. 配置 FreeSWITCH

FreeSWITCH 需要将私钥和证书合并为单个 PEM 文件：

```bash
# 合并私钥和证书
cat /etc/ssl/private/192.168.2.200+2-key.pem /etc/ssl/private/192.168.2.200+2.pem > /etc/freeswitch/tls/agent.pem

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

1. 登录 FusionPBX：`https://192.168.2.200`
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
openssl s_client -connect 192.168.2.200:7443 2>/dev/null | openssl x509 -noout -subject -issuer
```

### 9. 客户端测试

1. **完全关闭浏览器**（包括后台进程）
2. 重新打开浏览器
3. 访问 `https://192.168.2.200` - 应无证书警告
4. 访问 `https://192.168.2.200:7443` - 应无证书警告
5. 打开调度面板，测试 SIP 注册

### 10. 摄像头播放器 `9003` 接入 HTTPS（推荐）

如果摄像头管理页面中的播放器服务运行在 `192.168.2.200:9003`，不要让浏览器直接在 HTTPS 页面中加载 `http://192.168.2.200:9003/player`，否则会被判定为混合活动内容而被拦截。

推荐做法是：继续由 Nginx 的 `443` 对外提供 HTTPS，再把 `/camera-player/` 反向代理到本机 `9003`。这样浏览器只会访问已经受信任的 `443` 证书，不需要单独给 iframe 中的 `9003` 再处理浏览器信任问题。

将以下配置添加到 `/etc/nginx/sites-available/fusionpbx` 的 `server { ... }` 中：

```nginx
location /camera-player/ {
    proxy_http_version 1.1;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto https;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_read_timeout 3600s;
    proxy_send_timeout 3600s;
    proxy_buffering off;

    # 如果 9003 本身是 HTTP，使用这一行
    proxy_pass http://127.0.0.1:9003/;

    # 如果 9003 已经改成 HTTPS，但证书不受信任，可改用下面两行
    # proxy_pass https://127.0.0.1:9003/;
    # proxy_ssl_verify off;
}
```

应用配置：

```bash
nginx -t && systemctl reload nginx
```

然后在 FusionPBX 的 **摄像头管理** 页面，将播放器地址配置为：

```text
/camera-player/player
```

也可以填写完整地址：

```text
https://192.168.2.200/camera-player/player
```

摄像头页面当前的拼接方式是：

```text
播放器地址 + ?url= + encodeURIComponent(rtsp://用户名:密码@摄像头IP/streaming/channels/101)
```

因此当播放器地址配置为 `/camera-player/player` 时，浏览器最终访问的地址类似于：

```text
https://192.168.2.200/camera-player/player?url=rtsp%3A%2F%2Fadmin%3Apassword%40192.168.2.64%2Fstreaming%2Fchannels%2F101
```

这也是摄像头管理页面在 HTTPS 下推荐的接入方式。

## 多客户端部署

如果有其他客户端电脑需要访问系统，需要在每台电脑上安装 CA 根证书：

> 关键点：在 Windows 上执行 `mkcert -install`，只会把根 CA 安装到那台 Windows 的信任列表。Debian 图形服务器上的 Firefox 仍然会把它视为未知颁发者，必须额外导入 `rootCA.pem`。

### 方法一：使用 mkcert 安装

将 mkcert 工具复制到其他电脑，执行：

```powershell
.\mkcert-v1.4.4-windows-amd64.exe -install
```

### 方法二：手动安装 CA 证书

1. 从原电脑复制 CA 证书：
   - 路径：`%LOCALAPPDATA%\mkcert\rootCA.pem`
   - Windows 下建议复制一份并重命名为 `rootCA.crt`
   - `rootCA.pem` 与 `rootCA.crt` 的证书内容可以完全相同，这里只是为了方便 Windows 识别和双击导入，不需要转换证书格式
   - 如果双击 `.pem` 可以直接进入证书导入向导，也可以直接导入；如果 `.pem` 被系统当作文本打开，直接改为 `.crt` 即可
   - **不要复制 `rootCA-key.pem`，更不要上传到服务器**

#### Windows 客户端

2. 在目标电脑上双击 `rootCA.crt`
3. 点击 **安装证书**
4. 选择 **本地计算机**
5. 选择 **将所有证书放入下列存储** → **受信任的根证书颁发机构**
6. 完成安装

可选：如果希望保留原文件不动，可以在命令行复制一份：

```powershell
Copy-Item "$env:LOCALAPPDATA\mkcert\rootCA.pem" "$env:USERPROFILE\Desktop\rootCA.crt"
```

#### Debian 图形服务器 + Firefox

1. 在 Debian 服务器上创建独立目录存放根 CA：
   ```bash
   mkdir -p /etc/ssl/mkcert-rootCA
   cp /path/to/rootCA.pem /etc/ssl/mkcert-rootCA/rootCA.pem
   chown root:root /etc/ssl/mkcert-rootCA/rootCA.pem
   chmod 644 /etc/ssl/mkcert-rootCA/rootCA.pem
   ```

2. 如需让系统命令也信任该 CA，可额外导入系统证书库：
   ```bash
   cp /etc/ssl/mkcert-rootCA/rootCA.pem /usr/local/share/ca-certificates/mkcert-rootCA.crt
   update-ca-certificates
   ```

3. 在 Firefox 中手动导入根 CA：
   - 打开 **Settings** → **Privacy & Security** → **Certificates**
   - 点击 **View Certificates**
   - 切换到 **Authorities**
   - 点击 **Import**
   - 选择 `/etc/ssl/mkcert-rootCA/rootCA.pem`
   - 勾选 **Trust this CA to identify websites**

4. 完全退出 Firefox 并重新打开，再访问 `https://192.168.2.200`

5. 可选：如果需要用命令行导入 Firefox 的 NSS 证书库，可执行：
   ```bash
   apt install -y libnss3-tools
   certutil -A -n "mkcert local CA" -t "C,," -i /etc/ssl/mkcert-rootCA/rootCA.pem -d sql:$HOME/.mozilla/firefox/<profile>.default-release
   ```

## 故障排查

### 问题：WSS 连接失败

1. 检查端口是否监听：
   ```bash
   ss -tlnp | grep 7443
   ```

2. 检查证书是否正确加载：
   ```bash
   openssl s_client -connect 192.168.2.200:7443 2>/dev/null | openssl x509 -noout -fingerprint -sha256
   ```

3. 检查 FreeSWITCH 日志：
   ```bash
   tail -f /var/log/freeswitch/freeswitch.log | grep -i tls
   ```

### 问题：浏览器仍显示证书警告

1. 先确认服务端实际返回的是新证书，而不是旧证书或默认自签证书：
   ```bash
   openssl x509 -in /etc/ssl/private/192.168.2.200+2.pem -noout -subject -issuer -ext subjectAltName -fingerprint -sha256
   openssl s_client -connect 192.168.2.200:443 -servername 192.168.2.200 </dev/null 2>/dev/null | openssl x509 -noout -subject -issuer -ext subjectAltName -fingerprint -sha256
   openssl s_client -connect 192.168.2.200:7443 </dev/null 2>/dev/null | openssl x509 -noout -subject -issuer -ext subjectAltName -fingerprint -sha256
   ```

2. 如果本地文件、`443`、`7443` 输出的 SHA256 指纹一致，说明 Nginx 和 FreeSWITCH 都已经加载了正确证书。此时浏览器继续报警，通常是客户端不信任 `rootCA.pem`。

3. 确认 CA 已安装到信任列表：
   - Windows：运行 `certmgr.msc`，检查 **受信任的根证书颁发机构**
   - Debian + Firefox：在 Firefox 的 **Authorities** 中确认已导入 `/etc/ssl/mkcert-rootCA/rootCA.pem`

4. 如果 Windows 上双击 `rootCA.pem` 没有进入证书安装向导，而是被记事本等程序打开，这通常不是证书内容有问题，只是文件关联问题。直接复制/重命名为 `rootCA.crt` 后再导入即可。

5. 确认服务器证书与 CA 匹配：
   ```bash
   # 查看服务器证书的颁发者
   openssl x509 -in /etc/freeswitch/tls/agent.pem -noout -issuer
   ```

6. 完全重启浏览器（检查任务管理器确保无后台进程）

7. 如果 Firefox 仍然报警，优先检查错误码：
   - `SEC_ERROR_UNKNOWN_ISSUER`：通常是 Firefox 未信任 `rootCA.pem`
   - `SSL_ERROR_BAD_CERT_DOMAIN`：通常是访问地址未包含在证书 SAN 中

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
| `/etc/ssl/mkcert-rootCA/rootCA.pem` | 提供给 Debian Firefox 导入的根 CA 证书 |
| `/etc/freeswitch/vars.xml` | FreeSWITCH 变量配置 |

## 参考链接

- [mkcert GitHub](https://github.com/FiloSottile/mkcert)
- [FreeSWITCH TLS 配置](https://developer.signalwire.com/freeswitch/FreeSWITCH-Explained/Security/TLS_9634135/)
- [FusionPBX SIP Profiles](https://docs.fusionpbx.com/en/latest/advanced/sip_profiles.html)

---

*文档创建日期：2026-01-29*
*适用环境：FusionPBX 5.x + FreeSWITCH + Debian/Ubuntu*
