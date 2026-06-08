---
alwaysApply: true
priority: medium
---

# 调度控制面板开发规则

## 核心约束

调度控制面板是本项目的核心自定义功能，开发时必须遵守：

1. **WebSocket 必须有心跳检测**
   - 每 30 秒发送一次心跳
   - 60 秒未收到响应则重连
   - 连接断开时自动重连

2. **通话操作必须正确释放资源**
   - 挂断后清理本地状态
   - 会议室使用后必须销毁
   - WebSocket 连接断开时清理所有通话状态

3. **紧急呼叫必须特殊处理**
   - 紧急呼叫录音必须自动标记
   - 调度面板必须显示紧急呼叫报警
   - 紧急呼叫必须有更高的优先级

4. **批量呼叫必须排除调度员自身**
   - 组呼/全呼时自动排除调度员分机
   - 避免自呼叫导致的死锁

## 关键文件

- `app/basic_operator_panel/basic_operator_panel_index.php` - 主界面
- `app/basic_operator_panel/resources/dashboard/socket_server.php` - WebSocket 服务器

## 事件处理

```javascript
// 通话事件处理
function handleCallEvent(data) {
  switch (data.event_subtype) {
    case 'CHANNEL_CREATE':
      addCallToPanel(data)
      break
    case 'CHANNEL_DESTROY':
      removeCallFromPanel(data)
      cleanupCallResources(data.call_uuid)
      break
    case 'CHANNEL_ANSWER':
      updateCallStatus(data)
      break
  }
}
```

## 会议管理

```javascript
// 创建会议室
function createConferenceRoom(name) {
  // 1. 创建会议
  // 2. 记录会议信息
  // 3. 设置超时销毁
}

// 销毁会议室
function destroyConferenceRoom(conferenceName) {
  // 1. 移除所有参与者
  // 2. 销毁会议
  // 3. 清理状态
}
```
