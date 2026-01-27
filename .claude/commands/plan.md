---
description: 在进行复杂改动前，先制定详细的实施计划
---

# /plan - 实施规划命令

在进行复杂改动前，先制定详细的实施计划。

## 使用场景

涉及以下情况时**必须先使用 /plan**：

- 🔴 **高风险场景**：
  - 修改调度面板 (`basic_operator_panel/`) 相关代码
  - 修改紧急呼叫 (`emergency/`) 相关代码
  - 修改 WebSocket 逻辑或事件处理
  - 修改 FreeSWITCH 集成代码

- 🟡 **中风险场景**：
  - 修改 2 个或更多文件
  - 代码结构调整、重构
  - 数据库结构变更
  - SIP 拨号计划修改

- 🟢 **需求不明确**：
  - 需求描述模糊
  - 技术方案不确定
  - 需要权衡多种实现方式

## 命令输出格式

### 1. 需求复述

简要复述用户的需求，确保理解正确。

### 2. 风险评估

评估此次改动的风险等级和潜在问题：

| 风险项 | 说明 |
|--------|------|
| **影响范围** | 涉及哪些模块/功能 |
| **数据风险** | 是否涉及数据库结构变更 |
| **安全风险** | 是否涉及权限、多租户隔离 |
| **兼容性** | 是否影响现有功能 |
| **回滚难度** | 是否容易回滚 |

### 3. 实施步骤

详细的步骤清单，格式：

```markdown
## 实施步骤

1. [ ] 步骤1描述
   - 涉及文件：`file_path`
   - 具体操作：...

2. [ ] 步骤2描述
   - 涉及文件：`file_path`
   - 具体操作：...
```

### 4. 验收标准

清晰定义如何验收此次改动：

```markdown
## 验收标准

- [ ] 功能1正常工作
- [ ] 功能2正常工作
- [ ] WebSocket 心跳正常
- [ ] 通话资源正确释放
- [ ] 无控制台错误
```

### 5. 回滚方案

如果出现问题，如何回滚：

```markdown
## 回滚方案

如果出现问题：
1. 恢复文件：`git checkout -- file_path`
2. 回滚提交：`git revert <commit-hash>`
3. 重新启动服务：`systemctl restart freeswitch`
```

## 执行流程

1. **用户调用 /plan**
2. **AI 输出计划**
   - 需求复述
   - 风险评估
   - 实施步骤
   - 验收标准
   - 回滚方案
3. **等待用户确认**
4. **用户确认后开始实施**
5. **按步骤执行并标记进度**
6. **完成后通知用户验收**

## 示例

### 输入

```
/plan 为调度面板添加通话保持功能
```

### 输出

```
## 需求复述

为调度控制面板添加通话保持功能，允许调度员将当前通话保持，并能够取消保持。

## 风险评估

| 风险项 | 说明 |
|--------|------|
| **影响范围** | basic_operator_panel（调度面板） |
| **数据风险** | 无数据库变更 |
| **安全风险** | 无权限相关变更 |
| **兼容性** | 不影响现有功能，新增功能 |
| **回滚难度** | 容易，代码变更集中 |

## 实施步骤

1. [ ] 添加通话保持按钮 UI
   - 文件：`app/basic_operator_panel/basic_operator_panel_index.php`
   - 操作：在通话控制区添加"保持"和"取消保持"按钮

2. [ ] 实现 WebSocket 事件处理
   - 文件：`app/basic_operator_panel/resources/javascript/dispatcher-control.js`
   - 操作：添加 holdCall(callUuid) 和 unholdCall(callUuid) 函数

3. [ ] 添加后端 API
   - 文件：`app/basic_operator_panel/resources/dashboard/api/call_control.php`
   - 操作：添加 uuid_hold 和 uuid_unhold 接口

4. [ ] 更新通话状态显示
   - 文件：`app/basic_operator_panel/resources/javascript/dispatcher-control.js`
   - 操作：保持状态时显示特殊图标

## 验收标准

- [ ] 可以保持通话
- [ ] 可以取消保持
- [ ] 保持状态正确显示
- [ ] WebSocket 心跳正常
- [ ] 通话资源正确释放
- [ ] 无控制台错误

## 回滚方案

如果出现问题：
1. 恢复文件：`git checkout -- app/basic_operator_panel/`
2. 回滚提交：`git revert <commit-hash>`

---

等待确认：是否开始实施？输入 "确认" 开始执行，或提出修改建议。
```
