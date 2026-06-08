<?php
/*
	FusionPBX
	调度终端 API 接口
*/

//includes files
require_once dirname(__DIR__, 2) . "/resources/require.php";
require_once "resources/check_auth.php";
require_once __DIR__ . "/resources/classes/operator_panel_recording.php";
require_once __DIR__ . "/resources/classes/emergency_alarm_device.php";

//check permissions
if (permission_exists('operator_panel_view')) {
	//access granted
}
else {
	echo json_encode(['error' => 'access denied']);
	exit;
}

//set content type
header('Content-Type: application/json');

//get action
$action = $_GET['action'] ?? $_POST['action'] ?? '';

function operator_panel_settings_instance() {
	static $settings = null;
	if ($settings === null) {
		$settings = new settings([
			'category' => 'operator_panel',
			'domain_uuid' => $_SESSION['domain_uuid'] ?? null,
			'user_uuid' => $_SESSION['user']['user_uuid'] ?? null,
		]);
	}
	return $settings;
}

function operator_panel_setting($subcategory, $default = null, $allow_caching = true) {
	if ($allow_caching) {
		$value = operator_panel_settings_instance()->get('operator_panel', $subcategory, $default);
	}
	else {
		$settings = new settings([
			'category' => 'operator_panel',
			'domain_uuid' => $_SESSION['domain_uuid'] ?? null,
			'user_uuid' => $_SESSION['user']['user_uuid'] ?? null,
			'allow_caching' => false,
		]);
		$value = $settings->get('operator_panel', $subcategory, $default);
	}
	return ($value === null || $value === '') ? $default : $value;
}

function operator_panel_now() {
	return date('Y-m-d H:i:s');
}

function operator_panel_current_user_uuid() {
	return $_SESSION['user']['user_uuid'] ?? null;
}

function operator_panel_json_exit(array $payload) {
	echo json_encode($payload);
	exit;
}

function operator_panel_request($key, $default = '') {
	return $_POST[$key] ?? $_GET[$key] ?? $default;
}

function operator_panel_clean_extension($extension) {
	return preg_replace('/[^0-9A-Za-z_*#-]/', '', trim((string) $extension));
}

function operator_panel_clean_uuid($uuid) {
	return preg_replace('/[^-A-Fa-f0-9]/', '', trim((string) $uuid));
}

function operator_panel_clean_conference_name($value) {
	return preg_replace('/[^0-9A-Za-z_.:-]/', '', trim((string) $value));
}

function operator_panel_clean_monitor_mode($mode) {
	$value = strtolower(trim((string) $mode));
	if (in_array($value, ['listen', 'eavesdrop'], true)) {
		return 'listen';
	}
	if (in_array($value, ['barge', 'three-way', 'three_way'], true)) {
		return 'barge';
	}
	return '';
}

function operator_panel_esl_command_success($result) {
	return $result !== false && stripos((string) $result, '-ERR') === false;
}

function operator_panel_get_channel_rows() {
	$channels_result = event_socket::api('show channels as json');
	if ($channels_result === false) {
		return [false, 'esl_unavailable', []];
	}
	$channels = json_decode($channels_result, true);
	$rows = [];
	if (is_array($channels) && isset($channels['rows']) && is_array($channels['rows'])) {
		$rows = $channels['rows'];
	}
	return [true, '', $rows];
}

function operator_panel_find_bridge_uuid(array $row) {
	foreach (['variable_bridge_uuid', 'bridge_uuid'] as $field) {
		$value = operator_panel_clean_uuid($row[$field] ?? '');
		if ($value !== '') {
			return $value;
		}
	}
	return '';
}

function operator_panel_find_target_channel(array $rows, $target_extension = '', $target_channel_uuid = '') {
	$target_extension = operator_panel_clean_extension($target_extension);
	$target_channel_uuid = operator_panel_clean_uuid($target_channel_uuid);
	$best_row = null;
	$best_score = -1;

	foreach ($rows as $row) {
		$row_uuid = operator_panel_clean_uuid($row['uuid'] ?? '');
		if ($target_channel_uuid !== '' && $row_uuid === $target_channel_uuid) {
			return $row;
		}

		$state = strtoupper((string) ($row['callstate'] ?? ''));
		if (!in_array($state, ['ACTIVE', 'HELD', 'RINGING', 'EARLY'], true)) {
			continue;
		}
		if ($target_extension === '') {
			continue;
		}

		$score = 0;
		$destination = operator_panel_clean_extension($row['destination_number'] ?? '');
		$cid_num = operator_panel_clean_extension($row['cid_num'] ?? ($row['caller_id_number'] ?? ''));
		$presence_id = (string) ($row['presence_id'] ?? '');
		$name = (string) ($row['name'] ?? '');

		if ($destination !== '' && $destination === $target_extension) {
			$score = max($score, 100);
		}
		if ($cid_num !== '' && $cid_num === $target_extension) {
			$score = max($score, 90);
		}
		if ($presence_id !== '' && strpos($presence_id, $target_extension) !== false) {
			$score = max($score, 70);
		}
		if ($name !== '' && strpos($name, '/' . $target_extension . '@') !== false) {
			$score = max($score, 60);
		}

		if ($score > $best_score) {
			$best_score = $score;
			$best_row = $row;
		}
	}

	return $best_row;
}

function operator_panel_find_extension_channel_row(array $rows, $extension = '') {
	$extension = operator_panel_clean_extension($extension);
	if ($extension === '') {
		return null;
	}

	$candidates = [$extension];
	$best_row = null;
	$best_score = -1;

	foreach ($rows as $row) {
		if (!operator_panel_channel_matches_extension($row, $candidates)) {
			continue;
		}

		$score = operator_panel_entity_state_priority(operator_panel_row_entity_state($row));
		if ($score > $best_score) {
			$best_score = $score;
			$best_row = $row;
		}
	}

	return $best_row;
}

function operator_panel_find_recording_channel_row(array $rows, $extension = '', $remote_number = '', $direction_hint = '') {
	$extension = operator_panel_clean_extension($extension);
	if ($extension === '') {
		return null;
	}

	$remote_number = operator_panel_clean_extension($remote_number);
	$direction_hint = strtolower(trim((string) $direction_hint));
	$candidates = [$extension];
	$best_row = null;
	$best_score = -1;

	foreach ($rows as $row) {
		if (!operator_panel_channel_matches_extension($row, $candidates)) {
			continue;
		}

		$score = operator_panel_entity_state_priority(operator_panel_row_entity_state($row));
		$row_direction = operator_panel_row_direction($row);
		if ($direction_hint !== '' && $row_direction === $direction_hint) {
			$score += 20;
		}

		if ($remote_number !== '') {
			$source_number = operator_panel_clean_extension($row['cid_num'] ?? ($row['caller_id_number'] ?? ''));
			$target_number = operator_panel_clean_extension($row['destination_number'] ?? ($row['callee_num'] ?? ($row['sent_callee_num'] ?? '')));
			if ($source_number === $remote_number || $target_number === $remote_number) {
				$score += 30;
			}
			if ($direction_hint === 'incoming' && $source_number === $remote_number) {
				$score += 20;
			}
			if ($direction_hint === 'outgoing' && $target_number === $remote_number) {
				$score += 20;
			}
		}

		if ($score > $best_score) {
			$best_score = $score;
			$best_row = $row;
		}
	}

	return $best_row;
}

function operator_panel_extract_conference_name_from_text($value) {
	$text = operator_panel_clean_text($value);
	if ($text === '') {
		return '';
	}
	if (preg_match('/conference:([A-Za-z0-9_.:-]+)@/i', $text, $matches)) {
		return operator_panel_clean_conference_name($matches[1]);
	}
	if (preg_match('/\b(barge-[A-Za-z0-9_.:-]+)@default\b/i', $text, $matches)) {
		return operator_panel_clean_conference_name($matches[1]);
	}
	if (preg_match('/\b(barge-[A-Za-z0-9_.:-]+)\b/i', $text, $matches)) {
		return operator_panel_clean_conference_name($matches[1]);
	}
	return '';
}

function operator_panel_row_conference_name(array $row) {
	foreach ($row as $value) {
		if (!is_scalar($value)) {
			continue;
		}
		$conference_name = operator_panel_extract_conference_name_from_text($value);
		if ($conference_name !== '') {
			return $conference_name;
		}
	}
	return '';
}

function operator_panel_collect_conference_channel_uuids(array $rows, $conference_name) {
	$conference_name = operator_panel_clean_conference_name($conference_name);
	if ($conference_name === '') {
		return [];
	}
	$conference_name_lc = strtolower($conference_name);
	$channel_uuids = [];
	foreach ($rows as $row) {
		$row_conference_name = strtolower(operator_panel_row_conference_name($row));
		if ($row_conference_name === '' || $row_conference_name !== $conference_name_lc) {
			continue;
		}
		$row_uuid = operator_panel_clean_uuid($row['uuid'] ?? '');
		if ($row_uuid !== '') {
			$channel_uuids[] = $row_uuid;
		}
		$bridge_uuid = operator_panel_find_bridge_uuid($row);
		if ($bridge_uuid !== '') {
			$channel_uuids[] = $bridge_uuid;
		}
	}
	return array_values(array_unique($channel_uuids));
}

function operator_panel_clean_text($value) {
	return trim(preg_replace('/\s+/', ' ', (string) $value));
}

function operator_panel_first_non_empty(array $values, $default = '') {
	foreach ($values as $value) {
		$text = operator_panel_clean_text($value);
		if ($text !== '') {
			return $text;
		}
	}
	return $default;
}

function operator_panel_format_duration($seconds) {
	$seconds = max(0, (int) $seconds);
	$hours = floor($seconds / 3600);
	$minutes = floor(($seconds % 3600) / 60);
	$remaining_seconds = $seconds % 60;
	return sprintf('%02d:%02d:%02d', $hours, $minutes, $remaining_seconds);
}

function operator_panel_row_direction(array $row) {
	$direction = strtolower(trim((string) ($row['direction'] ?? '')));
	if (in_array($direction, ['inbound', 'incoming'], true)) {
		return 'incoming';
	}
	if (in_array($direction, ['outbound', 'outgoing'], true)) {
		return 'outgoing';
	}
	return $direction !== '' ? $direction : 'unknown';
}

function operator_panel_row_entity_state(array $row) {
	$callstate = strtoupper(trim((string) ($row['callstate'] ?? '')));
	$state = strtoupper(trim((string) ($row['state'] ?? '')));

	if ($callstate === 'HELD' || strpos($state, 'HOLD') !== false) {
		return 'held';
	}
	if (in_array($callstate, ['RINGING', 'EARLY', 'RING_WAIT'], true) || strpos($state, 'RING') !== false) {
		return 'ringing';
	}
	if (in_array($callstate, ['ACTIVE', 'DOWN'], true)) {
		return 'busy';
	}
	if ($callstate !== '' || strpos($state, 'EXECUTE') !== false || strpos($state, 'MEDIA') !== false) {
		return 'busy';
	}
	return 'unknown';
}

function operator_panel_entity_state_priority($state) {
	switch ($state) {
		case 'error':
			return 110;
		case 'ringing':
			return 100;
		case 'busy':
			return 90;
		case 'held':
			return 80;
		case 'idle':
			return 60;
		case 'offline':
			return 40;
		case 'disabled':
			return 20;
		default:
			return 10;
	}
}

function operator_panel_matches_any_extension($value, array $candidates) {
	$clean_value = operator_panel_clean_extension($value);
	if ($clean_value === '') {
		return false;
	}
	return in_array($clean_value, $candidates, true);
}

function operator_panel_row_presence_extension(array $row) {
	$presence_id = operator_panel_clean_text($row['presence_id'] ?? '');
	if ($presence_id === '') {
		return '';
	}
	$parts = explode('@', $presence_id, 2);
	return operator_panel_clean_extension($parts[0] ?? '');
}

function operator_panel_row_presence_domain(array $row) {
	$presence_id = operator_panel_clean_text($row['presence_id'] ?? '');
	if ($presence_id === '' || strpos($presence_id, '@') === false) {
		return '';
	}
	$parts = explode('@', $presence_id, 2);
	return strtolower(trim((string) ($parts[1] ?? '')));
}

function operator_panel_channel_matches_extension(array $row, array $candidates) {
	if (empty($candidates)) {
		return false;
	}

	$presence_extension = operator_panel_row_presence_extension($row);
	$presence_domain = operator_panel_row_presence_domain($row);
	$domain_name = strtolower(trim((string) ($_SESSION['domain_name'] ?? '')));
	if (
		$presence_extension !== '' &&
		in_array($presence_extension, $candidates, true) &&
		($presence_domain === '' || $domain_name === '' || $presence_domain === $domain_name)
	) {
		return true;
	}

	$name = operator_panel_clean_text($row['name'] ?? '');
	foreach ($candidates as $candidate) {
		if ($candidate !== '' && $name !== '' && strpos($name, '/' . $candidate . '@') !== false) {
			return true;
		}
	}

	$direction = operator_panel_row_direction($row);
	$destination = operator_panel_clean_extension($row['destination_number'] ?? '');
	$cid_num = operator_panel_clean_extension($row['cid_num'] ?? ($row['caller_id_number'] ?? ''));
	$callee_num = operator_panel_clean_extension($row['callee_num'] ?? '');
	$sent_callee_num = operator_panel_clean_extension($row['sent_callee_num'] ?? '');

	if ($direction === 'incoming' && ($destination !== '' || $callee_num !== '')) {
		return in_array($destination, $candidates, true) || in_array($callee_num, $candidates, true);
	}
	if ($direction === 'outgoing' && ($cid_num !== '' || $sent_callee_num !== '')) {
		return in_array($cid_num, $candidates, true) || in_array($sent_callee_num, $candidates, true);
	}

	return false;
}

function operator_panel_build_extension_call_descriptor(array $row, array $candidates) {
	$direction = operator_panel_row_direction($row);
	$channel_uuid = operator_panel_clean_uuid($row['uuid'] ?? '');
	$bridge_uuid = operator_panel_find_bridge_uuid($row);
	$call_uuid = operator_panel_clean_uuid($row['call_uuid'] ?? '');
	if ($call_uuid === '') {
		$call_uuid = $channel_uuid;
	}

	$source_number = operator_panel_first_non_empty([
		$row['cid_num'] ?? '',
		$row['caller_id_number'] ?? '',
	]);
	$source_name = operator_panel_first_non_empty([
		$row['cid_name'] ?? '',
		$row['caller_id_name'] ?? '',
	]);
	$target_number = operator_panel_first_non_empty([
		$row['destination_number'] ?? '',
		$row['callee_num'] ?? '',
		$row['sent_callee_num'] ?? '',
		$row['dest'] ?? '',
	]);
	$target_name = operator_panel_first_non_empty([
		$row['callee_name'] ?? '',
		$row['sent_callee_name'] ?? '',
	]);

	$source_matches = operator_panel_matches_any_extension($source_number, $candidates);
	$target_matches = operator_panel_matches_any_extension($target_number, $candidates);
	if ($direction === 'incoming' || $target_matches) {
		$remote_number = $source_number;
		$remote_name = $source_name;
	}
	else if ($direction === 'outgoing' || $source_matches) {
		$remote_number = $target_number;
		$remote_name = $target_name;
	}
	else {
		$remote_number = !$source_matches && $source_number !== '' ? $source_number : $target_number;
		$remote_name = !$source_matches && $source_name !== '' ? $source_name : $target_name;
	}

	$created_epoch = (int) ($row['created_epoch'] ?? 0);
	$duration_seconds = $created_epoch > 0 ? max(0, time() - $created_epoch) : 0;
	$callstate = strtoupper(operator_panel_clean_text($row['callstate'] ?? ''));

	return [
		'channel_uuid' => $channel_uuid,
		'bridge_uuid' => $bridge_uuid,
		'call_uuid' => $call_uuid,
		'direction' => $direction,
		'callstate' => $callstate,
		'session_state' => operator_panel_row_entity_state($row),
		'source_number' => $source_number,
		'source_name' => $source_name,
		'target_number' => $target_number,
		'target_name' => $target_name,
		'remote_number' => $remote_number,
		'remote_name' => $remote_name,
		'created' => operator_panel_clean_text($row['created'] ?? ''),
		'created_epoch' => $created_epoch,
		'duration_seconds' => $duration_seconds,
		'duration_label' => operator_panel_format_duration($duration_seconds),
		'channel_name' => operator_panel_clean_text($row['name'] ?? ''),
		'monitorable' => in_array($callstate, ['ACTIVE', 'HELD'], true),
		'force_releasable' => $channel_uuid !== '' || $bridge_uuid !== '',
	];
}

function operator_panel_channel_matches_gateway(array $row, array $gateway) {
	$name = strtolower(operator_panel_clean_text($row['name'] ?? ''));
	$application_data = strtolower(operator_panel_clean_text($row['application_data'] ?? ''));
	$gateway_name = strtolower(operator_panel_clean_text($gateway['gateway'] ?? ''));
	$gateway_uuid = strtolower(operator_panel_clean_text($gateway['gateway_uuid'] ?? ''));

	$patterns = array_filter([
		$gateway_name !== '' ? 'gateway/' . $gateway_name . '/' : '',
		$gateway_name !== '' ? 'gateway/' . $gateway_name : '',
		$gateway_uuid !== '' ? 'gateway/' . $gateway_uuid . '/' : '',
		$gateway_uuid !== '' ? 'gateway/' . $gateway_uuid : '',
	]);

	foreach ($patterns as $pattern) {
		if (($name !== '' && strpos($name, $pattern) !== false) || ($application_data !== '' && strpos($application_data, $pattern) !== false)) {
			return true;
		}
	}

	return false;
}

function operator_panel_build_gateway_call_descriptor(array $row) {
	$direction = operator_panel_row_direction($row);
	$channel_uuid = operator_panel_clean_uuid($row['uuid'] ?? '');
	$bridge_uuid = operator_panel_find_bridge_uuid($row);
	$call_uuid = operator_panel_clean_uuid($row['call_uuid'] ?? '');
	if ($call_uuid === '') {
		$call_uuid = $channel_uuid;
	}

	$source_number = operator_panel_first_non_empty([
		$row['cid_num'] ?? '',
		$row['caller_id_number'] ?? '',
	]);
	$source_name = operator_panel_first_non_empty([
		$row['cid_name'] ?? '',
		$row['caller_id_name'] ?? '',
	]);
	$target_number = operator_panel_first_non_empty([
		$row['destination_number'] ?? '',
		$row['callee_num'] ?? '',
		$row['sent_callee_num'] ?? '',
		$row['dest'] ?? '',
	]);
	$target_name = operator_panel_first_non_empty([
		$row['callee_name'] ?? '',
		$row['sent_callee_name'] ?? '',
	]);

	$created_epoch = (int) ($row['created_epoch'] ?? 0);
	$duration_seconds = $created_epoch > 0 ? max(0, time() - $created_epoch) : 0;
	$callstate = strtoupper(operator_panel_clean_text($row['callstate'] ?? ''));

	return [
		'channel_uuid' => $channel_uuid,
		'bridge_uuid' => $bridge_uuid,
		'call_uuid' => $call_uuid,
		'direction' => $direction,
		'callstate' => $callstate,
		'session_state' => operator_panel_row_entity_state($row),
		'source_number' => $source_number,
		'source_name' => $source_name,
		'target_number' => $target_number,
		'target_name' => $target_name,
		'route_text' => trim($source_number . ' -> ' . $target_number, ' ->'),
		'external_number' => $direction === 'incoming' ? $source_number : $target_number,
		'internal_number' => $direction === 'incoming' ? $target_number : $source_number,
		'created' => operator_panel_clean_text($row['created'] ?? ''),
		'created_epoch' => $created_epoch,
		'duration_seconds' => $duration_seconds,
		'duration_label' => operator_panel_format_duration($duration_seconds),
		'channel_name' => operator_panel_clean_text($row['name'] ?? ''),
		'monitorable' => in_array($callstate, ['ACTIVE', 'HELD'], true),
		'force_releasable' => $channel_uuid !== '' || $bridge_uuid !== '',
	];
}

function operator_panel_registration_is_active($status) {
	$value = strtolower(trim((string) $status));
	if ($value === '') {
		return true;
	}
	if (strpos($value, 'noreg') !== false) {
		return true;
	}
	if (strpos($value, 'reg') !== false && strpos($value, 'unreg') === false) {
		return true;
	}
	return !preg_match('/expired|fail|error|denied|reject|timeout|forbidden|down|unreg/', $value);
}

function operator_panel_get_registration_map(database $database) {
	$map = [];
	try {
		require_once dirname(__DIR__) . "/registrations/resources/classes/registrations.php";
		$registrations = new registrations([
			'database' => $database,
			'domain_name' => $_SESSION['domain_name'] ?? '',
		]);
		$registrations->show = 'all';
		$rows = $registrations->get('all');
		if (is_array($rows)) {
			foreach ($rows as $row) {
				$user_value = operator_panel_first_non_empty([
					explode('@', (string) ($row['user'] ?? ''), 2)[0] ?? '',
					$row['sip-auth-user'] ?? '',
				]);
				$extension = operator_panel_clean_extension($user_value);
				if ($extension === '') {
					continue;
				}
				if (!isset($map[$extension])) {
					$map[$extension] = [
						'registered' => false,
						'registration_count' => 0,
						'registration_profiles' => [],
						'rows' => [],
					];
				}

				$status = operator_panel_clean_text($row['status'] ?? '');
				$map[$extension]['registered'] = $map[$extension]['registered'] || operator_panel_registration_is_active($status);
				$map[$extension]['registration_count']++;

				$profile_name = operator_panel_clean_text($row['sip_profile_name'] ?? '');
				if ($profile_name !== '') {
					$map[$extension]['registration_profiles'][$profile_name] = $profile_name;
				}

				$map[$extension]['rows'][] = [
					'user' => operator_panel_clean_text($row['user'] ?? ''),
					'status' => $status,
					'contact' => operator_panel_clean_text($row['contact'] ?? ''),
					'profile' => $profile_name,
				];
			}
		}

		foreach ($map as $extension => $entry) {
			$map[$extension]['registration_profiles'] = array_values($entry['registration_profiles']);
		}

		return [true, '', $map];
	}
	catch (Throwable $throwable) {
		return [false, $throwable->getMessage(), []];
	}
}

function operator_panel_sanitize_xml_response($xml_response) {
	$xml_response = (string) $xml_response;
	if ($xml_response === '') {
		return '';
	}
	if (function_exists('iconv')) {
		$converted = @iconv("utf-8", "utf-8//IGNORE", $xml_response);
		if ($converted !== false) {
			$xml_response = $converted;
		}
	}
	$xml_response = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $xml_response);
	$xml_response = str_replace('&lt;', '', $xml_response);
	$xml_response = str_replace('&gt;', '', $xml_response);
	return trim($xml_response);
}

function operator_panel_get_gateway_runtime_map() {
	try {
		$xml_response = operator_panel_sanitize_xml_response(event_socket::api('sofia xmlstatus gateway'));
		if ($xml_response === '') {
			return [false, 'gateway_runtime_empty', []];
		}

		$xml = new SimpleXMLElement($xml_response);
		$map = [];
		if (!empty($xml->gateway)) {
			foreach ($xml->gateway as $gateway) {
				$name = strtolower(operator_panel_clean_text($gateway->name ?? ''));
				if ($name === '') {
					continue;
				}
				$map[$name] = [
					'name' => $name,
					'state' => operator_panel_clean_text($gateway->state ?? ''),
					'profile' => operator_panel_clean_text($gateway->profile ?? ''),
					'to' => operator_panel_clean_text($gateway->to ?? ''),
				];
			}
		}
		return [true, '', $map];
	}
	catch (Throwable $throwable) {
		return [false, $throwable->getMessage(), []];
	}
}

function operator_panel_get_extension_status_entities(database $database, array $channel_rows, array $registration_map, $registrations_available = true) {
	$sql = "select ";
	$sql .= "e.extension, ";
	$sql .= "e.number_alias, ";
	$sql .= "e.effective_caller_id_name, ";
	$sql .= "e.extension_owner, ";
	$sql .= "e.effective_caller_id_number, ";
	$sql .= "e.call_group, ";
	$sql .= "e.description, ";
	$sql .= "u.user_status ";
	$sql .= "from v_extensions as e ";
	$sql .= "left outer join v_extension_users as eu on (eu.extension_uuid = e.extension_uuid and eu.domain_uuid = :domain_uuid) ";
	$sql .= "left outer join v_users as u on (u.user_uuid = eu.user_uuid and u.domain_uuid = :domain_uuid) ";
	$sql .= "where e.enabled = 'true' ";
	$sql .= "and e.domain_uuid = :domain_uuid ";
	$sql .= "order by e.extension asc ";

	$rows = $database->select($sql, ['domain_uuid' => $_SESSION['domain_uuid']], 'all');
	$entities = [];
	if (!is_array($rows)) {
		return $entities;
	}

	$self_candidates = [];
	$current_extension = operator_panel_current_extension();
	if ($current_extension !== '') {
		$self_candidates[$current_extension] = $current_extension;
	}
	$current_session_row = operator_panel_current_session_extension_row($current_extension);
	if (is_array($current_session_row)) {
		foreach (['extension', 'user', 'destination', 'number_alias'] as $field) {
			$value = operator_panel_clean_extension($current_session_row[$field] ?? '');
			if ($value !== '') {
				$self_candidates[$value] = $value;
			}
		}
	}

	foreach ($rows as $row) {
		$extension = operator_panel_clean_extension($row['extension'] ?? '');
		$number_alias = operator_panel_clean_extension($row['number_alias'] ?? '');
		if ($extension === '') {
			continue;
		}

		$candidates = array_values(array_unique(array_filter([$extension, $number_alias])));
		if (!empty($self_candidates)) {
			foreach ($candidates as $candidate) {
				if (isset($self_candidates[$candidate])) {
					continue 2;
				}
			}
		}
		$registration = null;
		foreach ($candidates as $candidate) {
			if (isset($registration_map[$candidate])) {
				$registration = $registration_map[$candidate];
				break;
			}
		}

		$matched_rows = [];
		foreach ($channel_rows as $channel_row) {
			if (operator_panel_channel_matches_extension($channel_row, $candidates)) {
				$matched_rows[] = $channel_row;
			}
		}

		$primary_call = null;
		$entity_state = $registrations_available ? (!empty($registration['registered']) ? 'idle' : 'offline') : 'unknown';
		foreach ($matched_rows as $matched_row) {
			$current_call = operator_panel_build_extension_call_descriptor($matched_row, $candidates);
			if (
				$primary_call === null ||
				operator_panel_entity_state_priority($current_call['session_state']) > operator_panel_entity_state_priority($entity_state)
			) {
				$primary_call = $current_call;
				$entity_state = $current_call['session_state'];
			}
		}
		$effective_caller_id_name = operator_panel_clean_text($row['effective_caller_id_name'] ?? '');
		$extension_owner = operator_panel_clean_text($row['extension_owner'] ?? '');
		$display_name = $effective_caller_id_name;
		if ($effective_caller_id_name !== '' && $extension_owner !== '' && $effective_caller_id_name !== $extension_owner) {
			$display_name .= ' / '.$extension_owner;
		}
		if ($display_name === '') {
			$display_name = operator_panel_first_non_empty([
				$extension_owner,
				operator_panel_clean_text($row['description'] ?? ''),
				$extension,
			]);
		}

		$entities[] = [
			'entity_type' => 'user',
			'entity_id' => $extension,
			'extension' => $extension,
			'number_alias' => $number_alias,
			'display_number' => $extension,
			'display_name' => $display_name,
			'extension_owner' => $extension_owner,
			'caller_id_number' => operator_panel_clean_text($row['effective_caller_id_number'] ?? ''),
			'call_group' => operator_panel_clean_text($row['call_group'] ?? ''),
			'description' => operator_panel_clean_text($row['description'] ?? ''),
			'user_status' => operator_panel_clean_text($row['user_status'] ?? ''),
			'registered' => !empty($registration['registered']),
			'registration_known' => !!$registrations_available,
			'registration_count' => (int) ($registration['registration_count'] ?? 0),
			'registration_profiles' => $registration['registration_profiles'] ?? [],
			'entity_state' => $entity_state,
			'state_rank' => operator_panel_entity_state_priority($entity_state),
			'active_call_count' => count($matched_rows),
			'current_call' => $primary_call,
			'monitorable' => !empty($primary_call['monitorable']),
			'force_releasable' => !empty($primary_call['force_releasable']),
		];
	}

	usort($entities, function($left, $right) {
		$state_compare = ((int) ($right['state_rank'] ?? 0)) <=> ((int) ($left['state_rank'] ?? 0));
		if ($state_compare !== 0) {
			return $state_compare;
		}
		return strnatcasecmp((string) ($left['display_number'] ?? ''), (string) ($right['display_number'] ?? ''));
	});

	return $entities;
}

function operator_panel_gateway_runtime_state($enabled, $runtime_state, $runtime_available = true) {
	if (!$enabled) {
		return 'disabled';
	}
	if (!$runtime_available) {
		return 'unknown';
	}

	$value = strtolower(trim((string) $runtime_state));
	if ($value === '') {
		return 'offline';
	}
	if (preg_match('/fail|error|alarm/', $value)) {
		return 'error';
	}
	if (preg_match('/down|unreg|expired|timeout|reject/', $value)) {
		return 'offline';
	}
	if (preg_match('/reg|noreg|up|run|trying|active/', $value)) {
		return 'idle';
	}
	return 'unknown';
}

function operator_panel_get_trunk_status_entities(database $database, array $channel_rows, array $gateway_runtime_map, $gateway_runtime_available = true) {
	$sql = "select gateway_uuid, gateway, profile, username, from_user, proxy, register_proxy, outbound_proxy, realm, enabled ";
	$sql .= "from v_gateways ";
	$sql .= "where (domain_uuid = :domain_uuid or domain_uuid is null) ";
	$sql .= "order by gateway asc ";
	$rows = $database->select($sql, ['domain_uuid' => $_SESSION['domain_uuid']], 'all');
	$entities = [];
	if (!is_array($rows)) {
		return $entities;
	}

	foreach ($rows as $row) {
		$gateway_uuid = strtolower(operator_panel_clean_text($row['gateway_uuid'] ?? ''));
		$gateway_name = operator_panel_clean_text($row['gateway'] ?? '');
		$runtime = $gateway_runtime_map[$gateway_uuid] ?? $gateway_runtime_map[strtolower($gateway_name)] ?? null;

		$matched_rows = [];
		foreach ($channel_rows as $channel_row) {
			if (operator_panel_channel_matches_gateway($channel_row, $row)) {
				$matched_rows[] = $channel_row;
			}
		}

		$primary_call = null;
		$enabled = strtolower((string) ($row['enabled'] ?? 'true')) === 'true';
		$entity_state = operator_panel_gateway_runtime_state($enabled, $runtime['state'] ?? '', $gateway_runtime_available);
		foreach ($matched_rows as $matched_row) {
			$current_call = operator_panel_build_gateway_call_descriptor($matched_row);
			if (
				$primary_call === null ||
				operator_panel_entity_state_priority($current_call['session_state']) > operator_panel_entity_state_priority($entity_state)
			) {
				$primary_call = $current_call;
				$entity_state = $current_call['session_state'];
			}
		}

		$entities[] = [
			'entity_type' => 'trunk',
			'entity_id' => $gateway_uuid !== '' ? $gateway_uuid : strtolower($gateway_name),
			'gateway_uuid' => $gateway_uuid,
			'gateway' => $gateway_name,
			'display_number' => operator_panel_first_non_empty([
				$row['from_user'] ?? '',
				$row['username'] ?? '',
				$row['proxy'] ?? '',
				$row['register_proxy'] ?? '',
			]),
			'display_name' => $gateway_name !== '' ? $gateway_name : 'Gateway',
			'profile' => operator_panel_clean_text($row['profile'] ?? ''),
			'proxy' => operator_panel_first_non_empty([
				$row['proxy'] ?? '',
				$row['register_proxy'] ?? '',
				$row['outbound_proxy'] ?? '',
				$row['realm'] ?? '',
			]),
			'enabled' => $enabled,
			'entity_state' => $entity_state,
			'state_rank' => operator_panel_entity_state_priority($entity_state),
			'active_call_count' => count($matched_rows),
			'current_call' => $primary_call,
			'monitorable' => !empty($primary_call['monitorable']),
			'force_releasable' => !empty($primary_call['force_releasable']),
			'runtime_state_known' => !!$gateway_runtime_available,
			'runtime_state' => operator_panel_clean_text($runtime['state'] ?? ''),
			'runtime_profile' => operator_panel_clean_text($runtime['profile'] ?? ($row['profile'] ?? '')),
			'runtime_target' => operator_panel_clean_text($runtime['to'] ?? ''),
		];
	}

	usort($entities, function($left, $right) {
		$state_compare = ((int) ($right['state_rank'] ?? 0)) <=> ((int) ($left['state_rank'] ?? 0));
		if ($state_compare !== 0) {
			return $state_compare;
		}
		return strnatcasecmp((string) ($left['display_name'] ?? ''), (string) ($right['display_name'] ?? ''));
	});

	return $entities;
}

function operator_panel_summarize_entities(array $entities, $entity_type) {
	$summary = [
		'total' => count($entities),
		'idle' => 0,
		'ringing' => 0,
		'busy' => 0,
		'held' => 0,
		'offline' => 0,
		'unknown' => 0,
	];

	if ($entity_type === 'user') {
		$summary['registered'] = 0;
	}
	else if ($entity_type === 'trunk') {
		$summary['error'] = 0;
		$summary['disabled'] = 0;
	}

	foreach ($entities as $entity) {
		$state = strtolower(trim((string) ($entity['entity_state'] ?? 'unknown')));
		if (!array_key_exists($state, $summary)) {
			$summary['unknown']++;
		}
		else {
			$summary[$state]++;
		}

		if ($entity_type === 'user' && !empty($entity['registered'])) {
			$summary['registered']++;
		}
	}

	return $summary;
}

function operator_panel_requested_extensions() {
	$extensions = [];
	$requested_extensions = $_POST['extensions'] ?? $_GET['extensions'] ?? [];
	$has_requested_extensions = false;
	if (is_array($requested_extensions)) {
		foreach ($requested_extensions as $requested_extension) {
			$value = operator_panel_clean_extension($requested_extension);
			if ($value !== '') {
				$extensions[$value] = $value;
				$has_requested_extensions = true;
			}
		}
	}

	if (!$has_requested_extensions) {
		$current_extension = operator_panel_current_extension();
		if ($current_extension !== '') {
			$extensions[$current_extension] = $current_extension;
		}
	}

	$allowed_extensions = operator_panel_allowed_extensions();
	if (!empty($allowed_extensions)) {
		$allowed_lookup = array_flip($allowed_extensions);
		$extensions = array_intersect_key($extensions, $allowed_lookup);
	}

	return array_values($extensions);
}

function operator_panel_allowed_extensions() {
	$extensions = [];
	if (!empty($_SESSION['user']['extension']) && is_array($_SESSION['user']['extension'])) {
		foreach ($_SESSION['user']['extension'] as $extension) {
			$value = $extension['user'] ?? $extension['extension'] ?? $extension['destination'] ?? '';
			$value = operator_panel_clean_extension($value);
			if ($value !== '') {
				$extensions[$value] = $value;
			}
		}
	}
	return array_values($extensions);
}

function operator_panel_current_extension() {
	$extension = operator_panel_clean_extension(operator_panel_request('extension', ''));
	$allowed_extensions = operator_panel_allowed_extensions();
	if ($extension !== '' && in_array($extension, $allowed_extensions, true)) {
		return $extension;
	}
	if (!empty($allowed_extensions)) {
		return $allowed_extensions[0];
	}
	return $extension;
}

function operator_panel_current_session_extension_row($extension = '') {
	if (empty($_SESSION['user']['extension']) || !is_array($_SESSION['user']['extension'])) {
		return null;
	}

	foreach ($_SESSION['user']['extension'] as $row) {
		$candidates = [
			operator_panel_clean_extension($row['extension'] ?? ''),
			operator_panel_clean_extension($row['user'] ?? ''),
			operator_panel_clean_extension($row['destination'] ?? ''),
			operator_panel_clean_extension($row['number_alias'] ?? ''),
		];
		if ($extension === '' || in_array($extension, array_filter($candidates), true)) {
			return $row;
		}
	}

	return $_SESSION['user']['extension'][0] ?? null;
}

function operator_panel_get_sip_config(database $database) {
	$extension = operator_panel_current_extension();
	$session_row = operator_panel_current_session_extension_row($extension);
	$domain_uuid = $_SESSION['domain_uuid'] ?? '';
	$server_host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? '127.0.0.1');
	$server_ip = explode(':', $server_host)[0];
	$domain_name = $_SESSION['domain_name'] ?? $server_ip;
	$password = '';

	if ($extension === '' && is_array($session_row)) {
		foreach (['extension', 'user', 'destination', 'number_alias'] as $field) {
			$value = operator_panel_clean_extension($session_row[$field] ?? '');
			if ($value !== '') {
				$extension = $value;
				break;
			}
		}
	}

	if ($extension !== '' && $domain_uuid !== '') {
		$sql = "select extension, number_alias, password from v_extensions ";
		$sql .= "where domain_uuid = :domain_uuid ";
		$sql .= "and (extension = :extension or number_alias = :extension) ";
		$sql .= "order by case when extension = :extension_exact then 0 else 1 end ";
		$sql .= "limit 1";
		$row = $database->select($sql, [
			'domain_uuid' => $domain_uuid,
			'extension' => $extension,
			'extension_exact' => $extension,
		], 'row');

		if (is_array($row)) {
			$db_extension = operator_panel_clean_extension($row['extension'] ?? '');
			if ($db_extension !== '') {
				$extension = $db_extension;
			}
			$password = trim((string) ($row['password'] ?? ''));
		}
	}

	if ($password === '' && is_array($session_row)) {
		foreach (['password', 'sip_password', 'extension_password'] as $field) {
			$value = trim((string) ($session_row[$field] ?? ''));
			if ($value !== '') {
				$password = $value;
				break;
			}
		}
	}

	return [
		'uri' => $extension !== '' ? 'sip:' . $extension . '@' . $domain_name : '',
		'wsServers' => 'wss://' . $server_ip . ':7443',
		'authUser' => $extension,
		'password' => $password,
		'displayName' => $extension !== '' ? $extension : ($_SESSION['user']['username'] ?? 'Dispatcher'),
	];
}

function operator_panel_clean_host($host) {
	$host = trim((string) $host);
	if ($host === '' || strlen($host) > 255) {
		return '';
	}
	if (filter_var($host, FILTER_VALIDATE_IP)) {
		return $host;
	}
	return preg_match('/^[A-Za-z0-9.-]+$/', $host) ? strtolower($host) : '';
}

function operator_panel_alarm_config_int($value, $minimum, $maximum, $default) {
	$value = (int) $value;
	if ($value < $minimum || $value > $maximum) {
		return $default;
	}
	return $value;
}

function operator_panel_get_alarm_config_values($allow_caching = false) {
	$settings = new settings([
		'category' => 'operator_panel',
		'allow_caching' => $allow_caching,
	]);
	$host = (string) $settings->get('operator_panel', 'emergency_alarm_host', '192.168.50.1');
	$port = operator_panel_alarm_config_int($settings->get('operator_panel', 'emergency_alarm_port', 9003), 1, 65535, 9003);
	$timeout_ms = operator_panel_alarm_config_int($settings->get('operator_panel', 'emergency_alarm_socket_timeout_ms', 1200), 100, 60000, 1200);
	$audio_folder = operator_panel_alarm_config_int($settings->get('operator_panel', 'emergency_alarm_audio_folder', 1), 0, 255, 1);
	$audio_track = operator_panel_alarm_config_int($settings->get('operator_panel', 'emergency_alarm_audio_track', 1), 0, 255, 1);
	$strobe_mode = operator_panel_alarm_config_int($settings->get('operator_panel', 'emergency_alarm_strobe_mode', 3), 0, 255, 3);
	$audio_volume = operator_panel_alarm_config_int($settings->get('operator_panel', 'emergency_alarm_audio_volume', 30), 0, 30, 30);
	return [
		'host' => $host,
		'port' => $port,
		'timeout_ms' => $timeout_ms,
		'audio_folder' => $audio_folder,
		'audio_track' => $audio_track,
		'strobe_mode' => $strobe_mode,
		'audio_volume' => $audio_volume,
		'configured_endpoint' => $host . ':' . $port,
	];
}

function operator_panel_alarm_default_setting_uuids() {
	return [
		'emergency_alarm_host' => '6d85f8c1-ef41-4d8c-a70f-6a9d98974bb7',
		'emergency_alarm_port' => 'f95e95f2-f51a-4b2e-a0eb-ac28d29ddb4f',
		'emergency_alarm_socket_timeout_ms' => '1a9c1e6b-b9c6-4468-ad6b-f79f3a8a5803',
		'emergency_alarm_audio_folder' => '37539581-07cd-4add-8d78-d64ab3115892',
		'emergency_alarm_audio_track' => '9bc408d4-8644-4d7f-80a4-03f1092a83a4',
		'emergency_alarm_strobe_mode' => 'c1278375-e82c-4fc4-b5fc-1e8a24a20caa',
		'emergency_alarm_audio_volume' => 'd9553b3c-f159-4b1a-a8d1-f31bcb2d9b2c',
	];
}

function operator_panel_find_default_setting_uuid(database $database, $category, $subcategory) {
	$sql = "select default_setting_uuid from v_default_settings ";
	$sql .= "where default_setting_category = :default_setting_category ";
	$sql .= "and default_setting_subcategory = :default_setting_subcategory ";
	$sql .= "limit 1";
	return $database->select($sql, [
		'default_setting_category' => $category,
		'default_setting_subcategory' => $subcategory,
	], 'column');
}

function operator_panel_save_alarm_setting(database $database, $subcategory, $value, $type, $description = '') {
	$known_uuids = operator_panel_alarm_default_setting_uuids();
	$uuid = operator_panel_find_default_setting_uuid($database, 'operator_panel', $subcategory);
	$record = [
		'default_settings' => [[
			'default_setting_uuid' => is_uuid($uuid) ? $uuid : ($known_uuids[$subcategory] ?? uuid()),
			'default_setting_category' => 'operator_panel',
			'default_setting_subcategory' => $subcategory,
			'default_setting_name' => $type,
			'default_setting_value' => (string) $value,
			'default_setting_enabled' => 'true',
			'default_setting_description' => $description,
		]]
	];

	$p = permissions::new();
	$p->add('default_setting_add', 'temp');
	$p->add('default_setting_edit', 'temp');

	try {
		$database->app_name = 'default_settings';
		$database->app_uuid = '2c2453c0-1bea-4475-9f44-4d969650de09';
		$database->save($record);
	}
	finally {
		$p->delete('default_setting_add', 'temp');
		$p->delete('default_setting_edit', 'temp');
	}
}

function operator_panel_alarm_service_pid_file() {
	return '/var/run/fusionpbx/emergency_alarm_service.pid';
}

function operator_panel_find_alarm_service_pid() {
	$pid_file = operator_panel_alarm_service_pid_file();
	if (file_exists($pid_file)) {
		$pid = (int) trim((string) @file_get_contents($pid_file));
		if ($pid > 0) {
			return [
				'pid' => $pid,
				'source' => 'pid_file',
			];
		}
	}

	if (!function_exists('exec')) {
		return null;
	}

	$commands = [];
	if (stripos(PHP_OS, 'WIN') === 0) {
		$commands[] = 'wmic process where "CommandLine like \'%emergency_alarm.php%\'" get ProcessId /value';
	}
	else {
		$commands[] = 'systemctl show -p MainPID --value emergency_alarm';
		$commands[] = '/bin/systemctl show -p MainPID --value emergency_alarm';
		$commands[] = 'pgrep -f "/app/basic_operator_panel/resources/service/emergency_alarm.php"';
		$commands[] = 'pgrep -f "emergency_alarm.php --debug"';
	}

	foreach ($commands as $command) {
		$attempt = operator_panel_exec_command($command);
		if ((int) $attempt['exit_code'] !== 0 || trim($attempt['output']) === '') {
			continue;
		}

		if (preg_match('/\b([1-9][0-9]*)\b/', $attempt['output'], $matches)) {
			return [
				'pid' => (int) $matches[1],
				'source' => $command,
			];
		}
	}

	return null;
}

function operator_panel_signal_alarm_service_reload() {
	$result = [
		'success' => false,
		'method' => 'signal',
		'message' => '',
	];

	if (stripos(PHP_OS, 'WIN') === 0) {
		$result['message'] = 'Signal reload is unavailable on Windows.';
		return $result;
	}

	if (!function_exists('posix_kill')) {
		$result['message'] = 'posix_kill is unavailable.';
		return $result;
	}

	$pid_info = operator_panel_find_alarm_service_pid();
	if (!$pid_info || empty($pid_info['pid'])) {
		$result['message'] = 'Alarm service PID not found.';
		return $result;
	}
	$pid = (int) $pid_info['pid'];

	$signal = defined('SIGUSR1') ? SIGUSR1 : (defined('SIGHUP') ? SIGHUP : null);
	if ($signal === null) {
		$result['message'] = 'No supported reload signal is available.';
		return $result;
	}

	if (@posix_kill($pid, $signal)) {
		$result['success'] = true;
		$result['method'] = 'signal:' . ($pid_info['source'] ?? 'unknown');
		$result['message'] = 'Reload signal sent to PID ' . $pid . '.';
		return $result;
	}

	$error_message = function_exists('posix_strerror') ? @posix_strerror(posix_get_last_error()) : 'posix_kill failed';
	$result['message'] = 'Failed to send reload signal: ' . $error_message;
	return $result;
}

function operator_panel_exec_command($command) {
	$output = [];
	$exit_code = 1;
	@exec($command . ' 2>&1', $output, $exit_code);
	return [
		'command' => $command,
		'output' => implode("\n", $output),
		'exit_code' => $exit_code,
	];
}

function operator_panel_restart_alarm_service() {
	$service_name = 'emergency_alarm';
	$result = [
		'success' => false,
		'action' => 'restart',
		'method' => '',
		'message' => '',
		'details' => [],
	];

	if (!function_exists('exec')) {
		$reload_result = operator_panel_signal_alarm_service_reload();
		$reload_result['action'] = 'reload';
		return $reload_result;
	}

	$service_name_arg = escapeshellarg($service_name);
	$commands = [];
	if (stripos(PHP_OS, 'WIN') === 0) {
		$commands[] = 'sc stop ' . $service_name . ' && sc start ' . $service_name;
	}
	else {
		$commands[] = 'systemctl restart ' . $service_name_arg;
		$commands[] = '/bin/systemctl restart ' . $service_name_arg;
		$commands[] = 'sudo -n systemctl restart ' . $service_name_arg;
		$commands[] = 'sudo -n /bin/systemctl restart ' . $service_name_arg;
	}

	foreach ($commands as $command) {
		$attempt = operator_panel_exec_command($command);
		$result['details'][] = $attempt;
		if ((int) $attempt['exit_code'] === 0) {
			$result['success'] = true;
			$result['method'] = $command;
			$result['message'] = 'Service restarted successfully.';
			return $result;
		}
	}

	$reload_result = operator_panel_signal_alarm_service_reload();
	$reload_result['action'] = 'reload';
	$reload_result['details'] = array_merge($result['details'], $reload_result['details'] ?? []);
	if ($reload_result['success']) {
		$reload_result['message'] = 'Service restart failed, but reload signal succeeded.';
		return $reload_result;
	}

	$result['message'] = 'Service restart failed.';
	if (!empty($reload_result['message'])) {
		$result['message'] .= ' ' . $reload_result['message'];
	}
	return $result;
}

function operator_panel_valid_tab_uuid($tab_uuid) {
	return is_string($tab_uuid) && preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{7,127}$/', $tab_uuid);
}

function operator_panel_client_ip() {
	$forwarded_for = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
	if ($forwarded_for !== '') {
		$parts = explode(',', $forwarded_for);
		return trim($parts[0]);
	}
	return $_SERVER['REMOTE_ADDR'] ?? '';
}

function operator_panel_get_domain_row_by_name(database $database, $domain_name) {
	if ($domain_name === '') {
		return null;
	}
	$sql = "select domain_uuid, domain_name from v_domains ";
	$sql .= "where lower(domain_name) = lower(:domain_name) ";
	$sql .= "limit 1";
	return $database->select($sql, ['domain_name' => $domain_name], 'row');
}

function operator_panel_find_alarm_call(database $database, $emergency_uuid = '', $extension = '', $caller_extension = '') {
	$sql = "select * from v_emergency_calls ";
	$sql .= "where domain_uuid = :domain_uuid ";
	$parameters = ['domain_uuid' => $_SESSION['domain_uuid']];

	if (is_uuid($emergency_uuid)) {
		$sql .= "and emergency_call_uuid = :emergency_call_uuid ";
		$parameters['emergency_call_uuid'] = $emergency_uuid;
	}
	else {
		if ($extension !== '') {
			$sql .= "and callee_extension = :callee_extension ";
			$parameters['callee_extension'] = $extension;
		}
		if ($caller_extension !== '') {
			$sql .= "and caller_extension = :caller_extension ";
			$parameters['caller_extension'] = $caller_extension;
		}
		$sql .= "and (alarm_clear_time is null or coalesce(alarm_state, '') not in ('cleared', 'skipped')) ";
		$sql .= "and (end_time is null or coalesce(status, '') not in ('completed', 'failed', 'rejected')) ";
		$sql .= "order by coalesce(alarm_trigger_time, start_time, insert_date) desc ";
	}

	$sql .= "limit 1";
	return $database->select($sql, $parameters, 'row');
}

function operator_panel_find_alarm_call_by_call_uuid(database $database, $call_uuid) {
	$call_uuid = operator_panel_clean_uuid($call_uuid);
	if (!is_uuid($call_uuid)) {
		return [];
	}

	$sql = "select * from v_emergency_calls ";
	$sql .= "where domain_uuid = :domain_uuid ";
	$sql .= "and call_uuid = :call_uuid ";
	$sql .= "order by insert_date desc ";
	$sql .= "limit 1";
	return $database->select($sql, [
		'domain_uuid' => $_SESSION['domain_uuid'],
		'call_uuid' => $call_uuid,
	], 'row');
}

function operator_panel_insert_emergency_call(database $database, array $fields) {
	$now = operator_panel_now();
	$emergency_call_uuid = is_uuid($fields['emergency_call_uuid'] ?? '') ? $fields['emergency_call_uuid'] : uuid();
	$call_uuid = operator_panel_clean_uuid($fields['call_uuid'] ?? '');
	$caller_extension = operator_panel_clean_extension($fields['caller_extension'] ?? '');
	$callee_extension = operator_panel_clean_extension($fields['callee_extension'] ?? '');
	$direction = trim((string) ($fields['direction'] ?? 'user_to_dispatcher'));
	$emergency_type = trim((string) ($fields['emergency_type'] ?? 'single'));
	$status = trim((string) ($fields['status'] ?? 'ringing'));
	$alarm_state = array_key_exists('alarm_state', $fields) ? $fields['alarm_state'] : null;
	$alarm_trigger_time = array_key_exists('alarm_trigger_time', $fields) ? $fields['alarm_trigger_time'] : null;
	$start_time = array_key_exists('start_time', $fields) ? $fields['start_time'] : $now;

	$sql = "insert into v_emergency_calls ";
	$sql .= "(emergency_call_uuid, domain_uuid, call_uuid, direction, caller_extension, callee_extension, emergency_type, status, alarm_state, alarm_trigger_time, start_time, insert_date, insert_user, update_date, update_user) ";
	$sql .= "values ";
	$sql .= "(:emergency_call_uuid, :domain_uuid, :call_uuid, :direction, :caller_extension, :callee_extension, :emergency_type, :status, :alarm_state, :alarm_trigger_time, :start_time, :insert_date, :insert_user, :update_date, :update_user)";
	$database->execute($sql, [
		'emergency_call_uuid' => $emergency_call_uuid,
		'domain_uuid' => $_SESSION['domain_uuid'],
		'call_uuid' => is_uuid($call_uuid) ? $call_uuid : null,
		'direction' => $direction,
		'caller_extension' => $caller_extension,
		'callee_extension' => $callee_extension,
		'emergency_type' => $emergency_type,
		'status' => $status,
		'alarm_state' => $alarm_state,
		'alarm_trigger_time' => $alarm_trigger_time,
		'start_time' => $start_time,
		'insert_date' => $now,
		'insert_user' => operator_panel_current_user_uuid(),
		'update_date' => $now,
		'update_user' => operator_panel_current_user_uuid(),
	]);

	return operator_panel_find_alarm_call($database, $emergency_call_uuid);
}

function operator_panel_update_emergency_call(database $database, $emergency_call_uuid, array $fields) {
	if (!is_uuid($emergency_call_uuid)) {
		return false;
	}

	$assignments = [];
	$parameters = [
		'domain_uuid' => $_SESSION['domain_uuid'],
		'emergency_call_uuid' => $emergency_call_uuid,
	];

	foreach ($fields as $field_name => $field_value) {
		if ($field_value === '__ignore__') {
			continue;
		}
		if ($field_value === '__now__') {
			$assignments[] = $field_name . " = :" . $field_name;
			$parameters[$field_name] = operator_panel_now();
			continue;
		}
		$assignments[] = $field_name . " = :" . $field_name;
		$parameters[$field_name] = $field_value;
	}

	if (empty($assignments)) {
		return false;
	}

	if (!isset($fields['update_date'])) {
		$assignments[] = "update_date = :update_date";
		$parameters['update_date'] = operator_panel_now();
	}
	if (!array_key_exists('update_user', $fields)) {
		$assignments[] = "update_user = :update_user";
		$parameters['update_user'] = operator_panel_current_user_uuid();
	}

	$sql = "update v_emergency_calls set " . implode(', ', $assignments) . " ";
	$sql .= "where domain_uuid = :domain_uuid ";
	$sql .= "and emergency_call_uuid = :emergency_call_uuid";
	$database->execute($sql, $parameters);
	return true;
}

function operator_panel_find_triggered_alarm_calls(database $database, $limit = null) {
	$sql = "select * from v_emergency_calls ";
	$sql .= "where domain_uuid = :domain_uuid ";
	$sql .= "and alarm_state = 'triggered' ";
	$sql .= "and (end_time is null or coalesce(status, '') not in ('completed', 'failed', 'rejected')) ";
	$sql .= "order by coalesce(alarm_trigger_time, start_time, insert_date) asc ";
	if ($limit !== null) {
		$sql .= "limit " . max(1, (int) $limit);
	}
	return $database->select($sql, ['domain_uuid' => $_SESSION['domain_uuid']], 'all') ?: [];
}

function operator_panel_count_triggered_alarm_calls(database $database) {
	$sql = "select count(*) from v_emergency_calls ";
	$sql .= "where domain_uuid = :domain_uuid ";
	$sql .= "and alarm_state = 'triggered' ";
	$sql .= "and (end_time is null or coalesce(status, '') not in ('completed', 'failed', 'rejected'))";
	return (int) $database->select($sql, ['domain_uuid' => $_SESSION['domain_uuid']], 'column');
}

function operator_panel_alarm_device_instance(?array $config = null) {
	$alarm_config = $config ?: operator_panel_get_alarm_config_values(false);
	return new emergency_alarm_device(
		$alarm_config['host'] ?? '192.168.50.1',
		(int) ($alarm_config['port'] ?? 9003),
		(int) ($alarm_config['timeout_ms'] ?? 1200),
		(int) ($alarm_config['audio_folder'] ?? emergency_alarm_device::DEFAULT_AUDIO_FOLDER),
		(int) ($alarm_config['audio_track'] ?? emergency_alarm_device::DEFAULT_AUDIO_TRACK),
		(int) ($alarm_config['strobe_mode'] ?? emergency_alarm_device::DEFAULT_STROBE_MODE),
		(int) ($alarm_config['audio_volume'] ?? emergency_alarm_device::DEFAULT_AUDIO_VOLUME)
	);
}

function operator_panel_alarm_trigger_device(?array $config = null) {
	$device = operator_panel_alarm_device_instance($config);
	$result = $device->activate_alarm();
	$result['mode'] = 'standard_alarm_sequence';
	return $result;
}

function operator_panel_alarm_clear_device(?array $config = null) {
	$device = operator_panel_alarm_device_instance($config);
	return $device->clear_alarm();
}

function operator_panel_upsert_alarm_runtime(database $database, array $patch = []) {
	$domain_uuid = $_SESSION['domain_uuid'] ?? '';
	if (!is_uuid($domain_uuid)) {
		return null;
	}

	$service_name = 'emergency_alarm';
	$runtime_sql = "select * from v_emergency_alarm_runtime ";
	$runtime_sql .= "where domain_uuid = :domain_uuid ";
	$runtime_sql .= "and service_name = :service_name ";
	$runtime_sql .= "limit 1";
	$runtime = $database->select($runtime_sql, [
		'domain_uuid' => $domain_uuid,
		'service_name' => $service_name,
	], 'row');

	$alarm_config = operator_panel_get_alarm_config_values(false);
	$device_endpoint = $patch['device_endpoint']
		?? ($runtime['device_endpoint'] ?? ($alarm_config['configured_endpoint'] ?? (($alarm_config['host'] ?? '192.168.50.1') . ':' . ($alarm_config['port'] ?? 9003))));
	$data = [
		'domain_uuid' => $domain_uuid,
		'service_name' => $service_name,
		'service_state' => $patch['service_state'] ?? ($runtime['service_state'] ?? 'running'),
		'device_state' => $patch['device_state'] ?? ($runtime['device_state'] ?? 'idle'),
		'device_endpoint' => $device_endpoint,
		'last_heartbeat' => $patch['last_heartbeat'] ?? operator_panel_now(),
		'last_device_response' => array_key_exists('last_device_response', $patch) ? $patch['last_device_response'] : ($runtime['last_device_response'] ?? ''),
		'last_error' => array_key_exists('last_error', $patch) ? $patch['last_error'] : ($runtime['last_error'] ?? ''),
		'update_date' => operator_panel_now(),
		'update_user' => null,
	];

	if (is_uuid($runtime['emergency_alarm_runtime_uuid'] ?? '')) {
		$sql = "update v_emergency_alarm_runtime set ";
		$sql .= "service_state = :service_state, device_state = :device_state, device_endpoint = :device_endpoint, ";
		$sql .= "last_heartbeat = :last_heartbeat, last_device_response = :last_device_response, last_error = :last_error, ";
		$sql .= "update_date = :update_date, update_user = :update_user ";
		$sql .= "where emergency_alarm_runtime_uuid = :emergency_alarm_runtime_uuid";
		$data['emergency_alarm_runtime_uuid'] = $runtime['emergency_alarm_runtime_uuid'];
		$database->execute($sql, $data);
		return $runtime['emergency_alarm_runtime_uuid'];
	}

	$sql = "insert into v_emergency_alarm_runtime ";
	$sql .= "(emergency_alarm_runtime_uuid, domain_uuid, service_name, service_state, device_state, device_endpoint, last_heartbeat, last_device_response, last_error, update_date, update_user) ";
	$sql .= "values ";
	$sql .= "(:emergency_alarm_runtime_uuid, :domain_uuid, :service_name, :service_state, :device_state, :device_endpoint, :last_heartbeat, :last_device_response, :last_error, :update_date, :update_user)";
	$data['emergency_alarm_runtime_uuid'] = uuid();
	$database->execute($sql, $data);
	return $data['emergency_alarm_runtime_uuid'];
}

function operator_panel_prepare_emergency_alarm_call(database $database, array $options) {
	$requested_emergency_uuid = operator_panel_clean_uuid($options['emergency_uuid'] ?? '');
	$call_uuid = operator_panel_clean_uuid($options['call_uuid'] ?? '');
	$callee_extension = operator_panel_clean_extension($options['callee_extension'] ?? operator_panel_current_extension());
	$caller_extension = operator_panel_clean_extension($options['caller_extension'] ?? '');
	$call = [];

	if (is_uuid($requested_emergency_uuid)) {
		$call = operator_panel_find_alarm_call($database, $requested_emergency_uuid, '', '');
	}
	if (empty($call) && is_uuid($call_uuid)) {
		$call = operator_panel_find_alarm_call_by_call_uuid($database, $call_uuid);
	}
	if (empty($call) && $callee_extension !== '') {
		$call = operator_panel_find_alarm_call($database, '', $callee_extension, $caller_extension);
	}
	if (empty($call)) {
		$call = operator_panel_insert_emergency_call($database, [
			'emergency_call_uuid' => is_uuid($requested_emergency_uuid) ? $requested_emergency_uuid : uuid(),
			'call_uuid' => $call_uuid,
			'direction' => trim((string) ($options['direction'] ?? 'user_to_dispatcher')),
			'caller_extension' => $caller_extension,
			'callee_extension' => $callee_extension,
			'emergency_type' => trim((string) ($options['emergency_type'] ?? 'single')),
			'status' => trim((string) ($options['status'] ?? 'ringing')),
			'alarm_state' => $options['alarm_state'] ?? 'triggered',
			'alarm_trigger_time' => array_key_exists('alarm_trigger_time', $options) ? $options['alarm_trigger_time'] : operator_panel_now(),
			'start_time' => array_key_exists('start_time', $options) ? $options['start_time'] : operator_panel_now(),
		]);
	}

	return is_array($call) ? $call : [];
}

function operator_panel_direct_trigger_emergency_alarm(database $database, array $options = []) {
	$call = operator_panel_prepare_emergency_alarm_call($database, array_merge([
		'status' => 'ringing',
		'alarm_state' => 'triggered',
		'alarm_trigger_time' => operator_panel_now(),
		'direction' => 'user_to_dispatcher',
		'emergency_type' => 'single',
	], $options));
	if (empty($call)) {
		throw new RuntimeException('Emergency call not found');
	}

	$current_alarm_state = trim((string) ($call['alarm_state'] ?? ''));
	$current_status = trim((string) ($call['status'] ?? ''));
	$active_alarm_count_before = operator_panel_count_triggered_alarm_calls($database);
	if (
		in_array($current_alarm_state, ['acknowledged', 'cleared', 'skipped'], true) ||
		in_array($current_status, ['answered', 'completed', 'failed', 'rejected'], true)
	) {
		return [
			'call' => $call,
			'device_result' => null,
			'device_error' => '',
			'active_alarm_count' => operator_panel_count_triggered_alarm_calls($database),
			'skipped' => true,
		];
	}

	$update_fields = [
		'direction' => trim((string) ($options['direction'] ?? ($call['direction'] ?? 'user_to_dispatcher'))),
		'caller_extension' => operator_panel_clean_extension($options['caller_extension'] ?? ($call['caller_extension'] ?? '')),
		'callee_extension' => operator_panel_clean_extension($options['callee_extension'] ?? ($call['callee_extension'] ?? operator_panel_current_extension())),
		'emergency_type' => trim((string) ($options['emergency_type'] ?? ($call['emergency_type'] ?? 'single'))),
		'alarm_state' => 'triggered',
		'alarm_clear_time' => null,
		'alarm_clear_reason' => null,
		'alarm_error' => null,
	];
	if (empty($call['alarm_trigger_time'])) {
		$update_fields['alarm_trigger_time'] = '__now__';
	}
	if (is_uuid(operator_panel_clean_uuid($options['call_uuid'] ?? ''))) {
		$update_fields['call_uuid'] = operator_panel_clean_uuid($options['call_uuid']);
	}
	if (!in_array($current_status, ['answered', 'completed', 'failed', 'rejected'], true)) {
		$update_fields['status'] = trim((string) ($options['status'] ?? 'ringing'));
	}
	operator_panel_update_emergency_call($database, $call['emergency_call_uuid'], $update_fields);

	$device_result = null;
	$device_error = '';
	$should_trigger_device = ($active_alarm_count_before === 0);
	if ($should_trigger_device) {
		try {
			$device_result = operator_panel_alarm_trigger_device($options['alarm_config'] ?? null);
			operator_panel_update_emergency_call($database, $call['emergency_call_uuid'], [
				'alarm_last_response' => json_encode($device_result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
				'alarm_error' => '',
			]);
		}
		catch (Throwable $throwable) {
			$device_error = $throwable->getMessage();
			operator_panel_update_emergency_call($database, $call['emergency_call_uuid'], [
				'alarm_error' => $device_error,
			]);
		}
	}
	else {
		operator_panel_update_emergency_call($database, $call['emergency_call_uuid'], [
			'alarm_error' => '',
		]);
	}

	$runtime_patch = [
		'device_state' => $device_error !== '' ? 'error' : 'alarming',
		'last_error' => $device_error,
	];
	if ($device_result !== null) {
		$runtime_patch['last_device_response'] = json_encode($device_result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	}
	operator_panel_upsert_alarm_runtime($database, $runtime_patch);

	return [
		'call' => operator_panel_find_alarm_call($database, $call['emergency_call_uuid'], '', '') ?: $call,
		'device_result' => $device_result,
		'device_error' => $device_error,
		'active_alarm_count' => operator_panel_count_triggered_alarm_calls($database),
	];
}

function operator_panel_build_direct_clear_fields(array $call, array $options = []) {
	$now = operator_panel_now();
	$status = strtolower(trim((string) ($options['status'] ?? '')));
	$reason = trim((string) ($options['reason'] ?? 'operator_ack'));
	$acknowledge = array_key_exists('acknowledge', $options) ? !empty($options['acknowledge']) : true;
	$current_status = trim((string) ($call['status'] ?? ''));
	$fields = [];

	if ($status === 'answered' || $status === 'confirmed') {
		$fields['status'] = 'answered';
		if (empty($call['answer_time'])) {
			$fields['answer_time'] = $now;
		}
		$fields['alarm_state'] = 'cleared';
		if (empty($call['alarm_ack_time'])) {
			$fields['alarm_ack_time'] = $now;
		}
		if (empty($call['alarm_clear_time'])) {
			$fields['alarm_clear_time'] = $now;
		}
		$fields['alarm_clear_reason'] = ($reason !== '') ? $reason : 'answered';
		return $fields;
	}

	if (in_array($status, ['completed', 'failed', 'rejected'], true)) {
		$fields['status'] = $status;
		if (empty($call['end_time'])) {
			$fields['end_time'] = $now;
		}
		$fields['alarm_state'] = 'cleared';
		if (empty($call['alarm_clear_time'])) {
			$fields['alarm_clear_time'] = $now;
		}
		$fields['alarm_clear_reason'] = ($reason !== '') ? $reason : (($status === 'failed' || $status === 'rejected') ? 'failed' : 'hangup');
		return $fields;
	}

	$fields['alarm_state'] = 'acknowledged';
	if ($acknowledge && empty($call['alarm_ack_time'])) {
		$fields['alarm_ack_time'] = $now;
	}
	if (empty($call['alarm_clear_time'])) {
		$fields['alarm_clear_time'] = $now;
	}
	$fields['alarm_clear_reason'] = $reason !== '' ? $reason : 'operator_ack';
	if (!in_array($current_status, ['answered', 'completed', 'failed', 'rejected'], true)) {
		$fields['status'] = $status !== '' ? $status : ($acknowledge ? 'acknowledged' : $current_status);
	}
	return $fields;
}

function operator_panel_direct_clear_emergency_alarm(database $database, array $options = []) {
	$force_clear = !empty($options['force_clear']);
	$requested_emergency_uuid = operator_panel_clean_uuid($options['emergency_uuid'] ?? '');
	$call_uuid = operator_panel_clean_uuid($options['call_uuid'] ?? '');
	$callee_extension = operator_panel_clean_extension($options['callee_extension'] ?? operator_panel_current_extension());
	$caller_extension = operator_panel_clean_extension($options['caller_extension'] ?? '');
	$calls_to_update = [];

	if ($force_clear) {
		$calls_to_update = operator_panel_find_triggered_alarm_calls($database);
	}
	else {
		$call = [];
		if (is_uuid($requested_emergency_uuid)) {
			$call = operator_panel_find_alarm_call($database, $requested_emergency_uuid, '', '');
		}
		if (empty($call) && is_uuid($call_uuid)) {
			$call = operator_panel_find_alarm_call_by_call_uuid($database, $call_uuid);
		}
		if (empty($call) && $callee_extension !== '') {
			$call = operator_panel_find_alarm_call($database, '', $callee_extension, $caller_extension);
		}
		if (!empty($call)) {
			$calls_to_update[] = $call;
		}
	}

	if (empty($calls_to_update)) {
		$remaining_triggered = operator_panel_count_triggered_alarm_calls($database);
		operator_panel_upsert_alarm_runtime($database, [
			'device_state' => $remaining_triggered > 0 ? 'alarming' : 'idle',
		]);
		return [
			'updated_call_uuids' => [],
			'device_result' => null,
			'active_alarm_count' => $remaining_triggered,
			'skipped' => true,
		];
	}

	$updated_call_uuids = [];
	foreach ($calls_to_update as $call) {
		if (!is_uuid($call['emergency_call_uuid'] ?? '')) {
			continue;
		}
		$fields = operator_panel_build_direct_clear_fields($call, $options);
		operator_panel_update_emergency_call($database, $call['emergency_call_uuid'], $fields);
		$updated_call_uuids[] = $call['emergency_call_uuid'];
	}

	$remaining_triggered = operator_panel_count_triggered_alarm_calls($database);
	$device_result = null;
	$device_error = '';
	if ($remaining_triggered === 0) {
		try {
			$device_result = operator_panel_alarm_clear_device($options['alarm_config'] ?? null);
		}
		catch (Throwable $throwable) {
			$device_error = $throwable->getMessage();
		}
	}

	if (!empty($updated_call_uuids)) {
		$last_response = $device_result ? json_encode($device_result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
		foreach ($updated_call_uuids as $emergency_call_uuid) {
			$patch = [];
			if ($last_response !== null) {
				$patch['alarm_last_response'] = $last_response;
				$patch['alarm_error'] = '';
			}
			elseif ($device_error !== '') {
				$patch['alarm_error'] = $device_error;
			}
			if (!empty($patch)) {
				operator_panel_update_emergency_call($database, $emergency_call_uuid, $patch);
			}
		}
	}

	$runtime_patch = [
		'device_state' => $remaining_triggered > 0 ? 'alarming' : ($device_error !== '' ? 'error' : 'idle'),
		'last_error' => $device_error,
	];
	if ($device_result !== null) {
		$runtime_patch['last_device_response'] = json_encode($device_result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	}
	operator_panel_upsert_alarm_runtime($database, $runtime_patch);

	if ($device_error !== '') {
		throw new RuntimeException($device_error);
	}

	return [
		'updated_call_uuids' => $updated_call_uuids,
		'device_result' => $device_result,
		'active_alarm_count' => $remaining_triggered,
	];
}

function operator_panel_presence_ttl_seconds() {
	$ttl = (int) operator_panel_setting('emergency_alarm_presence_ttl', 90);
	if ($ttl < 30) {
		$ttl = 30;
	}
	if ($ttl > 600) {
		$ttl = 600;
	}
	return $ttl;
}

function operator_panel_upsert_presence(database $database, $tab_uuid, $extension, $sip_uri = '', $user_agent = '') {
	$now = operator_panel_now();
	$expires_at = date('Y-m-d H:i:s', time() + operator_panel_presence_ttl_seconds());
	$user_uuid = operator_panel_current_user_uuid();
	$select_sql = "select operator_panel_presence_uuid from v_operator_panel_presence ";
	$select_sql .= "where domain_uuid = :domain_uuid ";
	$select_sql .= "and user_uuid = :user_uuid ";
	$select_sql .= "and tab_uuid = :tab_uuid ";
	$select_sql .= "and extension = :extension ";
	$select_sql .= "limit 1";
	$select_parameters = [
		'domain_uuid' => $_SESSION['domain_uuid'],
		'user_uuid' => $user_uuid,
		'tab_uuid' => $tab_uuid,
		'extension' => $extension,
	];
	$presence_uuid = $database->select($select_sql, $select_parameters, 'column');

	if (is_uuid($presence_uuid)) {
		$sql = "update v_operator_panel_presence set ";
		$sql .= "sip_uri = :sip_uri, client_ip = :client_ip, user_agent = :user_agent, ";
		$sql .= "last_seen = :last_seen, expires_at = :expires_at, ";
		$sql .= "update_date = :update_date, update_user = :update_user ";
		$sql .= "where operator_panel_presence_uuid = :operator_panel_presence_uuid ";
		$sql .= "and domain_uuid = :domain_uuid";
		$parameters = [
			'operator_panel_presence_uuid' => $presence_uuid,
			'domain_uuid' => $_SESSION['domain_uuid'],
			'sip_uri' => $sip_uri,
			'client_ip' => operator_panel_client_ip(),
			'user_agent' => $user_agent,
			'last_seen' => $now,
			'expires_at' => $expires_at,
			'update_date' => $now,
			'update_user' => $user_uuid,
		];
		$database->execute($sql, $parameters);
		return $presence_uuid;
	}

	$presence_uuid = uuid();
	$sql = "insert into v_operator_panel_presence ";
	$sql .= "(operator_panel_presence_uuid, domain_uuid, user_uuid, tab_uuid, extension, sip_uri, client_ip, user_agent, last_seen, expires_at, insert_date, insert_user, update_date, update_user) ";
	$sql .= "values ";
	$sql .= "(:operator_panel_presence_uuid, :domain_uuid, :user_uuid, :tab_uuid, :extension, :sip_uri, :client_ip, :user_agent, :last_seen, :expires_at, :insert_date, :insert_user, :update_date, :update_user)";
	$parameters = [
		'operator_panel_presence_uuid' => $presence_uuid,
		'domain_uuid' => $_SESSION['domain_uuid'],
		'user_uuid' => $user_uuid,
		'tab_uuid' => $tab_uuid,
		'extension' => $extension,
		'sip_uri' => $sip_uri,
		'client_ip' => operator_panel_client_ip(),
		'user_agent' => $user_agent,
		'last_seen' => $now,
		'expires_at' => $expires_at,
		'insert_date' => $now,
		'insert_user' => $user_uuid,
		'update_date' => $now,
		'update_user' => $user_uuid,
	];
	$database->execute($sql, $parameters);
	return $presence_uuid;
}

function operator_panel_clear_presence(database $database, $tab_uuid, $extension = '') {
	$sql = "delete from v_operator_panel_presence ";
	$sql .= "where domain_uuid = :domain_uuid ";
	$sql .= "and user_uuid = :user_uuid ";
	$sql .= "and tab_uuid = :tab_uuid ";
	$parameters = [
		'domain_uuid' => $_SESSION['domain_uuid'],
		'user_uuid' => operator_panel_current_user_uuid(),
		'tab_uuid' => $tab_uuid,
	];
	if ($extension !== '') {
		$sql .= "and extension = :extension ";
		$parameters['extension'] = $extension;
	}
	$database->execute($sql, $parameters);
}

function operator_panel_insert_alarm_job(database $database, $action_name, $emergency_call_uuid = null, array $payload = []) {
	$job_uuid = uuid();
	$now = operator_panel_now();
	$sql = "insert into v_emergency_alarm_jobs ";
	$sql .= "(emergency_alarm_job_uuid, domain_uuid, emergency_call_uuid, action, payload, status, insert_date, insert_user, update_date, update_user) ";
	$sql .= "values ";
	$sql .= "(:emergency_alarm_job_uuid, :domain_uuid, :emergency_call_uuid, :action, :payload, :status, :insert_date, :insert_user, :update_date, :update_user)";
	$parameters = [
		'emergency_alarm_job_uuid' => $job_uuid,
		'domain_uuid' => $_SESSION['domain_uuid'],
		'emergency_call_uuid' => is_uuid($emergency_call_uuid) ? $emergency_call_uuid : null,
		'action' => $action_name,
		'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
		'status' => 'pending',
		'insert_date' => $now,
		'insert_user' => operator_panel_current_user_uuid(),
		'update_date' => $now,
		'update_user' => operator_panel_current_user_uuid(),
	];
	$database->execute($sql, $parameters);
	return $job_uuid;
}

function operator_panel_wait_for_alarm_job(database $database, $job_uuid, $timeout_seconds = 8) {
	$deadline = microtime(true) + max(1, (int) $timeout_seconds);
	$sql = "select * from v_emergency_alarm_jobs ";
	$sql .= "where emergency_alarm_job_uuid = :emergency_alarm_job_uuid ";
	$sql .= "and domain_uuid = :domain_uuid ";
	$sql .= "limit 1";
	$parameters = [
		'emergency_alarm_job_uuid' => $job_uuid,
		'domain_uuid' => $_SESSION['domain_uuid'],
	];

	do {
		$row = $database->select($sql, $parameters, 'row');
		if (!empty($row) && in_array($row['status'] ?? '', ['completed', 'failed'], true)) {
			return $row;
		}
		usleep(200000);
	} while (microtime(true) < $deadline);

	return $database->select($sql, $parameters, 'row');
}

function operator_panel_decode_json($value) {
	if (!is_string($value) || $value === '') {
		return [];
	}
	$decoded = json_decode($value, true);
	return is_array($decoded) ? $decoded : [];
}

//process request
switch ($action) {
	
	case 'reloadxml':
		//重载FreeSWITCH的XML配置
		$esl = event_socket::create();
		if ($esl && $esl->is_connected()) {
			$response = $esl->request("api reloadxml");
			echo json_encode([
				'success' => true,
				'message' => 'Reloaded XML configuration',
				'response' => $response
			]);
		} else {
			echo json_encode([
				'success' => false,
				'error' => 'Failed to connect to FreeSWITCH'
			]);
		}
		break;
		
	case 'get_extensions':
		//获取所有分机列表
		$sql = "select ";
		$sql .= "e.extension, ";
		$sql .= "e.number_alias, ";
		$sql .= "e.effective_caller_id_name, ";
		$sql .= "e.extension_owner, ";
		$sql .= "e.description, ";
		$sql .= "e.call_group ";
		$sql .= "from v_extensions as e ";
		$sql .= "where e.enabled = 'true' ";
		$sql .= "and e.domain_uuid = :domain_uuid ";
		$sql .= "order by e.extension asc ";
		
		$parameters['domain_uuid'] = $_SESSION['domain_uuid'];
		$database = new database;
		$extensions = $database->select($sql, $parameters);
		
		echo json_encode([
			'success' => true,
			'data' => $extensions
		]);
		break;
	
	case 'get_call_groups':
		//获取呼叫组配置
		$sql = "select distinct call_group ";
		$sql .= "from v_extensions ";
		$sql .= "where domain_uuid = :domain_uuid ";
		$sql .= "and call_group is not null ";
		$sql .= "and call_group != '' ";
		$sql .= "order by call_group ";
		
		$parameters['domain_uuid'] = $_SESSION['domain_uuid'];
		$database = new database;
		$call_groups = $database->select($sql, $parameters);
		
		//获取每个组的成员
		$groups = [];
		if (is_array($call_groups)) {
			foreach ($call_groups as $row) {
				$group_name = $row['call_group'];
				
				$sql = "select extension ";
				$sql .= "from v_extensions ";
				$sql .= "where domain_uuid = :domain_uuid ";
				$sql .= "and call_group = :call_group ";
				$sql .= "and enabled = 'true' ";
				$sql .= "order by extension ";
				
				$parameters['domain_uuid'] = $_SESSION['domain_uuid'];
				$parameters['call_group'] = $group_name;
				$members = $database->select($sql, $parameters);
				
				$extensions = [];
				if (is_array($members)) {
					foreach ($members as $member) {
						$extensions[] = $member['extension'];
					}
				}
				
				$groups[$group_name] = [
					'name' => $group_name,
					'extensions' => $extensions
				];
			}
		}
		
		echo json_encode([
			'success' => true,
			'data' => $groups
		]);
		break;
	
	case 'save_call_log':
		//保存通话日志
		$log_type = $_POST['log_type'] ?? ''; // trunk, group, conference
		$participants = $_POST['participants'] ?? '';
		$duration = $_POST['duration'] ?? 0;
		$start_time = $_POST['start_time'] ?? '';
		$end_time = $_POST['end_time'] ?? '';
		
		//可以保存到自定义表或使用现有的 CDR 表
		//这里仅做示例
		$log_data = [
			'log_type' => $log_type,
			'participants' => $participants,
			'duration' => $duration,
			'start_time' => $start_time,
			'end_time' => $end_time,
			'operator' => $_SESSION['user']['username'],
			'domain_uuid' => $_SESSION['domain_uuid']
		];
		
		//TODO: 保存到数据库
		//可以创建一个专门的调度日志表
		
		echo json_encode([
			'success' => true,
			'message' => 'Log saved'
		]);
		break;

	case 'get_sip_config':
		$database = new database;
		$sip_config = operator_panel_get_sip_config($database);

		echo json_encode([
			'success' => true,
			'data' => $sip_config
		]);
		break;

	case 'get_alarm_config':
		echo json_encode([
			'success' => true,
			'data' => operator_panel_get_alarm_config_values(false),
			'can_edit' => permission_exists('default_setting_edit')
		]);
		break;

	case 'save_alarm_config':
		if (!permission_exists('default_setting_edit')) {
			echo json_encode(['success' => false, 'error' => 'Permission denied']);
			break;
		}

		$host = operator_panel_clean_host(operator_panel_request('host', '192.168.50.1'));
		$port = (int) operator_panel_request('port', 9003);
		$timeout_ms = (int) operator_panel_request('timeout_ms', 1200);
		$audio_folder = (int) operator_panel_request('audio_folder', 1);
		$audio_track = (int) operator_panel_request('audio_track', 1);
		$strobe_mode = (int) operator_panel_request('strobe_mode', 3);
		$audio_volume = (int) operator_panel_request('audio_volume', 30);
		if ($host === '') {
			echo json_encode(['success' => false, 'error' => 'Invalid alarm host']);
			break;
		}
		if ($port < 1 || $port > 65535) {
			echo json_encode(['success' => false, 'error' => 'Invalid alarm port']);
			break;
		}
		if ($audio_volume < 0 || $audio_volume > 30) {
			echo json_encode(['success' => false, 'error' => 'Invalid alarm volume']);
			break;
		}
		if ($timeout_ms < 100 || $timeout_ms > 60000) {
			echo json_encode(['success' => false, 'error' => 'Invalid alarm socket timeout']);
			break;
		}
		if ($audio_folder < 0 || $audio_folder > 255) {
			echo json_encode(['success' => false, 'error' => 'Invalid audio folder']);
			break;
		}
		if ($audio_track < 0 || $audio_track > 255) {
			echo json_encode(['success' => false, 'error' => 'Invalid audio track']);
			break;
		}
		if ($strobe_mode < 0 || $strobe_mode > 255) {
			echo json_encode(['success' => false, 'error' => 'Invalid strobe mode']);
			break;
		}

		try {
			$database = new database;
			operator_panel_save_alarm_setting($database, 'emergency_alarm_host', $host, 'text', 'Emergency alarm TCP client host.');
			operator_panel_save_alarm_setting($database, 'emergency_alarm_port', $port, 'numeric', 'Emergency alarm TCP client port.');
			operator_panel_save_alarm_setting($database, 'emergency_alarm_socket_timeout_ms', $timeout_ms, 'numeric', 'Emergency alarm TCP socket timeout in milliseconds.');
			operator_panel_save_alarm_setting($database, 'emergency_alarm_audio_folder', $audio_folder, 'numeric', 'Alarm device audio folder number for loop playback.');
			operator_panel_save_alarm_setting($database, 'emergency_alarm_audio_track', $audio_track, 'numeric', 'Alarm device track number for loop playback.');
			operator_panel_save_alarm_setting($database, 'emergency_alarm_strobe_mode', $strobe_mode, 'numeric', 'Alarm device strobe mode value for emergency alarm activation.');
			operator_panel_save_alarm_setting($database, 'emergency_alarm_audio_volume', $audio_volume, 'numeric', 'Emergency alarm playback volume.');
			settings::clear_cache();
			$restart_result = operator_panel_restart_alarm_service();

			echo json_encode([
				'success' => true,
				'data' => operator_panel_get_alarm_config_values(false),
				'service_restart' => $restart_result,
				'restart_required' => !$restart_result['success'],
			]);
		}
		catch (Throwable $throwable) {
			echo json_encode([
				'success' => false,
				'error' => $throwable->getMessage(),
			]);
		}
		break;
		//获取调度员 SIP 配置（如果在数据库中存储）
		//从用户设置或默认设置中读取
		
		$sip_config = [
			'uri' => 'sip:' . ($_SESSION['user']['extension'][0]['user'] ?? '1000') . '@' . $_SESSION['domain_name'],
			'wsServers' => 'ws://' . $_SESSION['domain_name'] . ':5066',
			'authUser' => $_SESSION['user']['extension'][0]['user'] ?? '1000',
			'displayName' => $_SESSION['user']['username'] ?? '调度员'
		];
		
		echo json_encode([
			'success' => true,
			'data' => $sip_config
		]);
		break;
	
	case 'get_active_calls':
		//获取当前活动通话
		$switch_result = event_socket::api('show channels as json');
		
		if ($switch_result !== false) {
			$channels = json_decode($switch_result, true);
			echo json_encode([
				'success' => true,
				'data' => $channels
			]);
		} else {
			echo json_encode([
				'success' => false,
				'error' => 'Failed to get active calls'
			]);
		}
		break;

	case 'get_entity_status_snapshot':
		$database = new database;
		$warnings = [];

		list($channels_ok, $channels_error, $channel_rows) = operator_panel_get_channel_rows();
		if (!$channels_ok) {
			$warnings[] = [
				'code' => 'channels_unavailable',
				'detail' => $channels_error,
			];
			$channel_rows = [];
		}

		list($registrations_ok, $registrations_error, $registration_map) = operator_panel_get_registration_map($database);
		if (!$registrations_ok) {
			$warnings[] = [
				'code' => 'registrations_unavailable',
				'detail' => $registrations_error,
			];
			$registration_map = [];
		}

		list($gateway_runtime_ok, $gateway_runtime_error, $gateway_runtime_map) = operator_panel_get_gateway_runtime_map();
		if (!$gateway_runtime_ok) {
			$warnings[] = [
				'code' => 'gateway_runtime_unavailable',
				'detail' => $gateway_runtime_error,
			];
			$gateway_runtime_map = [];
		}

		$users = operator_panel_get_extension_status_entities($database, $channel_rows, $registration_map, $registrations_ok);
		$trunks = operator_panel_get_trunk_status_entities($database, $channel_rows, $gateway_runtime_map, $gateway_runtime_ok);

		echo json_encode([
			'success' => true,
			'generated_at' => operator_panel_now(),
			'current_extension' => operator_panel_current_extension(),
			'users' => $users,
			'trunks' => $trunks,
			'summary' => [
				'users' => operator_panel_summarize_entities($users, 'user'),
				'trunks' => operator_panel_summarize_entities($trunks, 'trunk'),
			],
			'sources' => [
				'channels' => $channels_ok,
				'registrations' => $registrations_ok,
				'gateway_runtime' => $gateway_runtime_ok,
			],
			'warnings' => $warnings,
		]);
		break;

	case 'heartbeat':
		echo json_encode([
			'success' => true,
			'timestamp' => operator_panel_now()
		]);
		break;

	case 'report_operator_panel_presence':
		$tab_uuid = operator_panel_request('tab_uuid', '');
		$extensions = operator_panel_requested_extensions();
		$sip_uri = trim((string) operator_panel_request('sip_uri', ''));
		$user_agent = trim((string) operator_panel_request('user_agent', ($_SERVER['HTTP_USER_AGENT'] ?? '')));
		if (!operator_panel_valid_tab_uuid($tab_uuid)) {
			echo json_encode(['success' => false, 'error' => 'Invalid tab UUID']);
			break;
		}
		if (empty($extensions)) {
			echo json_encode(['success' => false, 'error' => 'No operator extension available']);
			break;
		}
		$database = new database;
		$presence_uuids = [];
		foreach ($extensions as $extension) {
			$presence_uuids[] = operator_panel_upsert_presence($database, $tab_uuid, $extension, $sip_uri, $user_agent);
		}
		echo json_encode([
			'success' => true,
			'presence_uuid' => $presence_uuids[0] ?? null,
			'presence_uuids' => $presence_uuids,
			'extensions' => $extensions,
			'expires_in' => operator_panel_presence_ttl_seconds()
		]);
		break;

	case 'clear_operator_panel_presence':
		$tab_uuid = operator_panel_request('tab_uuid', '');
		$extensions = operator_panel_requested_extensions();
		if (!operator_panel_valid_tab_uuid($tab_uuid)) {
			echo json_encode(['success' => false, 'error' => 'Invalid tab UUID']);
			break;
		}
		$database = new database;
		if (empty($extensions)) {
			operator_panel_clear_presence($database, $tab_uuid);
		}
		else {
			foreach ($extensions as $extension) {
				operator_panel_clear_presence($database, $tab_uuid, $extension);
			}
		}
		echo json_encode(['success' => true]);
		break;

	case 'alarm_health':
		if (!permission_exists('operator_panel_emergency')) {
			echo json_encode(['success' => false, 'error' => 'Permission denied']);
			break;
		}
		$database = new database;
		$runtime_sql = "select * from v_emergency_alarm_runtime ";
		$runtime_sql .= "where domain_uuid = :domain_uuid ";
		$runtime_sql .= "and service_name = :service_name ";
		$runtime_sql .= "order by update_date desc ";
		$runtime_sql .= "limit 1";
		$runtime = $database->select($runtime_sql, [
			'domain_uuid' => $_SESSION['domain_uuid'],
			'service_name' => 'emergency_alarm'
		], 'row');

		$active_sql = "select count(*) from v_emergency_calls ";
		$active_sql .= "where domain_uuid = :domain_uuid ";
		$active_sql .= "and alarm_state = 'triggered' ";
		$active_sql .= "and (end_time is null or coalesce(status, '') not in ('completed', 'failed', 'rejected'))";
		$active_count = (int) $database->select($active_sql, ['domain_uuid' => $_SESSION['domain_uuid']], 'column');

		$presence_sql = "select count(*) from v_operator_panel_presence ";
		$presence_sql .= "where domain_uuid = :domain_uuid ";
		$presence_sql .= "and expires_at >= :now";
		$presence_count = (int) $database->select($presence_sql, [
			'domain_uuid' => $_SESSION['domain_uuid'],
			'now' => operator_panel_now()
		], 'column');

		$alarm_config = operator_panel_get_alarm_config_values();

		echo json_encode([
			'success' => true,
			'runtime' => $runtime ?: null,
			'active_alarm_count' => $active_count,
			'presence_count' => $presence_count,
			'device_host' => $alarm_config['host'],
			'device_port' => $alarm_config['port'],
			'configured_endpoint' => $alarm_config['configured_endpoint']
		]);
		break;

	case 'trigger_emergency_alarm':
		if (!permission_exists('operator_panel_emergency')) {
			echo json_encode(['success' => false, 'error' => 'Permission denied']);
			break;
		}
		$database = new database;
		try {
			$result = operator_panel_direct_trigger_emergency_alarm($database, [
				'emergency_uuid' => operator_panel_request('emergency_uuid', ''),
				'call_uuid' => operator_panel_request('call_uuid', operator_panel_request('session_id', '')),
				'caller_extension' => operator_panel_request('caller_extension', ''),
				'callee_extension' => operator_panel_current_extension(),
				'direction' => 'user_to_dispatcher',
				'emergency_type' => operator_panel_request('emergency_type', 'single'),
				'status' => operator_panel_request('status', 'ringing'),
			]);

			echo json_encode([
				'success' => ($result['device_error'] ?? '') === '',
				'emergency_uuid' => $result['call']['emergency_call_uuid'] ?? null,
				'active_alarm_count' => $result['active_alarm_count'] ?? 0,
				'device' => $result['device_result'] ?? null,
				'error' => $result['device_error'] ?? '',
			]);
		}
		catch (Throwable $throwable) {
			echo json_encode([
				'success' => false,
				'error' => $throwable->getMessage(),
			]);
		}
		break;

	case 'monitor_call':
		if (!permission_exists('operator_panel_eavesdrop')) {
			echo json_encode(['success' => false, 'error' => 'Permission denied']);
			break;
		}

		$mode = operator_panel_clean_monitor_mode(operator_panel_request('mode', 'listen'));
		if ($mode === '') {
			echo json_encode(['success' => false, 'error' => 'Invalid monitor mode']);
			break;
		}

		$operator_extension = operator_panel_clean_extension(operator_panel_request('operator_extension', ''));
		$allowed_extensions = operator_panel_allowed_extensions();
		if ($operator_extension === '') {
			$operator_extension = operator_panel_current_extension();
		}
		if ($operator_extension === '') {
			echo json_encode(['success' => false, 'error' => 'operator_extension required']);
			break;
		}
		if (!empty($allowed_extensions) && !in_array($operator_extension, $allowed_extensions, true)) {
			echo json_encode(['success' => false, 'error' => 'Invalid operator extension']);
			break;
		}

		$target_extension = operator_panel_clean_extension(operator_panel_request('target_extension', ''));
		$target_channel_uuid = operator_panel_clean_uuid(operator_panel_request('target_channel_uuid', ''));
		$bridge_channel_uuid = operator_panel_clean_uuid(operator_panel_request('bridge_channel_uuid', ''));
		if ($target_extension === '' && $target_channel_uuid === '') {
			echo json_encode(['success' => false, 'error' => 'target required']);
			break;
		}

		list($rows_ok, $rows_error, $channel_rows) = operator_panel_get_channel_rows();
		if (!$rows_ok) {
			echo json_encode(['success' => false, 'error' => $rows_error]);
			break;
		}

		$target_row = operator_panel_find_target_channel($channel_rows, $target_extension, $target_channel_uuid);
		if (!is_array($target_row)) {
			echo json_encode(['success' => false, 'error' => 'Target channel not found']);
			break;
		}

		$target_channel_uuid = operator_panel_clean_uuid($target_row['uuid'] ?? $target_channel_uuid);
		if ($target_channel_uuid === '') {
			echo json_encode(['success' => false, 'error' => 'Invalid target channel uuid']);
			break;
		}
		if ($target_extension === '') {
			$target_extension = operator_panel_clean_extension($target_row['destination_number'] ?? ($target_row['cid_num'] ?? ''));
		}
		if ($bridge_channel_uuid === '') {
			$bridge_channel_uuid = operator_panel_find_bridge_uuid($target_row);
		}

		$domain_name = $_SESSION['domain_name'] ?? '';
		if ($domain_name === '') {
			echo json_encode(['success' => false, 'error' => 'Missing domain context']);
			break;
		}

		$scene = $mode === 'barge' ? 'barge_leg' : 'eavesdrop_leg';
		$caller_id_name = $mode === 'barge' ? 'Barge' : 'Eavesdrop';
		$params = [
			'origination_caller_id_name=' . $caller_id_name,
			'origination_caller_id_number=' . ($target_extension !== '' ? $target_extension : $operator_extension),
			'originate_timeout=15',
			'sip_h_X-Call-Scene=' . $scene,
			'sip_h_X-Monitor-Mode=' . $mode,
			'sip_h_X-Monitor-Target-Ext=' . $target_extension,
			'sip_h_X-Monitor-Target-Uuid=' . $target_channel_uuid,
		];
		$params = operator_panel_recording::add_originate_recording_params(
			$params,
			operator_panel_recording::session_archive_path($domain_name, $mode === 'barge' ? 'barge-${uuid}' : 'eavesdrop-${uuid}')
		);
		if ($bridge_channel_uuid !== '') {
			$params[] = 'sip_h_X-Monitor-Bridge-Uuid=' . $bridge_channel_uuid;
		}

		$conference_name = '';
		$transfer_result = null;
		if ($mode === 'barge') {
			$conference_name = 'barge-' . time() . '-' . substr(md5(uniqid('', true)), 0, 8);
			$params[] = 'sip_auto_answer=true';
			$params[] = 'sip_h_X-Monitor-Conference=' . $conference_name;
			$transfer_cmd = 'uuid_transfer ' . $target_channel_uuid . ' -both conference:' . $conference_name . '@default inline';
			$transfer_result = event_socket::api($transfer_cmd);
			if (!operator_panel_esl_command_success($transfer_result)) {
				echo json_encode(['success' => false, 'error' => 'Target transfer failed', 'detail' => $transfer_result]);
				break;
			}
			$app = '&conference(' . $conference_name . '@default)';
		}
		else {
			$app = '&eavesdrop(' . $target_channel_uuid . ')';
		}

		$originate_cmd = 'bgapi originate {' . implode(',', $params) . '}user/' . $operator_extension . '@' . $domain_name . ' ' . $app;
		$originate_result = event_socket::api($originate_cmd);
		if (!operator_panel_esl_command_success($originate_result)) {
			echo json_encode(['success' => false, 'error' => 'Monitor originate failed', 'detail' => $originate_result]);
			break;
		}

		$job_uuid = '';
		if (preg_match('/Job-UUID:\\s*([A-Fa-f0-9-]+)/', (string) $originate_result, $match)) {
			$job_uuid = $match[1];
		}

		echo json_encode([
			'success' => true,
			'mode' => $mode,
			'scene' => $scene,
			'job_uuid' => $job_uuid,
			'operator_extension' => $operator_extension,
			'target_extension' => $target_extension,
			'target_channel_uuid' => $target_channel_uuid,
			'bridge_channel_uuid' => $bridge_channel_uuid,
			'conference_name' => $conference_name,
			'transfer_result' => $transfer_result,
			'originate_result' => $originate_result
		]);
		break;

	case 'force_release_call':
		if (!permission_exists('operator_panel_eavesdrop')) {
			echo json_encode(['success' => false, 'error' => 'Permission denied']);
			break;
		}

		$channel_uuids = $_POST['channel_uuids'] ?? $_GET['channel_uuids'] ?? [];
		if (!is_array($channel_uuids)) {
			$channel_uuids = array_map('trim', explode(',', (string) $channel_uuids));
		}
		$channel_uuids = array_values(array_unique(array_filter(array_map('operator_panel_clean_uuid', $channel_uuids))));
		$target_extension = operator_panel_clean_extension(operator_panel_request('target_extension', ''));
		$conference_name = operator_panel_clean_conference_name(operator_panel_request('conference_name', ''));
		$channel_rows = [];
		$rows_ok = false;
		$rows_error = '';

		if ($target_extension !== '' || $conference_name !== '') {
			list($rows_ok, $rows_error, $channel_rows) = operator_panel_get_channel_rows();
			if (!$rows_ok && empty($channel_uuids)) {
				echo json_encode(['success' => false, 'error' => $rows_error]);
				break;
			}
		}

		if ($rows_ok && $target_extension !== '') {
			$target_row = operator_panel_find_target_channel($channel_rows, $target_extension, '');
			if (is_array($target_row)) {
				$target_uuid = operator_panel_clean_uuid($target_row['uuid'] ?? '');
				$bridge_uuid = operator_panel_find_bridge_uuid($target_row);
				if ($target_uuid !== '') {
					$channel_uuids[] = $target_uuid;
				}
				if ($bridge_uuid !== '') {
					$channel_uuids[] = $bridge_uuid;
				}
				if ($conference_name === '') {
					$conference_name = operator_panel_row_conference_name($target_row);
				}
			}
		}

		$conference_channel_uuids = [];
		if ($rows_ok && $conference_name !== '') {
			$conference_channel_uuids = operator_panel_collect_conference_channel_uuids($channel_rows, $conference_name);
			if (!empty($conference_channel_uuids)) {
				$channel_uuids = array_merge($channel_uuids, $conference_channel_uuids);
			}
		}
		$channel_uuids = array_values(array_unique(array_filter(array_map('operator_panel_clean_uuid', $channel_uuids))));

		if (empty($channel_uuids)) {
			echo json_encode(['success' => false, 'error' => 'No channel uuid provided']);
			break;
		}

		$killed = 0;
		$results = [];
		foreach ($channel_uuids as $channel_uuid) {
			$response = event_socket::api('uuid_kill ' . $channel_uuid);
			$success = operator_panel_esl_command_success($response);
			if ($success) {
				$killed++;
			}
			$results[] = [
				'uuid' => $channel_uuid,
				'success' => $success,
				'response' => $response
			];
		}

		echo json_encode([
			'success' => $killed > 0,
			'killed' => $killed,
			'conference_name' => $conference_name,
			'resolved_channel_uuids' => $channel_uuids,
			'conference_channel_uuids' => $conference_channel_uuids,
			'results' => $results
		]);
		break;

	case 'start_call_recording':
		$extension = operator_panel_current_extension();
		$allowed_extensions = operator_panel_allowed_extensions();
		if ($extension === '') {
			echo json_encode(['success' => false, 'error' => 'extension required']);
			break;
		}
		if (!empty($allowed_extensions) && !in_array($extension, $allowed_extensions, true)) {
			echo json_encode(['success' => false, 'error' => 'Invalid operator extension']);
			break;
		}

		$requested_channel_uuid = operator_panel_clean_uuid(operator_panel_request('channel_uuid', ''));
		$remote_number = operator_panel_clean_extension(operator_panel_request('remote_number', ''));
		$direction_hint = strtolower(trim((string) operator_panel_request('direction', '')));
		list($rows_ok, $rows_error, $channel_rows) = operator_panel_get_channel_rows();
		if (!$rows_ok) {
			echo json_encode(['success' => false, 'error' => $rows_error]);
			break;
		}

		$target_row = null;
		if ($requested_channel_uuid !== '') {
			$target_row = operator_panel_find_target_channel($channel_rows, '', $requested_channel_uuid);
		}
		if (!is_array($target_row)) {
			$target_row = operator_panel_find_recording_channel_row($channel_rows, $extension, $remote_number, $direction_hint);
		}
		if (!is_array($target_row)) {
			$target_row = operator_panel_find_extension_channel_row($channel_rows, $extension);
		}
		if (!is_array($target_row)) {
			$target_row = operator_panel_find_target_channel($channel_rows, $extension, '');
		}
		if (!is_array($target_row)) {
			echo json_encode(['success' => false, 'error' => 'Target channel not found']);
			break;
		}

		$channel_uuid = operator_panel_clean_uuid($target_row['uuid'] ?? $requested_channel_uuid);
		if ($channel_uuid === '') {
			echo json_encode(['success' => false, 'error' => 'Invalid target channel uuid']);
			break;
		}

		$bridge_uuid = operator_panel_find_bridge_uuid($target_row);
		$recording_path = operator_panel_recording::session_archive_path(
			$_SESSION['domain_name'] ?? '',
			$channel_uuid
		);
		$recording_result = operator_panel_recording::enable_record_session($channel_uuid, $recording_path, [$bridge_uuid]);

		echo json_encode([
			'success' => !empty($recording_result['success']),
			'extension' => $extension,
			'remote_number' => $remote_number,
			'direction' => $direction_hint,
			'channel_uuid' => $channel_uuid,
			'bridge_uuid' => $bridge_uuid,
			'recording_path' => $recording_path,
			'responses' => $recording_result['responses'] ?? [],
			'error' => $recording_result['error'] ?? '',
		]);
		break;

	case 'check_transfer':
		// 检查转接目标是否已建立通话（用于前端轮询确认）
		$target_ext = $_POST['target_ext'] ?? $_GET['target_ext'] ?? '';
		if ($target_ext === '') {
			echo json_encode(['success' => false, 'error' => 'target_ext required']);
			break;
		}
		$channels_result = event_socket::api('show channels as json');
		if ($channels_result === false) {
			echo json_encode(['success' => false, 'error' => 'esl_unavailable']);
			break;
		}
		$channels = json_decode($channels_result, true);
		$found = false;
		$detail = null;
		if (is_array($channels) && isset($channels['rows'])) {
			foreach ($channels['rows'] as $row) {
				$dest = $row['destination_number'] ?? '';
				$state = $row['callstate'] ?? '';
				if ($dest == $target_ext && strtoupper($state) === 'ACTIVE') {
					$found = true;
					$detail = [
						'uuid' => $row['uuid'] ?? '',
						'cid_num' => $row['cid_num'] ?? '',
						'cid_name' => $row['cid_name'] ?? ''
					];
					break;
				}
			}
		}
		echo json_encode(['success' => $found, 'detail' => $detail]);
		break;

	case 'force_hangup':
		// 强制挂断：根据 uri/direction 查找并终止匹配的通道
		$uri = $_POST['uri'] ?? $_GET['uri'] ?? '';
		$direction = $_POST['direction'] ?? $_GET['direction'] ?? '';
		if ($uri === '') {
			echo json_encode(['success' => false, 'error' => 'uri required']);
			break;
		}
		if (!preg_match('/sip:(\d+)@/i', $uri, $m)) {
			echo json_encode(['success' => false, 'error' => 'invalid uri']);
			break;
		}
		$ext = $m[1];
		$channels_result = event_socket::api('show channels as json');
		if ($channels_result === false) {
			echo json_encode(['success' => false, 'error' => 'esl_unavailable']);
			break;
		}
		$channels = json_decode($channels_result, true);
		$killed = 0;
		$uuids = [];
		if (is_array($channels) && isset($channels['rows'])) {
			foreach ($channels['rows'] as $row) {
				$dest = $row['destination_number'] ?? '';
				$cid = $row['cid_num'] ?? '';
				$state = strtoupper($row['callstate'] ?? '');
				$dir = strtolower($row['direction'] ?? '');
				if ($state !== 'ACTIVE' && $state !== 'RINGING' && $state !== 'EARLY' && $state !== 'HELD') continue;
				if ($direction && $dir && $direction !== $dir) continue;
				if ($dest == $ext || $cid == $ext) {
					$uuid = $row['uuid'] ?? '';
					if ($uuid) { $uuids[] = $uuid; }
				}
			}
		}
		foreach ($uuids as $u) {
			$resp = event_socket::api('uuid_kill ' . $u);
			if ($resp !== false && stripos($resp, '-ERR') === false) { $killed++; }
		}
		echo json_encode(['success' => true, 'killed' => $killed]);
		break;
	
	case 'initiate_emergency_call':
		// 发起急呼（调度员→用户）
		$target_extension = $_POST['target_extension'] ?? '';
		$emergency_type = $_POST['emergency_type'] ?? 'single'; // single, group, broadcast
		$targets = $_POST['targets'] ?? []; // 目标分机数组
		
		if (empty($target_extension) && empty($targets)) {
			echo json_encode(['success' => false, 'error' => 'No target specified']);
			exit;
		}
		
		// 检查权限
		if (!permission_exists('operator_panel_emergency')) {
			echo json_encode(['success' => false, 'error' => 'Permission denied']);
			exit;
		}
		
		// 广播急呼需要特殊权限
		if ($emergency_type === 'broadcast' && !permission_exists('operator_panel_emergency_broadcast')) {
			echo json_encode(['success' => false, 'error' => 'Broadcast permission denied']);
			exit;
		}
		
		// 创建急呼记录
		$emergency_uuid = uuid();
		$sql = "INSERT INTO v_emergency_calls (";
		$sql .= "emergency_call_uuid, domain_uuid, direction, caller_extension, callee_extension, ";
		$sql .= "emergency_type, status, alarm_state, alarm_trigger_time, start_time, insert_date, insert_user, update_date, update_user";
		$sql .= ") VALUES (";
		$sql .= ":emergency_call_uuid, :domain_uuid, :direction, :caller_extension, :callee_extension, ";
		$sql .= ":emergency_type, :status, :alarm_state, :alarm_trigger_time, :start_time, :insert_date, :insert_user, :update_date, :update_user";
		$sql .= ")";
		
		$parameters = [
			'emergency_call_uuid' => $emergency_uuid,
			'domain_uuid' => $_SESSION['domain_uuid'],
			'direction' => 'dispatcher_to_user',
			'caller_extension' => $_SESSION['user']['extension'][0]['user'] ?? '',
			'callee_extension' => $target_extension,
			'emergency_type' => $emergency_type,
			'status' => 'initiated',
			'alarm_state' => 'triggered',
			'alarm_trigger_time' => operator_panel_now(),
			'start_time' => operator_panel_now(),
			'insert_date' => operator_panel_now(),
			'insert_user' => $_SESSION['user']['user_uuid'],
			'update_date' => operator_panel_now(),
			'update_user' => $_SESSION['user']['user_uuid']
		];
		
		$database = new database;
		$database->execute($sql, $parameters);
		
		echo json_encode([
			'success' => true,
			'emergency_uuid' => $emergency_uuid
		]);
		break;

	case 'log_emergency_call':
		// 记录急呼状态更新
		$emergency_uuid = operator_panel_request('emergency_uuid', '');
		$call_uuid = trim((string) operator_panel_request('call_uuid', ''));
		$status = trim((string) operator_panel_request('status', ''));
		$recording_path = trim((string) operator_panel_request('recording_path', ''));
		$current_extension = operator_panel_current_extension();
		$caller_extension = operator_panel_clean_extension(operator_panel_request('caller_extension', ''));
		
		$database = new database;
		$call = operator_panel_find_alarm_call($database, $emergency_uuid, $current_extension, $caller_extension);
		if (empty($call) && !is_uuid($emergency_uuid) && $caller_extension === '') {
			$call = operator_panel_find_alarm_call($database, '', $current_extension);
		}
		if (empty($call)) {
			echo json_encode(['success' => false, 'error' => 'Emergency call not found']);
			break;
		}

		$fields = [];
		if (is_uuid($call_uuid)) {
			$fields['call_uuid'] = $call_uuid;
		}
		$is_dispatcher_emergency = (($call['direction'] ?? '') === 'dispatcher_to_user');
		if ($status !== '') {
			$fields['status'] = $status;
			if ($status === 'answered' || $status === 'confirmed') {
				$fields['answer_time'] = operator_panel_now();
				if ($is_dispatcher_emergency && ($call['alarm_state'] ?? '') !== 'cleared') {
					$fields['alarm_state'] = 'cleared';
					if (empty($call['alarm_ack_time'])) {
						$fields['alarm_ack_time'] = operator_panel_now();
					}
					if (empty($call['alarm_clear_time'])) {
						$fields['alarm_clear_time'] = operator_panel_now();
					}
					$fields['alarm_clear_reason'] = 'answered';
				}
			}
			if (in_array($status, ['completed', 'failed', 'rejected'], true)) {
				$fields['end_time'] = operator_panel_now();
				if ($is_dispatcher_emergency && ($call['alarm_state'] ?? '') !== 'cleared') {
					$fields['alarm_state'] = 'cleared';
					if (empty($call['alarm_clear_time'])) {
						$fields['alarm_clear_time'] = operator_panel_now();
					}
					$fields['alarm_clear_reason'] = ($status === 'failed' || $status === 'rejected') ? 'failed' : 'hangup';
				}
			}
		}
		if ($recording_path !== '') {
			$fields['recording_path'] = $recording_path;
		}
		if (empty($fields)) {
			echo json_encode(['success' => true, 'emergency_uuid' => $call['emergency_call_uuid']]);
			break;
		}

		operator_panel_update_emergency_call($database, $call['emergency_call_uuid'], $fields);
		echo json_encode(['success' => true, 'emergency_uuid' => $call['emergency_call_uuid']]);
		break;

	case 'acknowledge_and_clear_alarm':
	case 'force_clear_alarm':
		if (!permission_exists('operator_panel_emergency')) {
			echo json_encode(['success' => false, 'error' => 'Permission denied']);
			break;
		}

		$database = new database;
		$requested_emergency_uuid = operator_panel_request('emergency_uuid', '');
		$caller_extension = operator_panel_clean_extension(operator_panel_request('caller_extension', ''));
		$current_extension = operator_panel_current_extension();
		try {
			$result = operator_panel_direct_clear_emergency_alarm($database, [
				'force_clear' => ($action === 'force_clear_alarm'),
				'acknowledge' => true,
				'emergency_uuid' => $requested_emergency_uuid,
				'call_uuid' => operator_panel_request('call_uuid', operator_panel_request('session_id', '')),
				'caller_extension' => $caller_extension,
				'callee_extension' => $current_extension,
				'reason' => operator_panel_request('reason', $action === 'force_clear_alarm' ? 'force_clear' : 'operator_ack'),
			]);
			echo json_encode([
				'success' => true,
				'queued' => false,
				'job_uuid' => null,
				'emergency_uuid' => is_uuid($requested_emergency_uuid) ? $requested_emergency_uuid : ($result['updated_call_uuids'][0] ?? null),
				'result' => [
					'cleared_call_uuids' => $result['updated_call_uuids'],
					'device' => $result['device_result'] ?? null,
					'active_alarm_count' => $result['active_alarm_count'] ?? 0,
				],
			]);
		}
		catch (Throwable $throwable) {
			echo json_encode([
				'success' => false,
				'queued' => false,
				'job_uuid' => null,
				'error' => $throwable->getMessage(),
			]);
		}
		break;

	case 'clear_emergency_alarm':
		if (!permission_exists('operator_panel_emergency')) {
			echo json_encode(['success' => false, 'error' => 'Permission denied']);
			break;
		}
		$database = new database;
		try {
			$result = operator_panel_direct_clear_emergency_alarm($database, [
				'force_clear' => filter_var(operator_panel_request('force_clear', false), FILTER_VALIDATE_BOOLEAN),
				'acknowledge' => filter_var(operator_panel_request('acknowledge', true), FILTER_VALIDATE_BOOLEAN),
				'emergency_uuid' => operator_panel_request('emergency_uuid', ''),
				'call_uuid' => operator_panel_request('call_uuid', operator_panel_request('session_id', '')),
				'caller_extension' => operator_panel_request('caller_extension', ''),
				'callee_extension' => operator_panel_current_extension(),
				'status' => operator_panel_request('status', ''),
				'reason' => operator_panel_request('reason', 'ui_clear'),
			]);
			echo json_encode([
				'success' => true,
				'active_alarm_count' => $result['active_alarm_count'] ?? 0,
				'emergency_uuid' => is_uuid(operator_panel_request('emergency_uuid', '')) ? operator_panel_request('emergency_uuid', '') : ($result['updated_call_uuids'][0] ?? null),
				'cleared_call_uuids' => $result['updated_call_uuids'],
				'device' => $result['device_result'] ?? null,
			]);
		}
		catch (Throwable $throwable) {
			echo json_encode([
				'success' => false,
				'error' => $throwable->getMessage(),
			]);
		}
		break;

	case 'bridge_emergency_call':
		if (!permission_exists('operator_panel_emergency')) {
			echo json_encode(['success' => false, 'error' => 'Permission denied']);
			break;
		}
		// 桥接急呼：将已park的通道桥接到调度员
		$destination = $_POST['destination'] ?? '';
		$target_channel_uuid = $_POST['target_channel_uuid'] ?? '';
		
		if (empty($destination)) {
			echo json_encode(['success' => false, 'error' => 'Destination is required']);
			break;
		}
		
		// 检查session中是否有急呼信息
		if (!isset($_SESSION['emergency_bridge'][$destination])) {
			echo json_encode(['success' => false, 'error' => 'No emergency call found for destination']);
			break;
		}
		
		$emergency_info = $_SESSION['emergency_bridge'][$destination];
		$source = $emergency_info['source'];
		$job_uuid = $emergency_info['job_uuid'];
		
		// 检查超时（5分钟）
		if (time() - $emergency_info['timestamp'] > 300) {
			unset($_SESSION['emergency_bridge'][$destination]);
			echo json_encode(['success' => false, 'error' => 'Emergency call timed out']);
			break;
		}
		
		// 获取目标分机的通道UUID（优先使用前端提供的 UUID）
		if (empty($target_channel_uuid)) {
			$channels_cmd = 'show channels as json';
			$channels_result = event_socket::api($channels_cmd);
			$channels = json_decode($channels_result, true);
			
			if (is_array($channels) && isset($channels['rows'])) {
				foreach ($channels['rows'] as $channel) {
					// 查找park的通道（destination_number为目标分机）
					if (isset($channel['destination_number']) && 
						$channel['destination_number'] == $destination &&
						isset($channel['application']) && strpos($channel['application'], 'park') !== false) {
						$target_channel_uuid = $channel['uuid'];
						break;
					}
				}
			}
		}
		
		if (!$target_channel_uuid) {
			echo json_encode(['success' => false, 'error' => 'Target channel not found']);
			break;
		}
		
		// 为调度员通道生成UUID，便于后续跟踪
		$dispatcher_uuid = uuid();
		$domain_name = $_SESSION['domain_name'];
		
		// 构造调度员 leg 的急呼参数（与 exec.php 中保持一致）
		$params = [];
		$params[] = 'origination_uuid=' . $dispatcher_uuid;
		$params[] = 'origination_caller_id_name=' . $destination;
		$params[] = 'origination_caller_id_number=' . $destination;
		$params[] = 'sip_h_X-Emergency-Call=true';
		$params[] = 'sip_h_Alert-Info=<http://fusionpbx.com>;info=emergency;answer-after=15';
		if (is_uuid($emergency_info['emergency_uuid'] ?? '')) {
			$params[] = 'sip_h_X-Emergency-Uuid=' . $emergency_info['emergency_uuid'];
		}
		$params_string = '{' . implode(',', $params) . '}';
		
		// 调度员 leg：呼叫调度分机到 park，随后通过 uuid_bridge 桥接到已park的目标通道
		$originate_cmd = 'bgapi originate ' . $params_string . 'user/' . $source . '@' . $domain_name . ' &park()';
		$originate_result = event_socket::api($originate_cmd);
		
		error_log("Emergency Bridge Debug - Originate Command: " . $originate_cmd);
		error_log("Emergency Bridge Debug - Originate Result: " . $originate_result);
		
		if ($originate_result === false || stripos($originate_result, '-ERR') !== false) {
			echo json_encode(['success' => false, 'error' => 'Dispatcher originate failed: ' . $originate_result]);
			break;
		}
		
		// 等待调度员通道创建完成
		$max_attempts = 10; // 最多等待约2秒
		$attempt = 0;
		$dispatcher_ready = false;
		while ($attempt < $max_attempts) {
			$attempt++;
			$exists = trim(event_socket::api('uuid_exists ' . $dispatcher_uuid));
			if ($exists === 'true') {
				$dispatcher_ready = true;
				break;
			}
			usleep(200000); // 200ms
		}
		
		if (!$dispatcher_ready) {
			echo json_encode(['success' => false, 'error' => 'Dispatcher channel not found']);
			break;
		}
		
		// 使用 uuid_bridge 将调度员通道与目标通道桥接
		$bridge_cmd = 'uuid_bridge ' . $dispatcher_uuid . ' ' . $target_channel_uuid;
		$bridge_result = event_socket::api($bridge_cmd);
		
		error_log("Emergency Bridge Debug - Bridge Command: " . $bridge_cmd);
		error_log("Emergency Bridge Debug - Bridge Result: " . $bridge_result);
		
		if ($bridge_result === false || stripos($bridge_result, '-ERR') !== false) {
			echo json_encode(['success' => false, 'error' => 'Bridge failed: ' . $bridge_result]);
			break;
		}
		
		// 清除session中的急呼信息
		unset($_SESSION['emergency_bridge'][$destination]);
		
		echo json_encode([
			'success' => true,
			'channel_uuid' => $target_channel_uuid,
			'dispatcher_uuid' => $dispatcher_uuid,
			'bridge_result' => $bridge_result
		]);
		break;

	case 'get_emergency_history':
		// 获取急呼历史记录
		$sql = "SELECT * FROM v_emergency_calls ";
		$sql .= "WHERE domain_uuid = :domain_uuid ";
		$sql .= "ORDER BY start_time DESC LIMIT 100";
		
		$parameters = ['domain_uuid' => $_SESSION['domain_uuid']];
		$database = new database;
		$history = $database->select($sql, $parameters, 'all');
		
		echo json_encode([
			'success' => true,
			'data' => $history
		]);
		break;
	
	case 'get_conferences':
	case 'get_emergency_conferences':
		// 获取会议列表（支持按紧急状态和呼叫模式筛选）
		$emergency_filter = $_GET['emergency_filter'] ?? 'all';  // 'true'/'false'/'all'
		$call_mode_filter = $_GET['call_mode_filter'] ?? 'all';   // 'group_call'/'all_call'/'single_call'/'all'
		
		// 为了向后兼容，如果调用的是 get_emergency_conferences，默认筛选紧急会议
		if ($action === 'get_emergency_conferences' && $emergency_filter === 'all') {
			$emergency_filter = 'true';
		}

		// 获取紧急会议列表
		$sql = "select conference_uuid, conference_extension, conference_name, call_mode, ";
		$sql .= "call_mode_targets, conference_description ";
		$sql .= "from v_conferences ";
		$sql .= "where domain_uuid = :domain_uuid ";
		$sql .= "and conference_enabled = 'true' ";
		
		// 按紧急状态筛选
		if ($emergency_filter === 'true') {
			$sql .= "and emergency_enabled = 'true' ";
		} else if ($emergency_filter === 'false') {
			$sql .= "and emergency_enabled = 'false' ";
		}
		// emergency_filter === 'all' 时不添加筛选条件
		
		// 按呼叫模式筛选
		if ($call_mode_filter !== 'all') {
			$sql .= "and call_mode = :call_mode ";
			$parameters['call_mode'] = $call_mode_filter;
		}
		
		$sql .= "order by conference_name asc ";
		
		$parameters['domain_uuid'] = $_SESSION['domain_uuid'];
		$database = new database;
		$conferences = $database->select($sql, $parameters, 'all');
		
		        $result = [];
        if (!empty($conferences)) {
            // 获取所有启用的分机（用于 all_call 和验证）
            $sql_ext = "select extension from v_extensions ";
            $sql_ext .= "where domain_uuid = :domain_uuid and enabled = 'true' ";
            $sql_ext .= "order by extension asc ";
            $parameters_ext = ['domain_uuid' => $_SESSION['domain_uuid']];
            $all_extensions_rows = $database->select($sql_ext, $parameters_ext, 'all');
            $all_extensions = [];
            if (!empty($all_extensions_rows)) {
                foreach ($all_extensions_rows as $e) {
                    $all_extensions[] = $e['extension'];
                }
            }

            // 计算在线分机列表（当前域名下的注册用户），默认启用 online_only 逻辑
            $online_only = !isset($_GET['online_only']) || $_GET['online_only'] !== 'false';
            $online_extensions = [];
            try {
                $registrations_json = event_socket::api('show registrations as json');
                $registrations = json_decode($registrations_json, true);
                if (is_array($registrations) && isset($registrations['rows']) && is_array($registrations['rows'])) {
                    $domain_name = $_SESSION['domain_name'];
                    $online_map = [];
                    foreach ($registrations['rows'] as $row) {
                        $realm = $row['realm'] ?? ($row['to-host'] ?? '');
                        $user  = $row['user'] ?? '';
                        if ($user === '') { continue; }
                        if ($realm !== '' && strcasecmp($realm, $domain_name) !== 0) { continue; }
                        $online_map[$user] = true;
                    }
                    if (!empty($online_map)) { $online_extensions = array_keys($online_map); }
                }
            } catch (Exception $e) { /* 忽略ESL异常，按非在线过滤失败处理 */ }
			
			foreach ($conferences as $conf) {
				$call_mode = $conf['call_mode'] ?? 'single_call';
				$participants = [];
                if ($call_mode === 'all_call') {
                    // 全呼：默认仅返回当前在线分机；如 online_only=false 则返回所有启用分机
                    if ($online_only && !empty($online_extensions)) {
                        $participants = array_values(array_intersect($all_extensions, $online_extensions));
                    } else {
                        $participants = $all_extensions;
                    }
                } else if ($call_mode === 'group_call' || $call_mode === 'single_call') {
                	// 群呼/单呼：解析 call_mode_targets
                	$targets_raw = $conf['call_mode_targets'] ?? '';
                	if (!empty($targets_raw)) {
                		$targets = array_filter(array_map('trim', explode(',', $targets_raw)));
                		// 过滤出在启用分机列表中的目标
                		foreach ($targets as $t) {
                			if (in_array($t, $all_extensions, true)) {
                				$participants[] = $t;
                			}
                		}
                	}
                }
				
				$result[] = [
					'conference_uuid' => $conf['conference_uuid'],
					'extension' => $conf['conference_extension'],
					'name' => $conf['conference_name'],
					'call_mode' => $call_mode,
					'participants' => $participants,
					'description' => $conf['conference_description'] ?? '',
					'emergency_enabled' => $conf['emergency_enabled'] ?? 'false'
				];
			}
		}
		
		echo json_encode([
			'success' => true,
			'items' => $result
		]);
		break;
	
	default:
		echo json_encode([
			'error' => 'Invalid action'
		]);
		break;
}

?>
