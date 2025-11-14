# 背景与问题
当前拨号流程在桥接分机前通过 `limit` 动作限制并发：`app/dialplans/resources/switch/conf/dialplan/890_local_extension.xml:5` 使用 `${limit_max}` 与 `${limit_destination}` 执行 `limit`。但在前置拨号计划 `010_user_exists.xml` 中，注入 `limit_max` 的动作被禁用（`enabled="false"`），且未注入 `limit_destination`。因此通道变量中这两个值为空，`limit` 无法生效，导致第二路呼入仍可进入，无忙音/486。

# 改动计划
- 文件：`app/dialplans/resources/switch/conf/dialplan/010_user_exists.xml`
  - 启用 `limit_max` 的注入：将现有 `<action application="set" data="limit_max=..." enabled="false"/>` 改为 `enabled="true"`。
  - 新增 `limit_destination` 的注入：添加 `<action application="set" data="limit_destination=${user_data ${destination_number}@${domain_name} var limit_destination}" inline="true" enabled="true"/>`，与其它变量注入保持一致。

- 文件：`app/dialplans/resources/switch/conf/dialplan/890_local_extension.xml`（保持不变）
  - 继续使用：`<action application="limit" data="hash ${domain_name} ${destination_number} ${limit_max} ${limit_destination}"/>`
  - 如需更鲁棒的替代方案（可选，不在本次默认实施）：将第5行 `limit` 的 `data` 内改为直接 `user_data` 拉取，避免依赖通道变量：
    - `hash ${domain_name} ${destination_number} ${user_data ${destination_number}@${domain_name} var limit_max} ${user_data ${destination_number}@${domain_name} var limit_destination}`

# 依赖与兼容性
- 变量来源：`limit_max`、`limit_destination` 已由目录/扩展类输出，`extension_edit.php` 默认在为空时设置 `limit_destination = !USER_BUSY`（`1103` 行），确保常见场景非空。
- 拨号顺序：`010_user_exists`（order 10）在 `890_local_extension`（order 890）之前执行，变量注入可被后续引用。

# 错误处理策略
- 若个别分机确实未配置 `limit_max` 或 `limit_destination`：
  - 仍将按 `extension_edit.php` 默认逻辑落库并输出到目录；
  - 本次仅恢复拨号计划注入，不新增额外回退逻辑，避免改变全局语义。

# 测试方案
1. 数据准备：分机 1413 在“分机编辑”中设置 `Limit Max=1`、`Limit Destination=!USER_BUSY`；保存后确保目录缓存刷新（见步骤3）。
2. 重载配置：
   - 在 FreeSWITCH 控制台执行：`reloadxml`
   - 清除目录缓存（任选其一）：
     - FusionPBX：在“状态-缓存”中清除目录缓存；或
     - FreeSWITCH：`fs_cli -x "lua scripts/app/xml_handler/resources/scripts/cache_delete.lua directory:<ext>@<domain>"`（按环境替换）；或直接 `sofia profile internal rescan` 以重新获取目录。
3. 验证用例：
   - 用 5001 呼叫 1413（接通后保持通话）。
   - 再用 1006 呼叫 1413，应立即收到 SIP 486（忙）并听到忙音；控制台可观察 `originate_disposition=USER_BUSY`。
   - 可选：在 `status` 或通话日志中确认未进入 `bridge`，`limit` 在桥接前阻断。

# 回滚方案
- 恢复 `010_user_exists.xml` 中 `limit_max` 的 `enabled` 为 `false`，并移除新增的 `limit_destination` 注入行；`reloadxml` 后恢复到当前行为。

# 实施清单
1. 在 `010_user_exists.xml` 中将 `limit_max` 的 `enabled="false"` 改为 `enabled="true"`。
2. 在 `010_user_exists.xml` 中新增 `limit_destination` 注入行，`inline="true" enabled="true"`。
3. 保持 `890_local_extension.xml` 不变（如选择替代方案，则修改第 5 行为内联 `user_data` 获取）。
4. 执行 `reloadxml` 并刷新目录缓存。
5. 用 5001→1413 接通后，1006→1413 重复呼叫，验证收到 486/忙音。