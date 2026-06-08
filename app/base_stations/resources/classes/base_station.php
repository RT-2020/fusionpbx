<?php
/*
	FusionPBX
	Version: MPL 1.1

	Base Station Resource Class
	Provides helper methods for base station operations
*/

class base_station {

	/**
	 * Toggle enabled status
	 */
	public function toggle($records) {
		if (permission_exists('base_station_edit')) {

			//validate the token
			$token = new token;
			if (!$token->validate($_SERVER['PHP_SELF'])) {
				message::add($text['message-invalid_token'],'negative');
				return;
			}

			//get the database connection
			$database = database::new();

			//toggle the checked records
			if (is_array($records) && @sizeof($records) != 0) {
				foreach ($records as $record) {
					if (!empty($record['checked']) && $record['checked'] == 'true' && is_uuid($record['uuid'])) {
						// Get current state
						$sql = "select enabled from v_base_stations where base_station_uuid = :base_station_uuid ";
						$parameters['base_station_uuid'] = $record['uuid'];
						$enabled = $database->select($sql, $parameters, 'column');
						unset($sql, $parameters);
						
						// Toggle state
						$enabled = ($enabled == 'true') ? 'false' : 'true';
						
						// Update
						$array['base_stations'][0]['base_station_uuid'] = $record['uuid'];
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
			}
		}
	}

	/**
	 * Delete records
	 */
	public function delete($records) {
		if (permission_exists('base_station_delete')) {

			//validate the token
			$token = new token;
			if (!$token->validate($_SERVER['PHP_SELF'])) {
				message::add($text['message-invalid_token'],'negative');
				return;
			}

			//get the database connection
			$database = database::new();

			//delete the checked records
			if (is_array($records) && @sizeof($records) != 0) {
				foreach ($records as $record) {
					if (!empty($record['checked']) && $record['checked'] == 'true' && is_uuid($record['uuid'])) {
						$array['base_stations'][0]['base_station_uuid'] = $record['uuid'];
						
						$p = permissions::new();
						$p->add('base_station_delete', 'temp');
						
						$database->app_name = 'base_stations';
						$database->app_uuid = 'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d';
						$database->delete($array);
						unset($array);
						
						$p->delete('base_station_delete', 'temp');
					}
				}
			}
		}
	}

	/**
	 * Probe connectivity to a base station
	 */
	public static function probe($ip_address, $port = 22, $timeout = 800) {
		$timeout_sec = $timeout / 1000;
		$connection = @fsockopen($ip_address, $port, $errno, $errstr, $timeout_sec);
		$is_reachable = $connection !== false;
		if ($connection) {
			fclose($connection);
		}
		return $is_reachable;
	}

	/**
	 * Probe multiple base stations concurrently.
	 */
	public static function probe_many($ip_addresses, $port = 22, $timeout = 800) {
		$results = [];
		$targets = [];

		if (!is_array($ip_addresses) || @sizeof($ip_addresses) == 0) {
			return $results;
		}

		foreach ($ip_addresses as $ip_address) {
			$ip_address = trim((string) $ip_address);
			if (!filter_var($ip_address, FILTER_VALIDATE_IP)) {
				continue;
			}
			$targets[$ip_address] = $ip_address;
			$results[$ip_address] = false;
		}

		if (empty($targets)) {
			return $results;
		}

		$timeout_sec = max(0.1, ((int) $timeout) / 1000);

		if (function_exists('stream_socket_client') && defined('STREAM_CLIENT_ASYNC_CONNECT') && function_exists('stream_select') && function_exists('socket_import_stream')) {
			$pending = [];
			foreach ($targets as $ip_address) {
				$errno = 0;
				$errstr = '';
				$stream = @stream_socket_client(
					'tcp://'.$ip_address.':'.((int) $port),
					$errno,
					$errstr,
					$timeout_sec,
					STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT
				);

				if ($stream === false) {
					continue;
				}

				@stream_set_blocking($stream, false);
				$pending[$ip_address] = $stream;
			}

			if (!empty($pending)) {
				$deadline = microtime(true) + $timeout_sec;

				while (!empty($pending) && microtime(true) < $deadline) {
					$read = [];
					$write = array_values($pending);
					$except = [];
					$remaining = $deadline - microtime(true);

					if ($remaining <= 0) {
						break;
					}

					$seconds = (int) floor($remaining);
					$microseconds = (int) (($remaining - $seconds) * 1000000);
					$changed = @stream_select($read, $write, $except, $seconds, $microseconds);

					if ($changed === false) {
						break;
					}

					if ($changed === 0) {
						continue;
					}

					foreach ($write as $stream) {
						$ip_address = array_search($stream, $pending, true);
						if ($ip_address === false) {
							continue;
						}

						$socket = @socket_import_stream($stream);
						$socket_error = ($socket !== false && defined('SOL_SOCKET') && defined('SO_ERROR'))
							? @socket_get_option($socket, SOL_SOCKET, SO_ERROR)
							: 1;

						$results[$ip_address] = ($socket !== false && (int) $socket_error === 0);
						@fclose($stream);
						unset($pending[$ip_address]);
					}
				}

				foreach ($pending as $ip_address => $stream) {
					@fclose($stream);
					$results[$ip_address] = false;
				}

				return $results;
			}
		}

		foreach ($targets as $ip_address) {
			$results[$ip_address] = self::probe($ip_address, $port, $timeout);
		}

		return $results;
	}

	/**
	 * Expand mixed IP input into a unique list.
	 * Supports single IPs, comma/newline separated values and ranges such as 192.168.2.10-200.
	 */
	public static function expand_ip_input($input) {
		$ip_addresses = [];
		$invalid_tokens = [];
		$tokens = preg_split('/[\s,;]+/', trim((string) $input));

		if (!is_array($tokens)) {
			return [
				'ip_addresses' => [],
				'invalid_tokens' => [],
			];
		}

		foreach ($tokens as $token) {
			$token = trim($token);
			if ($token === '') {
				continue;
			}

			if (filter_var($token, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || filter_var($token, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
				$ip_addresses[$token] = $token;
				continue;
			}

			if (preg_match('/^((?:\d{1,3}\.){3})(\d{1,3})-(\d{1,3})$/', $token, $matches)) {
				$prefix = $matches[1];
				$start = (int) $matches[2];
				$end = (int) $matches[3];

				if ($start < 0 || $start > 255 || $end < 0 || $end > 255 || $start > $end) {
					$invalid_tokens[] = $token;
					continue;
				}

				for ($octet = $start; $octet <= $end; $octet++) {
					$ip_address = $prefix.$octet;
					if (filter_var($ip_address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
						$ip_addresses[$ip_address] = $ip_address;
					}
				}
				continue;
			}

			if (preg_match('/^((?:\d{1,3}\.){3}\d{1,3})-((?:\d{1,3}\.){3}\d{1,3})$/', $token, $matches)) {
				$start_ip = $matches[1];
				$end_ip = $matches[2];

				if (!filter_var($start_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || !filter_var($end_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
					$invalid_tokens[] = $token;
					continue;
				}

				$start_parts = explode('.', $start_ip);
				$end_parts = explode('.', $end_ip);
				if (array_slice($start_parts, 0, 3) !== array_slice($end_parts, 0, 3)) {
					$invalid_tokens[] = $token;
					continue;
				}

				$start = (int) $start_parts[3];
				$end = (int) $end_parts[3];
				if ($start > $end) {
					$invalid_tokens[] = $token;
					continue;
				}

				$prefix = $start_parts[0].'.'.$start_parts[1].'.'.$start_parts[2].'.';
				for ($octet = $start; $octet <= $end; $octet++) {
					$ip_address = $prefix.$octet;
					if (filter_var($ip_address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
						$ip_addresses[$ip_address] = $ip_address;
					}
				}
				continue;
			}

			$invalid_tokens[] = $token;
		}

		return [
			'ip_addresses' => array_values($ip_addresses),
			'invalid_tokens' => array_values(array_unique($invalid_tokens)),
		];
	}

	/**
	 * Get decrypted password (requires special permission)
	 */
	public static function get_password($base_station_uuid) {
		if (permission_exists('base_station_password_view')) {
			$database = database::new();
			$settings = new settings(['database' => $database]);
			
			$sql = "select password_encrypted from v_base_stations ";
			$sql .= "where base_station_uuid = :base_station_uuid ";
			$parameters['base_station_uuid'] = $base_station_uuid;
			$password_encrypted = $database->select($sql, $parameters, 'column');
			
			if (!empty($password_encrypted)) {
				$secret_key = $settings->get('base_station', 'secret_key', '');
				if (!empty($secret_key)) {
					return decrypt($secret_key, $password_encrypted);
				}
			}
		}
		return null;
	}

}

?>

