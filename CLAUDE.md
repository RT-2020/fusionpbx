# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## 项目概述

FusionPBX 是一个企业级多租户 PBX（专用小交换机）系统，基于 FreeSWITCH 构建，提供完整的电信解决方案。本项目在标准 FusionPBX 基础上进行了定制开发，新增了调度控制面板、紧急呼叫、批量呼叫等功能模块。

## 技术栈

**后端：** PHP 7.4+、FreeSWITCH、Lua、PostgreSQL（支持 SQLite/MySQL）
**前端：** JavaScript (ES5/ES6)、jQuery、Bootstrap 4/5、ACE Editor、Chart.js
**通信协议：** SIP、WebSocket/WSS、WebRTC、Event Socket Layer (ESL)

## 核心架构

### 模块化结构

```
/app/{module_name}/          # 应用模块（70+个）
  ├── app_config.php         # 模块配置（权限、数据库schema、菜单）
  ├── app_defaults.php       # 默认设置
  ├── app_languages.php      # 多语言支持
  ├── app_menu.php           # 菜单定义
  └── resources/             # 模块资源文件

/core/                       # 核心功能（认证、仪表板、数据库、菜单等）
/resources/                  # 共享资源
  ├── classes/               # PHP 类库
  │   ├── database.php       # 数据库抽象层
  │   ├── settings.php       # 配置管理
  │   ├── event_socket.php   # FreeSWITCH Event Socket 通信
  │   └── permissions.php    # 权限管理
  ├── functions.php          # 全局函数
  └── require.php            # 自动加载和初始化

/themes/                     # 主题模板
```

### 关键设计模式

**数据库访问：**
- 使用全局 `$database` 对象（PDO 抽象层）
- 查询：`$database->select($sql, $parameters, $mode)`
- 执行：`$database->execute($sql, $parameters)`
- 计数：`$database->count($sql, $parameters)`

**配置层级（优先级从高到低）：**
1. 用户设置
2. 域设置 (`v_domain_settings`)
3. 默认设置 (`v_default_settings`)

**FreeSWITCH 通信：**
- 使用 `event_socket.php` 类进行 ESL 通信
- 通过 `switch.php` 执行 FreeSWITCH 命令
- WebSocket 用于实时事件推送

## 编码规范

### PHP

- **缩进：** 使用 Tab（不是空格）
- **命名：** 下划线命名法（`$user_name`、`function get_user_info()`、`class database_connection`）
- **主键：** 所有主键使用 UUID（36 字符）
- **表名：** 使用 `v_` 前缀
- **审计字段：** `insert_date`、`insert_user`、`update_date`、`update_user`

### 安全实践（必须遵守）

- 使用 PDO 预处理语句防止 SQL 注入
- 使用 `check_str()` 清理用户输入
- 使用 `permission_exists()` 检查权限
- 使用 `is_uuid()` 验证 UUID 格式
- 使用 `htmlentities()` 或 `escape()` 转义输出

### 多租户隔离

- 所有查询必须包含 `domain_uuid` 过滤
- 使用 `$_SESSION['domain_uuid']` 获取当前域
- 检查用户是否有 `domain_all` 权限

### 权限系统

- 权限命名：`{module}_{action}`（如：`extension_view`、`extension_add`）
- 在 `app_config.php` 中定义权限

### JavaScript

- 使用 jQuery 进行 DOM 操作和 AJAX
- 变量使用驼峰命名法：`userId`、`domainName`
- WebSocket 使用 WSS 协议，实现心跳检测和自动重连

### Lua (FreeSWITCH)

- 使用 `freeswitch.consoleLog()` 记录日志
- 使用 `session:ready()` 检查会话状态
- 正确处理 hangup 事件

## 常用函数

**全局函数（[resources/functions.php](resources/functions.php)）：**
- `permission_exists($permission)` - 检查权限
- `is_uuid($uuid)` - 验证 UUID 格式
- `check_str($str)` - 清理字符串
- `escape($str)` - 转义 HTML
- `format_phone($phone)` - 格式化电话号码
- `byte_convert($bytes)` - 字节单位转换

**设置管理（[resources/classes/settings.php](resources/classes/settings.php)）：**
- `$settings->get($category, $subcategory, $name)` - 获取设置
- `$settings->set($category, $subcategory, $name, $value)` - 设置值

**FreeSWITCH 通信（[resources/classes/event_socket.php](resources/classes/event_socket.php)）：**
- `$event_socket->connect()` - 连接到 FreeSWITCH
- `$event_socket->command($cmd)` - 执行命令
- `$event_socket->disconnect()` - 断开连接

## 核心模块

**基础功能：**
- [extensions/](app/extensions/) - 分机管理
- [domains/](app/domains/) - 域管理（多租户）
- [users/](app/users/) - 用户管理
- [permissions/](app/permissions/) - 权限管理

**通话功能：**
- [dialplans/](app/dialplans/) - 拨号计划
- [call_centers/](app/call_centers/) - 呼叫中心
- [conferences/](app/conferences/) - 会议系统
- [ring_groups/](app/ring_groups/) - Ring 组（支持紧急呼叫）
- [call_recordings/](app/call_recordings/) - 呼叫录制

**自定义模块：**
- [basic_operator_panel/](app/basic_operator_panel/) - 调度控制面板（实时通话管理、急呼、组呼/全呼）
- [emergency/](app/emergency/) - 紧急呼叫处理
- [base_stations/](app/base_stations/) - 基站管理

**高级功能：**
- [voicemails/](app/voicemails/) - 语音邮件
- [ivr_menus/](app/ivr_menus/) - IVR 菜单
- [devices/](app/devices/) - 设备配置
- [gateways/](app/gateways/) - 网关管理
- [sip_profiles/](app/sip_profiles/) - SIP 配置

**系统管理：**
- [switch/](app/switch/) - 系统状态监控
- [active_calls/](app/active_calls/) - 活动通话监控
- [registrations/](app/registrations/) - 注册状态

## 初始化

所有页面必须包含：
```php
require_once "resources/require.php";
```

这会初始化数据库连接、Session、权限系统和配置。

## 消息显示

使用 `message::add()` 显示用户消息：
- 错误消息：`message::add($text['error-message'], 'negative');`
- 警告消息：`message::add($text['warning-message'], 'alert');`
- 成功消息：`message::add($text['success-message'], 'positive');`

## 国际化

- 使用 `app_languages.php` 定义翻译
- 使用 `$text` 数组访问翻译
- 格式：`$text['label-key']['en-us'] = "English Text"`

## 调试

**PHP：**
- 使用 `echo` 或 `print_r()`（开发环境）
- 检查 `/var/log/freeswitch/` 下的日志
- 使用浏览器开发者工具检查 AJAX 请求

**FreeSWITCH：**
- 使用 `fs_cli` 命令行工具
- 查看 `freeswitch.log` 日志
- 使用 `sofia status` 检查 SIP 状态
- 使用 `show channels` 查看活动通话

## 自定义功能开发指南

### 调度控制面板 (basic_operator_panel)

调度控制面板是本项目的核心自定义功能，实现了：
- 实时通话监控和控制
- 急呼功能（紧急呼叫）
- 组呼/全呼（批量呼叫）
- 来电队列管理
- 通话转接、保持、挂断等操作

**关键文件：**
- `app/basic_operator_panel/basic_operator_panel_index.php` - 主界面
- `app/basic_operator_panel/resources/dashboard/socket_server.php` - WebSocket 服务器

**WebSocket 事件类型：**
- `call_event` - 通话事件（CHANNEL_CREATE、CHANNEL_DESTROY、CHANNEL_ANSWER）
- `registration_event` - 注册事件
- `heartbeat` - 心跳检测

### 紧急呼叫系统

紧急呼叫系统涉及多个模块的联动：
- 振铃组 (`ring_groups`) 添加"启用紧急呼叫"选项
- 会议 (`conferences`) 添加"紧急会议"选项
- 调度面板 (`basic_operator_panel`) 显示紧急呼叫报警
- 呼叫录制 (`call_recordings`) 自动标记紧急通话录音

### 数据库表设计规范

创建新表时必须包含：
```sql
CREATE TABLE v_table_name (
    table_name_uuid VARCHAR(36) PRIMARY KEY,
    domain_uuid VARCHAR(36) NOT NULL,
    -- 其他字段...
    insert_date TIMESTAMP,
    insert_user VARCHAR(36),
    update_date TIMESTAMP,
    update_user VARCHAR(36),
    FOREIGN KEY (domain_uuid) REFERENCES v_domains(domain_uuid)
);
```

## VoIP 特定开发规范

### SIP 拨号计划

XML 拨号计划位于 `/app/dialplans/`，使用条件判断和正则表达式匹配：
```xml
<extension name="example">
    <condition field="destination_number" expression="^(\d{4})$">
        <action application="bridge" data="user/$1@${domain_name}"/>
    </condition>
</extension>
```

### FreeSWITCH 命令执行

```php
// 通过 switch.php 执行命令
$cmd = "uuid_kill " . $call_uuid;
$response = switch_command($cmd);

// 检查响应
if ($response !== "+OK") {
    message::add("命令执行失败: $response", 'negative');
}
```

### 通话控制

- 发起通话：`originate user/{extension} &echo`
- 挂断通话：`uuid_kill {uuid}`
- 通话转移：`uuid_transfer {uuid} {destination}`
- 保持通话：`uuid_hold {uuid} on/off`

## 开发规范参考

项目包含详细的开发规范，位于 [`.cursor/rules/`](.cursor/rules/) 目录：
- `fusionpbx.mdc` - 项目总体规则和开发规范
- `php-development.mdc` - PHP 开发具体规则
- `voip-specific.mdc` - VoIP 和通信系统特定规则
- `javascript-development.mdc` - JavaScript 开发规则
- `lua-development.mdc` - Lua 脚本开发规则
- `database-development.mdc` - 数据库开发规则
- `security.mdc` - 安全最佳实践
- `PRPER5.mdc` - RIPER-5 开发协议（研究→创新→规划→执行→审查）

## 项目自定义文档

自定义功能开发文档位于 [`.trae/documents/`](.trae/documents/) 目录，包含：
- 调度控制面板实现文档
- 紧急呼叫实施方案
- 批量呼叫功能文档
- 各种问题修复方案

## 关键约定

- 优先考虑系统的稳定性和可靠性
- 保持向后兼容性
- 遵循 FusionPBX 现有的代码风格
- 考虑多租户场景下的数据隔离
- 实施严格的权限检查
- 编写可维护和可扩展的代码
- 注重电信系统的实时性和高可用性
- WebSocket 连接必须实现心跳检测和自动重连
- 所有通话操作必须正确处理资源释放
