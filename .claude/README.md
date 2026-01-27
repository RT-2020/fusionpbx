# FusionPBX Claude Code 配置

本目录包含团队共享的 Claude Code 配置，纳入版本控制。

## 目录结构

```
.claude/
├── rules/           # 必须遵守的规则（按优先级编号）
├── commands/        # 高频斜杠命令（待添加）
├── agents/          # 专用子代理（待添加）
├── hooks/           # 关键事件点自动化（待添加）
├── skills/          # 可复用方法论（待添加）
└── settings.json    # 配置文件
```

## 规则优先级

根据项目当前处于**功能迭代阶段**，规则按以下优先级应用：

### 🔴 高优先级（必须遵循）

违反这些规则将导致代码不被接受：

| 规则文件 | 说明 |
|----------|------|
| `01-security.md` | 安全规则（SQL 注入、多租户隔离、输入清理） |
| `02-php-coding.md` | PHP 编码规范 |
| `03-javascript-coding.md` | JavaScript 编码规范（含 WebSocket 心跳） |

### 🟡 中优先级（强烈建议）

| 规则文件 | 说明 |
|----------|------|
| `04-lua-coding.md` | Lua 脚本规范（FreeSWITCH） |
| `05-voip-rules.md` | VoIP 通信规则（事件规范、资源释放） |
| `06-operator-panel.md` | 调度控制面板开发规则 |

### 🟢 低优先级（建议遵循）

| 规则文件 | 说明 |
|----------|------|
| `07-git-commit.md` | Git 提交规范 |

## 核心规则摘要

### 安全红线

- ✅ 所有数据库查询使用预处理语句
- ✅ 所有查询包含 `domain_uuid` 过滤
- ✅ 所有用户输入使用 `check_str()` 清理
- ✅ 所有输出使用 `escape()` 转义
- ✅ 所有页面检查 `permission_exists()`

### WebSocket 必须实现

```javascript
// 心跳检测（每30秒）
setInterval(() => {
  ws.send(JSON.stringify({ type: 'heartbeat' }))
}, 30000)

// 自动重连（连接断开时）
ws.onclose = () => {
  setTimeout(connectWebSocket, 5000)
}
```

### 通话资源释放（必须处理）

```javascript
function cleanupCall(callUuid) {
  switch_command('uuid_kill ' + callUuid)
  delete activeCalls[callUuid]
  updateCallPanel()
}
```

## 落地进度

- [x] Day 1-2: 整理现有规则，创建分层规则体系
- [x] Day 3: 添加 `/plan` 命令
- [x] Day 3: 配置基础 Hooks（提醒型）
- [ ] Day 4+: 可选扩展（agents, MCP）

## 个人配置

个人偏好配置请放在 `~/.claude/`，不要提交到仓库。

## 常用命令参考

### FreeSWITCH 调试

```bash
# SIP 状态
fs_cli -x "sofia status"

# 活动通道
fs_cli -x "show channels"

# 注册状态
fs_cli -x "sofia status profile internal reg <extension>"
```

### Git 工作流

```bash
# 查看状态
git status

# 提交
git add .
git commit -m "feat(scope): description"

# 推送
git push origin 5.4
```

---
**最后更新**: 2025-01-27
