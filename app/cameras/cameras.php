<?php
/*
	FusionPBX
	Version: MPL 1.1

	Cameras - List Page
	Manage camera devices with IP, port, MAC, location, credentials and RTSP streaming
*/

//includes files
	require_once dirname(__DIR__, 2) . "/resources/require.php";
	require_once "resources/check_auth.php";
	require_once "resources/paging.php";

//check permissions
	if (permission_exists('camera_view')) {
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
	if (!empty($_POST['cameras']) && is_array($_POST['cameras'])) {
		$action = $_POST['action'];
		$search = $_POST['search'];
		$cameras = $_POST['cameras'];
	}

//process the http post data by action
	if (!empty($action) && !empty($cameras) && is_array($cameras) && @sizeof($cameras) != 0) {
		switch ($action) {
			case 'toggle':
				if (permission_exists('camera_edit')) {
					foreach ($cameras as $row) {
						if (!empty($row['checked']) && $row['checked'] == 'true' && is_uuid($row['uuid'])) {
							// Get current state
							$sql = "select enabled from v_cameras where camera_uuid = :camera_uuid ";
							$parameters['camera_uuid'] = $row['uuid'];
							$enabled = $database->select($sql, $parameters, 'column');
							unset($sql, $parameters);

							// Toggle state
							$enabled = ($enabled == 'true') ? 'false' : 'true';

							// Update
							$array['cameras'][0]['camera_uuid'] = $row['uuid'];
							$array['cameras'][0]['enabled'] = $enabled;
							$array['cameras'][0]['update_date'] = 'now()';
							$array['cameras'][0]['update_user'] = $_SESSION['user_uuid'];

							$p = permissions::new();
							$p->add('camera_edit', 'temp');

							$database->app_name = 'cameras';
							$database->app_uuid = 'c4d5e6f7-a8b9-4c0d-1e2f-3a4b5c6d7e8f';
							$database->save($array);
							unset($array);

							$p->delete('camera_edit', 'temp');
						}
					}
					message::add($text['message-toggle']);
				}
				break;
			case 'delete':
				if (permission_exists('camera_delete')) {
					foreach ($cameras as $row) {
						if (!empty($row['checked']) && $row['checked'] == 'true' && is_uuid($row['uuid'])) {
							$array['cameras'][0]['camera_uuid'] = $row['uuid'];

							$p = permissions::new();
							$p->add('camera_delete', 'temp');

							$database->app_name = 'cameras';
							$database->app_uuid = 'c4d5e6f7-a8b9-4c0d-1e2f-3a4b5c6d7e8f';
							$database->delete($array);
							unset($array);

							$p->delete('camera_delete', 'temp');
						}
					}
					message::add($text['message-delete']);
				}
				break;
		}

		header('Location: cameras.php'.($search != '' ? '?search='.urlencode($search) : null));
		exit;
	}

//process single record delete
	if (!empty($_GET['action']) && $_GET['action'] == 'delete' && !empty($_GET['id']) && is_uuid($_GET['id'])) {
		if (permission_exists('camera_delete')) {
			//delete the record
			$array['cameras'][0]['camera_uuid'] = $_GET['id'];

			$p = permissions::new();
			$p->add('camera_delete', 'temp');

			$database->app_name = 'cameras';
			$database->app_uuid = 'c4d5e6f7-a8b9-4c0d-1e2f-3a4b5c6d7e8f';
			$database->delete($array);
			unset($array);

			$p->delete('camera_delete', 'temp');

			message::add($text['message-delete']);
		}

		$redirect_params = [];
		if (!empty($_GET['page'])) {
			$redirect_params[] = 'page='.urlencode($_GET['page']);
		}
		if (!empty($_GET['search'])) {
			$redirect_params[] = 'search='.urlencode($_GET['search']);
		}
		header('Location: cameras.php'.(count($redirect_params) > 0 ? '?'.implode('&', $redirect_params) : ''));
		exit;
	}

//get order and order by
	$order_by = $_GET["order_by"] ?? 'camera_name';
	$order = $_GET["order"] ?? 'asc';

//add the search term
	$search = strtolower($_GET["search"] ?? '');

//get total count
	$sql = "select count(*) from v_cameras ";
	$sql .= "where domain_uuid = :domain_uuid ";
	if (!empty($search)) {
		$sql .= "and ( ";
		$sql .= " lower(camera_name) like :search ";
		$sql .= " or lower(cast(ip_address as text)) like :search ";
		$sql .= " or lower(cast(port as text)) like :search ";
		$sql .= " or lower(cast(mac_address as text)) like :search ";
		$sql .= " or lower(location) like :search ";
		$sql .= " or lower(username) like :search ";
		$sql .= " or lower(rtsp_url) like :search ";
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
	$sql = "select * from v_cameras ";
	$sql .= "where domain_uuid = :domain_uuid ";
	if (!empty($search)) {
		$sql .= "and ( ";
		$sql .= " lower(camera_name) like :search ";
		$sql .= " or lower(cast(ip_address as text)) like :search ";
		$sql .= " or lower(cast(port as text)) like :search ";
		$sql .= " or lower(cast(mac_address as text)) like :search ";
		$sql .= " or lower(location) like :search ";
		$sql .= " or lower(username) like :search ";
		$sql .= " or lower(rtsp_url) like :search ";
		$sql .= " or lower(description) like :search ";
		$sql .= ") ";
		$parameters['search'] = '%'.$search.'%';
	}
	$parameters['domain_uuid'] = $_SESSION['domain_uuid'];
	$sql .= order_by($order_by, $order);
	$sql .= limit_offset($rows_per_page, $offset);
	$cameras = $database->select($sql, $parameters ?? null, 'all');
	unset($sql, $parameters);

//get default port and player URL
	$default_port = $settings->get('camera', 'default_port', 80);
	$player_url = $settings->get('camera', 'player_url', 'http://192.168.2.225:9003/player');

//create token
	$object = new token;
	$token = $object->create($_SERVER['PHP_SELF']);

//include the header
	$document['title'] = $text['title-cameras'];
	require_once "resources/header.php";

//show the content
	echo "<div class='action_bar' id='action_bar'>\n";
	echo "	<div class='heading'><b>".$text['header-cameras']."</b><div class='count'>".number_format($num_rows)."</div></div>\n";
	echo "	<div class='actions'>\n";
	if (permission_exists('camera_add')) {
		echo button::create(['type'=>'button','label'=>$text['button-add'],'icon'=>$settings->get('theme', 'button_icon_add'),'id'=>'btn_add','link'=>'camera_edit.php']);
	}
	if (permission_exists('camera_edit') && $cameras) {
		echo button::create(['type'=>'button','label'=>$text['button-toggle'],'icon'=>$settings->get('theme', 'button_icon_toggle'),'id'=>'btn_toggle','name'=>'btn_toggle','style'=>'display: none; margin-left: 15px;','onclick'=>"modal_open('modal-toggle','btn_toggle');"]);
	}
	if (permission_exists('camera_delete') && $cameras) {
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

	if (permission_exists('camera_edit') && $cameras) {
		echo modal::create(['id'=>'modal-toggle','type'=>'toggle','actions'=>button::create(['type'=>'button','label'=>$text['button-continue'],'icon'=>'check','id'=>'btn_toggle','style'=>'float: right; margin-left: 15px;','collapse'=>'never','onclick'=>"modal_close(); list_action_set('toggle'); list_form_submit('form_list');"])]);
	}
	if (permission_exists('camera_delete') && $cameras) {
		echo modal::create(['id'=>'modal-delete','type'=>'delete','actions'=>button::create(['type'=>'button','label'=>$text['button-continue'],'icon'=>'check','id'=>'btn_delete','style'=>'float: right; margin-left: 15px;','collapse'=>'never','onclick'=>"modal_close(); list_action_set('delete'); list_form_submit('form_list');"])]);
	}

	echo $text['description-cameras']."\n";
	echo "<br /><br />\n";

// Player URL configuration card
	echo "<div class='card' style='margin-bottom: 15px;'>\n";
	echo "	<div class='card-header' style='font-weight: bold;'>\n";
	echo "		<i class='fa fa-globe' style='margin-right: 10px;'></i>".$text['label-player_url']."\n";
	echo "	</div>\n";
	echo "	<div class='card-body' style='padding: 15px;'>\n";
	echo "		<form id='form_player_config' method='post' style='display: flex; align-items: center; gap: 10px; flex-wrap: wrap;'>\n";
	echo "			<input type='hidden' name='action' value='save_player_url'>\n";
	echo "			<div style='flex: 1; min-width: 300px;'>\n";
	echo "				<input type='text' class='formfld' name='player_url' id='player_url' value=\"".escape($player_url)."\" style='width: 100%;'>\n";
	echo "				<br />\n";
	echo "				<small style='color: #666;'>".$text['description-player_url']."</small>\n";
	echo "			</div>\n";
	echo "			<button type='submit' class='btn'>\n";
	echo "				<i class='fa fa-save' style='margin-right: 5px;'></i>".$text['button-save']."\n";
	echo "			</button>\n";
	echo "		</form>\n";
	echo "	</div>\n";
	echo "</div>\n";

// Process player URL save
	if (!empty($_POST['action']) && $_POST['action'] == 'save_player_url' && permission_exists('camera_edit')) {
		$new_player_url = $_POST['player_url'] ?? '';
		if (!empty($new_player_url)) {
			// Save to default settings
			$sql = "SELECT default_setting_uuid FROM v_default_settings ";
			$sql .= "WHERE default_setting_category = 'camera' ";
			$sql .= "AND default_setting_subcategory = 'player_url' ";
			$sql .= "AND domain_uuid IS NULL";
			$setting_uuid = $database->select($sql, null, 'column');

			if ($setting_uuid) {
				// Update existing setting
				$sql = "UPDATE v_default_settings SET ";
				$sql .= "default_setting_value = :value ";
				$sql .= "WHERE default_setting_uuid = :uuid";
				$parameters['value'] = $new_player_url;
				$parameters['uuid'] = $setting_uuid;
				$database->execute($sql, $parameters);
			} else {
				// Insert new setting (fallback)
				$array['default_settings'][0]['default_setting_uuid'] = uuid();
				$array['default_settings'][0]['default_setting_category'] = 'camera';
				$array['default_settings'][0]['default_setting_subcategory'] = 'player_url';
				$array['default_settings'][0]['default_setting_name'] = 'text';
				$array['default_settings'][0]['default_setting_value'] = $new_player_url;
				$array['default_settings'][0]['default_setting_enabled'] = 'true';
				$array['default_settings'][0]['default_setting_description'] = 'Video player URL for camera streams';
				$database->app_name = 'cameras';
				$database->app_uuid = 'c4d5e6f7-a8b9-4c0d-1e2f-3a4b5c6d7e8f';
				$database->save($array);
			}

			// Update session variable
			$_SESSION['default_settings']['camera']['player_url']['text'] = $new_player_url;

			message::add($text['message-update'], 'positive');
		} else {
			message::add('播放器地址不能为空', 'negative');
		}

		header('Location: cameras.php'.(!empty($search) ? '?search='.urlencode($search) : ''));
		exit;
	}

	echo "<form id='form_list' method='post'>\n";
	echo "<input type='hidden' id='action' name='action' value=''>\n";
	echo "<input type='hidden' name='search' value=\"".escape($search)."\">\n";

	echo "<div class='card'>\n";
	echo "<table class='list'>\n";
	echo "<tr class='list-header'>\n";
	if (permission_exists('camera_edit') || permission_exists('camera_delete')) {
		echo "	<th class='checkbox'>\n";
		echo "		<input type='checkbox' id='checkbox_all' name='checkbox_all' onclick='list_all_toggle(); checkbox_on_change(this);' ".(empty($cameras) ? "style='visibility: hidden;'" : null).">\n";
		echo "	</th>\n";
	}
	echo th_order_by('camera_name', $text['label-camera_name'], $order_by, $order);
	echo th_order_by('ip_address', $text['label-ip_address'], $order_by, $order);
	echo th_order_by('port', $text['label-port'], $order_by, $order, null, "class='hide-xs'");
	echo th_order_by('mac_address', $text['label-mac_address'], $order_by, $order, null, "class='hide-sm-dn'");
	echo th_order_by('location', $text['label-location'], $order_by, $order, null, "class='hide-sm-dn'");
	echo th_order_by('username', $text['label-username'], $order_by, $order, null, "class='hide-md-dn'");
	echo th_order_by('enabled', $text['label-enabled'], $order_by, $order, null, "class='center'");
	echo th_order_by('description', $text['label-description'], $order_by, $order, null, "class='hide-sm-dn'");
	echo "	<th class='center' style='min-width: 120px;'>".$text['label-actions']."</th>\n";
	echo "</tr>\n";

	if (is_array($cameras) && @sizeof($cameras) != 0) {
		$x = 0;
		foreach($cameras as $row) {
			$list_row_url = '';
			if (permission_exists('camera_edit')) {
				$list_row_url = "camera_edit.php?id=".urlencode($row['camera_uuid']).(is_numeric($page) ? '&page='.urlencode($page) : null);
			}

			echo "<tr class='list-row' href='".$list_row_url."'>\n";
			if (permission_exists('camera_edit') || permission_exists('camera_delete')) {
				echo "	<td class='checkbox'>\n";
				echo "		<input type='checkbox' name='cameras[$x][checked]' id='checkbox_".$x."' value='true' onclick=\"checkbox_on_change(this); if (!this.checked) { document.getElementById('checkbox_all').checked = false; }\">\n";
				echo "		<input type='hidden' name='cameras[$x][uuid]' value='".escape($row['camera_uuid'])."' />\n";
				echo "	</td>\n";
			}
			echo "	<td>";
			if (permission_exists('camera_edit')) {
				echo "<a href='".$list_row_url."' title=\"".$text['button-edit']."\">".escape($row['camera_name'])."</a>";
			}
			else {
				echo escape($row['camera_name']);
			}
			echo "	</td>\n";
			echo "	<td class='no-wrap'>".escape($row['ip_address'])."&nbsp;</td>\n";
			echo "	<td class='hide-xs no-wrap'>".escape($row['port'] ?? $default_port)."&nbsp;</td>\n";
			echo "	<td class='hide-sm-dn no-wrap'>".escape($row['mac_address'])."&nbsp;</td>\n";
			echo "	<td class='hide-sm-dn'>".escape($row['location'])."&nbsp;</td>\n";
			echo "	<td class='hide-md-dn'>".escape($row['username'])."&nbsp;</td>\n";
			if (permission_exists('camera_edit')) {
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
			if (permission_exists('camera_edit')) {
				echo button::create(['type'=>'button','label'=>$text['button-edit'],'title'=>$text['button-edit'],'icon'=>$settings->get('theme', 'button_icon_edit'),'link'=>$list_row_url]);
			}
			if (permission_exists('camera_delete')) {
				$delete_url = 'cameras.php?action=delete&id='.urlencode($row['camera_uuid']);
				$delete_url .= (is_numeric($page) ? '&page='.urlencode($page) : '');
				$delete_url .= (!empty($search) ? '&search='.urlencode($search) : '');
				echo button::create(['type'=>'button','label'=>$text['button-delete'],'title'=>$text['button-delete'],'icon'=>$settings->get('theme', 'button_icon_delete'),'onclick'=>"if (confirm('".$text['confirm-delete']."')) { window.location.href='".$delete_url."'; }"]);
			}
			// Access button - connect to camera web interface
			$camera_port = !empty($row['port']) ? $row['port'] : $default_port;
			$access_url = 'http://'.$row['ip_address'].':'.$camera_port;
			echo button::create(['type'=>'button','label'=>$text['button-access'],'title'=>$text['button-access'],'icon'=>'globe','link'=>$access_url,'target'=>'_blank']);
			// Play button - open video player modal
			$has_password = !empty($row['password']);
			$play_disabled = $has_password ? '' : 'disabled="disabled"';
			$play_title = $has_password ? $text['button-play'] : $text['label-password_not_set'];
			echo button::create(['type'=>'button','label'=>$text['button-play'],'title'=>$play_title,'icon'=>'fa-play','id'=>'btn_play_'.$row['camera_uuid'],'onclick'=>$has_password ? "playCamera('".$row['camera_uuid']."')" : null,'style'=>$has_password ? null : 'opacity: 0.5; cursor: not-allowed;']);
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

// Camera player modal
	echo "<div id='camera-player-modal' class='modal' style='display:none; position: fixed !important; top: 0 !important; left: 0 !important; width: 100% !important; height: 100% !important; z-index: 99999 !important; background: rgba(0,0,0,0.5);'>\n";
	echo "	<div class='modal-dialog' id='camera-modal-dialog' style='width: 90%; max-width: 1400px; height: 90vh; position: relative; margin: 5vh auto; background: #fff; border-radius: 4px; box-shadow: 0 4px 20px rgba(0,0,0,0.3); display: flex; flex-direction: column;'>\n";
	echo "		<div class='modal-content' style='height: 100%; display: flex; flex-direction: column;'>\n";
	echo "			<div class='modal-header'>\n";
	echo "				<h4 class='modal-title'><i class='fa fa-video-camera' style='margin-right: 10px;'></i><span id='player-title'>".$text['button-play']."</span></h4>\n";
	echo "				<div style='float: right;'>\n";
	echo "					<button type='button' class='btn btn-default' id='btn-fullscreen' onclick='toggleFullscreen()' title='".$text['button-fullscreen']."'>\n";
	echo "						<i class='fa fa-expand'></i> ".$text['button-fullscreen']."\n";
	echo "					</button>\n";
	echo "					<button type='button' class='btn btn-default' onclick='closePlayerModal()' title='".$text['button-close']."'>\n";
	echo "						<i class='fa fa-times'></i>\n";
	echo "					</button>\n";
	echo "				</div>\n";
	echo "			</div>\n";
	echo "			<div class='modal-body' style='flex: 1; padding: 0; overflow: hidden; background: #000;'>\n";
	echo "				<iframe id='player-iframe' style='width:100%; height:100%; border:none;'></iframe>\n";
	echo "			</div>\n";
	echo "		</div>\n";
	echo "	</div>\n";
	echo "</div>\n";

// Camera player JavaScript
	echo "<script>\n";
	echo "// Camera data cache\n";
	echo "var cameraData = {};\n";

	// Build camera data array with plain text passwords
	if (is_array($cameras)) {
		foreach ($cameras as $camera) {
			echo "cameraData['".$camera['camera_uuid']."'] = {\n";
			echo "	uuid: '".$camera['camera_uuid']."',\n";
			echo "	name: '".addslashes($camera['camera_name'])."',\n";
			echo "	ip: '".$camera['ip_address']."',\n";
			echo "	username: '".$camera['username']."',\n";
			echo "	password: '".$camera['password']."'\n";
			echo "};\n";
		}
	}

	echo "var currentPlayerUrl = '';\n";
	echo "var isFullscreen = false;\n\n";

	echo "function playCamera(uuid) {\n";
	echo "	var camera = cameraData[uuid];\n";
	echo "	if (!camera) {\n";
	echo "		alert('摄像头信息不存在');\n";
	echo "		return;\n";
	echo "	}\n";
	echo "	if (!camera.password) {\n";
	echo "		alert('未设置密码，无法播放');\n";
	echo "		return;\n";
	echo "	}\n\n";

	// Build RTSP URL
	echo "	// Build RTSP URL: rtsp://username:password@ip/streaming/channels/101\n";
	echo "	var rtspUrl = 'rtsp://' + camera.username + ':' + camera.password + '@' + camera.ip + '/streaming/channels/101';\n\n";

	// Build player URL
	echo "	// Build player URL from configuration\n";
	echo "	var playerBaseUrl = '".addslashes($player_url)."';\n";
	echo "	currentPlayerUrl = playerBaseUrl + '?url=' + encodeURIComponent(rtspUrl);\n\n";

	// Update title and show modal
	echo "	document.getElementById('player-title').textContent = camera.name;\n";
	echo "	document.getElementById('player-iframe').src = currentPlayerUrl;\n";
	echo "	document.getElementById('camera-player-modal').style.display = 'block';\n";
	echo "}\n\n";

	// Close modal function
	echo "function closePlayerModal() {\n";
	echo "	// Exit fullscreen if in fullscreen mode\n";
	echo "	if (isFullscreen) {\n";
	echo "		toggleFullscreen();\n";
	echo "	}\n";
	echo "	// Clear iframe to stop video\n";
	echo "	document.getElementById('player-iframe').src = '';\n";
	echo "	currentPlayerUrl = '';\n";
	echo "	// Hide modal\n";
	echo "	document.getElementById('camera-player-modal').style.display = 'none';\n";
	echo "}\n\n";

	// Toggle fullscreen function
	echo "function toggleFullscreen() {\n";
	echo "	var modalDialog = document.getElementById('camera-modal-dialog');\n";
	echo "	var btnFullscreen = document.getElementById('btn-fullscreen');\n\n";

	echo "	if (!isFullscreen) {\n";
	echo "		// Enter fullscreen\n";
	echo "		modalDialog.style.width = '100%';\n";
	echo "		modalDialog.style.height = '100vh';\n";
	echo "		modalDialog.style.margin = '0';\n";
	echo "		modalDialog.style.maxWidth = 'none';\n";
	echo "		btnFullscreen.innerHTML = '<i class=\"fa fa-compress\"></i> ".addslashes($text['button-exit-fullscreen'])."';\n";
	echo "		isFullscreen = true;\n";
	echo "	} else {\n";
	echo "		// Exit fullscreen\n";
	echo "		modalDialog.style.width = '90%';\n";
	echo "		modalDialog.style.height = '90vh';\n";
	echo "		modalDialog.style.margin = '5vh auto';\n";
	echo "		modalDialog.style.maxWidth = '1400px';\n";
	echo "		btnFullscreen.innerHTML = '<i class=\"fa fa-expand\"></i> ".addslashes($text['button-fullscreen'])."';\n";
	echo "		isFullscreen = false;\n";
	echo "	}\n";
	echo "}\n\n";

	// Close modal on Escape key
	echo "document.addEventListener('keydown', function(e) {\n";
	echo "	if (e.key === 'Escape') {\n";
	echo "		closePlayerModal();\n";
	echo "	}\n";
	echo "});\n";

	// Move modal to body level after page loads
	echo "document.addEventListener('DOMContentLoaded', function() {\n";
	echo "	var modal = document.getElementById('camera-player-modal');\n";
	echo "	if (modal && modal.parentNode !== document.body) {\n";
	echo "		document.body.appendChild(modal);\n";
	echo "	}\n";
	echo "});\n";

	echo "</script>\n";

	unset($cameras);

//show the footer
	require_once "resources/footer.php";

?>
