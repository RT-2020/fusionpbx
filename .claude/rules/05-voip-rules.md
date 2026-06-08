---
alwaysApply: true
priority: high
---

# VoIP 通信规则

## WebSocket 事件规范

```javascript
// 通话事件
{
  type: 'call_event',
  event_subtype: 'CHANNEL_CREATE' | 'CHANNEL_DESTROY' | 'CHANNEL_ANSWER',
  call_uuid: 'uuid',
  caller_id_number: '1001',
  destination_number: '1002',
  timestamp: '2025-01-27T10:00:00Z'
}

// 注册事件
{
  type: 'registration_event',
  event_subtype: 'REGISTER' | 'UNREGISTER',
  extension: '1001',
  domain_uuid: 'uuid'
}

// 心跳事件
{
  type: 'heartbeat',
  timestamp: '2025-01-27T10:00:00Z'
}
```

## 通话资源释放（必须遵守）

```javascript
// 所有通话操作必须正确处理资源释放
function cleanupCall(callUuid) {
  // 1. 挂断通话
  switch_command('uuid_kill ' + callUuid)

  // 2. 清理本地状态
  delete activeCalls[callUuid]

  // 3. 更新 UI
  updateCallPanel()

  // 4. 记录日志
  logCallEnd(callUuid)
}
```

## FreeSWITCH 命令

```php
// 发起通话
$cmd = "originate user/1001 &echo";
$response = switch_command($cmd);

// 挂断通话
$cmd = "uuid_kill " . $call_uuid;
switch_command($cmd);

// 通话转移
$cmd = "uuid_transfer " . $call_uuid . " user/1002";
switch_command($cmd);
```

## SIP 拨号计划

```xml
<extension name="example">
    <condition field="destination_number" expression="^(\d{4})$">
        <action application="set" data="dialed_extension=$1"/>
        <action application="bridge" data="user/${dialed_extension}@${domain_name}"/>
    </condition>
</extension>
```
