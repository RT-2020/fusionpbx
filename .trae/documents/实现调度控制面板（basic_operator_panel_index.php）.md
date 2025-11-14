[MODE: PLAN 规划]

## 概述
- 在 `app/basic_operator_panel/index.php` 构建一个面向调度员的完整控制面板，利用已导入的 `jssip-client.js`、`dispatcher-control.js`、`dispatcher-logger.js` 实现分机注册、呼叫控制、人工中继与多路通话管理。
- 严格对齐人工中继标准流程，保持与 FusionPBX/FreeSWITCH 兼容；前端以 JsSIP 为主进行会话控制，按需调用服务端 `dispatcher_api.php/exec.php` 作为回退路径（如转接失败原因解析）。

## 架构与数据流
- 前端会话源：`JsSipClient` 负责 SIP 注册、会话维护（呼入/呼出/保持/恢复/挂断/转接）、批量拨打（组呼）。
- 调度控制：`DispatcherControl` 编排业务流程（人工/自动中继、组呼、会议桥）、统一状态更新与 UI 反馈。
- 日志与统计：`DispatcherLogger` 记录通话日志与统计并上报 `dispatcher_api.php?action=save_call_log`。
- 实时状态：以 `JsSipClient` 的回调事件驱动 UI；可选叠加现有 `resources/content.php` 轮询用于外线队列/停靠显示的补充（保持兼容）。

## 核心模块规格

### 1) 通话状态显示区
- 数据来源：`JsSipClient.sessions`（键为 `sessionId`），每条线路展示：
  - `方向`（incoming/outgoing）、`类型`（normal/trunk/eavesdrop/three-way/conference）、`对端号码`（基于 `remote_identity` 解析）、`状态`（ringing/connected/held/transferring/ended/failed）。
  - 区分局内分机与外线：解析 `sip:EXT@domain` vs `sip:number@pstn`；`DispatcherControl.isTrunkNumber(uri)` 作为外线判定辅助。
- 多路通话上限：显示最多 6 路（当 `sessions.length > 6`，提示“超过上限，请先挂断/保留其他线路”）。
- 状态颜色：
  - 绿色 `connected`；黄色 `ringing/holding/transferring`；红色 `failed/emergency`；灰色 `ended`。
- 视觉反馈：来电闪烁提示；状态变更过渡动画；紧急来电红色横幅与图标。

### 2) 操作控制区
- 拨打：输入分机或外线号码→`JsSipClient.makeCall('sip:'+number+'@'+host, {audio:true})`；急呼：`{emergency:true}` 附加头（UI 红色标识）。
- 接听：`DispatcherControl.acceptCall()` 或 `acceptTrunkCall()`（人工中继流程：应答后展示桥接选项）。
- 中转（人工中继）：
  - 人工模式：`acceptTrunkCall()` → 显示桥接面板（输入目标分机）→ `sipClient.transfer('sip:'+ext+'@'+host, trunkSessionId)`；成功后自动移除该中继线路并提示“调度员退出”。
  - 自动模式：`processAutoTrunk()` 接听后立即 `transfer(...)`。
  - 失败原因：捕获 `transfer` 异常显示错误；如需更具体原因，调用 `exec.php?cmd=uuid_transfer&uuid={uuid}&destination={ext}` 并显示返回 `-ERR` 文本。
- 保持/恢复：`sipClient.hold(sessionId)` / `sipClient.unhold(sessionId)`；保持后在该线路显示倒计时（3 分钟），超时自动挂断并提示。
- 挂断：单路 `sipClient.hangup(sessionId)`；全部 `sipClient.hangupAll()`；类型挂断 `hangupByType('conference'|'group'|'normal'|'eavesdrop')`。
- 紧急呼叫：
  - 调度发起：拨号时设置 `options.emergency=true` 特殊 UI 提示；
  - 外部急呼到调度：`DispatcherControl.onIncomingCall` 检测头 `X-Emergency-Call/Alert-Info`，播放提示音与高亮红色横幅。

### 3) 多路通话管理
- 线路切换：点击线路卡片设为 `currentSession`，突出显示当前处理线路；非当前线路允许“保持/挂断”。
- 被保持线路：明显的“Held”徽标 + 倒计时徽章（显示剩余保持时间，默认 180s）。
- 快速操作：每条线路独立按钮（接听/保持/恢复/转接/挂断）；顶部提供“全部挂断”“结束组呼/会议”的批量按钮。

### 4) 界面设计
- 布局：
  - 左侧“通话线路栅格”（3×2 卡片，最多 6 条线路）。
  - 右侧“操作控制区”（拨号输入、接听/中转面板、组呼/会议控制、紧急呼叫按钮、日志入口）。
  - 顶部状态条：注册状态、当前线路数、紧急来电横幅。
- 颜色与反馈：遵循上诉状态颜色；操作成功/失败 toast；转接/保持过程加载态；急呼常驻红色提醒。

### 5) 异常与容错
- 调度忙排队：当 `JsSipClient.isCalling === true` 且有新的来电，显示“调度忙，已排队”提示；可配置自动接听下一路或要求人工确认。
- 转接失败：捕获 `refer` 异常并以弹窗显示；如调用服务端 `exec.php` 返回 `-ERR`，显示原文详情（如无联系人、用户不可达等）。
- 保持超时：每路保持计时器到 180s 触发 `hangup(sessionId)` 并 toast 提示；若业务要求“自动恢复”，可配置为超时执行 `unhold` 改为恢复。

## 与现有 JS 的集成点
- `jssip-client.js`（已存在）：直接调用以下方法实现控制：
  - `register(config)`、`makeCall(target, options)`、`acceptIncomingCall(options)`、`transfer(target, sessionId)`、`hold(sessionId)`、`unhold(sessionId)`、`hangup(sessionId)`、`hangupAll()`、`makeCallBatch(targets, options)`。
  - 利用事件：`on('incomingCall'|'callEstablished'|'callEnded'|'callFailed'|'registered')` 驱动 UI 状态刷新。
- `dispatcher-control.js`（已存在）：复用以下能力：
  - 人工/自动中继流程：`acceptTrunkCall()`、`showTrunkBridgeOptions()`、`bridgeTrunkCall(sessionId)`、`holdTrunkCall(sessionId)`、`rejectTrunkCall()`；
  - 普通来电：`acceptCall()`、`rejectCall()`；
  - 组呼：`startGroupCall(groupId)` 或 `startGroupCallWithExtensions(extensions)`；会议桥：`joinConference(conferenceRoom, context)`；
  - UI 更新：`updateUI()`；在此文件中新增“保持倒计时与超时处理”与“线路卡片渲染”方法（见下文改动）。
- `dispatcher-logger.js`（已存在）：在 `onCallEnded`、组呼/会议结束时调用 `logCall(type, data)` 与 `sendLogToServer`；提供日志面板入口按钮。

## 具体改动与函数签名
- `index.php`：
  - 新增“通话线路栅格”DOM 占位与操作控制区 DOM（拨号、接听/中转、挂断/批量、紧急呼叫）。
  - 初始化控制器：
    - `var dispatcherControl = new DispatcherControl();`
    - 将 TURN 配置注入 `window.turnConfig`（已存在）；从表单读取 SIP 参数后调用 `dispatcherControl.register({...})`。
  - 绑定 UI 操作：拨号按钮→`sipClient.makeCall(...)`；紧急呼叫按钮→`makeCall(...,{emergency:true})`；“全部挂断”→`sipClient.hangupAll()`。
  - 订阅事件更新栅格：在 `dispatcherControl.sipClient.on('incomingCall'|'callEstablished'|'callEnded'|'callFailed')` 中调用 `renderLinesGrid()` 刷新 UI。
- `dispatcher-control.js`：
  - 新增：`DispatcherControl.prototype.renderLinesGrid()` 渲染最多 6 路通话卡片（读取 `sipClient.getActiveSessions()`）。
  - 新增：`DispatcherControl.prototype.startHoldTimer(sessionId, seconds=180)` 启动保持倒计时；在 `holdTrunkCall(sessionId)` 和通用保持按钮时调用；倒计时 UI 元素 id `hold-timer-{sessionId}`。
  - 新增：`DispatcherControl.prototype.resumeHeldCall(sessionId)` 封装 `sipClient.unhold(sessionId)` 并清理倒计时。
  - 新增：`DispatcherControl.prototype.hangupAll()` 调用 `sipClient.hangupAll()` 并刷新 UI（如果未已有统一入口）。
  - 修改：在 `onIncomingCall` 检测 `this.sipClient.isCalling` 时显示“调度忙，来电已排队”提示条。
- `dispatcher-logger.js`：
  - 无需改动；在 `DispatcherControl.onCallEnded()` 已调用日志；若需统计保持超时事件，额外调用 `logCall('normal',{status:'timeout'})`。
- `resources/content.php`：
  - 保持原轮询逻辑不变；如果需要展示停靠/队列（valet park/queue）信息，继续作为辅助手段；可按开关决定是否隐藏。

## 兼容与安全
- 认证与权限：页面访问依赖 `operator_panel_view`；所有 AJAX 走同域会话；如新增 POST 动作（日志/会议创建），需附带 CSRF token（沿用系统 `token.php` 方式）。
- 音频权限与自动播放：`JsSipClient` 已集成权限检查与解锁逻辑；保持现有实现。
- 不暴露敏感信息：密码不回显；TURN 配置应改为服务端动态提供（当前为示例）。

## 测试方案
- 单路来电/呼出：验证接听/挂断/静音/保持/恢复。
- 人工中继：来电→接听→输入目标→桥接成功后调度会话退出；验证失败提示与回退到服务端 `uuid_transfer`。
- 自动中继：来电→输入目标→自动接听并立即转接；验证失败提示。
- 多路通话（≥3，≤6）：交替保持/恢复，切换当前线路；验证 UI 状态与计时同步准确。
- 紧急呼叫：调用 `makeCall(...,{emergency:true})` 与外部急呼来电；验证红色提示与声音提醒。
- 保持超时：设定 180s 倒计时，自动挂断并提示；验证日志记录。

## 实施清单
1. 在 `index.php` 添加线路栅格与操作区 DOM，并初始化 `DispatcherControl` 与事件绑定。
2. 在 `dispatcher-control.js` 增加 `renderLinesGrid`、`startHoldTimer`、`resumeHeldCall`、`hangupAll` 等方法；在 `onIncomingCall` 增加“忙/排队”提示。
3. 在 `index.php` 绑定拨号、接听、转接、保持/恢复、挂断、紧急呼叫、批量挂断按钮到现有/新增方法。
4. 保留并可选叠加 `resources/content.php` 的轮询作为外线/队列信息补充；确保不会与 JsSIP 状态冲突。
5. 接入 `DispatcherLogger` 在结束/超时路径上记录日志；在页面提供日志面板入口。
6. 按测试方案逐项验证，重点检查多路通话下状态同步与倒计时准确性。
