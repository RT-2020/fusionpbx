<?php
/*
	FusionPBX
	Version: MPL 1.1

	Camera - Edit Page
	Add or edit camera details with password encryption
*/

//includes files
	require_once dirname(__DIR__, 2) . "/resources/require.php";
	require_once "resources/check_auth.php";

//check permissions
	if (permission_exists('camera_add') || permission_exists('camera_edit')) {
		//access granted
	}
	else {
		echo "access denied";
		exit;
	}

//add multi-lingual support
	$language = new text;
	$text = $language->get();

//initialize the database object
	$database = new database;

//set the action as an add or an update
	if (!empty($_REQUEST['id']) && is_uuid($_REQUEST['id'])) {
		$action = "update";
		$camera_uuid = $_REQUEST['id'];
	}
	else {
		$action = "add";
	}

//get the http post data
	if (!empty($_POST)) {
		//get the values from the HTTP POST and set them as php variables
		$camera_name = $_POST['camera_name'] ?? null;
		$ip_address = $_POST['ip_address'] ?? null;
		$port = $_POST['port'] ?? null;
		$mac_address = $_POST['mac_address'] ?? null;
		$location = $_POST['location'] ?? null;
		$username = $_POST['username'] ?? null;
		$password = $_POST['password'] ?? null;
		$rtsp_url = $_POST['rtsp_url'] ?? null;
		$enabled = $_POST['enabled'] ?? 'true';
		$description = $_POST['description'] ?? null;
	}

//process the http post
	if (!empty($_POST) && empty($_POST["persistformvar"])) {

		//get the uuid from the POST
		if ($action == "update") {
			$camera_uuid = $_POST['camera_uuid'];
		}

		//validate the token
		$token = new token;
		if (!$token->validate($_SERVER['PHP_SELF'])) {
			message::add($text['message-invalid_token'],'negative');
			header('Location: cameras.php');
			exit;
		}

		//check for required fields
		if (empty($camera_name)) {
			message::add($text['message-required'],'negative');
			header('Location: camera_edit.php'.($action == 'update' ? '?id='.urlencode($camera_uuid) : null));
			exit;
		}
		if (empty($ip_address)) {
			message::add($text['message-required'],'negative');
			header('Location: camera_edit.php'.($action == 'update' ? '?id='.urlencode($camera_uuid) : null));
			exit;
		}

		//validate IP address
		if (!filter_var($ip_address, FILTER_VALIDATE_IP)) {
			message::add($text['message-invalid_ip'],'negative');
			header('Location: camera_edit.php'.($action == 'update' ? '?id='.urlencode($camera_uuid) : null));
			exit;
		}

		//validate port number
		if (!empty($port)) {
			if (!is_numeric($port) || $port < 1 || $port > 65535) {
				message::add($text['message-invalid_port'],'negative');
				header('Location: camera_edit.php'.($action == 'update' ? '?id='.urlencode($camera_uuid) : null));
				exit;
			}
		}

		//validate MAC address if provided
		if (!empty($mac_address) && !is_mac($mac_address)) {
			message::add($text['message-invalid_mac'],'negative');
			header('Location: camera_edit.php'.($action == 'update' ? '?id='.urlencode($camera_uuid) : null));
			exit;
		}

		//check for duplicates
		if ($action == 'add') {
			$sql = "select count(*) from v_cameras ";
			$sql .= "where domain_uuid = :domain_uuid ";
			$sql .= "and ip_address = :ip_address ";
			$parameters['domain_uuid'] = $_SESSION['domain_uuid'];
			$parameters['ip_address'] = $ip_address;
			$num_rows = $database->select($sql, $parameters, 'column');
			if ($num_rows > 0) {
				message::add($text['message-duplicate'],'negative');
				header('Location: camera_edit.php');
				exit;
			}
			unset($sql, $parameters, $num_rows);
		}
		else {
			// Check for duplicates excluding current record
			$sql = "select count(*) from v_cameras ";
			$sql .= "where domain_uuid = :domain_uuid ";
			$sql .= "and ip_address = :ip_address ";
			$sql .= "and camera_uuid != :camera_uuid ";
			$parameters['domain_uuid'] = $_SESSION['domain_uuid'];
			$parameters['ip_address'] = $ip_address;
			$parameters['camera_uuid'] = $camera_uuid;
			$num_rows = $database->select($sql, $parameters, 'column');
			if ($num_rows > 0) {
				message::add($text['message-duplicate'],'negative');
				header('Location: camera_edit.php?id='.urlencode($camera_uuid));
				exit;
			}
			unset($sql, $parameters, $num_rows);
		}

		//build the array
		$x = 0;
		$array['cameras'][$x]['domain_uuid'] = $_SESSION['domain_uuid'];
		$array['cameras'][$x]['camera_name'] = $camera_name;
		$array['cameras'][$x]['ip_address'] = $ip_address;
		$array['cameras'][$x]['port'] = !empty($port) ? $port : null;
		$array['cameras'][$x]['mac_address'] = !empty($mac_address) ? $mac_address : null;
		$array['cameras'][$x]['location'] = $location;
		$array['cameras'][$x]['username'] = $username;
		$array['cameras'][$x]['password'] = $password;  // 存储明文密码
		$array['cameras'][$x]['rtsp_url'] = $rtsp_url;
		$array['cameras'][$x]['enabled'] = $enabled;
		$array['cameras'][$x]['description'] = $description;

		if ($action == "add") {
			$camera_uuid = uuid();
			$array['cameras'][$x]['camera_uuid'] = $camera_uuid;
			$array['cameras'][$x]['insert_date'] = 'now()';
			$array['cameras'][$x]['insert_user'] = $_SESSION['user_uuid'];
			$message = $text['message-add'];
		}

		if ($action == "update") {
			$array['cameras'][$x]['camera_uuid'] = $camera_uuid;
			// update_date 和 update_user 由 database->save() 自动添加
			$message = $text['message-update'];
		}

		//save to the database
		if (!empty($array)) {

			//grant temporary permissions
			$p = permissions::new();
			$p->add('camera_add', 'temp');
			$p->add('camera_edit', 'temp');

			//execute
			$database->app_name = 'cameras';
			$database->app_uuid = 'c4d5e6f7-a8b9-4c0d-1e2f-3a4b5c6d7e8f';
			$database->save($array);
			unset($array);

			//revoke temporary permissions
			$p->delete('camera_add', 'temp');
			$p->delete('camera_edit', 'temp');

			//set message
			message::add($message);

			//redirect the user
			header('Location: cameras.php');
			exit;
		}
	}

//pre-populate the form
	if (!empty($_GET) && empty($_POST["persistformvar"])) {
		$sql = "select * from v_cameras ";
		$sql .= "where camera_uuid = :camera_uuid ";
		$parameters['camera_uuid'] = $camera_uuid;
		$row = $database->select($sql, $parameters, 'row');
		if (!empty($row)) {
			$camera_name = $row['camera_name'];
			$ip_address = $row['ip_address'];
			$port = $row['port'];
			$mac_address = $row['mac_address'];
			$location = $row['location'];
			$username = $row['username'];
			$password = $row['password'] ?? '';
			$rtsp_url = $row['rtsp_url'];
			$enabled = $row['enabled'];
			$description = $row['description'];
		}
		unset($sql, $parameters, $row);
	}

//get default port
	$default_port = $settings->get('camera', 'default_port', 80);

//create token
	$object = new token;
	$token = $object->create($_SERVER['PHP_SELF']);

//include the header
	$document['title'] = $text['title-camera'];
	require_once "resources/header.php";

//show the content
	echo "<form method='post' name='frm' id='frm'>\n";

	echo "<div class='action_bar' id='action_bar'>\n";
	echo "	<div class='heading'><b>".$text['header-camera']."</b></div>\n";
	echo "	<div class='actions'>\n";
	echo button::create(['type'=>'button','label'=>$text['button-back'],'icon'=>$settings->get('theme','button_icon_back'),'id'=>'btn_back','style'=>'margin-right: 15px;','link'=>'cameras.php']);
	echo button::create(['type'=>'submit','label'=>$text['button-save'],'icon'=>$settings->get('theme','button_icon_save'),'id'=>'btn_save']);
	echo "	</div>\n";
	echo "	<div style='clear: both;'></div>\n";
	echo "</div>\n";

	echo "<div class='card'>\n";
	echo "<table class='tr_hover' width='100%' border='0' cellpadding='0' cellspacing='0'>\n";

	echo "<tr>\n";
	echo "<td class='vncellreq' valign='top' align='left' nowrap='nowrap'>\n";
	echo "	".$text['label-camera_name']."\n";
	echo "</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "	<input class='formfld' type='text' name='camera_name' maxlength='255' value=\"".escape($camera_name ?? '')."\" required='required'>\n";
	echo "<br />\n";
	echo $text['description-camera_name']."\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "<tr>\n";
	echo "<td class='vncellreq' valign='top' align='left' nowrap='nowrap'>\n";
	echo "	".$text['label-ip_address']."\n";
	echo "</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "	<input class='formfld' type='text' name='ip_address' maxlength='45' value=\"".escape($ip_address ?? '')."\" required='required'>\n";
	echo "<br />\n";
	echo $text['description-ip_address']."\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "<tr>\n";
	echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>\n";
	echo "	".$text['label-port']."\n";
	echo "</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "	<input class='formfld' type='number' name='port' min='1' max='65535' value=\"".escape($port ?? $default_port)."\">\n";
	echo "<br />\n";
	echo $text['description-port']."\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "<tr>\n";
	echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>\n";
	echo "	".$text['label-mac_address']."\n";
	echo "</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "	<input class='formfld' type='text' name='mac_address' maxlength='17' value=\"".escape($mac_address ?? '')."\">\n";
	echo "<br />\n";
	echo $text['description-mac_address']."\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "<tr>\n";
	echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>\n";
	echo "	".$text['label-location']."\n";
	echo "</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "	<input class='formfld' type='text' name='location' maxlength='255' value=\"".escape($location ?? '')."\">\n";
	echo "<br />\n";
	echo $text['description-location']."\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "<tr>\n";
	echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>\n";
	echo "	".$text['label-username']."\n";
	echo "</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "	<input class='formfld' type='text' name='username' maxlength='255' value=\"".escape($username ?? '')."\" autocomplete='off'>\n";
	echo "<br />\n";
	echo $text['description-username']."\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "<tr>\n";
	echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>\n";
	echo "	".$text['label-password']."\n";
	echo "</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "	<div style='display: flex; align-items: center; gap: 5px;'>\n";
	echo "		<input class='formfld' type='password' name='password' id='password_input' maxlength='255' value=\"".escape($password ?? '')."\" autocomplete='new-password' style='flex: 1;'>\n";
	echo "		<button type='button' class='btn btn-default' onclick='togglePasswordVisibility()' id='toggle_password_btn' title='".$text['label-show_password']."'>\n";
	echo "			<i class='fa fa-eye' id='toggle_password_icon'></i>\n";
	echo "		</button>\n";
	echo "	</div>\n";
	echo "	<script>\n";
	echo "	function togglePasswordVisibility() {\n";
	echo "		var input = document.getElementById('password_input');\n";
	echo "		var icon = document.getElementById('toggle_password_icon');\n";
	echo "		var btn = document.getElementById('toggle_password_btn');\n";
	echo "		if (input.type === 'password') {\n";
	echo "			input.type = 'text';\n";
	echo "			icon.className = 'fa fa-eye-slash';\n";
	echo "			btn.title = '".addslashes($text['label-hide_password'])."';\n";
	echo "		} else {\n";
	echo "			input.type = 'password';\n";
	echo "			icon.className = 'fa fa-eye';\n";
	echo "			btn.title = '".addslashes($text['label-show_password'])."';\n";
	echo "		}\n";
	echo "	}\n";
	echo "	</script>\n";
	echo "	<br />\n";
	echo $text['description-password']."\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "<tr>\n";
	echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>\n";
	echo "	".$text['label-rtsp_url']."\n";
	echo "</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "	<input class='formfld' type='text' name='rtsp_url' maxlength='255' value=\"".escape($rtsp_url ?? '')."\">\n";
	echo "<br />\n";
	echo $text['description-rtsp_url']."\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "<tr>\n";
	echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>\n";
	echo "	".$text['label-enabled']."\n";
	echo "</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "	<select class='formfld' name='enabled'>\n";
	echo "		<option value='true' ".($enabled == 'true' ? "selected='selected'" : null).">".$text['label-true']."</option>\n";
	echo "		<option value='false' ".($enabled == 'false' ? "selected='selected'" : null).">".$text['label-false']."</option>\n";
	echo "	</select>\n";
	echo "<br />\n";
	echo $text['description-enabled']."\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "<tr>\n";
	echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>\n";
	echo "	".$text['label-description']."\n";
	echo "</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "	<textarea class='formfld' name='description' rows='4'>".escape($description ?? '')."</textarea>\n";
	echo "<br />\n";
	echo $text['description-description']."\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "</table>\n";
	echo "</div>\n";

	echo "<input type='hidden' name='camera_uuid' value='".escape($camera_uuid ?? '')."'>\n";
	echo "<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>\n";

	echo "</form>\n";

//include the footer
	require_once "resources/footer.php";

?>
