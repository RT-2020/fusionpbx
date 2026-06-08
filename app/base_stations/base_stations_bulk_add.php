<?php
/*
	FusionPBX
	Version: MPL 1.1

	Base Stations - Bulk Add Page
	Add multiple base stations from IP ranges in a single request
*/

//includes files
	require_once dirname(__DIR__, 2) . "/resources/require.php";
	require_once "resources/check_auth.php";
	require_once __DIR__ . "/resources/classes/base_station.php";

//check permissions
	if (permission_exists('base_station_add')) {
		//access granted
	}
	else {
		echo "access denied";
		exit;
	}

//add multi-lingual support
	$language = new text;
	$text = $language->get();

//initialize
	$database = new database;
	$settings = new settings(['database' => $database]);
	$station_name_prefix = $_POST['station_name_prefix'] ?? null;
	$ip_range_input = $_POST['ip_range_input'] ?? null;
	$deploy_location = $_POST['deploy_location'] ?? null;
	$username = $_POST['username'] ?? null;
	$password = $_POST['password'] ?? null;
	$enabled = $_POST['enabled'] ?? 'true';
	$power_source = $_POST['power_source'] ?? 'ac';
	$description = $_POST['description'] ?? null;

//process the http post
	if (!empty($_POST) && empty($_POST["persistformvar"])) {

		//validate the token
		$token = new token;
		if (!$token->validate($_SERVER['PHP_SELF'])) {
			message::add($text['message-invalid_token'],'negative');
			header('Location: base_stations.php');
			exit;
		}

		$ip_range_input = trim((string) $ip_range_input);
		$station_name_prefix = trim((string) $station_name_prefix);
		$deploy_location = trim((string) $deploy_location);
		$username = trim((string) $username);
		$description = trim((string) $description);
		$power_source = strtolower(trim((string) $power_source));
		if (!in_array($power_source, ['ac', 'dc'], true)) {
			$power_source = 'ac';
		}
		$is_valid = true;

		if ($ip_range_input === '') {
			message::add($text['message-required'],'negative');
			$is_valid = false;
		}

		$expanded_input = base_station::expand_ip_input($ip_range_input);
		$ip_addresses = $expanded_input['ip_addresses'];
		$invalid_tokens = $expanded_input['invalid_tokens'];

		if (empty($ip_addresses)) {
			message::add($text['message-invalid_ip_range'],'negative');
			$is_valid = false;
		}

		if (!empty($invalid_tokens)) {
			message::add(sprintf($text['message-invalid_ip_range_tokens'], implode(', ', $invalid_tokens)),'negative');
			$is_valid = false;
		}

		if ($is_valid) {
			$parameters = [
				'domain_uuid' => $_SESSION['domain_uuid'],
			];
			$placeholders = [];
			foreach ($ip_addresses as $index => $ip_address) {
				$placeholder = 'ip_'.$index;
				$placeholders[] = ':'.$placeholder;
				$parameters[$placeholder] = $ip_address;
			}

			$sql = "select cast(ip_address as text) as ip_address from v_base_stations ";
			$sql .= "where domain_uuid = :domain_uuid ";
			$sql .= "and cast(ip_address as text) in (".implode(', ', $placeholders).") ";
			$existing_rows = $database->select($sql, $parameters, 'all');
			unset($sql, $parameters);

			$existing_ips = [];
			if (is_array($existing_rows)) {
				foreach ($existing_rows as $row) {
					$existing_ips[$row['ip_address']] = true;
				}
			}
			unset($existing_rows);

			$password_encrypted = null;
			if ($password !== null && $password !== '') {
				$secret_key = $settings->get('base_station', 'secret_key', '');
				if (!empty($secret_key)) {
					$password_encrypted = encrypt($secret_key, $password);
				}
			}

			$x = 0;
			$created_count = 0;
			$skipped_count = 0;
			foreach ($ip_addresses as $ip_address) {
				if (isset($existing_ips[$ip_address])) {
					$skipped_count++;
					continue;
				}

				$station_name = ($station_name_prefix !== '')
					? $station_name_prefix.' '.$ip_address
					: $ip_address;

				$array['base_stations'][$x]['base_station_uuid'] = uuid();
				$array['base_stations'][$x]['domain_uuid'] = $_SESSION['domain_uuid'];
				$array['base_stations'][$x]['station_name'] = $station_name;
				$array['base_stations'][$x]['ip_address'] = $ip_address;
				$array['base_stations'][$x]['mac_address'] = null;
				$array['base_stations'][$x]['deploy_location'] = ($deploy_location !== '') ? $deploy_location : null;
				$array['base_stations'][$x]['username'] = ($username !== '') ? $username : null;
				$array['base_stations'][$x]['password_encrypted'] = $password_encrypted;
				$array['base_stations'][$x]['enabled'] = $enabled;
				$array['base_stations'][$x]['power_source'] = $power_source;
				$array['base_stations'][$x]['description'] = ($description !== '') ? $description : null;
				$array['base_stations'][$x]['insert_date'] = 'now()';
				$array['base_stations'][$x]['insert_user'] = $_SESSION['user_uuid'];

				$created_count++;
				$x++;
			}

			if (!empty($array['base_stations'])) {
				$p = permissions::new();
				$p->add('base_station_add', 'temp');

				$database->app_name = 'base_stations';
				$database->app_uuid = 'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d';
				$database->save($array);
				unset($array);

				$p->delete('base_station_add', 'temp');

				message::add(sprintf($text['message-bulk_add_result'], $created_count, $skipped_count));
				header('Location: base_stations.php');
				exit;
			}
			else {
				message::add(sprintf($text['message-bulk_add_result'], 0, $skipped_count),'negative');
			}
		}
	}

//create token
	$object = new token;
	$token = $object->create($_SERVER['PHP_SELF']);

//include the header
	$document['title'] = $text['title-base_stations_bulk_add'];
	require_once "resources/header.php";

//show the content
	echo "<form method='post' name='frm' id='frm'>\n";

	echo "<div class='action_bar' id='action_bar'>\n";
	echo "	<div class='heading'><b>".$text['header-base_stations_bulk_add']."</b></div>\n";
	echo "	<div class='actions'>\n";
	echo button::create(['type'=>'button','label'=>$text['button-back'],'icon'=>$settings->get('theme','button_icon_back'),'id'=>'btn_back','style'=>'margin-right: 15px;','link'=>'base_stations.php']);
	echo button::create(['type'=>'submit','label'=>$text['button-save'],'icon'=>$settings->get('theme','button_icon_save'),'id'=>'btn_save']);
	echo "	</div>\n";
	echo "	<div style='clear: both;'></div>\n";
	echo "</div>\n";

	echo $text['description-base_stations_bulk_add']."\n";
	echo "<br /><br />\n";

	echo "<div class='card'>\n";
	echo "<table class='tr_hover' width='100%' border='0' cellpadding='0' cellspacing='0'>\n";

	echo "<tr>\n";
	echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>".$text['label-station_name_prefix']."</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "	<input class='formfld' type='text' name='station_name_prefix' maxlength='255' value=\"".escape($station_name_prefix ?? '')."\">\n";
	echo "<br />\n";
	echo $text['description-station_name_prefix']."\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "<tr>\n";
	echo "<td class='vncellreq' valign='top' align='left' nowrap='nowrap'>".$text['label-ip_range_input']."</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "	<textarea class='formfld' name='ip_range_input' rows='8' required='required'>".escape($ip_range_input ?? '')."</textarea>\n";
	echo "<br />\n";
	echo $text['description-ip_range_input']."\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "<tr>\n";
	echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>".$text['label-deploy_location']."</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "	<input class='formfld' type='text' name='deploy_location' maxlength='255' value=\"".escape($deploy_location ?? '')."\">\n";
	echo "<br />\n";
	echo $text['description-deploy_location']."\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "<tr>\n";
	echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>".$text['label-username']."</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "	<input class='formfld' type='text' name='username' maxlength='255' value=\"".escape($username ?? '')."\" autocomplete='off'>\n";
	echo "<br />\n";
	echo $text['description-username']."\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "<tr>\n";
	echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>".$text['label-password']."</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "	<input class='formfld' type='password' name='password' maxlength='255' value='' autocomplete='new-password'>\n";
	echo "<br />\n";
	echo $text['description-password']."\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "<tr>\n";
	echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>".$text['label-enabled']."</td>\n";
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
	echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>".$text['label-power_source']."</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "	<select class='formfld' name='power_source'>\n";
	echo "		<option value='ac' ".(($power_source ?? 'ac') == 'ac' ? "selected='selected'" : null).">".$text['label-ac']."</option>\n";
	echo "		<option value='dc' ".(($power_source ?? 'ac') == 'dc' ? "selected='selected'" : null).">".$text['label-dc']."</option>\n";
	echo "	</select>\n";
	echo "<br />\n";
	echo $text['description-power_source']."\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "<tr>\n";
	echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>".$text['label-description']."</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "	<textarea class='formfld' name='description' rows='4'>".escape($description ?? '')."</textarea>\n";
	echo "<br />\n";
	echo $text['description-description']."\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "</table>\n";
	echo "</div>\n";

	echo "<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>\n";
	echo "</form>\n";

//include the footer
	require_once "resources/footer.php";

?>
