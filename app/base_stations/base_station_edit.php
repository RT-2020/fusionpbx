<?php
/*
	FusionPBX
	Version: MPL 1.1

	Base Station - Edit Page
	Add or edit base station details with password encryption
*/

//includes files
	require_once dirname(__DIR__, 2) . "/resources/require.php";
	require_once "resources/check_auth.php";

//check permissions
	if (permission_exists('base_station_add') || permission_exists('base_station_edit')) {
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
		$base_station_uuid = $_REQUEST['id'];
	}
	else {
		$action = "add";
	}

//get the http post data
	if (!empty($_POST)) {
		//get the values from the HTTP POST and set them as php variables
		$station_name = $_POST['station_name'] ?? null;
		$ip_address = $_POST['ip_address'] ?? null;
		$mac_address = $_POST['mac_address'] ?? null;
		$deploy_location = $_POST['deploy_location'] ?? null;
		$username = $_POST['username'] ?? null;
		$password = $_POST['password'] ?? null;
		$enabled = $_POST['enabled'] ?? 'true';
		$description = $_POST['description'] ?? null;
	}

//process the http post
	if (!empty($_POST) && empty($_POST["persistformvar"])) {

		//get the uuid from the POST
		if ($action == "update") {
			$base_station_uuid = $_POST['base_station_uuid'];
		}

		//validate the token
		$token = new token;
		if (!$token->validate($_SERVER['PHP_SELF'])) {
			message::add($text['message-invalid_token'],'negative');
			header('Location: base_stations.php');
			exit;
		}

		//check for required fields
		if (empty($station_name)) {
			message::add($text['message-required'],'negative');
			header('Location: base_station_edit.php'.($action == 'update' ? '?id='.urlencode($base_station_uuid) : null));
			exit;
		}
		if (empty($ip_address)) {
			message::add($text['message-required'],'negative');
			header('Location: base_station_edit.php'.($action == 'update' ? '?id='.urlencode($base_station_uuid) : null));
			exit;
		}

		//validate IP address
		if (!filter_var($ip_address, FILTER_VALIDATE_IP)) {
			message::add($text['message-invalid_ip'],'negative');
			header('Location: base_station_edit.php'.($action == 'update' ? '?id='.urlencode($base_station_uuid) : null));
			exit;
		}

		//validate MAC address if provided
		if (!empty($mac_address) && !is_mac($mac_address)) {
			message::add($text['message-invalid_mac'],'negative');
			header('Location: base_station_edit.php'.($action == 'update' ? '?id='.urlencode($base_station_uuid) : null));
			exit;
		}

		//check for duplicates
		if ($action == 'add') {
			$sql = "select count(*) from v_base_stations ";
			$sql .= "where domain_uuid = :domain_uuid ";
			$sql .= "and ip_address = :ip_address ";
			$parameters['domain_uuid'] = $_SESSION['domain_uuid'];
			$parameters['ip_address'] = $ip_address;
			$num_rows = $database->select($sql, $parameters, 'column');
			if ($num_rows > 0) {
				message::add($text['message-duplicate'],'negative');
				header('Location: base_station_edit.php');
				exit;
			}
			unset($sql, $parameters, $num_rows);
		}

		//encrypt password if provided
		$password_encrypted = null;
		if (!empty($password)) {
			$secret_key = $settings->get('base_station', 'secret_key', '');
			if (!empty($secret_key)) {
				$password_encrypted = encrypt($secret_key, $password);
			}
		}
		elseif ($action == 'update') {
			// Keep existing password if not changed
			$sql = "select password_encrypted from v_base_stations ";
			$sql .= "where base_station_uuid = :base_station_uuid ";
			$parameters['base_station_uuid'] = $base_station_uuid;
			$password_encrypted = $database->select($sql, $parameters, 'column');
			unset($sql, $parameters);
		}

		//build the array
		$x = 0;
		$array['base_stations'][$x]['domain_uuid'] = $_SESSION['domain_uuid'];
		$array['base_stations'][$x]['station_name'] = $station_name;
		$array['base_stations'][$x]['ip_address'] = $ip_address;
		$array['base_stations'][$x]['mac_address'] = !empty($mac_address) ? $mac_address : null;
		$array['base_stations'][$x]['deploy_location'] = $deploy_location;
		$array['base_stations'][$x]['username'] = $username;
		$array['base_stations'][$x]['password_encrypted'] = $password_encrypted;
		$array['base_stations'][$x]['enabled'] = $enabled;
		$array['base_stations'][$x]['description'] = $description;

		if ($action == "add") {
			$base_station_uuid = uuid();
			$array['base_stations'][$x]['base_station_uuid'] = $base_station_uuid;
			$array['base_stations'][$x]['insert_date'] = 'now()';
			$array['base_stations'][$x]['insert_user'] = $_SESSION['user_uuid'];
			$message = $text['message-add'];
		}

		if ($action == "update") {
			$array['base_stations'][$x]['base_station_uuid'] = $base_station_uuid;
			$array['base_stations'][$x]['update_date'] = 'now()';
			$array['base_stations'][$x]['update_user'] = $_SESSION['user_uuid'];
			$message = $text['message-update'];
		}

		//save to the database
		if (!empty($array)) {

			//grant temporary permissions
			$p = permissions::new();
			$p->add('base_station_add', 'temp');
			$p->add('base_station_edit', 'temp');

			//execute
			$database->app_name = 'base_stations';
			$database->app_uuid = 'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d';
			$database->save($array);
			unset($array);

			//revoke temporary permissions
			$p->delete('base_station_add', 'temp');
			$p->delete('base_station_edit', 'temp');

			//set message
			message::add($message);

			//redirect the user
			header('Location: base_stations.php');
			exit;
		}
	}

//pre-populate the form
	if (!empty($_GET) && empty($_POST["persistformvar"])) {
		$sql = "select * from v_base_stations ";
		$sql .= "where base_station_uuid = :base_station_uuid ";
		$parameters['base_station_uuid'] = $base_station_uuid;
		$row = $database->select($sql, $parameters, 'row');
		if (!empty($row)) {
			$station_name = $row['station_name'];
			$ip_address = $row['ip_address'];
			$mac_address = $row['mac_address'];
			$deploy_location = $row['deploy_location'];
			$username = $row['username'];
			$password_encrypted = $row['password_encrypted'];
			$enabled = $row['enabled'];
			$description = $row['description'];
		}
		unset($sql, $parameters, $row);
	}

//create token
	$object = new token;
	$token = $object->create($_SERVER['PHP_SELF']);

//include the header
	$document['title'] = $text['title-base_station'];
	require_once "resources/header.php";

//show the content
	echo "<form method='post' name='frm' id='frm'>\n";

	echo "<div class='action_bar' id='action_bar'>\n";
	echo "	<div class='heading'><b>".$text['header-base_station']."</b></div>\n";
	echo "	<div class='actions'>\n";
	echo button::create(['type'=>'button','label'=>$text['button-back'],'icon'=>$settings->get('theme','button_icon_back'),'id'=>'btn_back','style'=>'margin-right: 15px;','link'=>'base_stations.php']);
	echo button::create(['type'=>'submit','label'=>$text['button-save'],'icon'=>$settings->get('theme','button_icon_save'),'id'=>'btn_save']);
	echo "	</div>\n";
	echo "	<div style='clear: both;'></div>\n";
	echo "</div>\n";

	echo "<div class='card'>\n";
	echo "<table class='tr_hover' width='100%' border='0' cellpadding='0' cellspacing='0'>\n";

	echo "<tr>\n";
	echo "<td class='vncellreq' valign='top' align='left' nowrap='nowrap'>\n";
	echo "	".$text['label-station_name']."\n";
	echo "</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "	<input class='formfld' type='text' name='station_name' maxlength='255' value=\"".escape($station_name ?? '')."\" required='required'>\n";
	echo "<br />\n";
	echo $text['description-station_name']."\n";
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
	echo "	".$text['label-deploy_location']."\n";
	echo "</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "	<input class='formfld' type='text' name='deploy_location' maxlength='255' value=\"".escape($deploy_location ?? '')."\">\n";
	echo "<br />\n";
	echo $text['description-deploy_location']."\n";
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
	echo "	<input class='formfld' type='password' name='password' maxlength='255' value='' autocomplete='new-password'>\n";
	echo "<br />\n";
	if ($action == 'update' && !empty($password_encrypted)) {
		echo "<span style='color: #666;'>".$text['label-password_set']."</span><br />\n";
	}
	echo $text['description-password']."\n";
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

	echo "<input type='hidden' name='base_station_uuid' value='".escape($base_station_uuid ?? '')."'>\n";
	echo "<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>\n";

	echo "</form>\n";

//include the footer
	require_once "resources/footer.php";

?>

