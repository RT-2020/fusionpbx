<?php
/*
 * FusionPBX
 *
 * Base Station Status Check API
 * Returns JSON response with connectivity status
 */

//includes
	require_once dirname(__DIR__, 3) . "/resources/require.php";
	require_once dirname(__DIR__, 3) . "/resources/check_auth.php";

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
	$port = intval($_POST['port'] ?? 22);
	$timeout = intval($_POST['timeout'] ?? 800);

//check cache first
	$cache_ttl = 60; // seconds
	$results = [];

	foreach ($ip_addresses as $ip) {
		//validate IP
		$ip = check_str($ip);
		if (empty($ip)) {
			continue;
		}

		$cache_key = 'base_station_status_' . str_replace(['.', ':'], '_', $ip);
		$cache_file = sys_get_temp_dir() . '/' . $cache_key . '.json';

		//check cache
		if (file_exists($cache_file)) {
			$cache_data = json_decode(file_get_contents($cache_file), true);
			if ($cache_data && (time() - $cache_data['timestamp']) < $cache_ttl) {
				$results[$ip] = $cache_data['status'];
				continue;
			}
		}

		//probe connectivity
		$timeout_sec = $timeout / 1000;
		$connection = @fsockopen($ip, $port, $errno, $errstr, $timeout_sec);
		$is_online = $connection !== false;
		if ($connection) fclose($connection);

		//save to cache
		$cache_data = [
			'timestamp' => time(),
			'status' => $is_online
		];
		file_put_contents($cache_file, json_encode($cache_data));

		$results[$ip] = $is_online;
	}

//return JSON response
	header('Content-Type: application/json');
	echo json_encode($results);
?>
