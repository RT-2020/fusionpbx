---
description: 定义项目中的风险模式和自动识别规则
---

# 风险规则配置

定义哪些文件/目录/操作属于高风险，/plan 命令会自动应用这些规则。

## 文件路径风险规则

### 🔴 严重风险 (critical)

任何涉及这些目录或文件的改动都需要特别谨慎：

```yaml
高风险目录:
  - app/basic_operator_panel/     # 调度控制面板（核心功能）
  - app/emergency/                # 紧急呼叫系统
  - resources/templates/          # 设备配置模板（影响大量设备）

高风险文件模式:
  - .*socket.*\.php              # Socket 通信相关
  - .*websocket.*\.(js|php)      # WebSocket 相关
  - .*conference.*\.php          # 会议系统
  - .*dialplan.*\.php            # 拨号计划
  - .*switch.*\.php              # FreeSWITCH 集成
```

### 🟡 中等风险 (moderate)

```yaml
中等风险目录:
  - app/ring_groups/             # 振铃组（涉及通话路由）
  - app/call_centers/             # 呼叫中心
  - app/conferences/              # 会议系统
  - app/call_recordings/          # 通话录制
  - app/ivr_menus/                # IVR 菜单

中等风险文件模式:
  - .*domain.*\.php              # 域配置（多租户）
  - .*extension.*\.php           # 分机管理
  - .*gateway.*\.php             # 网关配置
  - .*permission.*\.php          # 权限系统
```

### 🟢 低风险 (low)

```yaml
低风险目录:
  - app/voicemails/               # 语音邮件
  - app/contacts/                 # 联系人
  - app/recordings/               # 录音管理
  - themes/                       # 主题模板
  - docs/                         # 文档
```

## 操作类型风险规则

### 🔴 严重风险操作

```yaml
操作:
  - 修改数据库表结构
  - 修改权限系统
  - 修改多租户隔离逻辑
  - 修改 Session/认证逻辑
  - 修改 WebSocket 协议
  - 批量数据导入/导出
```

### 🟡 中等风险操作

```yaml
操作:
  - 修改 UI 布局
  - 添加新的配置项
  - 修改拨号计划
  - 修改通话路由
  - 添加新的 API 接口
```

## 自动风险计算

### 风险等级判定逻辑

```javascript
function calculateRisk(filesChanged, operations) {
  let riskScore = 0;

  // 文件路径风险
  filesChanged.forEach(file => {
    if (匹配高风险目录(file)) {
      riskScore += 3;
    } else if (匹配中等风险目录(file)) {
      riskScore += 1;
    }
  });

  // 操作类型风险
  operations.forEach(op => {
    if (匹配高风险操作(op)) {
      riskScore += 2;
    }
  });

  // 文件数量
  if (filesChanged.length >= 5) {
    riskScore += 1;
  }

  // 判定等级
  if (riskScore >= 5) return 'critical';
  if (riskScore >= 2) return 'moderate';
  return 'low';
}
```

## 特殊场景触发

以下场景**强制要求**使用 /plan：

```yaml
强制触发条件:
  - 修改文件数量 >= 2
  - 涉及调度面板相关
  - 涉及 WebSocket 相关
  - 涉及 FreeSWITCH 相关
  - 涉及数据库结构
  - 涉及权限系统
```

## 风险检查清单

根据风险等级，自动应用相应的检查清单：

### Critical 风险

```markdown
- [ ] 数据备份已完成
- [ ] 测试环境已验证
- [ ] 回滚方案已准备
- [ ] WebSocket 心跳机制已实现
- [ ] 资源释放机制已实现
- [ ] 多租户隔离已验证
- [ ] 权限检查已通过
```

### Moderate 风险

```markdown
- [ ] 影响范围已评估
- [ ] 现有功能测试通过
- [ ] 兼容性已验证
```

### Low 风险

```markdown
- [ ] 代码风格符合规范
- [ ] 功能测试通过
```
