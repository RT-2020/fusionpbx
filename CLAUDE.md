# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

1、请始终用中文（简体）进行回复。
2、必须完全以简体中文来进行内部推理和思考过程，这是一项严格的规定。
3、必填项：在每次聊天回复的开头，必须明确说明“真实的模型名称、模型大小、模型类型及其修订版本（更新日期）”。此规定仅适用于聊天回复，不适用于内联编辑。

## 仓库定位

这是一个基于 FusionPBX 的深度定制仓库：核心仍是 PHP + FreeSWITCH + PostgreSQL 的多租户 PBX，但业务重点已经偏向调度控制、紧急呼叫和终端资产管理。

最重要的定制模块：

- `app/basic_operator_panel/`：核心调度面板，包含浏览器 SIP/WebRTC、调度 API、会议与急呼联动。
- `app/emergency/`：紧急呼叫相关配置与迁移逻辑。
- `app/base_stations/`：基站管理。
- `app/cameras/`：摄像头/RTSP 管理。

## 常用命令

### 仓库级命令现状

仓库内**未发现**以下标准项目级入口：

- 根级 `composer.json`
- 根级 `package.json`
- `Makefile`
- `phpunit.xml*`
- 前端构建配置或 CI 流水线

因此不要默认存在以下命令：

- `composer install`
- `npm install` / `pnpm install` / `yarn`
- `npm run build`
- `npm test`
- `phpunit`
- 仓库级 lint / 单测 / 单文件测试命令

已知现实情况是：这个仓库更像“直接部署到 Linux 服务器运行的应用代码”，安装主要依赖外部 `fusionpbx-install.sh` 仓库（见 `readme.md`），而不是在当前仓库内完成构建。

### 运行与调试命令

以下命令来自仓库文档，主要用于部署后的 Linux/FreeSWITCH 环境：

```bash
# SIP 状态
fs_cli -x "sofia status"

# 活动通道
fs_cli -x "show channels"

# 查看某分机注册状态
fs_cli -x "sofia status profile internal reg <extension>"

# 重载 internal SIP profile
fs_cli -x "sofia profile internal restart"

# 重启服务
systemctl restart freeswitch
systemctl restart php-fpm
systemctl restart nginx

# 查看 TLS / WSS 相关日志
tail -f /var/log/freeswitch/freeswitch.log | grep -i tls

# 检查 7443 监听
ss -tlnp | grep 7443
```

### 手工测试入口

仓库内未发现自动化测试框架，但有少量手工/集成测试入口：

- `app/basic_operator_panel/api_test.php`：调度面板 API 手工测试页；依赖登录态和 `operator_panel_view` 权限。
- `app/email_queue/email_test.php`：邮件发送手工测试页；依赖 SMTP 配置。
- `app/switch/resources/scripts/resources/tests/self_test.lua`：Lua 自检脚本；仓库内未发现统一调用命令。

### 单个测试如何运行

未发现 PHPUnit/Jest/Vitest 等自动化测试框架，因此**没有标准的“运行单个测试”命令**。若需要验证功能，通常只能：

- 在部署环境中访问对应页面/API 做手工验证；或
- 直接用 FreeSWITCH/系统命令观察运行状态。

## 运行时架构

### 请求入口与页面生命周期

高频链路是：

`index.php` / 专用入口 -> `resources/require.php` -> `resources/check_auth.php` -> 页面逻辑 -> `resources/header.php` -> 页面主体 -> `resources/footer.php`

关键点：

- `index.php` 主要做入口分流：已登录时跳到登录目标或 dashboard，未登录时跳到主题首页或 `login.php`。
- `login.php` 更像认证跳板，真正的会话校验发生在 `resources/check_auth.php`。
- 不是所有请求都走根入口：`.htaccess` 会把 API、Provision 等请求重写到各自入口。

### `resources/require.php` 是统一 bootstrap

`resources/require.php` 负责加载并初始化：

- `config` / `auto_loader`
- 全局函数 `resources/functions.php`
- 全局数据库对象 `$database`
- session bootstrap（经 `resources/php.php`）
- 全局设置对象 `$settings`
- CIDR 校验与 `resources/switch.php`
- 动态语言切换、域切换

未来排查运行时问题时，先确认目标页面是否已经正确经过 `require.php`。

### `header.php` / `footer.php` 不是简单模板片段

- `resources/header.php`：建立域会话、处理 `reloadxml`、选择主题、初始化输出缓冲、计算当前菜单父节点、装配 CMS 内容。
- `resources/footer.php`：取出页面主体缓冲，初始化 Smarty 模板，注入翻译、菜单、主题设置、token、domain selector、body，最后统一渲染 `themes/.../template.php`。

因此多数业务页面只负责“查询 + 输出主体 HTML”；真正的外壳渲染发生在 `header/footer`。

## 模块结构

`app/<module>/` 下的模块通常由“元数据文件 + 页面控制器 + 资源类”组成。理解一个模块时，优先按下面顺序阅读：

1. `app_config.php`
2. `app_menu.php`
3. `app_languages.php`
4. 页面入口（如 `index.php`、列表页、编辑页、API）
5. `resources/classes/`

### 四类元数据文件的职责

- `app_config.php`：模块 manifest，定义应用信息、权限、默认设置、数据库 schema。
- `app_menu.php`：菜单挂载位置、路径、分组可见性。
- `app_defaults.php`：升级/迁移逻辑；它不是简单常量表，而是会在升级流程中执行的 PHP 脚本。
- `app_languages.php`：模块多语言文本。

## 数据访问与多租户

### 数据访问模式

仓库主要有两种数据库写法：

- 手写 SQL + `$database->select()` / `execute()` / `count()`
- 结构化数组 + `$database->save()` / `delete()`

不要引入新的数据库访问风格，优先沿用目标模块已有模式。

### 多租户隔离不是自动 ORM 规则

这个仓库的多租户隔离主要靠以下三层协作：

1. `domains` / `settings` 将当前域上下文写入 `$_SESSION`
2. 业务 SQL 显式带 `domain_uuid`
3. `*_all` 或 `domain_all` 权限决定是否允许跨域

因此修改查询时必须人工检查：

- 是否带 `domain_uuid`
- 是否需要处理 `*_all` / `domain_all`
- 编辑、删除、列表接口是否都保持同样的域隔离逻辑

### 设置读取优先级

运行时设置优先级是：

1. user
2. domain
3. default

优先通过 `$settings->get(...)` 读取设置，而不是直接查询设置表。

## FreeSWITCH、Event Socket 与 WebSocket

### ESL 桥接

`resources/classes/event_socket.php` 是 PHP 到 FreeSWITCH Event Socket 的核心桥接层。很多业务逻辑会直接调用：

- `event_socket::api('show channels as json')`
- `event_socket::api('show registrations as json')`
- `event_socket::api('uuid_kill ...')`
- `event_socket::api('uuid_transfer ...')`
- `event_socket::api('reloadxml')`

### 一定要区分两套“WebSocket”

这套系统里至少存在两种不同含义的 WebSocket：

1. `core/websockets/`：FusionPBX 内部实时事件总线，用于后端服务向订阅者广播事件。
2. `app/basic_operator_panel/resources/jssip-client.js` 中的 JsSIP WS/WSS：浏览器软电话到 SIP/FreeSWITCH 的信令通道。

不要把这两者混为一谈。

### 调度面板关键数据流

调度面板的典型链路是：

浏览器操作 / JsSIP 事件 -> `dispatcher_api.php` / `dispatcher_conference_api.php` / `exec.php` -> `event_socket::api(...)` -> FreeSWITCH

这意味着 `basic_operator_panel` 不是普通后台页面，而是：

- 浏览器 SIP/WebRTC 终端
- 调度控制 UI
- PHP API 层
- FreeSWITCH ESL 控制层

四者的组合。

## 高风险区域

以下改动默认按高风险处理，先评估影响再动手：

- `app/basic_operator_panel/`
- `app/emergency/`
- WebSocket 相关逻辑
- FreeSWITCH / Event Socket / 拨号计划相关逻辑
- 数据库 schema、权限系统、多租户隔离逻辑

仓库已经提供了风险规则与 `/plan` 说明，优先参考：

- `.claude/rules/risk-rules.md`
- `.claude/commands/plan.md`

## 必看规则文件

这些文件比本 CLAUDE.md 更细，修改代码前应先对照：

- `.claude/rules/01-security.md`
- `.claude/rules/02-php-coding.md`
- `.claude/rules/03-javascript-coding.md`
- `.claude/rules/04-lua-coding.md`
- `.claude/rules/05-voip-rules.md`
- `.claude/rules/06-operator-panel.md`
- `.claude/rules/07-git-commit.md`

## 现实限制与易错点

- 这个仓库不是标准“构建型”项目，很多常见命令并不存在。
- 许多调试命令只适用于运行中的 Linux 服务器，不适用于仅有源码的本地工作副本。
- `resources/` 下包含第三方代码；搜索到某些工具关键词时，先确认它是不是第三方库自己的文件，而不是仓库级工作流。
- 紧急呼叫不是孤立模块，而是 `basic_operator_panel`、`emergency`、会议/录音等多模块联动能力。
