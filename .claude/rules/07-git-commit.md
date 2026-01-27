---
alwaysApply: true
priority: low
---

# Git 提交规范

## 提交信息格式

```
<type>(<scope>): <subject>

<body>

<footer>
```

## 类型定义

- `feat`: 新功能
- `fix`: 修复 bug
- `docs`: 文档更新
- `style`: 代码格式调整
- `refactor`: 重构
- `perf`: 性能优化
- `test`: 添加测试
- `chore`: 构建/辅助工具变动

## 提交示例

```
feat(调度控制): 添加紧急呼叫录音功能

- 为紧急呼叫自动标记录音
- 录音文件存储到专用目录
- 在录音列表中显示紧急标识

Closes #123
```

```
fix(分机注册): 修复多租户环境下分机注册失败

原因：domain_uuid 过滤条件缺失
影响：仅限多租户环境
```
