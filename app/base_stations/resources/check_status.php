<?php
/*
 * FusionPBX
 *
 * Base Station Status Check API
 * Returns JSON response with connectivity status
 * Uses concurrent TCP probes for near real-time detection
 */

//includes
	require_once dirname(__DIR__, 3) . "/resources/require.php";
	require_once dirname(__DIR__, 3) . "/resources/check_auth.php";
	require_once __DIR__ . "/classes/base_station.php";

//check permissions
	if (permission_exists('base_station_view')) {
		//access granted
	}
	else {
		echo json_encode(['error' => 'access denied']);
		exit;
	}

//get posted data
	$ip_addresses = $_POST['ip_addresses'] ?? [];
	$force_all_online = in_array(strtolower(trim((string) ($_POST['force_all_online'] ?? ''))), ['1', 'true', 'yes', 'on'], true);

//load settings
	$database = database::new();
	$settings = new settings(['database' => $database]);
	$probe_port = (int) $settings->get('base_station', 'probe_port', 22);
	$probe_timeout = (int) $settings->get('base_station', 'probe_timeout', 800);

	if (!empty($_POST['timeout']) && is_numeric($_POST['timeout'])) {
		$probe_timeout = max(100, min(5000, (int) $_POST['timeout']));
	}

	if ($force_all_online) {
		$results = [];
		if (is_array($ip_addresses)) {
			foreach ($ip_addresses as $ip_address) {
				$ip_address = trim((string) $ip_address);
				if (!filter_var($ip_address, FILTER_VALIDATE_IP)) {
					continue;
				}
				$results[$ip_address] = true;
			}
		}
		header('Content-Type: application/json');
		echo json_encode($results);
		exit;
	}

//initialize results
	$results = base_station::probe_many($ip_addresses, $probe_port, $probe_timeout);

//return JSON response
	header('Content-Type: application/json');
	echo json_encode($results);
?>
