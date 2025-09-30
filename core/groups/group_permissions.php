<?php
/*
	FusionPBX
	Version: MPL 1.1

	The contents of this file are subject to the Mozilla Public License Version
	1.1 (the "License"); you may not use this file except in compliance with
	the License. You may obtain a copy of the License at
	http://www.mozilla.org/MPL/

	Software distributed under the License is distributed on an "AS IS" basis,
	WITHOUT WARRANTY OF ANY KIND, either express or implied. See the License
	for the specific language governing rights and limitations under the
	License.

	The Original Code is FusionPBX

	The Initial Developer of the Original Code is
	Mark J Crane <markjcrane@fusionpbx.com>
	Portions created by the Initial Developer are Copyright (C) 2018-2024
	the Initial Developer. All Rights Reserved.

	Contributor(s):
	Mark J Crane <markjcrane@fusionpbx.com>
*/

//includes files
	require_once dirname(__DIR__, 2) . "/resources/require.php";
	require_once "resources/check_auth.php";

//function to generate friendly permission description
function get_permission_description($permission_name) {
	// Module name mappings
	$module_names = [
		'access_control' => '访问控制',
		'account_setting' => '账户设置',
		'bridge' => '桥接',
		'call_block' => '呼叫阻止',
		'call_broadcast' => '呼叫广播',
		'call_center' => '呼叫中心',
		'call_flow' => '呼叫流程',
		'call_forward' => '呼叫前转',
		'call_recording' => '通话录音',
		'conference' => '会议',
		'contact' => '联系人',
		'database_transaction' => '数据库事务',
		'device' => '设备',
		'dialplan' => '拨号计划',
		'destination' => '目的地',
		'domain' => '域',
		'domain_setting' => '域设置',
		'email_queue' => '邮件队列',
		'email_template' => '邮件模板',
		'emergency' => '紧急',
		'event_guard' => '事件守护',
		'extension' => '分机',
		'extension_setting' => '分机设置',
		'fax' => '传真',
		'fax_queue' => '传真队列',
		'fifo' => '先进先出',
		'follow_me' => '跟随我',
		'gateway' => '网关',
		'group' => '组',
		'ivr_menu' => 'IVR菜单',
		'log_viewer' => '日志查看器',
		'menu' => '菜单',
		'module' => '模块',
		'music_on_hold' => '保持音乐',
		'number_translation' => '号码转换',
		'phrase' => '短语',
		'pin_number' => 'PIN号码',
		'recording' => '录音',
		'ring_group' => '响铃组',
		'sip_profile' => 'SIP配置',
		'sip_status' => 'SIP状态',
		'sofia_global_setting' => 'Sofia全局设置',
		'stream' => '流',
		'switch' => '交换机',
		'system' => '系统',
		'time_condition' => '时间条件',
		'tone' => '音调',
		'user' => '用户',
		'user_log' => '用户日志',
		'user_setting' => '用户设置',
		'var' => '变量',
		'voicemail' => '语音邮件',
		'xml_cdr' => 'XML CDR'
	];

	// Action name mappings
	$action_names = [
		'view' => '查看',
		'add' => '添加',
		'edit' => '编辑',
		'delete' => '删除',
		'import' => '导入',
		'export' => '导出',
		'backup' => '备份',
		'restore' => '恢复',
		'reload' => '重载',
		'download' => '下载',
		'upload' => '上传',
		'copy' => '复制',
		'move' => '移动',
		'save' => '保存',
		'send' => '发送',
		'receive' => '接收',
		'execute' => '执行',
		'manage' => '管理',
		'configure' => '配置',
		'monitor' => '监控',
		'control' => '控制',
		'list' => '列表',
		'show' => '显示',
		'hide' => '隐藏',
		'enable' => '启用',
		'disable' => '禁用',
		'start' => '启动',
		'stop' => '停止',
		'restart' => '重启',
		'pause' => '暂停',
		'resume' => '恢复',
		'cancel' => '取消',
		'reset' => '重置',
		'clear' => '清除',
		'clean' => '清理',
		'search' => '搜索',
		'filter' => '过滤',
		'sort' => '排序',
		'group' => '分组',
		'assign' => '分配',
		'remove' => '移除',
		'select' => '选择',
		'deselect' => '取消选择',
		'check' => '检查',
		'validate' => '验证',
		'verify' => '验证',
		'test' => '测试',
		'debug' => '调试',
		'log' => '日志',
		'trace' => '跟踪'
	];

	// Parse permission name
	$parts = explode('_', $permission_name);
	$action = array_pop($parts); // Last part is usually the action
	$module = implode('_', $parts);

	// Get module name
	$module_name = $module_names[$module] ?? $module;

	// Get action name
	$action_name = $action_names[$action] ?? $action;

	// Special cases
	if ($module === 'group' && $action === 'permission') {
		return '组权限管理';
	}
	if ($module === 'user' && $action === 'setting') {
		return '用户设置管理';
	}
	if ($module === 'domain' && $action === 'setting') {
		return '域设置管理';
	}
	if ($module === 'call' && in_array($action, ['center', 'recording', 'forward', 'flow', 'broadcast', 'block'])) {
		return $module_names[$module . '_' . $action] ?? $module_name . $action_name;
	}

	return $module_name . $action_name;
}

//check permissions
	if (permission_exists('group_permission_view')) {
		//access granted
	}
	else {
		echo "access denied";
		exit;
	}

//get the group_uuid
	if (!empty($_REQUEST["group_uuid"])) {
		$group_uuid = $_GET['group_uuid'];
	}

//connect to the database
	$database = new database;

//get the group_name
	if (isset($group_uuid) && is_uuid($group_uuid)) {
		$sql = "select group_name from v_groups ";
		$sql .= "where group_uuid = :group_uuid ";
		$parameters['group_uuid'] = $group_uuid;
		$group_name = $database->select($sql, $parameters, 'column');
		unset($sql, $parameters);
	}

//add multi-lingual support
	$language = new text;
	$text = $language->get();


//get the http post data
	$view = $_REQUEST['view'] ?? '';
	// 	$action = $_POST['action'] ?? '';
	$search = $_REQUEST['search'] ?? '';
	$group_permissions = $_POST['group_permissions'] ?? '';

//process permission reload
	if (!empty($_GET['action']) && $_GET['action'] == 'reload' && !empty($group_uuid)) {
		if (is_array($_SESSION["groups"]) && @sizeof($_SESSION["groups"]) != 0) {
			//clear current permissions
				unset($_SESSION['permissions'], $_SESSION['user']['permissions']);

			//get the permissions assigned to the groups that the current user is a member of, set the permissions in session variables
				$x = 0;
				$sql = "select distinct(permission_name) from v_group_permissions ";
				$sql .= "where (domain_uuid = :domain_uuid or domain_uuid is null) ";
				$sql .= "and permission_assigned = 'true' ";
				foreach ($_SESSION["groups"] as $field) {
					if (!empty($field['group_name'])) {
						$sql_where_or[] = "group_name = :group_name_".$x;
						$parameters['group_name_'.$x] = $field['group_name'];
						$x++;
					}
				}
				if (is_array($sql_where_or) && @sizeof($sql_where_or) != 0) {
					$sql .= "and (".implode(' or ', $sql_where_or).") ";
				}
				$parameters['domain_uuid'] = $_SESSION["domain_uuid"];
				$result = $database->select($sql, $parameters, 'all');
				if (is_array($result) && @sizeof($result) != 0) {
					foreach ($result as $row) {
						$_SESSION['permissions'][$row["permission_name"]] = true;
						$_SESSION["user"]["permissions"][$row["permission_name"]] = true;
					}
				}
				unset($sql, $parameters, $result, $row);

			//set message and redirect
				message::add($text['message-permissions_reloaded'],'positive');
				header('Location: group_permissions.php?group_uuid='.urlencode($_GET['group_uuid']).($view ? '&view='.urlencode($view) : null).($search ? '&search='.urlencode($search) : null));
				exit;
		}
	}

//get the list
	$sql = "select ";
	$sql .= "	distinct p.permission_name, \n";
	$sql .= "	p.application_name, \n";
	$sql .= "	p.permission_description, \n";
	$sql .= "	g.permission_protected, \n";
	$sql .= "	g.group_permission_uuid, \n";
	$sql .= "	g.permission_assigned \n";
	$sql .= "from v_permissions as p \n";
	$sql .= "left join \n";
	$sql .= "	v_group_permissions as g \n";
	$sql .= "	on p.permission_name = g.permission_name \n";
	$sql .= "	and group_name = :group_name \n";
	$sql .= " 	and g.group_uuid = :group_uuid \n";
	$sql .= "where true \n";
	if (!empty($search)) {
		// Create a temporary column for friendly description for searching
		$sql .= "and (";
		$sql .= "	lower(p.permission_name) like :search \n";
		$sql .= "	or lower(p.permission_description) like :search \n";
		// Note: We can't easily search generated descriptions in SQL, so we'll filter in PHP
		$sql .= ") ";
		$parameters['search'] = '%'.$search.'%';
	}
	$sql .= "	order by p.application_name, p.permission_name asc ";
	$parameters['group_name'] = $group_name;
	$parameters['group_uuid'] = $group_uuid;
	$group_permissions = $database->select($sql, $parameters, 'all');

	// Filter by search if provided (for generated descriptions)
	if (!empty($search) && !empty($group_permissions)) {
		$filtered_permissions = [];
		$search_lower = strtolower($search);
		foreach ($group_permissions as $permission) {
			$permission_name = strtolower($permission['permission_name']);
			$permission_desc = strtolower($permission['permission_description'] ?? '');

			// Generate friendly description for search
			$friendly_desc = '';
			if (empty(trim($permission_desc))) {
				$friendly_desc = strtolower(get_permission_description($permission['permission_name']));
			}

			if (strpos($permission_name, $search_lower) !== false ||
				strpos($permission_desc, $search_lower) !== false ||
				strpos($friendly_desc, $search_lower) !== false) {
				$filtered_permissions[] = $permission;
			}
		}
		$group_permissions = $filtered_permissions;
	}

//process the user data and save it to the database
	if (!empty($_POST) > 0 && empty($_POST["persistformvar"])) {
			$x = 0;
			if (is_array($_POST['group_permissions'])) {
				foreach($_POST['group_permissions'] as $row) {
					//reset values
						$action = "";
						$save_permission = false;
						$delete_permission = false;
						$save_protected = false;
						$delete_protected = false;
						$persist = false;

					//set row defaults
						$row['checked'] = $row['checked'] ?? '';
						$row['permission_assigned'] = $row['permission_assigned'] ?? '';
						$row['permission_protected'] = $row['permission_protected'] ?? '';

					//get the action save or delete
						foreach($group_permissions as $field) {
							if ($field['permission_name'] === $row['permission_name']) {
								$row['checked'] = $row['checked'] ?? '';
								$row['permission_assigned'] = $row['permission_assigned'] ?? '';
								if ($field['permission_assigned'] == 'true') {
									if ($row['checked'] == "true") {
										$persist = true;
									}
									else {
										$delete_permission = true;
									}
								}
								else {
									if ($row['checked'] == "true") {
										$save_permission = true;
									}
									else {
										//do nothing
									}
								}

								if ($field['permission_protected'] == 'true') {
									if ($row['permission_protected'] == "true") {
										$persist = true;
									}
									else {
										$delete_protected = true;
									}
								}
								else {
									if ($row['permission_protected'] == "true") {
										$save_protected = true;
									}
									else {
										//do nothing
									}
								}

								if ($save_permission || $save_protected) {
									$action = "save";
								}
								elseif ($delete_permission || $delete_protected){
									if ($persist) {
										$action = "save";
									}
									else {
										$action = "delete";
									}
								}
								else {
									$action = "";
								}
								$group_permission_uuid = $field['group_permission_uuid'];
								break;
							}
						}

					//build the array;
						if ($action == "save") {
							if (empty($group_permission_uuid)) {
								$group_permission_uuid = uuid();
							}
							if (isset($row['permission_name']) && !empty($row['permission_name'])) {
								$array['save']['group_permissions'][$x]['group_permission_uuid'] = $group_permission_uuid;
								$array['save']['group_permissions'][$x]['permission_name'] = $row['permission_name'];
								$array['save']['group_permissions'][$x]['permission_protected'] = $row['permission_protected'] == 'true' ? "true" : 'false';
								$array['save']['group_permissions'][$x]['permission_assigned'] = $row['checked'] != "true" ? "false" : "true";
								$array['save']['group_permissions'][$x]['group_uuid'] = $group_uuid;
								$array['save']['group_permissions'][$x]['group_name'] = $group_name;
								$x++;
							}
						}

						if ($action == "delete") {
							if (isset($row['permission_name']) && !empty($row['permission_name'])) {
								$array['delete']['group_permissions'][$x]['permission_name'] = $row['permission_name'];
								$array['delete']['group_permissions'][$x]['group_uuid'] = $group_uuid;
								$array['delete']['group_permissions'][$x]['group_name'] = $group_name;
							}
							$x++;
						}
				}
			}

		//validate the token
			$token = new token;
			if (!$token->validate($_SERVER['PHP_SELF'])) {
				message::add($text['message-invalid_token'],'negative');
				header('Location: group_permissions.php?group_uuid='.urlencode($group_uuid).($view ? '&view='.urlencode($view) : null).($search ? '&search='.urlencode($search) : null));
				exit;
			}

		//save the save array
			if (!empty($array['save']) && is_array($array['save']) && @sizeof($array['save']) != 0) {
				$database->app_name = 'groups';
				$database->app_uuid = '2caf27b0-540a-43d5-bb9b-c9871a1e4f84';
				$database->save($array['save']);
				$message = $database->message;
			}

		//delete the delete array
			if (!empty($array['delete']) && is_array($array['delete']) && @sizeof($array['delete']) != 0) {
				if (permission_exists('group_permission_delete')) {
					$database->app_name = 'groups';
					$database->app_uuid = '2caf27b0-540a-43d5-bb9b-c9871a1e4f84';
					$database->delete($array['delete']);
				}
			}

		//set the message
			message::add($text['message-update']);

		//redirect
			header('Location: group_permissions.php?group_uuid='.urlencode($group_uuid).($view ? '&view='.urlencode($view) : null).($search ? '&search='.urlencode($search) : null));
			exit;
	}

//create token
	$object = new token;
	$token = $object->create($_SERVER['PHP_SELF']);

//include the header
	$document['title'] = $text['title-group_permissions'];
	require_once "resources/header.php";

//show the content
	echo "<div class='action_bar' id='action_bar'>\n";
	echo "	<div class='heading'><b>".$text['title-group_permissions']."</b><div class='count'>".escape($group_name)."</div></div>\n";
	echo "	<div class='actions'>\n";
	echo button::create(['type'=>'button','label'=>$text['button-back'],'icon'=>$settings->get('theme', 'button_icon_back'),'id'=>'btn_back','style'=>'margin-right: 15px;','collapse'=>'hide-md-dn','link'=>'groups.php']);
	echo button::create(['type'=>'button','label'=>$text['button-reload'],'icon'=>$settings->get('theme', 'button_icon_reload'),'collapse'=>'hide-md-dn','link'=>'?group_uuid='.urlencode($group_uuid).'&action=reload'.($view ? '&view='.urlencode($view) : null).($search ? '&search='.urlencode($search) : null)]);
	if (permission_exists('group_member_view')) {
		echo button::create(['type'=>'button','label'=>$text['button-members'],'icon'=>'users','collapse'=>'hide-md-dn','link'=>'group_members.php?group_uuid='.urlencode($group_uuid)]);
	}
	echo 		"<form id='form_search' class='inline' method='get'>\n";
	echo 		"<input type='hidden' name='group_uuid' value='".escape($group_uuid)."'>\n";
	echo 		"<select class='txt' style='margin-left: 15px; margin-right: 0;' id='view' name='view' onchange=\"document.getElementById('form_search').submit();\">\n";
	echo 		"	<option value=''>".$text['label-all']."</option>\n";
	echo 		"	<option value='assigned' ".($view == 'assigned' ? "selected='selected'" : null).">".$text['label-assigned']."</option>\n";
	echo 		"	<option value='unassigned' ".($view == 'unassigned' ? "selected='selected'" : null).">".$text['label-unassigned']."</option>\n";
	echo 		"	<option value='protected' ".($view == 'protected' ? "selected='selected'" : null).">".$text['label-group_protected']."</option>\n";
	echo 		"</select>\n";
	echo 		"<input type='text' class='txt list-search' style='margin-left: 0;' name='search' id='search' value=\"".escape($search)."\" placeholder=\"".$text['label-search']."\" onkeydown='list_search_reset();'>";
	echo button::create(['label'=>$text['button-search'],'icon'=>$settings->get('theme', 'button_icon_search'),'type'=>'submit','id'=>'btn_search','collapse'=>'hide-md-dn','style'=>($search != '' ? 'display: none;' : null)]);
	echo button::create(['label'=>$text['button-reset'],'icon'=>$settings->get('theme', 'button_icon_reset'),'type'=>'button','id'=>'btn_reset','collapse'=>'hide-md-dn','link'=>'group_permissions.php?group_uuid='.urlencode($group_uuid),'style'=>($search == '' ? 'display: none;' : null)]);
	if (permission_exists('group_permission_edit')) {
		echo button::create(['type'=>'button','label'=>$text['button-save'],'icon'=>$settings->get('theme', 'button_icon_save'),'id'=>'btn_save','collapse'=>'hide-md-dn','style'=>'margin-left: 15px;','onclick'=>"document.getElementById('form_list').submit();"]);
	}
	echo "		</form>\n";
	echo "	</div>\n";
	echo "	<div style='clear: both;'></div>\n";
	echo "</div>\n";

	echo $text['description-group_permissions']."\n";
	echo "<br /><br />\n";

	echo "<form id='form_list' method='post'>\n";
	echo "<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>\n";
	echo "<input type='hidden' name='group_uuid' value='".escape($group_uuid)."'>\n";
	echo "<input type='hidden' name='view' value=\"".escape($view)."\">\n";
	echo "<input type='hidden' name='search' value=\"".escape($search)."\">\n";

	echo "<div class='card'>\n";
	echo "<table class='list'>\n";
	if (is_array($group_permissions) && @sizeof($group_permissions) != 0) {
		$x = 0;
		foreach ($group_permissions as $row) {
			$previous_application_name = $previous_application_name ?? '';
			$checked = ($row['permission_assigned'] === 'true') ? " checked=\"checked\"" : $checked = '';
			$protected = ($row['permission_protected'] === 'true') ? " checked=\"checked\"" : '';
			$application_name = strtolower(str_replace([' ','-'], '_', $row['application_name']));
			$application_name_label = ucwords(str_replace(['_','-'], " ", $row['application_name']));

			//application heading
			if ($previous_application_name !== $row['application_name']) {
				if ($previous_application_name != '') {
					echo "		<tr class='heading_".$application_name."'>";
					echo "			<td align='left' colspan='999' style='cursor: default !important;'>&nbsp;</td>\n";
					echo "		</tr>";
				}
				echo "		<tr class='heading_".$application_name."'>";
				echo "			<td align='left' colspan='999' style='cursor: default !important;' nowrap='nowrap'><b>".escape($application_name_label)."</b></td>\n";
				echo "		</tr>";
				echo "		<tr class='list-header heading_".$application_name."'>\n";
				if (permission_exists('group_permission_add') || permission_exists('group_permission_edit') || permission_exists('group_permission_delete')) {
					echo "		<th class='checkbox'>\n";
					echo "			<input type='checkbox' id='checkbox_all_".$application_name."' name='checkbox_all' onclick=\"list_all_toggle('".$application_name."');\">\n";
					echo "		</th>\n";
				}
				echo "			<th>".$text['label-permission_permissions']."</th>\n";
				if (permission_exists('group_permission_add') || permission_exists('group_permission_edit') || permission_exists('group_permission_delete')) {
					echo "		<th class='checkbox' onmouseover=\"document.getElementById('checkbox_all_label_".$application_name."').style.display='none'; document.getElementById('checkbox_all_".$application_name."_protected').style.display='';\" onmouseout=\"document.getElementById('checkbox_all_label_".$application_name."').style.display=''; document.getElementById('checkbox_all_".$application_name."_protected').style.display='none';\">\n";
					echo "			<span id='checkbox_all_label_".$application_name."'>".$text['label-group_protected']."</span>\n";
					echo "			<input type='checkbox' id='checkbox_all_".$application_name."_protected' name='checkbox_protected_all' style='display: none;' onclick=\"list_all_toggle('".$application_name."_protected');\">\n";
					echo "		</th>\n";
				}
				if (!permission_exists('group_permission_add') && !permission_exists('group_permission_edit') && !permission_exists('group_permission_delete')) {
					echo "\t\t<th></th>\n";
				}
				echo "		</tr>\n";
				$displayed_permissions[$application_name] = 0;
			}

			//application permission
			if (!$view || ($view == 'assigned' && $checked) || ($view == 'unassigned' && !$checked) || ($view == 'protected' && $protected)) {
				echo "<tr class='list-row'>\n";
				if (permission_exists('group_permission_add') || permission_exists('group_permission_edit') || permission_exists('group_permission_delete')) {
					echo "	<td class='checkbox'>\n";
					echo "		<input type='checkbox' name='group_permissions[$x][checked]' id='checkbox_".$x."' class='checkbox_".$application_name."' value='true' ".$checked." onclick=\"if (!this.checked) { document.getElementById('checkbox_all_".$application_name."').checked = false; }\">\n";
					//echo "		<input type='hidden' name='group_permissions[$x][permission_uuid]' value='".escape($row['permission_uuid'])."' />\n";
					echo "		<input type='hidden' name='group_permissions[$x][permission_name]' value='".escape($row['permission_name'])."' />\n";
					echo "	</td>\n";
				}
				echo "\t<td class='no-wrap' onclick=\"if (document.getElementById('checkbox_".$x."').checked) { document.getElementById('checkbox_".$x."').checked = false; document.getElementById('checkbox_all_".$application_name."').checked = false; } else { document.getElementById('checkbox_".$x."').checked = true; }\">";
				$display_label = $row['permission_description'] ?? '';
				if (trim($display_label) === '') {
					// Generate friendly description from permission name
					$display_label = get_permission_description($row['permission_name']);
				}
				echo "\t\t".escape($display_label);
				echo "<div style='color: #888; font-size: 11px; margin-top: 2px;'>".escape($row['permission_name'])."</div>";
				echo "\t</td>\n";
				if (permission_exists('group_permission_add') || permission_exists('group_permission_edit') || permission_exists('group_permission_delete')) {
					echo "	<td class='checkbox'>\n";
					echo "		<input type='checkbox' name='group_permissions[$x][permission_protected]' id='checkbox_protected_".$x."' class='checkbox_".$application_name."_protected' value='true' ".$protected." onclick=\"if (!this.checked) { document.getElementById('checkbox_all_".$application_name."_protected').checked = false; }\">\n";
					echo "	</td>\n";
				}
				echo "</tr>\n";
				$displayed_permissions[$application_name]++;
			}

			//set the previous application name
			$previous_application_name = $row['application_name'];
			$x++;

		}
		unset($group_permissions);

		//hide application heading if no permissions displayed
		if (is_array($displayed_permissions) && @sizeof($displayed_permissions) != 0) {
			echo "<script>\n";
			foreach ($displayed_permissions as $application_name => $permission_count) {
				if (!$permission_count) {
					echo "$('.heading_".$application_name."').hide();\n";
				}
			}
			echo "</script>\n";
		}

	}

	echo "</table>\n";
	echo "</div>\n";
	echo "</form>\n";

//include the footer
	require_once "resources/footer.php";

?>
