---
alwaysApply: true
priority: high
globs: "*.lua"
---

# Lua 脚本规范（FreeSWITCH）

## 基本结构

```lua
-- 记录日志
freeswitch.consoleLog("INFO", "脚本开始执行\n")

-- 检查会话状态
if session:ready() then
    -- 获取变量
    local destination_number = session:getVariable("destination_number")
    local domain_name = session:getVariable("domain_name")

    -- 执行操作
    session:answer()
    session:execute("sleep", "1000")
    session:hangup()
end
```

## 错误处理

```lua
-- 使用 pcall 包装可能失败的操作
local ok, err = pcall(function()
    session:execute("playback", "sounds/welcome.wav")
end)

if not ok then
    freeswitch.consoleLog("ERR", "操作失败: " .. err .. "\n")
end
```

## 通话控制

```lua
-- 发起通话
api:execute_string("uuid originate user/1001 &echo")

-- 挂断通话
api:execute_string("uuid_kill " .. uuid)

-- 通话转移
api:execute_string("uuid_transfer " .. uuid .. " user/1002")
```
