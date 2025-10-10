# 基站管理模块 (Base Stations)

## 模块简介

基站管理模块为 FusionPBX 提供了完整的基站设备管理功能，支持记录基站的网络信息、部署位置、访问凭据，并实时监控连通性状态。

## 主要功能

### 📋 基站信息管理

- **基本信息**：名称、IP 地址、MAC 地址
- **部署信息**：安装位置、描述备注
- **访问凭据**：用户名、密码（加密存储）
- **状态管理**：启用/禁用控制

### 🔍 列表与搜索

- 分页浏览所有基站
- 多字段模糊搜索（名称/IP/MAC/位置/用户名/描述）
- 自定义排序（按任意列升序/降序）
- 批量操作（启用/禁用、删除）

### 🟢 连通性监控

- 实时 TCP 端口探测
- 可视化状态指示（绿点=在线，红点=离线）
- 可配置探测端口和超时时间
- 状态缓存机制避免频繁探测

### 🔐 安全特性

- 密码 AES-256-CBC 加密存储
- 自动生成加密密钥
- 权限分级控制
- 明文密码查看需特殊权限

## 快速开始

### 1. 安装模块

```bash
# 文件已生成在 app/base_stations/ 目录
# 访问 FusionPBX Web 界面完成安装
```

### 2. 初始化数据库

1. 登录 FusionPBX 管理界面
2. 导航到 `Advanced` > `Upgrade`
3. 依次执行：
   - **Schema** → Execute（创建数据表）
   - **Menu** → Execute（刷新菜单）
   - **Permission** → Execute（刷新权限）
   - **Defaults** → Execute（加载默认设置）

### 3. 使用模块

1. 退出并重新登录
2. 菜单 `Advanced` > `Base Stations`
3. 点击"添加"按钮创建第一个基站

## 文件结构

```
app/base_stations/
├── app_config.php              # 模块配置（数据库schema、权限、默认设置）
├── app_menu.php                # 菜单配置
├── app_languages.php           # 多语言文本
├── base_stations.php           # 列表页
├── base_station_edit.php       # 编辑页
├── resources/
│   └── classes/
│       └── base_station.php    # 资源类（批量操作、工具方法）
├── DATABASE_SETUP.md           # 详细安装与配置指南
└── README.md                   # 本文件
```

## 配置说明

### 默认设置（Advanced > Default Settings）

| 设置项                          | 默认值 | 说明                  |
| ------------------------------- | ------ | --------------------- |
| `base_station:probe_port`       | 22     | 连通性探测端口（SSH） |
| `base_station:probe_timeout`    | 800    | 探测超时（毫秒）      |
| `base_station:secret_key`       | (自动) | 密码加密密钥          |
| `base_station:status_cache_ttl` | 60     | 状态缓存时间（秒）    |

### 权限配置（Advanced > Group Manager）

| 权限                         | 说明         | 默认组            |
| ---------------------------- | ------------ | ----------------- |
| `base_station_view`          | 查看列表     | superadmin, admin |
| `base_station_add`           | 添加基站     | superadmin, admin |
| `base_station_edit`          | 编辑基站     | superadmin, admin |
| `base_station_delete`        | 删除基站     | superadmin, admin |
| `base_station_all`           | 跨域查看     | superadmin        |
| `base_station_password_view` | 查看明文密码 | superadmin        |

## 使用示例

### 添加基站

1. 点击"添加"按钮
2. 填写必填字段：
   - **基站名称**：例如 "办公楼 A 栋基站"
   - **IP 地址**：例如 "192.168.1.100"
3. 填写可选字段：
   - **MAC 地址**：例如 "00:11:22:33:44:55"
   - **部署位置**：例如 "办公楼 A 栋 3 楼"
   - **用户名**：例如 "admin"
   - **密码**：例如 "SecurePass123"
4. 点击"保存"

### 查看状态

- 列表页"状态"列显示实时连通性
- 🟢 绿点 = 在线（TCP 端口可达）
- 🔴 红点 = 离线（TCP 端口不可达）

### 批量操作

1. 勾选多个基站
2. 点击"切换"按钮批量启用/禁用
3. 或点击"删除"按钮批量删除

## 技术细节

### 数据库

- **类型**：PostgreSQL（默认）
- **表名**：`v_base_stations`
- **特性**：
  - `inet` 类型存储 IP（自动验证格式）
  - `macaddr` 类型存储 MAC（自动格式化）
  - 外键关联 `v_domains`（租户隔离）

### 密码加密

- **算法**：AES-256-CBC
- **IV**：每次加密生成随机 IV
- **密钥**：存储在 `v_default_settings` 表
- **函数**：使用 FusionPBX 内置 `encrypt()` / `decrypt()`

### 连通性探测

- **方法**：TCP Socket 连接（`fsockopen`）
- **端口**：可配置（默认 22）
- **超时**：可配置（默认 800ms）
- **缓存**：避免频繁探测影响性能

## 常见问题

### Q: 为什么所有基站显示离线？

**A**: 检查以下几点：

1. 服务器能否访问基站网络
2. 探测端口是否正确（默认 22）
3. 防火墙是否阻止连接
4. 增加超时时间（Default Settings）

### Q: 密码无法保存？

**A**: 确保：

1. PHP 已安装 OpenSSL 扩展
2. `base_station:secret_key` 已生成（执行 Defaults 升级）
3. 检查 FusionPBX 日志

### Q: 如何查看明文密码？

**A**: 需要：

1. 拥有 `base_station_password_view` 权限
2. 使用 `base_station::get_password($uuid)` 方法
3. 或在编辑页添加"显示密码"按钮（需自行实现）

### Q: 如何更改探测端口？

**A**:

1. 访问 `Advanced` > `Default Settings`
2. 找到 `base_station:probe_port`
3. 修改值（22=SSH, 80=HTTP, 443=HTTPS）

## 性能优化

### 大量基站场景（1000+）

1. 增加 `status_cache_ttl` 到 300 秒
2. 考虑使用后台定时任务探测
3. 在列表页禁用实时探测，改为手动刷新

### 数据库优化

```sql
-- 已自动创建索引
CREATE INDEX v_base_stations_domain_idx ON v_base_stations(domain_uuid);
CREATE INDEX v_base_stations_ip_idx ON v_base_stations(ip_address);

-- 可选：为常搜索字段添加索引
CREATE INDEX v_base_stations_name_idx ON v_base_stations(station_name);
```

## 扩展开发

### 添加自定义字段

1. 修改 `app_config.php` 的 schema 定义
2. 执行数据库升级（Advanced > Upgrade > Schema）
3. 在编辑页添加对应表单字段

### 集成外部监控

```php
// 在 resources/classes/base_station.php 添加方法
public static function check_with_snmp($ip_address) {
    // 使用 SNMP 协议探测
    // ...
}
```

### 添加批量导入

参考 `app/devices/device_imports.php` 实现 CSV 导入功能

## 许可证

Mozilla Public License 1.1 (MPL 1.1)

## 支持

- **文档**：查看 `DATABASE_SETUP.md` 获取详细安装指南
- **社区**：https://www.fusionpbx.com
- **问题反馈**：通过 FusionPBX 官方渠道

---

**版本**：1.0  
**作者**：FusionPBX Community  
**更新日期**：2025-10-09
