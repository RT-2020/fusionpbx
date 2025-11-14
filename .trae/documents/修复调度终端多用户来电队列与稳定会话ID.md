[MODE: PLAN 规划]

## 目标
- 修复同时多路来电仅能处理最后一个的问题。
- 解决前一个来电接听/挂断后，后续来电提示“没有来电可接听/拒绝/挂断”的状态错乱。
- 完整实现来电队列管理，支持同时接听多路来电且共享麦克风音频。
- 修复弹窗容器隐藏逻辑，确保全部卡片清理后容器正确隐藏。

## 架构与数据结构
- `JsSipClient`：维持稳定、唯一的会话 ID（`session._customId`）从会话创建开始到结束不变；`sessions` 保持 `sessionId → session` 映射；新增 `incomingSessions` 集（或 `Set<string>`）追踪处于 `incoming` 状态的会话。
- `DispatcherControl`：基于稳定的 `sessionId` 渲染卡片并执行精确操作；统一普通来电与中继来电的卡片选择器统计，确保容器隐藏判断一致。
- 音频资源：`_micStream` 共享单例，为每个已接通会话注入；每会话独立远端 `audio` 元素播放。

## 具体更改计划

### 1) jssip-client.js（app/basic_operator_panel/resources/jssip-client.js）
- 更改：在 `newRTCSession` 的 `incoming` 分支（约 218–248 行）仅在首次检测到无 `_customId` 时生成并赋值，将 `session._customId` 加入 `sessions`；同时将其加入 `incomingSessions`。
- 更改：在 `acceptIncomingCall`（约 1048–1117 行）移除对 `_customId` 的二次生成与覆盖，仅使用既有 `session._customId`；不再依赖全局 `incomingSession` 指针进行接听，新增基于 `sessionId` 的 API（见下）。
- 新增 API：
  - `acceptById(sessionId, options): Promise<void>` 使用 `sessions[sessionId]` 定位目标会话进行 `answer`，成功后从 `incomingSessions` 移除并保持 `sessions` 不变。
  - `rejectById(sessionId): Promise<void>` 使用 `sessions[sessionId]` 定位目标会话并 `terminate`，同时从 `incomingSessions` 与 `sessions` 清理。
  - `hangupById(sessionId): Promise<void>` 对已接通或振铃态会话执行 `terminate` 并清理映射。
  - `getIncomingSessions(): Array<{ sessionId: string }>` 返回当前入队的来电 ID 列表，供 UI 校验与恢复。
- 事件监听调整：在 `setupSessionListeners(session)` 的 `confirmed`、`ended`、`failed` 分支确保：
  - 使用稳定的 `_customId` 作为 `audio` 元素 ID 和所有日志标签。
  - 在 `ended/failed` 清理 `incomingSessions` 与 `sessions`；若存在回调或事件触发机制，触发 `bop-session-ended` 携带 `sessionId`（使用已有 jQuery 事件机制，如果存在）。
- 错误处理：将“没有来电可接听/拒绝/挂断”“没有活动会话”的判定基于 `sessions`/`incomingSessions` 明确化，返回统一的错误对象并包含 `sessionId`，便于 UI 精确提示。
- 音频共享验证：保留并复用 `_micStream`；仅在 `sessions` 空时释放；确保不会在多路通话未结束时提前释放。

### 2) dispatcher-control.js（app/basic_operator_panel/resources/dispatcher-control.js）
- 入队渲染：`queueIncomingCall(sessionId)` 使用稳定的 `sessionId` 渲染卡片 `id="incoming-{sessionId}"`；统一普通与中继来电卡片类名为 `.incoming-call`（或在隐藏逻辑统计两类）。
- 操作方法替换：
  - `acceptIncomingById(sessionId)` 改为调用 `this.sipClient.acceptById(sessionId, { audio: true, video: false })`，不再改写全局 `incomingSession`。
  - `rejectIncomingById(sessionId)` 改为调用 `this.sipClient.rejectById(sessionId)`。
  - 挂断路径如存在卡片级挂断，改为调用 `this.sipClient.hangupById(sessionId)`。
- 容器显示/隐藏：
  - 显示：入队时 `#dispatcher-alerts` 执行 `append` 后 `show()`。
  - 隐藏：卡片移除后统计 `.incoming-call` 与（如保留）`.trunk-incoming-call` 的总数为 0 时隐藏容器；避免仅统计一种类名导致容器残留。
- 容器存在性校验：初始化时检测 `#dispatcher-alerts` 是否存在，若不存在执行一次性创建或记录错误；与模板层协同（见 3)）。
- 错误与提示：所有操作的错误提示携带 `sessionId`，并区分“不可接听/拒绝的状态”（例如已结束、已接通）与“未找到会话”（映射缺失），避免笼统提示。

### 3) content.php（app/basic_operator_panel/resources/content.php）
- 模板容器：若页面未静态定义 `#dispatcher-alerts`，在适当位置添加 `<div id="dispatcher-alerts" style="display:none"></div>`，确保 JS 对应选择器有效。
- 不改动其他不相关 UI；仅确保容器存在并初始隐藏状态。

### 4) 兼容与边界策略
- 并发接听：允许多路会话调用 `answer`，每路远端音频独立播放；不进行会话间音频混合，依赖浏览器混音；
- 竞态处理：
  - 所有 UI 操作以 `sessionId` 为准，禁止改写全局 `incomingSession` 指针；
  - 操作前校验会话当前状态（incoming/early/confirmed/ended），不合法状态直接提示并移除残卡；
  - 在 `ended/failed` 事件触发时，若对应卡片仍存在，执行清理并二次检查容器隐藏条件。
- 兜底挂断：保留既有后端 `force_hangup` 接口用于异常通道清理，不做修改；仅在前端挂断失败时调用。

### 5) 依赖与约束
- 不引入新第三方库；继续使用现有 jQuery 与 JsSIP。
- 保持既有日志与事件风格；新增日志必须包含 `sessionId`、状态与操作动作。

### 6) 测试方法
- 多用户并发：
  - 同时来到 3–5 路来电，验证每路生成独立卡片且均可独立接听/拒绝。
  - 依次接听前两路、保留后两路为振铃，验证操作提示与状态无误。
- 容器隐藏：
  - 混用普通与中继来电卡片，逐个移除后验证 `#dispatcher-alerts` 在卡片清空时隐藏。
- 音频共享：
  - 同时接通 2–3 路通话，验证声音正常、`_micStream` 未被提前释放；全部挂断后资源回收。
- 异常路径：
  - 对已结束或已接通的卡片点击“接听/拒绝/挂断”，应精确提示并自动清理残卡。
  - 人为移除 `sessions` 映射（模拟异常），操作提示应为“未找到会话”并清理卡片。
- 兜底挂断：
  - 模拟前端挂断失败后调用后端 `force_hangup`，确认残留通道被处理，前端 UI 能正确清理。

### 7) 回归验证
- 验证既有呼出流程、转接、保持/取消保持、静音/取消静音等未受影响。
- 验证忙/排队横幅文本只在真实排队条件下显示，不在单路来电时误报。

## 变更理由
- 稳定的会话 ID 避免 UI 与内部状态错位，消除“操作目标丢失”导致的错误提示。
- 基于会话 ID 的精确操作消除对全局指针的竞态覆盖，提升多路并发的可靠性。
- 统一容器隐藏逻辑避免混合卡片类型时的残留。
- 保持音频资源共享与独立播放路径，满足并发通话的声音需求。

## 实施清单
1. 在 `jssip-client.js` 的 `newRTCSession`（incoming 分支）确保 `_customId` 仅在首次生成并写入 `sessions` 与 `incomingSessions`。
2. 在 `jssip-client.js` 的 `acceptIncomingCall` 移除 `_customId` 二次生成，新增 `acceptById(sessionId, options)` 并以 `sessions[sessionId]` 执行 `answer` 与清理 `incomingSessions`。
3. 在 `jssip-client.js` 新增 `rejectById(sessionId)` 与 `hangupById(sessionId)`，并保证在事件 `ended/failed` 中清理 `sessions`/`incomingSessions`。
4. 在 `jssip-client.js` 的 `setupSessionListeners` 使用稳定的 `session._customId` 作为音频元素与日志标识；在 `ended/failed` 时触发会话清理。
5. 在 `dispatcher-control.js` 的队列渲染统一卡片类名或在隐藏判断统计两类卡片；操作方法改为调用 `sipClient.acceptById/rejectById/hangupById`。
6. 在 `dispatcher-control.js` 卡片移除后重新统计并隐藏 `#dispatcher-alerts`；初始化时校验容器存在性。
7. 在 `content.php` 若未存在 `#dispatcher-alerts`，添加静态容器并默认隐藏。
8. 为所有操作添加包含 `sessionId` 的日志与错误提示，区分状态异常与会话缺失。
9. 执行并发、容器隐藏、音频共享、异常与兜底挂断测试用例，修正发现的问题。
10. 回归验证非相关功能的稳定性并准备提交。