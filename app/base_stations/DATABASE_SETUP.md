# 基站管理模块 - 数据库设置指南

## 概述

本模块已完成文件生成，数据库表结构在 `app_config.php` 中定义。FusionPBX 会在访问"高级 > 升级"页面时自动创建表结构。

## 安装步骤

### 方法一：通过 FusionPBX Web 界面

1. **登录 FusionPBX 管理界面**

   - 使用超级管理员账号登录

2. **访问升级页面**

   - 导航到：`Advanced` -> `Upgrade`
   - 或直接访问：`/core/upgrade/upgrade.php`

3. **执行数据库升级**

   - 点击 "数据库升级" 选项卡
   - 系统会自动检测新模块的数据库 schema
   - 点击 "Execute" 执行数据库升级
   - 等待完成（会创建 `v_base_stations` 表）

4. **刷新菜单和权限**

   - 点击 "Menu" 选项卡 -> Execute（刷新菜单）
   - 点击 "Permission" 选项卡 -> Execute（刷新权限）
   - 点击 "Defaults" 选项卡 -> Execute（刷新默认设置）

5. **验证安装**
   - 退出并重新登录
   - 菜单 `Advanced` 下应该出现 `Base Stations`（基站管理）
   - 点击进入即可使用

### 方法二：手动执行 SQL（仅供参考）

如果自动升级失败，可以手动执行以下 SQL（PostgreSQL）：

```sql
-- 创建基站表
CREATE TABLE IF NOT EXISTS v_base_stations (
    base_station_uuid uuid PRIMARY KEY,
    domain_uuid uuid NOT NULL REFERENCES v_domains(domain_uuid) ON DELETE CASCADE,
    station_name text NOT NULL,
    ip_address inet NOT NULL,
    mac_address macaddr,
    deploy_location text,
    username text,
    password_encrypted text,
    enabled text DEFAULT 'true',
    description text,
    insert_date timestamptz,
    insert_user uuid,
    update_date timestamptz,
    update_user uuid
);

-- 创建索引
CREATE INDEX IF NOT EXISTS v_base_stations_domain_idx ON v_base_stations(domain_uuid);
CREATE INDEX IF NOT EXISTS v_base_stations_ip_idx ON v_base_stations(ip_address);

-- 注意：权限、菜单和默认设置仍需通过 Web 界面刷新
```

## 数据库表结构说明

### v_base_stations 表

| 字段名             | 类型        | 说明                                                |
| ------------------ | ----------- | --------------------------------------------------- |
| base_station_uuid  | uuid        | 主键                                                |
| domain_uuid        | uuid        | 租户/域 UUID（外键）                                |
| station_name       | text        | 基站名称（必填）                                    |
| ip_address         | inet        | IP 地址（必填，PostgreSQL inet 类型支持验证）       |
| mac_address        | macaddr     | MAC 地址（可选，PostgreSQL macaddr 类型自动格式化） |
| deploy_location    | text        | 部署/安装位置                                       |
| username           | text        | 登录用户名                                          |
| password_encrypted | text        | 加密密码（使用系统密钥 AES-256-CBC 加密）           |
| enabled            | text        | 启用状态（'true'/'false'）                          |
| description        | text        | 描述备注                                            |
| insert_date        | timestamptz | 创建时间                                            |
| insert_user        | uuid        | 创建用户                                            |
| update_date        | timestamptz | 更新时间                                            |
| update_user        | uuid        | 更新用户                                            |

## 默认设置项

模块会自动创建以下默认设置（通过 `Advanced > Upgrade > Defaults`）：

| 分类         | 子分类           | 值         | 说明                                         |
| ------------ | ---------------- | ---------- | -------------------------------------------- |
| base_station | probe_port       | 22         | 连通性探测端口（22=SSH, 80=HTTP, 443=HTTPS） |
| base_station | probe_timeout    | 800        | 探测超时时间（毫秒）                         |
| base_station | secret_key       | (自动生成) | 密码加密密钥（base64 编码的 32 字节随机数）  |
| base_station | status_cache_ttl | 60         | 状态缓存时间（秒）                           |

## 权限说明

| 权限名称                   | 默认分配组        | 说明                     |
| -------------------------- | ----------------- | ------------------------ |
| base_station_view          | superadmin, admin | 查看基站列表             |
| base_station_add           | superadmin, admin | 添加基站                 |
| base_station_edit          | superadmin, admin | 编辑基站                 |
| base_station_delete        | superadmin, admin | 删除基站                 |
| base_station_all           | superadmin        | 跨域查看所有基站         |
| base_station_password_view | superadmin        | 查看明文密码（高危权限） |

## 菜单位置

- **路径**：Advanced > Base Stations（高级 > 基站管理）
- **权限**：需要 `base_station_view` 权限

## 功能特性

### 1. 列表页 (base_stations.php)

- **分页搜索**：支持按名称、IP、MAC、位置、用户名、描述搜索
- **状态显示**：实时探测基站连通性（绿点=在线，红点=离线）
- **批量操作**：启用/禁用、批量删除
- **排序**：支持按各列排序

### 2. 编辑页 (base_station_edit.php)

- **字段验证**：IP 地址格式、MAC 地址格式、必填字段
- **密码加密**：使用 AES-256-CBC 加密，密钥从默认设置读取
- **重复检测**：防止同一 IP 重复添加
- **密码显示**：编辑时仅显示"已设置密码"，不回显明文

### 3. 连通性探测

- **方法**：TCP 端口探测（fsockopen）
- **配置**：端口和超时可在默认设置中调整
- **性能**：支持状态缓存（TTL 可配置）

### 4. 密码安全

- **加密算法**：AES-256-CBC + 随机 IV
- **密钥管理**：密钥存储在默认设置中，首次安装自动生成
- **权限控制**：查看明文需要 `base_station_password_view` 权限

## 故障排查

### 问题 1：菜单中没有"基站管理"

**解决**：

1. 检查是否已执行升级（Advanced > Upgrade > Menu > Execute）
2. 退出并重新登录
3. 检查当前用户是否有 `base_station_view` 权限（Advanced > Group Manager）

### 问题 2：数据库表未创建

**解决**：

1. 访问 Advanced > Upgrade > Schema
2. 点击 Execute 执行数据库升级
3. 检查 PostgreSQL 日志确认是否有错误

### 问题 3：状态全部显示离线

**解决**：

1. 检查默认设置中的 `probe_port` 是否正确（Advanced > Default Settings）
2. 检查服务器是否能访问基站网络
3. 调整 `probe_timeout` 增加超时时间
4. 检查防火墙规则

### 问题 4：密码无法保存或解密失败

**解决**：

1. 检查 `base_station:secret_key` 默认设置是否存在
2. 确保 PHP 安装了 OpenSSL 扩展：`php -m | grep openssl`
3. 检查 FusionPBX 日志：`/var/log/fusionpbx/fusionpbx.log`

## 卸载（如需）

1. 删除数据表：

```sql
DROP TABLE IF EXISTS v_base_stations CASCADE;
```

2. 删除目录：

```bash
rm -rf /var/www/fusionpbx/app/base_stations
```

3. 执行升级刷新菜单和权限：Advanced > Upgrade > Menu/Permission

---

**文档版本**：1.0  
**最后更新**：2025-10-09  
**兼容版本**：FusionPBX 5.3+, PostgreSQL 12+
