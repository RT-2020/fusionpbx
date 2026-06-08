# 调度终端急呼声光报警 TCP 接入实施记录

## 1. 文档状态

- 文档状态：已按当前代码实现修订
- 修订日期：2026-03-20
- 适用范围：`app/basic_operator_panel/` 网页调度终端急呼声光报警闭环
- 当前结论：方案已落地为单台全局 TCP 报警器方案，现阶段属于“已实现并进入部署/联调阶段”，不再是待开发讨论稿

本文档用于说明当前仓库里已经实现的内容、实际行为、与原方案的偏差、上线步骤和剩余联调项。

## 2. 当前已实现范围

当前工作区已经完成以下闭环：

1. 内部用户急呼打到网页调度终端时，服务端可识别紧急来话并建档。
2. 只有命中网页调度终端在线 `presence` 的来话才会触发声光报警器。
3. 报警器由服务端常驻服务统一控制，浏览器不直接控制 TCP 硬件。
4. 调度员接听后，服务端依据 `answered / CHANNEL_ANSWER / CHANNEL_BRIDGE / ACTIVE` 等状态清警。
5. 页面上的“响应并清警”和“强制清警”会写入 job，由常驻服务执行清警。
6. `failed / hangup / CHANNEL_DESTROY / presence_expired / 启动恢复校验` 都带兜底清警路径。
7. 新增独立测试页用于人工验证报警器连通性和命令下发。
8. 报警器配置改为单台全局配置，保存后会尝试自动重启服务，失败时降级为 reload 信号。

## 3. 实际实现结构

### 3.1 关键文件

- `app/basic_operator_panel/resources/classes/emergency_alarm_device.php`
  - 封装 YX02S TCP 命令、收发、回包解析和状态查询。
- `app/basic_operator_panel/resources/classes/emergency_alarm_service.php`
  - 常驻服务。负责 ESL 事件监听、急呼建档、presence 校验、job 消费、运行态维护和恢复补偿。
- `app/basic_operator_panel/resources/service/emergency_alarm.php`
  - CLI 服务入口。不是 HTTP 页面，浏览器访问返回 `Unauthorized` 属于预期。
- `app/basic_operator_panel/resources/service/debian-emergency_alarm.service`
  - systemd 服务样例。
- `app/basic_operator_panel/dispatcher_api.php`
  - 配置保存、presence API、health 查询、ack/force clear、急呼记录更新。
- `app/basic_operator_panel/index.php`
  - 操作面板 UI、报警状态区、全局报警器配置入口。
- `app/basic_operator_panel/emergency_alarm_test.php`
  - 报警器测试页，支持连接、查询、开启/关闭报警和原始命令发送。
- `app/basic_operator_panel/resources/dispatcher-control.js`
  - 网页调度终端 `presence` 上报、清理、清警按钮行为。
- `app/basic_operator_panel/resources/jssip-client.js`
  - 前端急呼识别和 STUN 开关逻辑。
- `app/switch/resources/scripts/app/ring_groups/index.lua`
  - 振铃组急呼头透传，补齐 `Call-Info`、`Alert-Info`、`X-Emergency-Call`。
- `app/basic_operator_panel/exec.php`
  - 调度面板主动发起急呼时下发急呼相关 SIP 头。

### 3.2 实际数据表

本次实现已经在 `app/basic_operator_panel/app_config.php` 中补齐以下定义：

- 扩展 `v_emergency_calls`
  - 增加 `alarm_state`
  - 增加 `alarm_trigger_time`
  - 增加 `alarm_ack_time`
  - 增加 `alarm_clear_time`
  - 增加 `alarm_clear_reason`
  - 增加 `alarm_error`
  - 增加 `alarm_last_response`
  - 增加 `update_date`
  - 增加 `update_user`
- 新增 `v_emergency_alarm_logs`
- 新增 `v_operator_panel_presence`
- 新增 `v_emergency_alarm_jobs`
- 新增 `v_emergency_alarm_runtime`

### 3.3 实际权限与入口

- 新增权限：`operator_panel_alarm_test`
  - 默认授予 `superadmin`、`admin`
- 报警器测试页入口：
  - `/app/basic_operator_panel/emergency_alarm_test.php`
- 操作面板中的“报警器配置”按钮：
  - 需要 `default_setting_edit`
- 配置保存位置：
  - 当前 UI 实际写入 `v_default_settings`
  - 当前 UI 是“全局单台设备配置”，不是按域配置，也不是按分机配置

## 4. 实际业务闭环

### 4.1 急呼识别

当前实现兼容以下紧急呼叫头：

- `Call-Info`
- `Alert-Info`
- `X-Emergency-Call`

前端 `jssip-client.js` 和服务端 `emergency_alarm_service.php` 都已兼容上述头部识别。服务端还会对原始 ESL 事件正文做兜底匹配。

### 4.2 触发条件

当前服务端触发报警必须同时满足：

1. FreeSWITCH 事件被识别为急呼。
2. 能提取出被叫分机。
3. `v_operator_panel_presence` 中存在该被叫分机的未过期在线记录。

如果没有命中 `presence`，当前实现不会新建该条 `user_to_dispatcher` 急呼主记录，也不会触发硬件报警。

### 4.3 实际状态流转

当前服务端会把急呼主记录统一推进为 `direction = 'user_to_dispatcher'`，并按事件更新：

- 响铃中：`status = ringing`
- 已接听：`status = answered`
- 已结束：`status = completed` 或 `failed`

`alarm_state` 当前实际会用到这些值：

- `pending_presence`
- `triggered`
- `acknowledged`
- `cleared`

### 4.4 清警逻辑

当前会触发清警的路径：

- `CHANNEL_ANSWER`
- `CHANNEL_BRIDGE`
- `answer_state = ANSWERED`
- `channel_call_state = ACTIVE`
- `CHANNEL_HANGUP_COMPLETE`
- `CHANNEL_DESTROY`
- 页面人工 `acknowledge_and_clear_alarm`
- 页面人工 `force_clear_alarm`
- `presence_expired`
- 启动恢复和周期恢复发现设备状态与数据库不一致

## 5. 报警器 TCP 控制实际行为

### 5.1 当前默认配置

当前默认值来自 `app/basic_operator_panel/app_config.php` 和设备类默认值：

- 主机：`192.168.50.1`
- 端口：`9003`
- Socket 超时：`1200ms`
- 音频目录：`1`
- 音频曲目：`1`
- 频闪模式：`3`
- 音量：`30`

### 5.2 实际开启序列

当前 `emergency_alarm_device::activate_alarm()` 的实际步骤不是只发两条命令，而是：

1. `0x3F` 查询在线
2. `0x06` 设置音量
3. `0x43` 查询音量
4. `0x10` 循环播放目录/曲目
5. `0xC2` 启动频闪
6. `0x42` 查询播放状态
7. `0x70` 查询声光状态

### 5.3 实际关闭序列

当前 `emergency_alarm_device::clear_alarm()` 的实际步骤：

1. `0x16` 停止播放
2. `0xC2 ... 06` 关闭频闪
3. `0x42` 查询播放状态
4. `0x70` 查询声光状态

### 5.4 设备恢复补偿

当前服务在以下时机会做恢复校验：

- 启动时 `startup`
- 配置 reload 时 `reload`
- 周期巡检时 `periodic`

恢复策略：

- 数据库没有触发中的急呼，但设备仍在报警：强制清警
- 数据库存在触发中的急呼，但设备处于空闲：补发激活

## 6. Presence、Job 与运行态实际参数

### 6.1 Presence

当前实现和原始讨论稿不同，实际参数为：

- 页面 heartbeat 间隔：`20s`
- 服务端默认 TTL：`90s`
- 服务端最小 TTL 下限：`30s`
- 过期清理轮询：`15s`
- 触发中记录与 presence 对账：`5s`

### 6.2 Job

当前 `dispatcher_api.php` 与常驻服务通过 `v_emergency_alarm_jobs` 通信。

实际 job 动作为：

- `acknowledge_and_clear_alarm`
- `force_clear_alarm`

当前代码里实际 job 状态值为：

- `pending`
- `running`
- `completed`
- `failed`

Web API 默认等待 job 结果超时为：

- `8s`

### 6.3 Runtime

`v_emergency_alarm_runtime` 当前用于保存：

- `service_state`
- `device_state`
- `device_endpoint`
- `last_heartbeat`
- `last_device_response`
- `last_error`

操作面板的健康状态展示也读取这张表。

## 7. 配置保存与服务重载实际行为

### 7.1 当前配置保存行为

操作面板中的“报警器配置”当前会保存这些全局参数：

- `host`
- `port`
- `timeout_ms`
- `audio_folder`
- `audio_track`
- `strobe_mode`
- `audio_volume`

保存后会清理 settings 缓存，并尝试让 `emergency_alarm` 服务加载新配置。

### 7.2 自动重启实际逻辑

当前 `dispatcher_api.php` 的处理顺序是：

1. 尝试 `systemctl restart emergency_alarm`
2. 尝试 `/bin/systemctl restart emergency_alarm`
3. 尝试 `sudo -n systemctl restart emergency_alarm`
4. 尝试 `sudo -n /bin/systemctl restart emergency_alarm`
5. 如果以上都失败，再尝试向现有进程发送 reload 信号

reload 信号的 PID 获取顺序：

1. `/var/run/fusionpbx/emergency_alarm_service.pid`
2. `systemctl show -p MainPID --value emergency_alarm`
3. `pgrep -f "/app/basic_operator_panel/resources/service/emergency_alarm.php"`
4. `pgrep -f "emergency_alarm.php --debug"`

### 7.3 关于 “PID file not found” 报错

如果页面提示：

`Service restart failed. PID file not found: /var/run/fusionpbx/emergency_alarm_service.pid`

说明的是：

- 配置保存本身已经成功
- 但目标机器上 `emergency_alarm` 服务没有被当前重启逻辑可靠接管

常见原因：

- 服务还没按 systemd 安装
- 服务不是通过 systemd/守护方式运行
- PID 文件未创建
- Web 进程无权执行重启命令

这时需要按部署步骤先把常驻服务安装并启动好，再由页面进行自动重载。

### 7.4 关于浏览器访问 `emergency_alarm.php`

`app/basic_operator_panel/resources/service/emergency_alarm.php` 是 CLI 后台服务入口，不是 HTTP 接口。

浏览器访问时返回：

```text
#!/usr/bin/env php
Unauthorized
```

属于预期行为，不是程序异常。

## 8. 与 2026-03-17 原方案的主要差异

以下内容已按当前代码纠正：

1. 文档状态已从“待实施方案”改为“已实现记录”。
2. 报警器配置当前是“单台全局默认设置”，不是页面内的临时域配置。
3. 当前实际写入的是 `v_default_settings`，入口权限依赖 `default_setting_edit`。
4. 配置保存后不是只做重启，失败时还会降级尝试 reload。
5. presence 实际不是 `15s/45s`，而是 `20s heartbeat + 90s TTL`。
6. 服务端急呼识别已兼容 `Call-Info`、`Alert-Info`、`X-Emergency-Call`。
7. 报警器开启序列实际包含在线查询、音量设置和状态查询，不是只有 `0x10 + 0xC2`。
8. 当前已新增测试页 `emergency_alarm_test.php`，原方案未反映。
9. 当前已新增 `v_emergency_alarm_logs`、`v_operator_panel_presence`、`v_emergency_alarm_jobs`、`v_emergency_alarm_runtime`。
10. STUN 本次未继续调整，当前逻辑是默认关闭，只有 `localStorage.dispatcher_use_stun=1` 才开启。

## 9. 部署与升级步骤

### 9.1 必须执行的升级

本次改动包含：

- 新表定义
- `v_emergency_calls` 字段扩展
- 新默认设置
- 新菜单
- 新权限

部署后必须执行：

1. `php core/upgrade/upgrade_schema.php`
2. `php core/upgrade/upgrade_menu.php`
3. 重新登录 FusionPBX 后台，刷新菜单和权限会话

如果不做这一步，会出现以下典型现象：

- 配置保存后仍读到旧地址
- 新权限或新菜单不生效
- 新表不存在导致 API/服务异常

### 9.2 常驻服务安装建议

Linux 环境建议使用：

- `app/basic_operator_panel/resources/service/debian-emergency_alarm.service`

典型命令示例：

```bash
cp app/basic_operator_panel/resources/service/debian-emergency_alarm.service /etc/systemd/system/emergency_alarm.service
systemctl daemon-reload
systemctl enable --now emergency_alarm
systemctl status emergency_alarm
```

## 10. 当前验证状态

当前代码已完成的静态验证：

- `php -l` 已通过：
  - `app/basic_operator_panel/app_config.php`
  - `app/basic_operator_panel/app_languages.php`
  - `app/basic_operator_panel/app_menu.php`
  - `app/basic_operator_panel/dispatcher_api.php`
  - `app/basic_operator_panel/exec.php`
  - `app/basic_operator_panel/emergency_alarm_test.php`
  - `app/basic_operator_panel/resources/classes/emergency_alarm_device.php`
  - `app/basic_operator_panel/resources/classes/emergency_alarm_service.php`
  - `app/basic_operator_panel/resources/service/emergency_alarm.php`
- `node --check` 已通过：
  - `app/basic_operator_panel/resources/jssip-client.js`
  - `app/basic_operator_panel/resources/dispatcher-control.js`

## 11. 仍需现场联调确认的事项

以下内容需要在真实设备和目标主机上继续确认：

1. 报警器目录/曲目是否与设备实际存储一致，否则可能“已连接但无声音”。
2. 设备音量是否被面板配置成了 `0` 或设备本机静音。
3. 目标主机是否已正确安装并托管 `emergency_alarm` systemd 服务。
4. Web 进程是否具备重启或 reload 常驻服务的权限。
5. 真实急呼入口是否全部通过振铃组并带上急呼头。

## 12. 最终结论

当前仓库已经不是“方案评审阶段”，而是“单台全局 TCP 声光报警器闭环已完成”的状态。

本次实现的边界也已经明确：

- 只支持单台全局报警器
- 只对网页调度终端在线 `presence` 命中的急呼触发
- 接听、人工响应、挂断、恢复补偿都会清警
- 浏览器不直接控制硬件
- STUN 暂按当前实现保持不变

后续如果继续扩展，应单独规划：

- 多台报警器或分域/分机级设备映射
- 更精细的席位在线判定
- 更完善的设备安装脚本和 PID 管理
