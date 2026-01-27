# FusionPBX 官方文档参考

本文档记录了从 FusionPBX 官方文档 (https://docs.fusionpbx.com/) 收集的重要参考信息。

## 官方文档链接

- **主文档站点:** https://docs.fusionpbx.com/
- **项目主页:** https://www.fusionpbx.com/
- **YouTube 频道:** https://www.youtube.com/FusionPBX
- **GitHub:** https://github.com/fusionpbx/fusionpbx

## 快速安装指南

### Debian 12 安装

```bash
# 1. 预安装脚本
wget -O - https://raw.githubusercontent.com/fusionpbx/fusionpbx-install.sh/master/debian/pre-install.sh | sh;

# 2. 执行安装
cd /usr/src/fusionpbx-install.sh/debian && ./install.sh
```

### Proxmox LXC 容器安装前准备

```bash
apt-get update && apt-get upgrade
apt-get install systemd
apt-get install systemd-sysv
apt-get install ca-certificates
reboot
```

### 首次登录

安装完成后会提供登录凭据：
```
domain name: https://server-ip
username: admin
password: [随机生成的密码]
```

数据库密码位置: `/etc/fusionpbx/config.php`

## 安全配置

### Fail2ban 规则启用

编辑 `/etc/fail2ban/jail.local`：

```ini
[freeswitch-ip]
enabled  = true

[auth-challenge-ip]
enabled  = true
```

## 核心功能模块

### 1. 账户管理 (Accounts)

#### 设备 (Devices)
- 设备供应商配置
- 配置文件设置
- 支持多品牌设备：Yealink, Polycom, Cisco, Grandstream, Fanvil, Htek, SNOM 等

#### 分机 (Extensions)
- 基本设置
- 高级设置
- 来电显示选择

#### 网关 (Gateways)
- 基本设置
- 高级设置
- SIP 注册配置

#### 用户 (Users)
- 用户管理
- 权限分配
- 默认设置

### 2. 拨号计划 (Dialplans)

#### 目的地 (Destinations)
- 目标管理
- 默认设置

#### 拨号计划管理器
- 全局拨号计划
- 域特定拨号计划
- 条件判断和正则表达式

#### 入站路由 (Inbound Routes)
- DID 号码配置
- 目标设置
- XML 示例

#### 出站路由 (Outbound Routes)
- PIN 码管理
- 网关选择
- 路由规则

### 3. 应用程序 (Applications)

| 功能 | 描述 |
|------|------|
| **Call Block** | 按来电 ID 阻止来电 |
| **Call Broadcast** | 创建录音并向一组号码播放 |
| **Call Center** | 呼叫中心队列和坐席管理 |
| **Call Detail Records** | 通话详细记录和统计 |
| **Call Flows** | 日/夜模式呼叫流 |
| **Call Forward** | 呼叫转发 |
| **Call Recordings** | 通话录音 |
| **Conference** | 语音/视频会议 |
| **Conference Center** | 无限会议房间 |
| **Contacts** | 联系人管理 |
| **Fax Server** | 传真服务器 |
| **Follow Me** | 跟随功能 |
| **IVR Menu** | 交互式语音应答 |
| **Music on Hold** | 保留音乐 |
| **Queues** | 呼叫队列 |
| **Recordings** | 录音管理 |
| **Ring Groups** | 响铃组 |
| **Time Conditions** | 时间条件 |
| **Voicemail** | 语音邮件 |

### 4. 状态监控 (Status)

| 监控项 | 描述 |
|--------|------|
| **Active Calls** | 活动通话监控 |
| **Active Conferences** | 活动会议 |
| **Agent Status** | 坐席状态 |
| **CDR Statistics** | 通话详单统计 |
| **Registrations** | 注册状态 |
| **SIP Status** | SIP 状态 |
| **System Status** | 系统状态 |
| **Traffic Graph** | 流量图表 |

### 5. 高级设置 (Advanced)

#### 默认设置 (Default Settings)

##### 缓存设置
```php
// 文件缓存
method: file
location: /var/cache/fusionpbx
```

##### CDR 设置
```php
stat_hours_limit: 24
format: json
limit: 800
storage: db
```

##### 域设置
```php
dial_string: {sip_invite_domain=${domain_name},leg_timeout=${call_timeout},presence_id=${dialed_user}@${dialed_domain}}${sofia_contact(*/${dialed_user}@${dialed_domain})}
template: default
language: en-us
time_zone: America/Los_Angeles
```

##### 邮件设置
```php
smtp_host: mail.server.provider.com
smtp_port: 587
smtp_secure: tls
smtp_auth: true
method: smtp
```

##### 传真设置
```php
page_size: letter
resolution: fine
keep_local: true
storage_type: base64
```

##### 录音设置
```php
storage_type: base64
```

##### 语音邮件设置
```php
voicemail_file: attach
keep_local: true
storage_type: base64
message_max_length: 300
password_length: 8
```

##### 安全设置
```php
password_length: 15
password_strength: 4
session_rotate: true
```

##### 主题设置
```php
background_color: #6c89b5
menu_style: fixed
menu_position: top
```

#### 配置编辑器
- PHP 编辑器
- XML 编辑器
- 配置模板编辑器
- 脚本编辑器

#### SIP 配置文件
- Internal (内部)
- External (外部)
- Internal ipv6
- External ipv6

## 多租户配置

### 域管理
- 基于子域的多租户 (red.pbxhosting.tld, green.pbxhosting.tld)
- 独立域设置
- 域级权限控制

### 数据隔离
- 所有查询必须包含 `domain_uuid` 过滤
- 域级设置覆盖全局默认设置

## 设备配置

### 支持的设备品牌

#### Yealink
```php
yealink_time_zone: -5
yealink_time_format: 1  // 0=12hr, 1=24hr
yealink_firmware_url: https://server.yourdomain.com/app/yealink/resources/firmware
```

#### Grandstream
```php
grandstream_call_waiting: 0
grandstream_time_zone: auto
grandstream_firmware_path: mydomain.com/app/provision
```

#### Polycom
```php
polycom_call_waiting: 1
polycom_feature_key_sync: 0
```

#### SNOM
```php
snom_call_waiting: on
snom_time_zone: USA-7
```

#### Fanvil
```php
fanvil_time_zone: -20
fanvil_date_display: 3
```

## VoIP 特定功能

### 呼叫控制命令

```bash
# 发起通话
originate user/1001 &echo

# 挂断通话
uuid_kill <uuid>

# 通话转移
uuid_transfer <uuid> <destination>

# 保持通话
uuid_hold <uuid> on

# 通话监听
uuid_park <uuid>
```

### FreeSWITCH 调试

```bash
# SIP 状态
sofia status

# 活动通道
show channels

# 注册状态
sofia status profile internal reg <extension>

# 网关状态
sofia status gateway <gateway_name>
```

## 开发参考

### 数据库表结构规范

```sql
CREATE TABLE v_table_name (
    table_name_uuid VARCHAR(36) PRIMARY KEY,
    domain_uuid VARCHAR(36) NOT NULL,
    -- 业务字段...
    insert_date TIMESTAMP,
    insert_user VARCHAR(36),
    update_date TIMESTAMP,
    update_user VARCHAR(36),
    CONSTRAINT fk_domain FOREIGN KEY (domain_uuid)
        REFERENCES v_domains(domain_uuid)
);
```

### PHP 编码规范

```php
// 缩进使用 Tab
// 变量命名使用下划线命名法
$user_name = "value";
$domain_uuid = "uuid-here";

// 数据库查询使用 PDO 预处理
$sql = "SELECT * FROM v_extensions WHERE domain_uuid = :domain_uuid";
$parameters = array('domain_uuid' => $_SESSION['domain_uuid']);
$extensions = $database->select($sql, $parameters, 'all');

// 权限检查
if (permission_exists('extension_view')) {
    // 执行操作
}
```

### 模块开发

创建新模块需要包含的文件：
```
/app/{module_name}/
  ├── app_config.php         # 模块配置
  ├── app_defaults.php       # 默认设置
  ├── app_languages.php      # 多语言支持
  ├── app_menu.php           # 菜单定义
  └── resources/             # 资源文件
```

### WebSocket 事件

```javascript
// 通话事件
call_event: {
    event_subtype: 'CHANNEL_CREATE' | 'CHANNEL_DESTROY' | 'CHANNEL_ANSWER',
    call_uuid: 'uuid',
    caller_id_number: '1001',
    destination_number: '1002'
}

// 注册事件
registration_event: {
    event_subtype: 'REGISTER' | 'UNREGISTER',
    extension: '1001'
}

// 心跳
heartbeat: {}
```

## 故障排除

### 常见问题

1. **通话记录不显示**
   - 检查 CDR 配置
   - 验证数据库连接
   - 检查权限设置

2. **设备无法注册**
   - 检查 SIP 配置文件
   - 验证网络连接
   - 查看防火墙规则

3. **传真发送失败**
   - 启用 T.38
   - 检查音频编解码器
   - 验证网络质量

### 日志位置

```bash
# FreeSWITCH 日志
/var/log/freeswitch/freeswitch.log

# FusionPBX 日志
/var/log/freeswitch/fusionpbx.log

# Nginx 日志
/var/log/nginx/error.log
/var/log/nginx/access.log

# PHP-FPM 日志
/var/log/php-fpm/error.log
```

## 网络配置

### 防火墙端口

```bash
# SIP 端口
5060/udp  # SIP
5061/tcp  # SIP TLS

# RTP 端口范围
16384-32768/udp

# WebSocket
7443/wss
5066/ws

# Event Socket
8021/tcp
```

### NAT 穿透

```xml
<!-- external.xml -->
<param name="ext-rtp-ip" value="auto"/>
<param name="ext-sip-ip" value="auto"/>
<param name="apply-nat-acl" value="acl_nat"/>
```

## 备份与恢复

### 备份脚本

```bash
# 命令行备份
/usr/share/fusionpbx/scripts/backup/sh/backup.sh
```

### 备份内容
- PostgreSQL 数据库
- FreeSWITCH 配置文件
- 录音文件
- 语音邮件
- 传真文件

## 升级指南

```bash
# 1. 更新 FusionPBX 源码
cd /var/www/fusionpbx
git pull

# 2. 更新 FreeSWITCH 脚本
cd /usr/share/freeswitch/scripts
git pull

# 3. 升级数据库架构
# 通过 Web 界面: Advanced -> Upgrade

# 4. 重启服务
systemctl restart freeswitch
systemctl restart php-fpm
systemctl restart nginx
```

## 资源链接

### 社区
- IRC: irc.libera.chat #fusionpbx
- GitHub: https://github.com/fusionpbx/fusionpbx
- 论坛: https://www.fusionpbx.com

### 培训与支持
- 免费培训视频: https://www.youtube.com/FusionPBX
- 商业支持: https://www.fusionpbx.com/support
- 管理员培训
- 高级培训
- 开发者培训

---

**文档版本:** 基于 FusionPBX Docs Latest (2024)
**最后更新:** 2026-01-27
