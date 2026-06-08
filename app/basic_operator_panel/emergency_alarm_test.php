<?php
/*
	FusionPBX
	Version: MPL 1.1

	Emergency Alarm - Test Page
*/

//includes files
	require_once dirname(__DIR__, 2) . "/resources/require.php";
	require_once "resources/check_auth.php";
	require_once __DIR__ . "/resources/classes/emergency_alarm_device.php";

//check permissions
	if (permission_exists('operator_panel_alarm_test')) {
		//access granted
	}
	else {
		echo "access denied";
		exit;
	}

//add multi-lingual support
	$language = new text;
	$text = $language->get();

//initialize objects
$database = new database;
	$settings = new settings([
		'category' => 'operator_panel',
		'database' => $database,
	]);

	function emergency_alarm_test_setting(settings $settings, $subcategory, $default = null) {
		$value = $settings->get('operator_panel', $subcategory, $default);
		return ($value === null || $value === '') ? $default : $value;
	}

	function emergency_alarm_test_clean_host($host) {
		$host = trim((string) $host);
		if ($host === '' || strlen($host) > 255) {
			return '';
		}
		if (filter_var($host, FILTER_VALIDATE_IP)) {
			return $host;
		}
		return preg_match('/^[A-Za-z0-9.-]+$/', $host) ? strtolower($host) : '';
	}

	function emergency_alarm_test_int($value, $minimum, $maximum, $default) {
		if ($value === null || $value === '') {
			return $default;
		}
		if (!is_numeric($value)) {
			return $default;
		}
		$value = (int) $value;
		if ($value < $minimum || $value > $maximum) {
			return $default;
		}
		return $value;
	}

	function emergency_alarm_test_parse_byte($value) {
		$value = trim((string) $value);
		if ($value === '') {
			return null;
		}
		if (preg_match('/^0x([0-9a-f]{1,2})$/i', $value, $matches)) {
			$parsed = hexdec($matches[1]);
		}
		else if (preg_match('/^[0-9a-f]{1,2}$/i', $value) && preg_match('/[a-f]/i', $value)) {
			$parsed = hexdec($value);
		}
		else if (ctype_digit($value)) {
			$parsed = (int) $value;
		}
		else {
			return null;
		}

		if ($parsed < 0 || $parsed > 255) {
			return null;
		}

		return $parsed;
	}

	function emergency_alarm_test_hex_value($value) {
		return strtoupper(str_pad(dechex((int) $value & 0xFF), 2, '0', STR_PAD_LEFT));
	}

	function emergency_alarm_test_parse_hex_byte($value) {
		$value = trim((string) $value);
		if ($value === '') {
			return null;
		}

		if (preg_match('/^0x([0-9a-f]{1,2})$/i', $value, $matches)) {
			$parsed = hexdec($matches[1]);
		}
		else if (preg_match('/^[0-9a-f]{1,2}$/i', $value)) {
			$parsed = hexdec($value);
		}
		else {
			return null;
		}

		if ($parsed < 0 || $parsed > 255) {
			return null;
		}

		return $parsed;
	}

	function emergency_alarm_test_calculate_checksum($command, $feedback, $param1, $param2) {
		$sum = 0xFF + 0x06 + ((int) $command & 0xFF) + ((int) $feedback & 0xFF) + ((int) $param1 & 0xFF) + ((int) $param2 & 0xFF);
		return (0xFFFF - $sum + 1) & 0xFFFF;
	}

	function emergency_alarm_test_parse_frame($value) {
		$value = trim((string) $value);
		if ($value === '') {
			return null;
		}

		$normalized = preg_replace('/[\r\n\t,，;；]+/', ' ', $value);
		$collapsed = strtoupper(str_replace(['0X', ' '], '', strtoupper($normalized)));

		if ($collapsed !== '' && preg_match('/^[0-9A-F]+$/', $collapsed) && strlen($collapsed) % 2 === 0) {
			$parts = str_split($collapsed, 2);
		}
		else {
			$parts = preg_split('/\s+/', trim($normalized));
		}

		$bytes = [];
		foreach ($parts as $part) {
			if ($part === '') {
				continue;
			}
			$parsed = emergency_alarm_test_parse_hex_byte($part);
			if ($parsed === null) {
				return [
					'error' => '无法解析字节: ' . $part,
				];
			}
			$bytes[] = $parsed;
		}

		$count = count($bytes);
		if ($count !== 8 && $count !== 10) {
			return [
				'error' => '整帧命令需要 8 个字节（无校验）或 10 个字节（带校验），当前为 ' . $count . ' 个字节。',
			];
		}

		if ($bytes[0] !== 0x7E) {
			return [
				'error' => '起始字节必须是 7E。',
			];
		}
		if ($bytes[1] !== 0xFF) {
			return [
				'error' => '地址字节必须是 FF。',
			];
		}
		if ($bytes[2] !== 0x06) {
			return [
				'error' => '长度字节必须是 06。',
			];
		}
		if ($bytes[$count - 1] !== 0xEF) {
			return [
				'error' => '结束字节必须是 EF。',
			];
		}

		$command = $bytes[3];
		$feedback = $bytes[4];
		$param1 = $bytes[5];
		$param2 = $bytes[6];
		$checksum = emergency_alarm_test_calculate_checksum($command, $feedback, $param1, $param2);

		if ($count === 10) {
			$provided_checksum = (($bytes[7] & 0xFF) << 8) | ($bytes[8] & 0xFF);
			if ($provided_checksum !== $checksum) {
				return [
					'error' => '校验码不匹配。期望 ' . strtoupper(str_pad(dechex($checksum), 4, '0', STR_PAD_LEFT)) . '，实际为 ' . strtoupper(str_pad(dechex($provided_checksum), 4, '0', STR_PAD_LEFT)) . '。',
				];
			}
		}

		return [
			'command' => $command,
			'feedback' => $feedback,
			'param1' => $param1,
			'param2' => $param2,
			'bytes' => $bytes,
			'has_checksum' => ($count === 10),
			'normalized' => implode(' ', array_map('emergency_alarm_test_hex_value', $bytes)),
			'calculated_checksum' => strtoupper(str_pad(dechex($checksum), 4, '0', STR_PAD_LEFT)),
		];
	}

//load current configuration
	$config = [
		'host' => (string) emergency_alarm_test_setting($settings, 'emergency_alarm_host', '192.168.50.1'),
		'port' => emergency_alarm_test_int(emergency_alarm_test_setting($settings, 'emergency_alarm_port', 9003), 1, 65535, 9003),
		'timeout_ms' => emergency_alarm_test_int(emergency_alarm_test_setting($settings, 'emergency_alarm_socket_timeout_ms', 1200), 100, 60000, 1200),
		'audio_folder' => emergency_alarm_test_int(emergency_alarm_test_setting($settings, 'emergency_alarm_audio_folder', emergency_alarm_device::DEFAULT_AUDIO_FOLDER), 0, 255, emergency_alarm_device::DEFAULT_AUDIO_FOLDER),
		'audio_track' => emergency_alarm_test_int(emergency_alarm_test_setting($settings, 'emergency_alarm_audio_track', emergency_alarm_device::DEFAULT_AUDIO_TRACK), 0, 255, emergency_alarm_device::DEFAULT_AUDIO_TRACK),
		'strobe_mode' => emergency_alarm_test_int(emergency_alarm_test_setting($settings, 'emergency_alarm_strobe_mode', emergency_alarm_device::DEFAULT_STROBE_MODE), 0, 255, emergency_alarm_device::DEFAULT_STROBE_MODE),
		'audio_volume' => emergency_alarm_test_int(emergency_alarm_test_setting($settings, 'emergency_alarm_audio_volume', emergency_alarm_device::DEFAULT_AUDIO_VOLUME), 0, 30, emergency_alarm_device::DEFAULT_AUDIO_VOLUME),
	];
	$config['endpoint'] = $config['host'] . ':' . $config['port'];

//init form state
	$result_payload = null;
	$error_message = '';
	$form_values = [
		'host' => $config['host'],
		'port' => (string) $config['port'],
		'timeout_ms' => (string) $config['timeout_ms'],
		'audio_folder' => (string) $config['audio_folder'],
		'audio_track' => (string) $config['audio_track'],
		'strobe_mode' => (string) $config['strobe_mode'],
		'audio_volume' => (string) $config['audio_volume'],
		'raw_command' => '',
		'command' => 'C2',
		'feedback' => '00',
		'param1' => '00',
		'param2' => emergency_alarm_test_hex_value($config['strobe_mode']),
	];

//process request
	if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST['persistformvar'])) {
		$form_values['host'] = trim((string) ($_POST['host'] ?? $form_values['host']));
		$form_values['port'] = trim((string) ($_POST['port'] ?? $form_values['port']));
		$form_values['timeout_ms'] = trim((string) ($_POST['timeout_ms'] ?? $form_values['timeout_ms']));
		$form_values['audio_folder'] = trim((string) ($_POST['audio_folder'] ?? $form_values['audio_folder']));
		$form_values['audio_track'] = trim((string) ($_POST['audio_track'] ?? $form_values['audio_track']));
		$form_values['strobe_mode'] = trim((string) ($_POST['strobe_mode'] ?? $form_values['strobe_mode']));
		$form_values['audio_volume'] = trim((string) ($_POST['audio_volume'] ?? $form_values['audio_volume']));
		$form_values['raw_command'] = trim((string) ($_POST['raw_command'] ?? $form_values['raw_command']));
		$form_values['command'] = strtoupper(trim((string) ($_POST['command'] ?? $form_values['command'])));
		$form_values['feedback'] = strtoupper(trim((string) ($_POST['feedback'] ?? $form_values['feedback'])));
		$form_values['param1'] = strtoupper(trim((string) ($_POST['param1'] ?? $form_values['param1'])));
		$form_values['param2'] = strtoupper(trim((string) ($_POST['param2'] ?? $form_values['param2'])));

		$token = new token;
		if (!$token->validate($_SERVER['PHP_SELF'])) {
			$error_message = $text['message-invalid_token'] ?? 'Invalid token.';
		}
		else {
			$alarm_action = trim((string) ($_POST['alarm_action'] ?? ''));
			$host = emergency_alarm_test_clean_host($form_values['host']);
			$port = emergency_alarm_test_int($form_values['port'], 1, 65535, $config['port']);
			$timeout_ms = emergency_alarm_test_int($form_values['timeout_ms'], 100, 60000, $config['timeout_ms']);
			$audio_folder = emergency_alarm_test_int($form_values['audio_folder'], 0, 255, $config['audio_folder']);
			$audio_track = emergency_alarm_test_int($form_values['audio_track'], 0, 255, $config['audio_track']);
			$strobe_mode = emergency_alarm_test_int($form_values['strobe_mode'], 0, 255, $config['strobe_mode']);
			$audio_volume = emergency_alarm_test_int($form_values['audio_volume'], 0, 30, $config['audio_volume']);

			if ($host === '') {
				$error_message = $text['message-required'] ?? 'Host is required.';
			}
			else {
				$device = new emergency_alarm_device($host, $port, $timeout_ms, $audio_folder, $audio_track, $strobe_mode, $audio_volume);

				try {
					switch ($alarm_action) {
						case 'test_connect':
							$device->connect();
							$result_payload = [
								'action' => $alarm_action,
								'status' => 'connected',
								'endpoint' => $device->endpoint(),
								'timeout_ms' => $timeout_ms,
							];
							break;
						case 'query_online':
							$result_payload = [
								'action' => $alarm_action,
								'endpoint' => $device->endpoint(),
								'response' => $device->query_online(),
							];
							break;
						case 'query_playback':
							$result_payload = [
								'action' => $alarm_action,
								'endpoint' => $device->endpoint(),
								'response' => $device->query_playback_status(),
							];
							break;
						case 'query_volume':
							$result_payload = [
								'action' => $alarm_action,
								'endpoint' => $device->endpoint(),
								'response' => $device->query_volume(),
							];
							break;
						case 'query_sound_light':
							$result_payload = [
								'action' => $alarm_action,
								'endpoint' => $device->endpoint(),
								'response' => $device->query_sound_light_status(),
							];
							break;
						case 'activate_alarm':
							$result_payload = [
								'action' => $alarm_action,
								'endpoint' => $device->endpoint(),
								'response' => $device->activate_alarm(),
							];
							break;
						case 'clear_alarm':
							$result_payload = [
								'action' => $alarm_action,
								'endpoint' => $device->endpoint(),
								'response' => $device->clear_alarm(),
							];
							break;
						case 'start_strobe':
							$result_payload = [
								'action' => $alarm_action,
								'endpoint' => $device->endpoint(),
								'response' => $device->start_strobe($strobe_mode),
							];
							break;
						case 'stop_strobe':
							$result_payload = [
								'action' => $alarm_action,
								'endpoint' => $device->endpoint(),
								'response' => $device->stop_strobe(),
							];
							break;
						case 'loop_audio':
							$result_payload = [
								'action' => $alarm_action,
								'endpoint' => $device->endpoint(),
								'response' => $device->loop_track_in_folder($audio_folder, $audio_track),
							];
							break;
						case 'stop_audio':
							$result_payload = [
								'action' => $alarm_action,
								'endpoint' => $device->endpoint(),
								'response' => $device->stop_playback(),
							];
							break;
						case 'set_volume':
							$result_payload = [
								'action' => $alarm_action,
								'endpoint' => $device->endpoint(),
								'requested_volume' => $audio_volume,
								'steps' => [
									$device->set_volume($audio_volume),
									$device->query_volume(),
								],
							];
							break;
						case 'send_command':
							$frame_meta = null;
							if ($form_values['raw_command'] !== '') {
								$frame_meta = emergency_alarm_test_parse_frame($form_values['raw_command']);
								if (!empty($frame_meta['error'])) {
									$error_message = $frame_meta['error'];
									break;
								}
								$command = $frame_meta['command'];
								$feedback = $frame_meta['feedback'];
								$param1 = $frame_meta['param1'];
								$param2 = $frame_meta['param2'];
							}
							else {
								$command = emergency_alarm_test_parse_byte($form_values['command']);
								$feedback = emergency_alarm_test_parse_byte($form_values['feedback']);
								$param1 = emergency_alarm_test_parse_byte($form_values['param1']);
								$param2 = emergency_alarm_test_parse_byte($form_values['param2']);

								if ($command === null || $feedback === null || $param1 === null || $param2 === null) {
									$error_message = 'Invalid command bytes.';
									break;
								}
							}

							$result_payload = [
								'action' => $alarm_action,
								'endpoint' => $device->endpoint(),
								'input_mode' => $frame_meta ? 'raw_frame' : 'byte_fields',
								'command' => [
									'command' => emergency_alarm_test_hex_value($command),
									'feedback' => emergency_alarm_test_hex_value($feedback),
									'param1' => emergency_alarm_test_hex_value($param1),
									'param2' => emergency_alarm_test_hex_value($param2),
								],
								'raw_command' => $frame_meta['normalized'] ?? null,
								'raw_command_has_checksum' => $frame_meta['has_checksum'] ?? null,
								'calculated_checksum' => $frame_meta['calculated_checksum'] ?? strtoupper(str_pad(dechex(emergency_alarm_test_calculate_checksum($command, $feedback, $param1, $param2)), 4, '0', STR_PAD_LEFT)),
								'response' => $device->send_command($command, $feedback, $param1, $param2),
							];
							break;
						default:
							$error_message = 'Unknown action.';
					}
				}
				catch (Throwable $throwable) {
					$error_message = $throwable->getMessage();
					$result_payload = [
						'action' => $alarm_action,
						'endpoint' => $device->endpoint(),
						'last_request_hex' => $device->last_request_hex(),
						'last_response_hex' => $device->last_response_hex(),
					];
				}
				finally {
					$device->disconnect();
				}
			}
		}
	}

//create token
	$object = new token;
	$token = $object->create($_SERVER['PHP_SELF']);

//include the header
	$document['title'] = $text['title-emergency_alarm_test'] ?? 'Emergency Alarm Test';
	require_once "resources/header.php";

//page styling
	echo "<style>\n";
	echo ".alarm-test-note{margin-top:8px;color:#58606b;}\n";
	echo ".alarm-test-actions{display:flex;flex-wrap:wrap;gap:8px;}\n";
	echo ".alarm-test-actions button{min-width:128px;}\n";
	echo ".alarm-test-code{white-space:pre-wrap;word-break:break-word;background:#111827;color:#f3f4f6;border-radius:6px;padding:16px;max-height:420px;overflow:auto;}\n";
	echo ".alarm-test-error{background:#fff1f2;border:1px solid #fecdd3;color:#9f1239;border-radius:6px;padding:12px 14px;margin-bottom:15px;}\n";
	echo ".alarm-test-summary{background:#f8fafc;border:1px solid #dbe2ea;border-radius:6px;padding:12px 14px;margin-bottom:15px;}\n";
	echo "</style>\n";

//show the content
	echo "<form method='post' name='frm' id='frm'>\n";
	echo "<div class='action_bar' id='action_bar'>\n";
	echo "	<div class='heading'><b>".($text['header-emergency_alarm_test'] ?? 'Emergency Alarm Test')."</b></div>\n";
	echo "	<div class='actions'>\n";
	echo button::create(['type'=>'button','label'=>$text['button-back'],'icon'=>$settings->get('theme','button_icon_back'),'id'=>'btn_back','style'=>'margin-right: 15px;','link'=>'index.php']);
	echo "	</div>\n";
	echo "	<div style='clear: both;'></div>\n";
	echo "</div>\n";

	echo "<div class='card'>\n";
	echo "	<div class='alarm-test-summary'>".($text['description-emergency_alarm_test'] ?? 'This page tests alarm device TCP connectivity and command delivery.')."</div>\n";
	if ($error_message !== '') {
		echo "	<div class='alarm-test-error'>".escape($error_message)."</div>\n";
	}
	if (is_array($result_payload)) {
		$encoded_result = json_encode($result_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		if ($encoded_result === false) {
			$encoded_result = print_r($result_payload, true);
		}
		echo "	<div class='alarm-test-summary'><strong>".($text['label-last_result'] ?? 'Last Result')."</strong><br />".($text['description-last_result'] ?? 'The payload below shows the command request and response.')."</div>\n";
		echo "	<pre class='alarm-test-code'>".escape($encoded_result)."</pre>\n";
	}
	echo "	<table class='tr_hover' width='100%' border='0' cellpadding='0' cellspacing='0'>\n";

	echo "	<tr>\n";
	echo "		<td class='vncell' valign='top' align='left' nowrap='nowrap'>".($text['label-configured_endpoint'] ?? 'Configured Endpoint')."</td>\n";
	echo "		<td class='vtable'>".escape($config['endpoint'])."<br /><span class='alarm-test-note'>".($text['description-configured_endpoint'] ?? 'Runtime service uses this configured endpoint after settings reload.')."</span></td>\n";
	echo "	</tr>\n";

	echo "	<tr>\n";
	echo "		<td class='vncellreq' valign='top' align='left' nowrap='nowrap'>".($text['label-test_host'] ?? 'Test Host')."</td>\n";
	echo "		<td class='vtable'><input class='formfld' type='text' name='host' maxlength='255' value=\"".escape($form_values['host'])."\" required='required'><br /><span class='alarm-test-note'>".($text['description-test_host'] ?? 'Temporary host used only for this test.')."</span></td>\n";
	echo "	</tr>\n";

	echo "	<tr>\n";
	echo "		<td class='vncellreq' valign='top' align='left' nowrap='nowrap'>".($text['label-test_port'] ?? 'Test Port')."</td>\n";
	echo "		<td class='vtable'><input class='formfld' type='number' min='1' max='65535' name='port' value=\"".escape($form_values['port'])."\" required='required'><br /><span class='alarm-test-note'>".($text['description-test_port'] ?? 'Temporary TCP port used only for this test.')."</span></td>\n";
	echo "	</tr>\n";

	echo "	<tr>\n";
	echo "		<td class='vncellreq' valign='top' align='left' nowrap='nowrap'>".($text['label-timeout_ms'] ?? 'Socket Timeout (ms)')."</td>\n";
	echo "		<td class='vtable'><input class='formfld' type='number' min='100' max='60000' name='timeout_ms' value=\"".escape($form_values['timeout_ms'])."\" required='required'><br /><span class='alarm-test-note'>".($text['description-timeout_ms'] ?? 'Read/write timeout for the test socket.')."</span></td>\n";
	echo "	</tr>\n";

	echo "	<tr>\n";
	echo "		<td class='vncell' valign='top' align='left' nowrap='nowrap'>".($text['label-audio_loop'] ?? 'Audio Loop')."</td>\n";
	echo "		<td class='vtable'>";
	echo "			<input class='formfld' style='width:120px;margin-right:10px;' type='number' min='0' max='255' name='audio_folder' value=\"".escape($form_values['audio_folder'])."\">";
	echo "			<span>".($text['label-audio_folder'] ?? 'Folder')."</span>";
	echo "			<input class='formfld' style='width:120px;margin:0 10px 0 18px;' type='number' min='0' max='255' name='audio_track' value=\"".escape($form_values['audio_track'])."\">";
	echo "			<span>".($text['label-audio_track'] ?? 'Track')."</span>";
	echo "			<br /><span class='alarm-test-note'>".($text['description-audio_loop'] ?? 'Parameters used by audio loop actions.')."</span>";
	echo "		</td>\n";
	echo "	</tr>\n";

	echo "	<tr>\n";
	echo "		<td class='vncell' valign='top' align='left' nowrap='nowrap'>".($text['label-strobe_mode'] ?? 'Strobe Mode')."</td>\n";
	echo "		<td class='vtable'><input class='formfld' type='number' min='0' max='255' name='strobe_mode' value=\"".escape($form_values['strobe_mode'])."\"><br /><span class='alarm-test-note'>".($text['description-strobe_mode'] ?? 'Mode byte used by strobe actions.')."</span></td>\n";
	echo "	</tr>\n";

	echo "	<tr>\n";
	echo "		<td class='vncell' valign='top' align='left' nowrap='nowrap'>".($text['label-audio_volume'] ?? 'Audio Volume')."</td>\n";
	echo "		<td class='vtable'><input class='formfld' type='number' min='0' max='30' name='audio_volume' value=\"".escape($form_values['audio_volume'])."\"><br /><span class='alarm-test-note'>".($text['description-audio_volume'] ?? 'YX02S audio volume. 0 is mute and 30 is maximum.')."</span></td>\n";
	echo "	</tr>\n";

	echo "	<tr>\n";
	echo "		<td class='vncell' valign='top' align='left' nowrap='nowrap'>".($text['label-quick_actions'] ?? 'Quick Actions')."</td>\n";
	echo "		<td class='vtable'>\n";
	echo "			<div class='alarm-test-actions'>\n";
	echo "				<button class='btn' type='submit' name='alarm_action' value='test_connect'>".($text['button-test_connect'] ?? 'Test Connect')."</button>\n";
	echo "				<button class='btn' type='submit' name='alarm_action' value='query_online'>".($text['button-query_online'] ?? 'Query Online')."</button>\n";
	echo "				<button class='btn' type='submit' name='alarm_action' value='query_playback'>".($text['button-query_playback'] ?? 'Query Playback')."</button>\n";
	echo "				<button class='btn' type='submit' name='alarm_action' value='query_volume'>".($text['button-query_volume'] ?? 'Query Volume')."</button>\n";
	echo "				<button class='btn' type='submit' name='alarm_action' value='query_sound_light'>".($text['button-query_sound_light'] ?? 'Query Sound/Light')."</button>\n";
	echo "				<button class='btn' type='submit' name='alarm_action' value='set_volume'>".($text['button-set_volume'] ?? 'Set Volume')."</button>\n";
	echo "				<button class='btn' type='submit' name='alarm_action' value='activate_alarm'>".($text['button-activate_alarm'] ?? 'Activate Alarm')."</button>\n";
	echo "				<button class='btn' type='submit' name='alarm_action' value='clear_alarm'>".($text['button-clear_alarm'] ?? 'Clear Alarm')."</button>\n";
	echo "				<button class='btn' type='submit' name='alarm_action' value='start_strobe'>".($text['button-start_strobe'] ?? 'Start Strobe')."</button>\n";
	echo "				<button class='btn' type='submit' name='alarm_action' value='stop_strobe'>".($text['button-stop_strobe'] ?? 'Stop Strobe')."</button>\n";
	echo "				<button class='btn' type='submit' name='alarm_action' value='loop_audio'>".($text['button-loop_audio'] ?? 'Loop Audio')."</button>\n";
	echo "				<button class='btn' type='submit' name='alarm_action' value='stop_audio'>".($text['button-stop_audio'] ?? 'Stop Audio')."</button>\n";
	echo "			</div>\n";
	echo "			<div class='alarm-test-note'>".($text['description-quick_actions'] ?? 'Use these buttons to verify device connectivity and common alarm commands.')."</div>\n";
	echo "		</td>\n";
	echo "	</tr>\n";

	echo "	<tr>\n";
	echo "		<td class='vncell' valign='top' align='left' nowrap='nowrap'>".($text['label-custom_command'] ?? 'Custom Command')."</td>\n";
	echo "		<td class='vtable'>\n";
	echo "			<div style='margin-bottom:10px;'>\n";
	echo "				<label style='display:block;margin-bottom:6px;font-weight:600;'>".escape('整帧命令')."</label>\n";
	echo "				<textarea class='formfld' name='raw_command' rows='3' style='width:100%;max-width:920px;font-family:Consolas,Monaco,monospace;'>".escape($form_values['raw_command'])."</textarea>\n";
	echo "				<div class='alarm-test-note'>".escape('可直接粘贴说明书里的整条命令，例如：7E FF 06 C2 00 00 03 FE 36 EF 或 7E FF 06 0F 60 01 01 FE 8A EF。支持带校验或不带校验的整帧，若这里有内容则优先使用。')."</div>\n";
	echo "			</div>\n";
	echo "			<div style='margin:10px 0 6px;font-weight:600;'>".escape('分字节输入')."</div>\n";
	echo "			<input class='formfld' style='width:90px;margin-right:10px;' type='text' name='command' value=\"".escape($form_values['command'])."\" maxlength='4'>\n";
	echo "			<span>".($text['label-command'] ?? 'Command')."</span>\n";
	echo "			<input class='formfld' style='width:90px;margin:0 10px 0 18px;' type='text' name='feedback' value=\"".escape($form_values['feedback'])."\" maxlength='4'>\n";
	echo "			<span>".($text['label-feedback'] ?? 'Feedback')."</span>\n";
	echo "			<input class='formfld' style='width:90px;margin:0 10px 0 18px;' type='text' name='param1' value=\"".escape($form_values['param1'])."\" maxlength='4'>\n";
	echo "			<span>".($text['label-param1'] ?? 'Param1')."</span>\n";
	echo "			<input class='formfld' style='width:90px;margin:0 10px 0 18px;' type='text' name='param2' value=\"".escape($form_values['param2'])."\" maxlength='4'>\n";
	echo "			<span>".($text['label-param2'] ?? 'Param2')."</span>\n";
	echo "			<div style='margin-top:10px;'><button class='btn' type='submit' name='alarm_action' value='send_command'>".($text['button-send_command'] ?? 'Send Command')."</button></div>\n";
	echo "			<div class='alarm-test-note'>".($text['description-custom_command'] ?? 'Each field accepts decimal, 0x-prefixed hex, or plain hex bytes.')." ".escape('保留原有逐字段发送方式，便于单独试验 command / feedback / param1 / param2。')."</div>\n";
	echo "		</td>\n";
	echo "	</tr>\n";

	echo "	<tr>\n";
	echo "		<td class='vncell' valign='top' align='left' nowrap='nowrap'>".($text['label-permission_name'] ?? 'Permission')."</td>\n";
	echo "		<td class='vtable'><code>operator_panel_alarm_test</code><br /><span class='alarm-test-note'>".($text['description-permission_name'] ?? 'Grant this permission to additional roles that need to open this page.')."</span></td>\n";
	echo "	</tr>\n";

	echo "	</table>\n";
	echo "	<input type='hidden' name='".escape($token['name'])."' value='".escape($token['hash'])."'>\n";
	echo "</div>\n";
	echo "</form>\n";

//include the footer
	require_once "resources/footer.php";

?>
