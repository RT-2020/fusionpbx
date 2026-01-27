---
alwaysApply: true
priority: critical
globs: "*.php"
---

# PHP 编码规范（必须遵守）

## 文件结构

```php
<?php
/*
 * FusionPBX
 *
 * @author        Your Name
 * @copyright     Copyright (c) 2025
 * @license       Mozilla Public License 1.1
 */

require_once "resources/require.php";

// 权限检查
if (!permission_exists('module_view')) {
    // ...
}

// 处理表单
if (count($_POST) > 0) {
    // ...
}

// 查询数据
$sql = "SELECT * FROM v_table WHERE domain_uuid = :domain_uuid";
// ...

require_once "resources/header.php";
// 页面内容
require_once "resources/footer.php";
?>
```

## 代码风格

```php
// 使用 Tab 缩进（不是空格）
// 变量使用下划线命名法
$user_name = "value";
$domain_uuid = "uuid";

// 函数使用下划线命名法
function get_user_info($user_uuid) {
    // ...
}

// 类名使用下划线命名法
class database_connection {
    // ...
}
```

## 数据库操作

```php
// 使用全局 $database 对象
$result = $database->select($sql, $parameters, 'all');
$database->execute($sql, $parameters);
$count = $database->count($sql, $parameters);
```

## 消息显示

```php
// 错误消息
message::add('操作失败', 'negative');

// 警告消息
message::add('请注意', 'alert');

// 成功消息
message::add('保存成功', 'positive');
```

## 表结构规范

```sql
-- 所有表必须包含
CREATE TABLE v_table_name (
    table_name_uuid VARCHAR(36) PRIMARY KEY,
    domain_uuid VARCHAR(36) NOT NULL,
    insert_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    insert_user VARCHAR(36),
    update_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    update_user VARCHAR(36),
    FOREIGN KEY (domain_uuid) REFERENCES v_domains(domain_uuid)
);
```
