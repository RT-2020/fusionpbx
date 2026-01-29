<?php
/*
 * FusionPBX
 *
 * Base Station Status Check API
 * Returns JSON response with connectivity status
 * No caching - real-time ping detection for production deployment
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
	$timeout = intval($_POST['timeout'] ?? 800);

//initialize results
	$results = [];

	foreach ($ip_addresses as $ip) {
		//validate IP
		$ip = check_str($ip);
		if (empty($ip)) {
			continue;
		}

		//probe connectivity using ICMP ping (no cache, real-time)
		if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
			// Windows: ping -n 1 -w <timeout_ms> <ip>
			$cmd = 'ping -n 1 -w ' . intval($timeout) . ' "' . $ip . '"';
		} else {
			// Linux/Unix: ping -c 1 -W <timeout_sec> <ip>
			$timeout_sec = max(1, ceil($timeout / 1000));
			$cmd = 'ping -c 1 -W ' . intval($timeout_sec) . ' ' . escapeshellarg($ip) . ' 2>/dev/null';
		}

		$output = [];
		$return_var = 0;
		@exec($cmd, $output, $return_var);

		// Determine status: parse output for success indicators
		$output_str = implode(' ', $output);
		$is_online = false;

		if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
			// Windows: check for TTL=, bytes=, "来自", "Reply from"
			$is_online = ($return_var === 0) ||
			              (stripos($output_str, 'TTL=') !== false) ||
			              (stripos($output_str, 'bytes=') !== false) ||
			              (stripos($output_str, '来自') !== false) ||
			              (stripos($output_str, 'Reply from') !== false);
		} else {
			// Linux: check for "1 received", "packets received"
			$is_online = ($return_var === 0) ||
			              (stripos($output_str, '1 received') !== false) ||
			              (stripos($output_str, 'packets received') !== false);
		}

		unset($output);
		$results[$ip] = $is_online;
	}

//return JSON response
	header('Content-Type: application/json');
	echo json_encode($results);
?>
