<?php

class emergency_alarm_service extends service {

	const SERVICE_NAME = 'emergency_alarm';

	const SWITCH_EVENTS = [
		['Event-Name' => 'CHANNEL_CREATE'],
		['Event-Name' => 'CHANNEL_CALLSTATE'],
		['Event-Name' => 'CHANNEL_ANSWER'],
		['Event-Name' => 'CHANNEL_BRIDGE'],
		['Event-Name' => 'CHANNEL_HANGUP_COMPLETE'],
		['Event-Name' => 'CHANNEL_DESTROY'],
		['Event-Name' => 'HEARTBEAT'],
	];

	private static $switch_port = null;
	private static $switch_host = null;
	private static $switch_password = null;
	private static $device_host = null;
	private static $device_port = null;

	private $database;
	private $settings;
	private $event_socket;
	private $switch_socket;
	private $device;
	private $active_alarm_enabled;
	private $device_state;
	private $last_alarm_response;
	private $last_error;
	private $last_job_poll;
	private $last_presence_cleanup;
	private $last_presence_reconcile;
	private $last_runtime_heartbeat;
	private $last_recovery_check;
	private $domain_cache;

	protected function reload_settings(): void {
		parent::$config->read();
		$this->load_settings();
		$this->disconnect_event_socket();
		$this->recover_alarm_runtime_state('reload');
		$this->heartbeat_all_domains();
	}

	protected static function display_version(): void {
		echo "Emergency Alarm Service 1.0\n";
	}

	protected static function set_command_options() {
		parent::append_command_option(
			command_option::new()
				->description('Set the IP address for the switch')
				->short_option('i:')
				->short_description('-i <ip_addr>')
				->long_option('switch-ip:')
				->long_description('--switch-ip <ip_addr>')
				->callback('set_switch_host_address')
		);
		parent::append_command_option(
			command_option::new()
				->description('Set the port to connect to the switch')
				->short_option('p:')
				->short_description('-p <port>')
				->long_option('switch-port:')
				->long_description('--switch-port <port>')
				->callback('set_switch_port')
		);
		parent::append_command_option(
			command_option::new()
				->description('Set the password for the switch')
				->short_option('o:')
				->short_description('-o <password>')
				->long_option('switch-password:')
				->long_description('--switch-password <password>')
				->callback('set_switch_password')
		);
		parent::append_command_option(
			command_option::new()
				->description('Override the alarm device TCP host')
				->short_option('m:')
				->short_description('-m <ip_addr>')
				->long_option('device-host:')
				->long_description('--device-host <ip_addr>')
				->callback('set_device_host')
		);
		parent::append_command_option(
			command_option::new()
				->description('Override the alarm device TCP port')
				->short_option('n:')
				->short_description('-n <port>')
				->long_option('device-port:')
				->long_description('--device-port <port>')
				->callback('set_device_port')
		);
	}

	protected static function set_switch_host_address($host): void {
		self::$switch_host = $host;
	}

	protected static function set_switch_port($port): void {
		self::$switch_port = $port;
	}

	protected static function set_switch_password($password): void {
		self::$switch_password = $password;
	}

	protected static function set_device_host($host): void {
		self::$device_host = $host;
	}

	protected static function set_device_port($port): void {
		self::$device_port = (int) $port;
	}

	public function run(): int {
		$this->database = new database;
		$this->settings = new settings(['category' => 'operator_panel', 'database' => $this->database]);
		$this->active_alarm_enabled = false;
		$this->device_state = 'idle';
		$this->last_alarm_response = '';
		$this->last_error = '';
		$this->last_job_poll = 0;
		$this->last_presence_cleanup = 0;
		$this->last_presence_reconcile = 0;
		$this->last_runtime_heartbeat = 0;
		$this->last_recovery_check = 0;
		$this->domain_cache = [];
		$this->load_settings();

		$this->info('Starting emergency alarm service');
		$this->recover_alarm_runtime_state('startup');
		$this->heartbeat_all_domains();

		while ($this->running) {
			if ($this->event_socket === null || !$this->event_socket->is_connected()) {
				if (!$this->connect_to_event_socket()) {
					$this->last_error = 'Unable to connect to switch event socket';
					$this->device_state = $this->active_alarm_enabled ? 'alarming' : 'idle';
					$this->process_periodic_tasks();
					usleep(500000);
					continue;
				}
				$this->register_event_socket_filters();
			}

			$read = [];
			if ($this->event_socket !== null && $this->event_socket->is_connected()) {
				$read[] = $this->switch_socket;
			}

			if (!empty($read)) {
				$write = [];
				$except = [];
				$result = stream_select($read, $write, $except, 0, 250000);
				if ($result === false) {
					$this->last_error = 'stream_select failed for event socket';
					return 1;
				}
				if ($result > 0) {
					foreach ($read as $resource) {
						if ($resource === $this->switch_socket) {
							$this->handle_switch_event();
						}
					}
				}
			}
			else {
				usleep(250000);
			}

			$this->process_periodic_tasks();
		}

		return 0;
	}

	private function load_settings() {
		$this->settings->reload();
		$device_host = self::$device_host ?: $this->settings->get('operator_panel', 'emergency_alarm_host', '192.168.50.1');
		$device_port = self::$device_port ?: (int) $this->settings->get('operator_panel', 'emergency_alarm_port', 9003);
		$timeout_ms = (int) $this->settings->get('operator_panel', 'emergency_alarm_socket_timeout_ms', 1200);
		$audio_folder = (int) $this->settings->get('operator_panel', 'emergency_alarm_audio_folder', emergency_alarm_device::DEFAULT_AUDIO_FOLDER);
		$audio_track = (int) $this->settings->get('operator_panel', 'emergency_alarm_audio_track', emergency_alarm_device::DEFAULT_AUDIO_TRACK);
		$strobe_mode = (int) $this->settings->get('operator_panel', 'emergency_alarm_strobe_mode', emergency_alarm_device::DEFAULT_STROBE_MODE);
		$audio_volume = (int) $this->settings->get('operator_panel', 'emergency_alarm_audio_volume', emergency_alarm_device::DEFAULT_AUDIO_VOLUME);
		$this->device = new emergency_alarm_device($device_host, $device_port, $timeout_ms, $audio_folder, $audio_track, $strobe_mode, $audio_volume);
	}

	private function process_periodic_tasks() {
		$now = time();

		if ($now - $this->last_job_poll >= 1) {
			$this->process_pending_jobs();
			$this->last_job_poll = $now;
		}

		if ($now - $this->last_presence_cleanup >= 15) {
			$this->purge_expired_presence();
			$this->last_presence_cleanup = $now;
		}

		if ($now - $this->last_presence_reconcile >= 5) {
			$this->reconcile_triggered_calls();
			$this->last_presence_reconcile = $now;
		}

		if ($now - $this->last_runtime_heartbeat >= 30) {
			$this->heartbeat_all_domains();
			$this->last_runtime_heartbeat = $now;
		}

		if ($now - $this->last_recovery_check >= 30) {
			$this->recover_alarm_runtime_state('periodic');
		}
	}

	private function recover_alarm_runtime_state($reason = 'startup') {
		$this->last_recovery_check = time();
		$active_count = $this->count_triggered_calls();
		$state_payload = [
			'reason' => $reason,
			'active_alarm_count' => $active_count,
			'strategy' => 'database_state_reconcile',
		];
		$force_reconcile = in_array($reason, ['startup', 'reload'], true);

		try {
			if ($active_count > 0) {
				$triggered_calls = $this->find_triggered_calls(null, 1);
				$existing_alarm_call = $triggered_calls[0] ?? null;
				$existing_alarm_runtime = $this->call_has_successful_alarm_runtime($existing_alarm_call);
				if ($existing_alarm_runtime) {
					$state_payload['recovery_action'] = 'observe_existing_alarm_runtime';
					$state_payload['recovery_reference_call_uuid'] = $existing_alarm_call['emergency_call_uuid'] ?? null;
					$this->last_alarm_response = trim((string) ($existing_alarm_call['alarm_last_response'] ?? ''));
					$this->last_error = '';
				}
				elseif ($force_reconcile || !$this->active_alarm_enabled) {
					$this->warn('Alarm recovery [' . $reason . ']: triggered emergency calls exist, ensuring the alarm device is active.');
					$state_payload['recovery_action'] = 'ensure_alarm_active';
					$state_payload['recovery_result'] = $this->device->activate_alarm();
				}
				else {
					$state_payload['recovery_action'] = 'none';
				}
				$this->active_alarm_enabled = true;
				$this->device_state = 'alarming';
			}
			else {
				$runtime_reports_active_alarm = $this->runtime_reports_active_alarm();
				if ($force_reconcile || $this->active_alarm_enabled || $runtime_reports_active_alarm) {
					$this->warn('Alarm recovery [' . $reason . ']: no triggered emergency calls remain, ensuring the alarm device is cleared.');
					$state_payload['recovery_action'] = 'ensure_alarm_cleared';
					$state_payload['recovery_result'] = $this->device->clear_alarm();
				}
				else {
					$state_payload['recovery_action'] = 'none';
				}
				$this->active_alarm_enabled = false;
				$this->device_state = 'idle';
			}

			$this->last_alarm_response = json_encode($state_payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
			$this->last_error = '';
		}
		catch (Exception $exception) {
			$this->active_alarm_enabled = false;
			$this->device_state = 'error';
			$state_payload['recovery_action'] = 'failed';
			$state_payload['recovery_error'] = $exception->getMessage();
			$this->last_error = 'Alarm recovery [' . $reason . '] failed: ' . $exception->getMessage();
			$this->warn($this->last_error);
			$this->last_alarm_response = json_encode($state_payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		}
	}

	private function inspect_device_runtime_state() {
		$snapshot = [
			'checked_at' => date('Y-m-d H:i:s'),
			'endpoint' => $this->device ? $this->device->endpoint() : '',
			'online' => null,
			'playback_active' => null,
			'strobe_active' => null,
			'alarming' => null,
			'responses' => [],
		];

		if (!$this->device) {
			return $snapshot;
		}

		try {
			$online_response = $this->device->query_online();
			$snapshot['responses']['online'] = $online_response;

			$online_frame = $this->first_response_frame_by_command($online_response, 0x3F);
			if (!empty($online_frame)) {
				$snapshot['online'] = !empty($online_frame['online']);
			}
			elseif (!empty($online_response['response_received'])) {
				$snapshot['online'] = true;
			}
		}
		catch (Exception $exception) {
			$snapshot['responses']['online_error'] = $exception->getMessage();
		}

		try {
			$playback_response = $this->device->query_playback_status();
			$snapshot['responses']['playback'] = $playback_response;

			$playback_frame = $this->first_response_frame_by_command($playback_response, 0x42);
			if (!empty($playback_frame)) {
				$playback_code = (int) ($playback_frame['playback_status']['code'] ?? $playback_frame['param2'] ?? 0x00);
				$snapshot['playback_active'] = ($playback_code !== 0x00);
			}
			elseif (!empty($playback_response['response_received'])) {
				$snapshot['playback_active'] = false;
			}
		}
		catch (Exception $exception) {
			$snapshot['responses']['playback_error'] = $exception->getMessage();
		}

		try {
			$sound_light_response = $this->device->query_sound_light_status();
			$snapshot['responses']['sound_light'] = $sound_light_response;

			$sound_light_frame = $this->first_response_frame_by_command($sound_light_response, 0x70);
			if (!empty($sound_light_frame)) {
				$audio_active = null;
				if (isset($sound_light_frame['sound_light_status']['audio_active'])) {
					$audio_active = (bool) $sound_light_frame['sound_light_status']['audio_active'];
				}
				$light_mode_code = (int) ($sound_light_frame['sound_light_status']['light_mode_code'] ?? $sound_light_frame['param2'] ?? 0x06);
				$strobe_active = ($light_mode_code !== 0x06);
				if ($light_mode_code === 0x00 && $audio_active === false) {
					$strobe_active = false;
				}
				$snapshot['strobe_active'] = $strobe_active;
				if ($snapshot['playback_active'] === null && $audio_active !== null) {
					$snapshot['playback_active'] = $audio_active;
				}
			}
			elseif (!empty($sound_light_response['response_received'])) {
				$snapshot['strobe_active'] = false;
			}
		}
		catch (Exception $exception) {
			$snapshot['responses']['sound_light_error'] = $exception->getMessage();
		}

		$snapshot['alarming'] = $this->snapshot_indicates_alarming($snapshot);
		return $snapshot;
	}

	private function first_response_frame_by_command(array $response, $command_byte) {
		$frames = $response['response_frames'] ?? [];
		if (!is_array($frames)) {
			return [];
		}

		foreach ($frames as $frame) {
			if ((int) ($frame['command_byte'] ?? -1) === (int) $command_byte) {
				return $frame;
			}
		}

		return [];
	}

	private function snapshot_is_online(array $snapshot) {
		return isset($snapshot['online']) && $snapshot['online'] === true;
	}

	private function snapshot_indicates_alarming(array $snapshot) {
		if (!isset($snapshot['playback_active']) && !isset($snapshot['strobe_active'])) {
			return null;
		}
		if (($snapshot['playback_active'] ?? null) === null && ($snapshot['strobe_active'] ?? null) === null) {
			return null;
		}
		return ($snapshot['playback_active'] ?? false) === true
			|| ($snapshot['strobe_active'] ?? false) === true;
	}

	private function connect_to_event_socket() {
		if ($this->switch_socket && is_resource($this->switch_socket) && $this->event_socket && $this->event_socket->is_connected()) {
			return true;
		}

		$host = self::$switch_host ?: parent::$config->get('switch.event_socket.host', '127.0.0.1');
		$port = self::$switch_port ?: parent::$config->get('switch.event_socket.port', 8021);
		$password = self::$switch_password ?: parent::$config->get('switch.event_socket.password', 'ClueCon');

		$this->switch_socket = @stream_socket_client('tcp://' . $host . ':' . $port, $errno, $errstr, 5);
		if (!$this->switch_socket) {
			$this->warn('Unable to connect to switch event socket: ' . $errstr . ' (' . $errno . ')');
			return false;
		}

		stream_set_blocking($this->switch_socket, true);
		$this->event_socket = new event_socket($this->switch_socket);
		$this->event_socket->connect(null, null, $password);
		stream_set_blocking($this->switch_socket, false);

		return $this->event_socket->is_connected();
	}

	private function disconnect_event_socket() {
		if ($this->event_socket) {
			$this->event_socket->close();
		}
		$this->event_socket = null;
		$this->switch_socket = null;
	}

	private function register_event_socket_filters() {
		$this->event_socket->request('event plain all');
		foreach (self::SWITCH_EVENTS as $event_filter) {
			foreach ($event_filter as $key => $value) {
				$this->event_socket->request('filter ' . $key . ' ' . $value);
			}
		}
	}

	private function handle_switch_event() {
		$raw_event = $this->event_socket->read_event();
		if (empty($raw_event) || !is_array($raw_event)) {
			return;
		}

		$event = $this->normalize_event($raw_event);
		$event_name = strtoupper($event['event_name'] ?? '');
		if ($event_name === 'HEARTBEAT') {
			return;
		}

		if (!$this->is_emergency_event($raw_event, $event)) {
			return;
		}

		$this->process_emergency_event($event);
	}

	private function process_emergency_event(array $event) {
		$domain = $this->resolve_domain($event);
		if (empty($domain['domain_uuid'])) {
			return;
		}

		$call_uuid = $this->extract_call_uuid($event);
		if (!is_uuid($call_uuid)) {
			return;
		}

		$callee_extension = $this->clean_extension($this->event_value($event, [
			'caller_destination_number',
			'variable_destination_number',
			'destination_number',
			'other_leg_destination_number',
			'caller_callee_id_number',
			'variable_dialed_extension',
			'variable_dialed_user',
		]));
		if ($callee_extension === '') {
			return;
		}

		$caller_extension = $this->clean_extension($this->event_value($event, [
			'caller_caller_id_number',
			'variable_sip_from_user',
			'variable_origination_caller_id_number',
			'variable_caller_id_number',
		]));

		$event_name = strtoupper($event['event_name'] ?? '');
		$channel_state = strtoupper($this->event_value($event, ['channel_call_state', 'call_state']));
		$answer_state = strtoupper($this->event_value($event, ['answer_state']));
		$now = date('Y-m-d H:i:s');
		$presence_active = $this->has_active_presence($domain['domain_uuid'], $callee_extension);

		$call = $this->find_emergency_call($domain['domain_uuid'], $call_uuid);
		if (empty($call) && !$presence_active) {
			return;
		}
		if (empty($call)) {
			$call = $this->insert_emergency_call($domain['domain_uuid'], $call_uuid, $caller_extension, $callee_extension, $now);
		}

		$fields = [
			'caller_extension' => $caller_extension,
			'callee_extension' => $callee_extension,
			'direction' => 'user_to_dispatcher',
			'emergency_type' => 'single',
			'update_date' => $now,
			'update_user' => null,
		];

		$current_alarm_state = $call['alarm_state'] ?? '';
		$current_status = $call['status'] ?? '';

		if ($this->event_indicates_answered($event_name, $channel_state, $answer_state)) {
			$fields['status'] = 'answered';
			if (empty($call['answer_time'])) {
				$fields['answer_time'] = $now;
			}
			if ($current_alarm_state !== 'cleared') {
				$fields['alarm_state'] = 'cleared';
				if (empty($call['alarm_ack_time'])) {
					$fields['alarm_ack_time'] = $now;
				}
				if (empty($call['alarm_clear_time'])) {
					$fields['alarm_clear_time'] = $now;
				}
				$fields['alarm_clear_reason'] = 'answered';
			}
		}
		elseif ($this->event_indicates_finished($event_name)) {
			$fields['status'] = ($current_status === 'answered') ? 'completed' : 'failed';
			if (empty($call['end_time'])) {
				$fields['end_time'] = $now;
			}
			if ($current_alarm_state !== 'cleared') {
				$fields['alarm_state'] = 'cleared';
				if (empty($call['alarm_clear_time'])) {
					$fields['alarm_clear_time'] = $now;
				}
				$fields['alarm_clear_reason'] = 'hangup';
			}
		}
		else {
			$fields['status'] = ($current_status === 'answered') ? 'answered' : 'ringing';
			if ($current_alarm_state !== 'acknowledged' && $current_alarm_state !== 'cleared') {
				if ($presence_active) {
					$fields['alarm_state'] = 'triggered';
					if (empty($call['alarm_trigger_time'])) {
						$fields['alarm_trigger_time'] = $now;
					}
				}
				elseif ($current_alarm_state === '' || $current_alarm_state === null) {
					$fields['alarm_state'] = 'pending_presence';
				}
			}
		}

		$this->update_emergency_call($call['emergency_call_uuid'], $fields, $domain['domain_uuid']);
	}

	private function process_pending_jobs() {
		$sql = "select * from v_emergency_alarm_jobs ";
		$sql .= "where status = 'pending' ";
		$sql .= "order by insert_date asc ";
		$sql .= "limit 10";
		$jobs = $this->database->select($sql, null, 'all');
		if (empty($jobs)) {
			return;
		}

		foreach ($jobs as $job) {
			$this->claim_job($job['emergency_alarm_job_uuid']);
			$this->handle_job($job);
		}
	}

	private function claim_job($job_uuid) {
		$sql = "update v_emergency_alarm_jobs set ";
		$sql .= "status = :status, update_date = :update_date ";
		$sql .= "where emergency_alarm_job_uuid = :emergency_alarm_job_uuid ";
		$sql .= "and status = 'pending'";
		$this->database->execute($sql, [
			'status' => 'running',
			'update_date' => date('Y-m-d H:i:s'),
			'emergency_alarm_job_uuid' => $job_uuid,
		]);
	}

	private function handle_job(array $job) {
		$result = [];
		$error = '';
		$status = 'completed';

		try {
			switch ($job['action']) {
				case 'acknowledge_and_clear_alarm':
					$result = $this->handle_acknowledge_job($job, false);
					break;
				case 'force_clear_alarm':
					$result = $this->handle_acknowledge_job($job, true);
					break;
				default:
					$status = 'failed';
					$error = 'Unsupported job action: ' . $job['action'];
			}
		}
		catch (Exception $exception) {
			$status = 'failed';
			$error = $exception->getMessage();
		}

		$this->finish_job($job['emergency_alarm_job_uuid'], $status, $result, $error);
	}

	private function handle_acknowledge_job(array $job, $force_clear) {
		$domain_uuid = $job['domain_uuid'];
		$cleared = [];
		$reason = $force_clear ? 'force_clear' : 'operator_ack';

		if ($force_clear) {
			$calls = $this->find_triggered_calls($domain_uuid);
			foreach ($calls as $call) {
				$fields = [
					'alarm_state' => 'acknowledged',
					'alarm_ack_time' => date('Y-m-d H:i:s'),
					'alarm_clear_time' => date('Y-m-d H:i:s'),
					'alarm_clear_reason' => $reason,
					'update_user' => null,
				];
				$current_status = $call['status'] ?? '';
				if (!in_array($current_status, ['answered', 'completed', 'failed', 'rejected'], true)) {
					$fields['status'] = 'acknowledged';
				}
				$this->update_emergency_call($call['emergency_call_uuid'], $fields, $domain_uuid);
				$cleared[] = $call['emergency_call_uuid'];
			}
		}
		else {
			$call = null;
			if (is_uuid($job['emergency_call_uuid'] ?? '')) {
				$call = $this->find_emergency_call_by_uuid($job['emergency_call_uuid'], $domain_uuid);
			}
			if (empty($call)) {
				$calls = $this->find_triggered_calls($domain_uuid, 1);
				$call = $calls[0] ?? null;
			}
			if (!empty($call)) {
				$fields = [
					'alarm_state' => 'acknowledged',
					'alarm_ack_time' => date('Y-m-d H:i:s'),
					'alarm_clear_time' => date('Y-m-d H:i:s'),
					'alarm_clear_reason' => $reason,
					'update_user' => null,
				];
				$current_status = $call['status'] ?? '';
				if (!in_array($current_status, ['answered', 'completed', 'failed', 'rejected'], true)) {
					$fields['status'] = 'acknowledged';
				}
				$this->update_emergency_call($call['emergency_call_uuid'], $fields, $domain_uuid);
				$cleared[] = $call['emergency_call_uuid'];
			}
		}

		$active_alarm_count = $this->count_triggered_calls();
		$this->active_alarm_enabled = ($active_alarm_count > 0);
		if ($this->device_state !== 'error') {
			$this->device_state = $this->active_alarm_enabled ? 'alarming' : 'idle';
		}
		if (is_uuid($domain_uuid)) {
			$this->upsert_runtime_row($domain_uuid);
		}
		return [
			'cleared_call_uuids' => $cleared,
			'device_state' => $this->device_state,
			'active_alarm_count' => $active_alarm_count,
		];
	}

	private function finish_job($job_uuid, $status, array $result, $error_message = '') {
		$sql = "update v_emergency_alarm_jobs set ";
		$sql .= "status = :status, result_payload = :result_payload, error_message = :error_message, update_date = :update_date ";
		$sql .= "where emergency_alarm_job_uuid = :emergency_alarm_job_uuid";
		$this->database->execute($sql, [
			'status' => $status,
			'result_payload' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
			'error_message' => $error_message,
			'update_date' => date('Y-m-d H:i:s'),
			'emergency_alarm_job_uuid' => $job_uuid,
		]);
	}

	private function synchronize_alarm_state($domain_uuid = null, $reference_call_uuid = null) {
		$active_count = $this->count_triggered_calls();
		if ($active_count > 0 && !$this->active_alarm_enabled) {
			$existing_call = null;
			if (is_uuid($reference_call_uuid)) {
				$existing_call = $this->find_emergency_call_by_uuid($reference_call_uuid, $domain_uuid);
			}
			if (
				!empty($existing_call) &&
				trim((string) ($existing_call['alarm_last_response'] ?? '')) !== '' &&
				trim((string) ($existing_call['alarm_error'] ?? '')) === ''
			) {
				$this->active_alarm_enabled = true;
				$this->device_state = 'alarming';
				$this->last_alarm_response = trim((string) $existing_call['alarm_last_response']);
				$this->last_error = '';
				return;
			}
			try {
				$result = $this->device->activate_alarm();
				$this->active_alarm_enabled = true;
				$this->device_state = 'alarming';
				$this->last_alarm_response = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
				$this->last_error = '';
				if ($reference_call_uuid) {
					$this->update_emergency_call($reference_call_uuid, [
						'alarm_last_response' => $this->last_alarm_response,
						'alarm_error' => '',
						'update_user' => null,
					], $domain_uuid);
				}
				if ($domain_uuid && $reference_call_uuid) {
					$this->log_alarm_action($domain_uuid, $reference_call_uuid, 'activate', $result, 'completed', '');
				}
			}
			catch (Exception $exception) {
				$this->device_state = 'error';
				$this->last_error = $exception->getMessage();
				if ($reference_call_uuid) {
					$this->update_emergency_call($reference_call_uuid, [
						'alarm_error' => $exception->getMessage(),
						'update_user' => null,
					], $domain_uuid);
				}
				if ($domain_uuid && $reference_call_uuid) {
					$this->log_alarm_action($domain_uuid, $reference_call_uuid, 'activate', [], 'failed', $exception->getMessage());
				}
			}
		}
		elseif ($active_count === 0 && $this->active_alarm_enabled) {
			try {
				$result = $this->device->clear_alarm();
				$this->active_alarm_enabled = false;
				$this->device_state = 'idle';
				$this->last_alarm_response = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
				$this->last_error = '';
				if ($reference_call_uuid) {
					$this->update_emergency_call($reference_call_uuid, [
						'alarm_last_response' => $this->last_alarm_response,
						'alarm_error' => '',
						'update_user' => null,
					], $domain_uuid);
				}
				if ($domain_uuid && $reference_call_uuid) {
					$this->log_alarm_action($domain_uuid, $reference_call_uuid, 'clear', $result, 'completed', '');
				}
			}
			catch (Exception $exception) {
				$this->device_state = 'error';
				$this->last_error = $exception->getMessage();
				if ($domain_uuid && $reference_call_uuid) {
					$this->log_alarm_action($domain_uuid, $reference_call_uuid, 'clear', [], 'failed', $exception->getMessage());
				}
			}
		}
		elseif ($active_count > 0) {
			$this->device_state = $this->active_alarm_enabled ? 'alarming' : 'pending';
		}
		else {
			$this->device_state = 'idle';
		}
	}

	private function purge_expired_presence() {
		$sql = "delete from v_operator_panel_presence where expires_at < :now";
		$this->database->execute($sql, ['now' => date('Y-m-d H:i:s')]);
	}

	private function reconcile_triggered_calls() {
		$calls = $this->find_triggered_calls();
		if (empty($calls)) {
			return;
		}

		foreach ($calls as $call) {
			if (($call['direction'] ?? '') === 'dispatcher_to_user') {
				continue;
			}
			if (!$this->has_active_presence($call['domain_uuid'], $call['callee_extension'])) {
				$this->update_emergency_call($call['emergency_call_uuid'], [
					'alarm_state' => 'cleared',
					'alarm_clear_time' => date('Y-m-d H:i:s'),
					'alarm_clear_reason' => 'presence_expired',
					'update_user' => null,
				], $call['domain_uuid']);
			}
		}
	}

	private function heartbeat_all_domains() {
		$sql = "select domain_uuid from v_domains order by domain_name asc";
		$domains = $this->database->select($sql, null, 'all');
		if (empty($domains)) {
			return;
		}

		foreach ($domains as $domain) {
			$this->upsert_runtime_row($domain['domain_uuid']);
		}
	}

	private function upsert_runtime_row($domain_uuid) {
		$runtime_sql = "select * from v_emergency_alarm_runtime ";
		$runtime_sql .= "where domain_uuid = :domain_uuid ";
		$runtime_sql .= "and service_name = :service_name ";
		$runtime_sql .= "limit 1";
		$runtime = $this->database->select($runtime_sql, [
			'domain_uuid' => $domain_uuid,
			'service_name' => self::SERVICE_NAME,
		], 'row');
		$runtime_uuid = $runtime['emergency_alarm_runtime_uuid'] ?? null;

		$active_count = $this->count_triggered_calls();
		$device_state = $this->device_state;
		if ($device_state !== 'error') {
			$device_state = $active_count > 0 ? 'alarming' : 'idle';
		}
		$last_device_response = $this->last_alarm_response !== ''
			? $this->last_alarm_response
			: trim((string) ($runtime['last_device_response'] ?? ''));
		$last_error = $this->last_error !== ''
			? $this->last_error
			: trim((string) ($runtime['last_error'] ?? ''));
		$device_endpoint = $this->device
			? $this->device->endpoint()
			: trim((string) ($runtime['device_endpoint'] ?? ''));

		$data = [
			'domain_uuid' => $domain_uuid,
			'service_name' => self::SERVICE_NAME,
			'service_state' => $this->event_socket && $this->event_socket->is_connected() ? 'running' : 'degraded',
			'device_state' => $device_state,
			'device_endpoint' => $device_endpoint,
			'last_heartbeat' => date('Y-m-d H:i:s'),
			'last_device_response' => $last_device_response,
			'last_error' => $last_error,
			'update_date' => date('Y-m-d H:i:s'),
			'update_user' => null,
		];

		if (is_uuid($runtime_uuid)) {
			$sql = "update v_emergency_alarm_runtime set ";
			$sql .= "service_state = :service_state, device_state = :device_state, device_endpoint = :device_endpoint, ";
			$sql .= "last_heartbeat = :last_heartbeat, last_device_response = :last_device_response, last_error = :last_error, ";
			$sql .= "update_date = :update_date, update_user = :update_user ";
			$sql .= "where emergency_alarm_runtime_uuid = :emergency_alarm_runtime_uuid";
			$data['emergency_alarm_runtime_uuid'] = $runtime_uuid;
			$this->database->execute($sql, $data);
			return;
		}

		$sql = "insert into v_emergency_alarm_runtime ";
		$sql .= "(emergency_alarm_runtime_uuid, domain_uuid, service_name, service_state, device_state, device_endpoint, last_heartbeat, last_device_response, last_error, update_date, update_user) ";
		$sql .= "values ";
		$sql .= "(:emergency_alarm_runtime_uuid, :domain_uuid, :service_name, :service_state, :device_state, :device_endpoint, :last_heartbeat, :last_device_response, :last_error, :update_date, :update_user)";
		$data['emergency_alarm_runtime_uuid'] = uuid();
		$this->database->execute($sql, $data);
	}

	private function call_has_successful_alarm_runtime($call) {
		if (empty($call) || !is_array($call)) {
			return false;
		}

		return trim((string) ($call['alarm_last_response'] ?? '')) !== ''
			&& trim((string) ($call['alarm_error'] ?? '')) === '';
	}

	private function runtime_reports_active_alarm() {
		$sql = "select count(*) from v_emergency_alarm_runtime ";
		$sql .= "where service_name = :service_name ";
		$sql .= "and device_state = :device_state";
		$count = (int) $this->database->select($sql, [
			'service_name' => self::SERVICE_NAME,
			'device_state' => 'alarming',
		], 'column');
		return $count > 0;
	}

	private function resolve_domain(array $event) {
		$domain_uuid = $this->event_value($event, ['variable_domain_uuid', 'domain_uuid']);
		if (is_uuid($domain_uuid)) {
			return ['domain_uuid' => $domain_uuid];
		}

		$domain_name = $this->event_value($event, [
			'variable_domain_name',
			'caller_context',
			'context',
			'variable_sip_to_host',
			'variable_sip_req_host',
			'domain_name',
		]);
		if ($domain_name === '') {
			return null;
		}

		if (isset($this->domain_cache[$domain_name])) {
			return $this->domain_cache[$domain_name];
		}

		$sql = "select domain_uuid, domain_name from v_domains ";
		$sql .= "where lower(domain_name) = lower(:domain_name) ";
		$sql .= "limit 1";
		$domain = $this->database->select($sql, ['domain_name' => $domain_name], 'row');
		if (!empty($domain)) {
			$this->domain_cache[$domain_name] = $domain;
		}
		return $domain;
	}

	private function normalize_event(array $event) {
		$normalized = [];
		foreach ($event as $key => $value) {
			if ($key === '$') {
				continue;
			}
			$normalized[$this->normalize_key($key)] = is_string($value) ? trim($value) : $value;
		}

		if (!empty($event['$']) && is_string($event['$'])) {
			$lines = preg_split('/\r\n|\r|\n/', $event['$']);
			foreach ($lines as $line) {
				if (strpos($line, ':') === false) {
					continue;
				}
				list($key, $value) = explode(':', $line, 2);
				$normalized[$this->normalize_key($key)] = trim($value);
			}
		}

		return $normalized;
	}

	private function normalize_key($key) {
		return strtolower(str_replace('-', '_', trim($key)));
	}

	private function event_value(array $event, array $keys) {
		foreach ($keys as $key) {
			if (isset($event[$key]) && $event[$key] !== '') {
				return trim((string) $event[$key]);
			}
		}
		return '';
	}

	private function extract_call_uuid(array $event) {
		return $this->event_value($event, ['unique_id', 'channel_call_uuid', 'uuid', 'caller_unique_id', 'variable_uuid']);
	}

	private function clean_extension($value) {
		return preg_replace('/[^0-9A-Za-z_*#-]/', '', trim((string) $value));
	}

	private function event_indicates_answered($event_name, $channel_state, $answer_state) {
		if ($event_name === 'CHANNEL_ANSWER' || $event_name === 'CHANNEL_BRIDGE') {
			return true;
		}
		if ($answer_state === 'ANSWERED') {
			return true;
		}
		return $channel_state === 'ACTIVE';
	}

	private function event_indicates_finished($event_name) {
		return in_array($event_name, ['CHANNEL_HANGUP_COMPLETE', 'CHANNEL_DESTROY'], true);
	}

	private function is_emergency_event(array $raw_event, array $event) {
		$header_keys = [
			'variable_sip_h_x_emergency_call',
			'sip_h_x_emergency_call',
			'variable_sip_h_alert_info',
			'sip_h_alert_info',
			'variable_sip_h_call_info',
			'sip_h_call_info',
			'variable_sip_h_x_emergency_uuid',
		];
		foreach ($header_keys as $key) {
			$value = strtolower($event[$key] ?? '');
			if ($value === 'true' || $value === '1' || strpos($value, 'emergency') !== false) {
				return true;
			}
		}

		$raw = json_encode($raw_event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if (stripos($raw, 'X-Emergency-Call') !== false) {
			return true;
		}
		if (stripos($raw, 'Alert-Info') !== false && stripos($raw, 'emergency') !== false) {
			return true;
		}
		return stripos($raw, 'Call-Info') !== false && stripos($raw, 'emergency') !== false;
	}

	private function find_emergency_call($domain_uuid, $call_uuid) {
		$sql = "select * from v_emergency_calls ";
		$sql .= "where domain_uuid = :domain_uuid ";
		$sql .= "and call_uuid = :call_uuid ";
		$sql .= "order by insert_date desc ";
		$sql .= "limit 1";
		return $this->database->select($sql, [
			'domain_uuid' => $domain_uuid,
			'call_uuid' => $call_uuid,
		], 'row');
	}

	private function find_emergency_call_by_uuid($emergency_call_uuid, $domain_uuid = null) {
		$sql = "select * from v_emergency_calls ";
		$sql .= "where emergency_call_uuid = :emergency_call_uuid ";
		$parameters = ['emergency_call_uuid' => $emergency_call_uuid];
		if (is_uuid($domain_uuid)) {
			$sql .= "and domain_uuid = :domain_uuid ";
			$parameters['domain_uuid'] = $domain_uuid;
		}
		$sql .= "limit 1";
		return $this->database->select($sql, $parameters, 'row');
	}

	private function insert_emergency_call($domain_uuid, $call_uuid, $caller_extension, $callee_extension, $now) {
		$emergency_call_uuid = uuid();
		$sql = "insert into v_emergency_calls ";
		$sql .= "(emergency_call_uuid, domain_uuid, call_uuid, direction, caller_extension, callee_extension, emergency_type, status, start_time, insert_date, insert_user, update_date, update_user) ";
		$sql .= "values ";
		$sql .= "(:emergency_call_uuid, :domain_uuid, :call_uuid, :direction, :caller_extension, :callee_extension, :emergency_type, :status, :start_time, :insert_date, :insert_user, :update_date, :update_user)";
		$this->database->execute($sql, [
			'emergency_call_uuid' => $emergency_call_uuid,
			'domain_uuid' => $domain_uuid,
			'call_uuid' => $call_uuid,
			'direction' => 'user_to_dispatcher',
			'caller_extension' => $caller_extension,
			'callee_extension' => $callee_extension,
			'emergency_type' => 'single',
			'status' => 'ringing',
			'start_time' => $now,
			'insert_date' => $now,
			'insert_user' => null,
			'update_date' => $now,
			'update_user' => null,
		]);
		return $this->find_emergency_call_by_uuid($emergency_call_uuid, $domain_uuid);
	}

	private function update_emergency_call($emergency_call_uuid, array $fields, $domain_uuid = null) {
		if (!is_uuid($emergency_call_uuid)) {
			return;
		}

		$assignments = [];
		$parameters = ['emergency_call_uuid' => $emergency_call_uuid];
		if (is_uuid($domain_uuid)) {
			$parameters['domain_uuid'] = $domain_uuid;
		}
		foreach ($fields as $key => $value) {
			$assignments[] = $key . ' = :' . $key;
			$parameters[$key] = $value;
		}
		if (empty($assignments)) {
			return;
		}

		$sql = "update v_emergency_calls set " . implode(', ', $assignments) . " ";
		$sql .= "where emergency_call_uuid = :emergency_call_uuid ";
		if (is_uuid($domain_uuid)) {
			$sql .= "and domain_uuid = :domain_uuid";
		}
		$this->database->execute($sql, $parameters);
	}

	private function has_active_presence($domain_uuid, $extension) {
		if ($extension === '') {
			return false;
		}

		$sql = "select count(*) from v_operator_panel_presence ";
		$sql .= "where domain_uuid = :domain_uuid ";
		$sql .= "and extension = :extension ";
		$sql .= "and expires_at >= :now";
		$count = (int) $this->database->select($sql, [
			'domain_uuid' => $domain_uuid,
			'extension' => $extension,
			'now' => date('Y-m-d H:i:s'),
		], 'column');
		return $count > 0;
	}

	private function count_triggered_calls() {
		$sql = "select count(*) from v_emergency_calls ";
		$sql .= "where alarm_state = 'triggered' ";
		$sql .= "and (end_time is null or coalesce(status, '') not in ('completed', 'failed', 'rejected'))";
		return (int) $this->database->select($sql, null, 'column');
	}

	private function find_triggered_calls($domain_uuid = null, $limit = null) {
		$sql = "select * from v_emergency_calls ";
		$sql .= "where alarm_state = 'triggered' ";
		$sql .= "and (end_time is null or coalesce(status, '') not in ('completed', 'failed', 'rejected')) ";
		$parameters = [];
		if ($domain_uuid) {
			$sql .= "and domain_uuid = :domain_uuid ";
			$parameters['domain_uuid'] = $domain_uuid;
		}
		$sql .= "order by coalesce(alarm_trigger_time, start_time, insert_date) asc ";
		if ($limit !== null) {
			$sql .= "limit " . (int) $limit;
		}
		return $this->database->select($sql, empty($parameters) ? null : $parameters, 'all') ?: [];
	}

	private function log_alarm_action($domain_uuid, $emergency_call_uuid, $action, array $payload, $status, $error_message) {
		if (!is_uuid($domain_uuid) || !is_uuid($emergency_call_uuid)) {
			return;
		}

		$sql = "insert into v_emergency_alarm_logs ";
		$sql .= "(emergency_alarm_log_uuid, domain_uuid, emergency_call_uuid, action, request_payload, response_payload, status, error_message, insert_date, insert_user) ";
		$sql .= "values ";
		$sql .= "(:emergency_alarm_log_uuid, :domain_uuid, :emergency_call_uuid, :action, :request_payload, :response_payload, :status, :error_message, :insert_date, :insert_user)";
		$this->database->execute($sql, [
			'emergency_alarm_log_uuid' => uuid(),
			'domain_uuid' => $domain_uuid,
			'emergency_call_uuid' => $emergency_call_uuid,
			'action' => $action,
			'request_payload' => $this->device ? $this->device->last_request_hex() : '',
			'response_payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
			'status' => $status,
			'error_message' => $error_message,
			'insert_date' => date('Y-m-d H:i:s'),
			'insert_user' => null,
		]);
	}

	protected function debug(string $message = ''): void {
		self::log($message, LOG_DEBUG);
	}

	protected function info(string $message = ''): void {
		self::log($message, LOG_INFO);
	}

	private function warn($message) {
		self::log($message, LOG_WARNING);
	}
}
