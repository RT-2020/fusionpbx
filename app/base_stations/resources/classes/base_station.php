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

