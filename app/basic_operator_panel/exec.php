<?php
/* $Id$ */
/*
	v_exec.php
	Copyright (C) 2008-2023 Mark J Crane
	All rights reserved.

	Redistribution and use in source and binary forms, with or without
	modification, are permitted provided that the following conditions are met:

	1. Redistributions of source code must retain the above copyright notice,
	   this list of conditions and the following disclaimer.

	2. Redistributions in binary form must reproduce the above copyright
	   notice, this list of conditions and the following disclaimer in the
	   documentation and/or other materials provided with the distribution.

	THIS SOFTWARE IS PROVIDED ``AS IS'' AND ANY EXPRESS OR IMPLIED WARRANTIES,
	INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY
	AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
	AUTHOR BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY,
	OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
	SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
	INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
	CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
	ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
	POSSIBILITY OF SUCH DAMAGE.
*/

//includes files
	require_once dirname(__DIR__, 2) . "/resources/require.php";
	require_once "resources/check_auth.php";
	require_once __DIR__ . "/resources/classes/operator_panel_recording.php";

//check permissions
	if (permission_exists('operator_panel_view')) {
		//access granted
	}
	else {
		echo "access denied";
		exit;
	}

//process the requests
if (count($_REQUEST) > 0) {
	//set the variables
		$switch_cmd = trim($_REQUEST["cmd"] ?? '');
		$action = trim($_REQUEST["action"] ?? '');
		$data = trim($_REQUEST["data"] ?? '');
		$direction = trim($_REQUEST["direction"] ?? '');

	//setup the event socket connection
		$esl = event_socket::create();

	//allow specific commands
		if (!empty($switch_cmd)) {
			$api_cmd = '';
			$uuid_pattern = '/[^-A-Fa-f0-9]/';
			$num_pattern = '/[^-A-Za-z0-9()*#]/';
			$destination = '';
			$emergency = 'false';

			if ($switch_cmd == 'originate') {
				$source = preg_replace($num_pattern,'',$_REQUEST['source']);
				$destination = preg_replace($num_pattern,'',$_REQUEST['destination']);
				$emergency = $_REQUEST['emergency'] ?? 'false';
				$emergency_uuid = trim((string) ($_REQUEST['emergency_uuid'] ?? ''));
				$domain_name = $_SESSION['domain_name'];
				$params_array = [];

				if ($emergency === 'true') {
					$params_array[] = 'origination_caller_id_name=' . $source;
					$params_array[] = 'origination_caller_id_number=' . $source;
					$params_array[] = 'sip_h_X-Emergency-Call=true';
					$params_array[] = 'sip_h_Alert-Info=<http://fusionpbx.com>;info=emergency;answer-after=15';
					if (is_uuid($emergency_uuid)) {
						$params_array[] = 'sip_h_X-Emergency-Uuid=' . $emergency_uuid;
					}
					$params_array = operator_panel_recording::add_originate_recording_params(
						$params_array,
						operator_panel_recording::session_archive_path($domain_name, 'emergency-${uuid}')
					);
					$api_cmd = 'bgapi originate {' . implode(',', $params_array) . '}user/'.$destination.'@'.$domain_name.' &park()';
					$_SESSION['emergency_bridge'][$destination] = [
						'source' => $source,
						'destination' => $destination,
						'emergency_uuid' => is_uuid($emergency_uuid) ? $emergency_uuid : '',
						'job_uuid' => '',
						'timestamp' => time()
					];
				}
				else {
					$params_array[] = 'origination_caller_id_name=' . $source;
					$params_array[] = 'origination_caller_id_number=' . $source;
					$params_array = operator_panel_recording::add_originate_recording_params(
						$params_array,
						operator_panel_recording::session_archive_path($domain_name)
					);
					$api_cmd = 'bgapi originate {' . implode(',', $params_array) . '}user/'.$source.'@'.$domain_name.' '.$destination.' XML '.trim($_SESSION['user_context']);
				}
			}
			else if ($switch_cmd == 'uuid_record') {
				$uuid = preg_replace($uuid_pattern,'',$_REQUEST['uuid']);
				$recording_path = operator_panel_recording::session_archive_path($_SESSION['domain_name'] ?? '', $uuid);
				$recording_result = operator_panel_recording::enable_record_session($uuid, $recording_path);
				echo !empty($recording_result['success']) ? '+OK' : '-ERR';
				if (!empty($recording_result['responses'])) {
					echo "\n" . json_encode($recording_result['responses']);
				}
				return;
			}
			else if ($switch_cmd == 'uuid_kill') {
				$call_id = preg_replace($uuid_pattern,'',$_REQUEST['call_id']);
				$api_cmd = 'uuid_kill ' . $call_id;
			}
			else if ($switch_cmd == 'uuid_exists') {
				$uuid = preg_replace($uuid_pattern,'',$_REQUEST['uuid']);
				$api_cmd = 'uuid_exists ' . $uuid;
			}
			else if ($switch_cmd == 'uuid_bridge') {
				$uuid = preg_replace($uuid_pattern,'',$_REQUEST['uuid']);
				$destination = preg_replace($num_pattern,'',$_REQUEST['destination']);
				$api_cmd = 'uuid_bridge ' . $uuid . ' ' . $destination;
			}
			else if ($switch_cmd == 'get_channel_uuid') {
				$destination = preg_replace($num_pattern,'',$_REQUEST['destination']);
				$api_cmd = 'show channels as json';
			}
			else {
				echo 'access denied';
				return;
			}

		//run the command
			$switch_result = event_socket::api($api_cmd);

		//output FreeSWITCH result for debugging, except get_channel_uuid
			if ($switch_cmd != 'get_channel_uuid') {
				echo $switch_result;
			}

		//special handling for get_channel_uuid
			if ($switch_cmd == 'get_channel_uuid') {
				$channels = json_decode($switch_result, true);

				error_log("Channel UUID Debug - Looking for destination: " . $destination);
				error_log("Channel UUID Debug - Total channels: " . (is_array($channels) && isset($channels['rows']) ? count($channels['rows']) : 0));

				if (is_array($channels) && isset($channels['rows'])) {
					foreach ($channels['rows'] as $channel) {
						error_log("Channel UUID Debug - Channel: " . json_encode($channel));

						$found = false;
						if (
							isset($channel['application']) &&
							$channel['application'] === 'park' &&
							isset($channel['name']) &&
							strpos($channel['name'], $destination) !== false
						) {
							$found = true;
							error_log("Channel UUID Debug - Found park channel for " . $destination);
						}
						if (!$found && isset($channel['destination_number']) && $channel['destination_number'] == $destination) {
							$found = true;
						}
						if (!$found && isset($channel['caller_id_number']) && $channel['caller_id_number'] == $destination) {
							$found = true;
						}
						if (!$found && isset($channel['presence_id']) && strpos($channel['presence_id'], $destination) !== false) {
							$found = true;
						}
						if (!$found && isset($channel['name']) && strpos($channel['name'], $destination) !== false) {
							$found = true;
						}

						if ($found) {
							error_log("Channel UUID Debug - Found channel for " . $destination . ": " . $channel['uuid']);
							echo $channel['uuid'];
							return;
						}
					}
				}

				error_log("Channel UUID Debug - No channel found for: " . $destination);
				echo 'false';
				return;
			}

		//debugging for emergency originate
			if ($switch_cmd == 'originate' && $emergency === 'true') {
				error_log("Emergency Call Debug - Command: " . $api_cmd);
				error_log("Emergency Call Debug - Response: " . $switch_result);
				error_log("Emergency Call Debug - Source: " . $source . ", Destination: " . $destination);

				if (preg_match('/Job-UUID:\s*([a-f0-9\-]+)/i', $switch_result, $matches)) {
					$job_uuid = $matches[1];
					error_log("Emergency Call Debug - Job UUID: " . $job_uuid);

					if (isset($_SESSION['emergency_bridge'][$destination])) {
						$_SESSION['emergency_bridge'][$destination]['job_uuid'] = $job_uuid;
					}

					$channels_result = event_socket::api('show channels as json');
					$channels = json_decode($channels_result, true);

					if (is_array($channels) && isset($channels['rows'])) {
						error_log("Emergency Call Debug - Active channels after originate: " . count($channels['rows']));
						foreach ($channels['rows'] as $channel) {
							if (
								isset($channel['destination_number']) &&
								($channel['destination_number'] == $destination || $channel['destination_number'] == $source)
							) {
								error_log("Emergency Call Debug - Found related channel: " . json_encode($channel));
							}
						}
					}
				}
				else {
					error_log("Emergency Call Debug - No Job UUID found in response");
				}
			}

		//debugging for uuid_exists
			if ($switch_cmd == 'uuid_exists') {
				error_log("UUID Exists Debug - Command: " . $api_cmd);
				error_log("UUID Exists Debug - Response: " . $switch_result);
				error_log("UUID Exists Debug - UUID: " . $uuid);
			}

		//append additional debug information on error
			if (stripos($switch_result, '-ERR') !== false) {
				$domain_name = $_SESSION['domain_name'] ?? '';
				$debug_destination = $destination !== '' ? $destination : preg_replace($num_pattern,'',$_REQUEST['destination'] ?? '');
				if ($debug_destination !== '' && $domain_name !== '') {
					$contact_info = @event_socket::api('sofia_contact ' . $debug_destination . '@' . $domain_name);
					$user_status = @event_socket::api('sofia status user ' . $debug_destination . '@' . $domain_name);
					echo "\nCONTACT: " . trim((string)$contact_info);
					echo "\nUSER_STATUS: " . trim((string)$user_status);
					error_log("Emergency Call Failed - Destination: " . $debug_destination . ", Error: " . $switch_result);
					error_log("Emergency Call Failed - Contact: " . trim((string)$contact_info));
					error_log("Emergency Call Failed - Status: " . trim((string)$user_status));
				}
				echo "\nCMD: " . $api_cmd;
			}
		}
}
?>
