---
alwaysApply: true
priority: critical
---

# 安全规则（必须遵守）

违反这些规则将导致安全漏洞或数据问题。

## SQL 注入防护

```php
// ✅ 正确：使用预处理语句
$sql = "SELECT * FROM v_extensions WHERE domain_uuid = :domain_uuid";
$parameters = array('domain_uuid' => $_SESSION['domain_uuid']);
$result = $database->select($sql, $parameters);

// ❌ 禁止：直接拼接 SQL
$sql = "SELECT * FROM v_extensions WHERE domain_uuid = '" . $domain_uuid . "'";
```

## 多租户数据隔离

```php
// 所有查询必须包含 domain_uuid 过滤
$sql = "SELECT * FROM v_table WHERE domain_uuid = :domain_uuid";
$parameters['domain_uuid'] = $_SESSION['domain_uuid'];

// 跨域操作必须检查 domain_all 权限
if (!permission_exists('domain_all')) {
    // 限制在当前域
}
```

## 输入清理

```php
// 所有用户输入必须清理
$extension = check_str($_REQUEST['extension']);
$description = check_str($_REQUEST['description']);

// UUID 必须验证
if (!is_uuid($uuid)) {
    message::add('无效的 UUID', 'negative');
    return;
}
```

## 输出转义

```php
// HTML 输出必须转义
echo escape($user_input);

// JavaScript 中使用数据
echo '<script>var data = ' . json_encode($data) . ';</script>';
```

## 权限检查

```php
// 所有页面必须检查权限
if (!permission_exists('extension_view')) {
    message::add('无权限', 'negative');
    header('Location: /core/dashboard/');
    exit;
}
```

## 敏感信息

- ❌ 禁止将密码、密钥提交到代码仓库
- ❌ 禁止在日志中记录敏感信息
- ❌ 禁止在 URL 中传递敏感参数
