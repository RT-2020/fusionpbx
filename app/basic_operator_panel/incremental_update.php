<?php
/*
	FusionPBX
	Version: MPL 1.1

	The contents of this file are subject to the Mozilla Public License Version
	1.1 (the "License"); you may not use this file except in compliance with
	the License. You may obtain a copy of the License at
	http://www.mozilla/MPL/

	Software distributed under the License is distributed on an "AS IS" basis,
	WITHOUT WARRANTY OF ANY KIND, either express or implied. See the License
	for the specific language governing rights and limitations under the
	License.

	The Original Code is FusionPBX

	The Initial Developer of the Original Code is
	Mark J Crane <markjcrane@fusionpbx.com>
	Portions created by the Initial Developer are Copyright (C) 2008-2023
	the Initial Developer. All Rights Reserved.

	Contributor(s):
	Mark J Crane <markjcrane@fusionpbx.com>
	Luis Daniel Lucio Quiroz <dlucio@okay.com.mx>
*/

//includes files
	require_once dirname(__DIR__, 2) . "/resources/require.php";
	require_once "resources/check_auth.php";

//check permissions
	if (!permission_exists('operator_panel_view')) {
		header('Content-Type: application/json');
		echo json_encode(['error' => 'access denied']);
		exit;
	}

//add multi-lingual support
	$language = new text;
	$text = $language->get();

//get parameters
	$domain_uuid = $_SESSION['domain_uuid'];
	$group_filter = $_GET['group'] ?? '';
	$search_filter = $_GET['filter'] ?? '';

//get extensions from the database
	$sql = "select e.extension, e.effective_caller_id_name, e.description, e.enabled, ";
	$sql .= "(select count(*) from v_extensions as e2 where e2.domain_uuid = e.domain_uuid) as extension_count ";
	$sql .= "from v_extensions as e ";
	$sql .= "where e.domain_uuid = :domain_uuid ";
	if ($group_filter != '') {
		$sql .= "and e.extension like :group_filter ";
	}
	if ($search_filter != '') {
		$sql .= "and (e.extension like :search_filter or e.effective_caller_id_name like :search_filter or e.description like :search_filter) ";
	}
	$sql .= "order by e.extension asc ";
	$parameters['domain_uuid'] = $domain_uuid;
	if ($group_filter != '') {
		$parameters['group_filter'] = '%'.$group_filter.'%';
	}
	if ($search_filter != '') {
		$parameters['search_filter'] = '%'.$search_filter.'%';
	}
	$database = new database;
	$extensions = $database->select($sql, $parameters, 'all');
	unset($sql, $parameters);

//get the call info from the database
	$sql = "select extension, call_length, call_state, caller_id_name, caller_id_number, destination ";
	$sql .= "from v_extensions ";
	$sql .= "where domain_uuid = :domain_uuid ";
	$sql .= "and (call_state is not null or call_length is not null) ";
	$parameters['domain_uuid'] = $domain_uuid;
	$database = new database;
	$call_info = $database->select($sql, $parameters, 'all');
	unset($sql, $parameters);

//create an array with the extension as the key
	if (is_array($call_info) && sizeof($call_info) != 0) {
		foreach ($call_info as $row) {
			$extensions[$row['extension']]['call_length'] = $row['call_length'];
			$extensions[$row['extension']]['call_state'] = $row['call_state'];
			$extensions[$row['extension']]['caller_id_name'] = $row['caller_id_name'];
			$extensions[$row['extension']]['caller_id_number'] = $row['caller_id_number'];
			$extensions[$row['extension']]['destination'] = $row['destination'];
		}
	}
	unset($call_info);

//prepare the extensions array for json output
	$extensions_status = [];
	if (is_array($extensions) && sizeof($extensions) != 0) {
		foreach ($extensions as $row) {
			//get the extension state
			$ext_state = $row['call_state'] ?? '';
			$call_length = $row['call_length'] ?? '';
			
			//determine the status icon
			if ($ext_state == 'ringing') {
				$status_icon = 'ringing';
			}
			elseif ($ext_state == 'active') {
				$status_icon = 'active';
			}
			elseif ($row['enabled'] == 'true') {
				$status_icon = 'registered';
			}
			else {
				$status_icon = 'offline';
			}
			
			$extensions_status[] = [
				'extension' => $row['extension'],
				'state' => $ext_state,
				'call_length' => $call_length,
				'status_icon' => $status_icon,
				'caller_id_name' => $row['caller_id_name'] ?? '',
				'caller_id_number' => $row['caller_id_number'] ?? '',
				'destination' => $row['destination'] ?? ''
			];
		}
	}

//return the json data
	header('Content-Type: application/json');
	echo json_encode([
		'success' => true,
		'extensions' => $extensions_status,
		'timestamp' => time()
	]);
?>
