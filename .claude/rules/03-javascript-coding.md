---
alwaysApply: true
priority: critical
globs: "*.js"
---

# JavaScript 编码规范（必须遵守）

## 代码组织

```javascript
$(document).ready(function () {
  initializeWebSocket()
  bindEvents()
  loadData()
})

function initializeWebSocket() {
  const protocol = window.location.protocol === 'https:' ? 'wss:' : 'ws:'
  const wsUrl = protocol + '//' + window.location.host + '/ws'

  window.ws = new WebSocket(wsUrl)

  ws.onopen = function () {
    startHeartbeat()
  }

  ws.onmessage = function (event) {
    const data = JSON.parse(event.data)
    handleMessage(data)
  }

  ws.onerror = function (error) {
    console.error('WebSocket 错误:', error)
  }

  ws.onclose = function () {
    setTimeout(connectWebSocket, 5000) // 5秒后重连
  }
}
```

## WebSocket 心跳（必须实现）

```javascript
// WebSocket 必须实现心跳检测
function startHeartbeat() {
  setInterval(function () {
    if (window.ws && window.ws.readyState === WebSocket.OPEN) {
      window.ws.send(JSON.stringify({ type: 'heartbeat' }))
    }
  }, 30000) // 每30秒发送一次心跳

  // 60秒未收到响应则重连
  setTimeout(function () {
    if (lastHeartbeat < Date.now() - 60000) {
      window.ws.close()
    }
  }, 60000)
}
```

## 命名规范

```javascript
// 变量使用驼峰命名法
const userId = '123'
const domainName = 'example.com'

// 函数使用驼峰命名法
function getUserInfo() {
    // ...
}

// 常量使用大写下划线
const MAX_CONNECTIONS = 100
```

## AJAX 请求

```javascript
$.ajax({
  url: '/api/endpoint',
  type: 'POST',
  data: { key: 'value' },
  dataType: 'json',
  success: function (response) {
    // 处理响应
  },
  error: function (xhr, status, error) {
    console.error('请求失败:', error)
    showMessage('请求失败', 'error')
  }
})
```
