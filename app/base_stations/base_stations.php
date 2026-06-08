<?php
/*
	FusionPBX
	Version: MPL 1.1

	Base Stations - List Page
	Manage base stations with IP, MAC, location, credentials and connectivity status
*/

//includes files
	require_once dirname(__DIR__, 2) . "/resources/require.php";
	require_once "resources/check_auth.php";
	require_once "resources/paging.php";

//check permissions
	if (permission_exists('base_station_view')) {
		//access granted
	}
	else {
		echo "access denied";
		exit;
	}

//initialize the database object
	$database = new database;

//add multi-lingual support
	$language = new text;
	$text = $language->get();

//get posted data
	if (!empty($_POST['base_stations']) && is_array($_POST['base_stations'])) {
		$action = $_POST['action'];
		$search = $_POST['search'];
		$base_stations = $_POST['base_stations'];
	}

//process the http post data by action
	if (!empty($action) && !empty($base_stations) && is_array($base_stations) && @sizeof($base_stations) != 0) {
		switch ($action) {
			case 'toggle':
				if (permission_exists('base_station_edit')) {
					foreach ($base_stations as $row) {
						if (!empty($row['checked']) && $row['checked'] == 'true' && is_uuid($row['uuid'])) {
							// Get current state
							$sql = "select enabled from v_base_stations where base_station_uuid = :base_station_uuid ";
							$parameters['base_station_uuid'] = $row['uuid'];
							$enabled = $database->select($sql, $parameters, 'column');
							unset($sql, $parameters);
							
							// Toggle state
							$enabled = ($enabled == 'true') ? 'false' : 'true';
							
							// Update
							$array['base_stations'][0]['base_station_uuid'] = $row['uuid'];
							$array['base_stations'][0]['enabled'] = $enabled;
							$array['base_stations'][0]['update_date'] = 'now()';
							$array['base_stations'][0]['update_user'] = $_SESSION['user_uuid'];
							
							$p = permissions::new();
							$p->add('base_station_edit', 'temp');
							
							$database->app_name = 'base_stations';
							$database->app_uuid = 'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d';
							$database->save($array);
							unset($array);
							
							$p->delete('base_station_edit', 'temp');
						}
					}
					message::add($text['message-toggle']);
				}
				break;
			case 'delete':
				if (permission_exists('base_station_delete')) {
					foreach ($base_stations as $row) {
						if (!empty($row['checked']) && $row['checked'] == 'true' && is_uuid($row['uuid'])) {
							$array['base_stations'][0]['base_station_uuid'] = $row['uuid'];
							
							$p = permissions::new();
							$p->add('base_station_delete', 'temp');
							
							$database->app_name = 'base_stations';
							$database->app_uuid = 'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d';
							$database->delete($array);
							unset($array);
							
							$p->delete('base_station_delete', 'temp');
						}
					}
					message::add($text['message-delete']);
				}
				break;
		}

		header('Location: base_stations.php'.($search != '' ? '?search='.urlencode($search) : null));
		exit;
	}

//process single record delete
	if (!empty($_GET['action']) && $_GET['action'] == 'delete' && !empty($_GET['id']) && is_uuid($_GET['id'])) {
		if (permission_exists('base_station_delete')) {
			//delete the record
			$array['base_stations'][0]['base_station_uuid'] = $_GET['id'];
			
			$p = permissions::new();
			$p->add('base_station_delete', 'temp');
			
			$database->app_name = 'base_stations';
			$database->app_uuid = 'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d';
			$database->delete($array);
			unset($array);
			
			$p->delete('base_station_delete', 'temp');
			
			message::add($text['message-delete']);
		}
		
		$redirect_params = [];
		if (!empty($_GET['page'])) {
			$redirect_params[] = 'page='.urlencode($_GET['page']);
		}
		if (!empty($_GET['search'])) {
			$redirect_params[] = 'search='.urlencode($_GET['search']);
		}
		header('Location: base_stations.php'.(count($redirect_params) > 0 ? '?'.implode('&', $redirect_params) : ''));
		exit;
	}

//get order and order by
	$order_by = $_GET["order_by"] ?? 'station_name';
	$order = $_GET["order"] ?? 'asc';

//add the search term
	$search = strtolower($_GET["search"] ?? '');

//get total count
	$sql = "select count(*) from v_base_stations ";
	$sql .= "where domain_uuid = :domain_uuid ";
	if (!empty($search)) {
		$sql .= "and ( ";
		$sql .= " lower(station_name) like :search ";
		$sql .= " or lower(cast(ip_address as text)) like :search ";
		$sql .= " or lower(cast(mac_address as text)) like :search ";
		$sql .= " or lower(deploy_location) like :search ";
		$sql .= " or lower(power_source) like :search ";
		$sql .= " or lower(username) like :search ";
		$sql .= " or lower(description) like :search ";
		$sql .= ") ";
		$parameters['search'] = '%'.$search.'%';
	}
	$parameters['domain_uuid'] = $_SESSION['domain_uuid'];
	$num_rows = $database->select($sql, $parameters ?? null, 'column');

//prepare to page the results
	$rows_per_page = $settings->get('domain', 'paging', 50);
	$param = "&search=".$search;
	$page = isset($_GET['page']) && is_numeric($_GET['page']) ? $_GET['page'] : 0;
	list($paging_controls, $rows_per_page) = paging($num_rows, $param, $rows_per_page);
	list($paging_controls_mini, $rows_per_page) = paging($num_rows, $param, $rows_per_page, true);
	$offset = $rows_per_page * $page;

//get the list
	$sql = "select * from v_base_stations ";
	$sql .= "where domain_uuid = :domain_uuid ";
	if (!empty($search)) {
		$sql .= "and ( ";
		$sql .= " lower(station_name) like :search ";
		$sql .= " or lower(cast(ip_address as text)) like :search ";
		$sql .= " or lower(cast(mac_address as text)) like :search ";
		$sql .= " or lower(deploy_location) like :search ";
		$sql .= " or lower(power_source) like :search ";
		$sql .= " or lower(username) like :search ";
		$sql .= " or lower(description) like :search ";
		$sql .= ") ";
		$parameters['search'] = '%'.$search.'%';
	}
	$parameters['domain_uuid'] = $_SESSION['domain_uuid'];
	$sql .= order_by($order_by, $order);
	$sql .= limit_offset($rows_per_page, $offset);
	$base_stations = $database->select($sql, $parameters ?? null, 'all');
	unset($sql, $parameters);

//get probe settings (for AJAX)
	$probe_timeout = intval($settings->get('base_station', 'probe_timeout', 800));

//create token
	$object = new token;
	$token = $object->create($_SERVER['PHP_SELF']);

//include the header
	$document['title'] = $text['title-base_stations'];
	require_once "resources/header.php";

//show the content
	echo "<div class='action_bar' id='action_bar'>\n";
	echo "	<div class='heading'><b>".$text['header-base_stations']."</b><div class='count'>".number_format($num_rows)."</div></div>\n";
	echo "	<div class='actions'>\n";
	if (permission_exists('base_station_add')) {
		echo button::create(['type'=>'button','label'=>$text['button-add'],'icon'=>$settings->get('theme', 'button_icon_add'),'id'=>'btn_add','link'=>'base_station_edit.php']);
		echo button::create(['type'=>'button','label'=>$text['button-bulk_add'],'icon'=>'plus-square','id'=>'btn_bulk_add','style'=>'margin-left: 15px;','link'=>'base_stations_bulk_add.php']);
	}
	if ($base_stations) {
		echo button::create(['type'=>'button','label'=>$text['button-refresh_status'],'icon'=>'refresh','id'=>'btn_refresh_status','style'=>'margin-left: 15px;','onclick'=>'manualRefreshStatus();']);
	}
	if (permission_exists('base_station_edit') && $base_stations) {
		echo button::create(['type'=>'button','label'=>$text['button-toggle'],'icon'=>$settings->get('theme', 'button_icon_toggle'),'id'=>'btn_toggle','name'=>'btn_toggle','style'=>'display: none; margin-left: 15px;','onclick'=>"modal_open('modal-toggle','btn_toggle');"]);
	}
	if (permission_exists('base_station_delete') && $base_stations) {
		echo button::create(['type'=>'button','label'=>$text['button-delete'],'icon'=>$settings->get('theme', 'button_icon_delete'),'id'=>'btn_delete','name'=>'btn_delete','style'=>'display: none; margin-left: 15px;','onclick'=>"modal_open('modal-delete','btn_delete');"]);
	}
	echo 		"<form id='form_search' class='inline' method='get'>\n";
	echo 		"<input type='text' class='txt list-search' name='search' id='search' value=\"".escape($search)."\" placeholder=\"".$text['label-search']."\" onkeydown=''>";
	echo button::create(['label'=>$text['button-search'],'icon'=>$settings->get('theme', 'button_icon_search'),'type'=>'submit','id'=>'btn_search']);
	if ($paging_controls_mini != '') {
		echo 	"<span style='margin-left: 15px;'>".$paging_controls_mini."</span>";
	}
	echo "		</form>\n";
	echo "	</div>\n";
	echo "	<div style='clear: both;'></div>\n";
	echo "</div>\n";

	if (permission_exists('base_station_edit') && $base_stations) {
		echo modal::create(['id'=>'modal-toggle','type'=>'toggle','actions'=>button::create(['type'=>'button','label'=>$text['button-continue'],'icon'=>'check','id'=>'btn_toggle','style'=>'float: right; margin-left: 15px;','collapse'=>'never','onclick'=>"modal_close(); list_action_set('toggle'); list_form_submit('form_list');"])]);
	}
	if (permission_exists('base_station_delete') && $base_stations) {
		echo modal::create(['id'=>'modal-delete','type'=>'delete','actions'=>button::create(['type'=>'button','label'=>$text['button-continue'],'icon'=>'check','id'=>'btn_delete','style'=>'float: right; margin-left: 15px;','collapse'=>'never','onclick'=>"modal_close(); list_action_set('delete'); list_form_submit('form_list');"])]);
	}

	echo $text['description-base_stations']."\n";
	echo "<br /><br />\n";

	echo "<form id='form_list' method='post'>\n";
	echo "<input type='hidden' id='action' name='action' value=''>\n";
	echo "<input type='hidden' name='search' value=\"".escape($search)."\">\n";

	echo "<div class='card'>\n";
	echo "<table class='list'>\n";
	echo "<tr class='list-header'>\n";
	if (permission_exists('base_station_edit') || permission_exists('base_station_delete')) {
		echo "	<th class='checkbox'>\n";
		echo "		<input type='checkbox' id='checkbox_all' name='checkbox_all' onclick='list_all_toggle(); checkbox_on_change(this);' ".(empty($base_stations) ? "style='visibility: hidden;'" : null).">\n";
		echo "	</th>\n";
	}
	echo th_order_by('station_name', $text['label-station_name'], $order_by, $order);
	echo th_order_by('ip_address', $text['label-ip_address'], $order_by, $order);
	echo th_order_by('mac_address', $text['label-mac_address'], $order_by, $order, null, "class='hide-xs'");
	echo th_order_by('deploy_location', $text['label-deploy_location'], $order_by, $order, null, "class='hide-sm-dn'");
	echo th_order_by('username', $text['label-username'], $order_by, $order, null, "class='hide-md-dn'");
	echo "<th class='center'>".$text['label-status']."</th>\n";
	echo th_order_by('power_source', $text['label-power_source'], $order_by, $order, null, "class='center'");
	echo th_order_by('enabled', $text['label-enabled'], $order_by, $order, null, "class='center'");
	echo th_order_by('description', $text['label-description'], $order_by, $order, null, "class='hide-sm-dn'");
	echo "	<th class='center' style='min-width: 120px;'>".$text['label-actions']."</th>\n";
	echo "</tr>\n";

	if (is_array($base_stations) && @sizeof($base_stations) != 0) {
		$x = 0;
		foreach($base_stations as $row) {
			$list_row_url = '';
			if (permission_exists('base_station_edit')) {
				$list_row_url = "base_station_edit.php?id=".urlencode($row['base_station_uuid']).(is_numeric($page) ? '&page='.urlencode($page) : null);
			}
			
			echo "<tr class='list-row' href='".$list_row_url."'>\n";
			if (permission_exists('base_station_edit') || permission_exists('base_station_delete')) {
				echo "	<td class='checkbox'>\n";
				echo "		<input type='checkbox' name='base_stations[$x][checked]' id='checkbox_".$x."' value='true' onclick=\"checkbox_on_change(this); if (!this.checked) { document.getElementById('checkbox_all').checked = false; }\">\n";
				echo "		<input type='hidden' name='base_stations[$x][uuid]' value='".escape($row['base_station_uuid'])."' />\n";
				echo "	</td>\n";
			}
			echo "	<td>";
			if (permission_exists('base_station_edit')) {
				echo "<a href='".$list_row_url."' title=\"".$text['button-edit']."\">".escape($row['station_name'])."</a>";
			}
			else {
				echo escape($row['station_name']);
			}
			echo "	</td>\n";
			echo "	<td class='no-wrap'>".escape($row['ip_address'])."&nbsp;</td>\n";
			echo "	<td class='hide-xs no-wrap'>".escape($row['mac_address'])."&nbsp;</td>\n";
			echo "	<td class='hide-sm-dn'>".escape($row['deploy_location'])."&nbsp;</td>\n";
			echo "	<td class='hide-md-dn'>".escape($row['username'])."&nbsp;</td>\n";
			echo "	<td class='middle button center' style='text-align: center;'>\n";
			echo "		<span id='status_".$row['base_station_uuid']."' data-ip='".escape($row['ip_address'])."' style='display: inline-block; vertical-align: middle;'>\n";
			echo "			<div class='status-dot' style='display: inline-block; width: 8px; height: 8px; border-radius: 50%; background-color: #999; border: 1px solid #777; vertical-align: middle;'></div>\n";
			echo "			<span class='status-text' style='margin-left: 6px; vertical-align: middle;'>".$text['label-checking']."</span>\n";
			echo "		</span>\n";
			echo "	</td>\n";
			$power_source = strtolower((string) ($row['power_source'] ?? ''));
			if (!in_array($power_source, ['ac', 'dc'], true)) {
				$power_source = 'ac';
			}
			echo "	<td class='center no-wrap'>".$text['label-'.$power_source]."</td>\n";
			if (permission_exists('base_station_edit')) {
				echo "	<td class='no-link center'>";
				echo button::create(['type'=>'submit','class'=>'link','label'=>$text['label-'.$row['enabled']],'title'=>$text['button-toggle'],'onclick'=>"list_self_check('checkbox_".$x."'); list_action_set('toggle'); list_form_submit('form_list')"]);
			}
			else {
				echo "	<td class='center'>";
				echo $text['label-'.$row['enabled']];
			}
			echo "	</td>\n";
			echo "	<td class='description overflow hide-sm-dn'>".escape($row['description'])."</td>\n";
			echo "	<td class='middle no-link center' style='white-space: nowrap;'>\n";
			if (permission_exists('base_station_edit')) {
				echo button::create(['type'=>'button','label'=>$text['button-edit'],'title'=>$text['button-edit'],'icon'=>$settings->get('theme', 'button_icon_edit'),'link'=>$list_row_url]);
			}
			if (permission_exists('base_station_delete')) {
				$delete_url = 'base_stations.php?action=delete&id='.urlencode($row['base_station_uuid']);
				$delete_url .= (is_numeric($page) ? '&page='.urlencode($page) : '');
				$delete_url .= (!empty($search) ? '&search='.urlencode($search) : '');
				echo button::create(['type'=>'button','label'=>$text['button-delete'],'title'=>$text['button-delete'],'icon'=>$settings->get('theme', 'button_icon_delete'),'onclick'=>"if (confirm('".$text['confirm-delete']."')) { window.location.href='".$delete_url."'; }"]);
			}
			// 访问按钮 - 不对IP地址进行转义
			$access_url = 'http://'.$row['ip_address'].':80';
			echo button::create(['type'=>'button','label'=>$text['button-access'],'title'=>$text['button-access'],'icon'=>'globe','link'=>$access_url,'target'=>'_blank']);
			echo "	</td>\n";
			echo "</tr>\n";
			$x++;
		}
	}

	echo "</table>\n";
	echo "</div>\n";

	echo "<br />\n";
	echo "<div align='center'>".$paging_controls."</div>\n";

	echo "<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>\n";

	echo "</form>\n";

	unset($base_stations);

//AJAX status check JavaScript
	$refresh_interval = intval($settings->get('base_station', 'refresh_interval', 2)); // seconds
	echo "<script>\n";
	echo "var probeTimeout = ".intval($settings->get('base_station', 'probe_timeout', 800)).";\n";
	echo "var refreshInterval = ".max(1, $refresh_interval)." * 1000; // convert to ms\n";
	echo "var forceOnlineStorageKey = 'base_station_localstoreg_force_online';\n";
	echo "var statusCheckTimer = null;\n\n";
	echo "var statusCheckInFlight = false;\n\n";

	echo "function isForceAllOnlineEnabled() {\n";
	echo "	try {\n";
	echo "		return localStorage.getItem(forceOnlineStorageKey) === '1';\n";
	echo "	} catch (error) {\n";
	echo "		return false;\n";
	echo "	}\n";
	echo "}\n\n";

	echo "function setCheckingUI(el) {\n";
	echo "	var dot = el.querySelector('.status-dot');\n";
	echo "	var text = el.querySelector('.status-text');\n";
	echo "	if (dot) {\n";
	echo "		dot.style.backgroundColor = '#999';\n";
	echo "		dot.style.borderColor = '#777';\n";
	echo "	}\n";
	echo "	if (text) {\n";
	echo "		text.textContent = '".addslashes($text['label-checking'])."';\n";
	echo "	}\n";
	echo "}\n\n";

	echo "function scheduleStatusCheck(delay) {\n";
	echo "	if (statusCheckTimer) {\n";
	echo "		clearTimeout(statusCheckTimer);\n";
	echo "	}\n";
	echo "	statusCheckTimer = setTimeout(checkBaseStationStatus, delay || refreshInterval);\n";
	echo "}\n\n";

	echo "function finishStatusCheck() {\n";
	echo "	statusCheckInFlight = false;\n";
	echo "	if (!document.hidden) {\n";
	echo "		scheduleStatusCheck(refreshInterval);\n";
	echo "	}\n";
	echo "}\n\n";

	echo "function checkBaseStationStatus() {\n";
	echo "	if (statusCheckInFlight) return;\n";
	echo "	var statusElements = document.querySelectorAll('[id^=\"status_\"]');\n";
	echo "	if (statusElements.length === 0) return;\n\n";

	echo "	var ipAddresses = [];\n";
	echo "	var ipMap = {};\n\n";

	echo "	statusElements.forEach(function(el) {\n";
	echo "		var ip = el.getAttribute('data-ip');\n";
	echo "		var uuid = el.id.replace('status_', '');\n";
	echo "		if (!ipMap[ip]) {\n";
	echo "			ipMap[ip] = [];\n";
	echo "			ipAddresses.push(ip);\n";
	echo "		}\n";
	echo "		ipMap[ip].push(uuid);\n";
	echo "	});\n\n";

	echo "	if (ipAddresses.length === 0) return;\n\n";
	echo "	statusCheckInFlight = true;\n\n";

	echo "	//Show checking status\n";
	echo "	statusElements.forEach(function(el) {\n";
	echo "		setCheckingUI(el);\n";
	echo "	});\n\n";

	echo "	//AJAX request to check status\n";
	echo "	var formData = new FormData();\n";
	echo "	formData.append('timeout', probeTimeout);\n";
	echo "	formData.append('force_all_online', isForceAllOnlineEnabled() ? 'true' : 'false');\n";
	echo "	ipAddresses.forEach(function(ip) {\n";
	echo "		formData.append('ip_addresses[]', ip);\n";
	echo "	});\n\n";

	echo "	fetch('resources/check_status.php', {\n";
	echo "		method: 'POST',\n";
	echo "		body: formData\n";
	echo "	})\n";
	echo "	.then(response => response.json())\n";
	echo "	.then(data => {\n";
	echo "		for (var ip in ipMap) {\n";
	echo "			var isOnline = Object.prototype.hasOwnProperty.call(data, ip) ? data[ip] : null;\n";
	echo "			ipMap[ip].forEach(function(uuid) {\n";
	echo "				var el = document.getElementById('status_' + uuid);\n";
	echo "				if (el) {\n";
	echo "					updateStatusUI(el, isOnline);\n";
	echo "				}\n";
	echo "			});\n";
	echo "		}\n";
	echo "		finishStatusCheck();\n";
	echo "	})\n";
	echo "	.catch(error => {\n";
	echo "		console.error('Status check error:', error);\n";
	echo "		statusElements.forEach(function(el) {\n";
	echo "			updateStatusUI(el, null);\n";
	echo "		});\n";
	echo "		finishStatusCheck();\n";
	echo "	});\n";
	echo "}\n\n";

	echo "function updateStatusUI(el, isOnline) {\n";
	echo "	var dot = el.querySelector('.status-dot');\n";
	echo "	var text = el.querySelector('.status-text');\n";
	echo "	if (!dot || !text) return;\n";
	echo "	if (isOnline === null) {\n";
	echo "		dot.style.backgroundColor = '#999';\n";
	echo "		dot.style.borderColor = '#777';\n";
	echo "		text.textContent = '".addslashes($text['label-unknown'])."';\n";
	echo "		return;\n";
	echo "	}\n";
	echo "	var statusColor = isOnline ? '#12d600' : '#e21b1b';\n";
	echo "	var statusText = isOnline ? '".addslashes($text['label-online'])."' : '".addslashes($text['label-offline'])."';\n\n";
	echo "	dot.style.backgroundColor = statusColor;\n";
	echo "	dot.style.borderColor = isOnline ? '#0f9e00' : '#b01616';\n";
	echo "	text.textContent = statusText;\n";
	echo "}\n\n";

	echo "function manualRefreshStatus() {\n";
	echo "	if (statusCheckTimer) {\n";
	echo "		clearTimeout(statusCheckTimer);\n";
	echo "	}\n";
	echo "	checkBaseStationStatus();\n";
	echo "}\n\n";

	echo "document.addEventListener('DOMContentLoaded', function() {\n";
	echo "	manualRefreshStatus();\n";
	echo "});\n";
	echo "document.addEventListener('visibilitychange', function() {\n";
	echo "	if (document.hidden) {\n";
	echo "		if (statusCheckTimer) {\n";
	echo "			clearTimeout(statusCheckTimer);\n";
	echo "			statusCheckTimer = null;\n";
	echo "		}\n";
	echo "		return;\n";
	echo "	}\n";
	echo "	manualRefreshStatus();\n";
	echo "});\n";
	echo "</script>\n";

//show the footer
	require_once "resources/footer.php";

?>

