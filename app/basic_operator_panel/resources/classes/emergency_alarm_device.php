<?php

class emergency_alarm_device {

	const START_BYTE = 0x7E;
	const VERSION = 0xFF;
	const LENGTH = 0x06;
	const END_BYTE = 0xEF;

	const DEFAULT_AUDIO_FOLDER = 0x01;
	const DEFAULT_AUDIO_TRACK = 0x01;
	const DEFAULT_STROBE_MODE = 0x03;
	const DEFAULT_AUDIO_VOLUME = 30;
	const DEFAULT_AUDIO_FEEDBACK = 0x00;
	const DEFAULT_RESPONSE_IDLE_GRACE_MS = 80;
	const DEFAULT_BUSY_RETRY_DELAY_MS = 250;
	const DEFAULT_SEQUENCE_STEP_DELAY_MS = 120;
	const DEFAULT_LOCK_FILE = 'fusionpbx_emergency_alarm.lock';

	private $host;
	private $port;
	private $timeout_ms;
	private $socket;
	private $audio_folder;
	private $audio_track;
	private $strobe_mode;
	private $audio_volume;
	private $last_request_hex;
	private $last_response_hex;
	private $lock_handle;
	private $lock_depth;

	public function __construct(
		$host = '192.168.50.1',
		$port = 9003,
		$timeout_ms = 1200,
		$audio_folder = self::DEFAULT_AUDIO_FOLDER,
		$audio_track = self::DEFAULT_AUDIO_TRACK,
		$strobe_mode = self::DEFAULT_STROBE_MODE,
		$audio_volume = self::DEFAULT_AUDIO_VOLUME
	) {
		$this->host = $host;
		$this->port = (int) $port;
		$this->timeout_ms = (int) $timeout_ms;
		$this->socket = null;
		$this->audio_folder = $this->sanitize_byte($audio_folder, self::DEFAULT_AUDIO_FOLDER);
		$this->audio_track = $this->sanitize_byte($audio_track, self::DEFAULT_AUDIO_TRACK);
		$this->strobe_mode = $this->sanitize_byte($strobe_mode, self::DEFAULT_STROBE_MODE);
		$this->audio_volume = $this->sanitize_volume($audio_volume, self::DEFAULT_AUDIO_VOLUME);
		$this->last_request_hex = '';
		$this->last_response_hex = '';
		$this->lock_handle = null;
		$this->lock_depth = 0;
	}

	public function __destruct() {
		$this->disconnect();
		$this->release_device_lock(true);
	}

	public function endpoint() {
		return $this->host . ':' . $this->port;
	}

	public function last_request_hex() {
		return $this->last_request_hex;
	}

	public function last_response_hex() {
		return $this->last_response_hex;
	}

	public function is_connected() {
		return is_resource($this->socket) && !feof($this->socket);
	}

	public function connect() {
		if ($this->is_connected()) {
			return true;
		}

		$timeout_seconds = max(0.2, $this->timeout_ms / 1000);
		$this->socket = @stream_socket_client(
			'tcp://' . $this->host . ':' . $this->port,
			$errno,
			$errstr,
			$timeout_seconds
		);

		if (!$this->socket) {
			throw new RuntimeException('Unable to connect to alarm device: ' . $errstr . ' (' . $errno . ')');
		}

		$seconds = (int) floor($this->timeout_ms / 1000);
		$microseconds = ($this->timeout_ms % 1000) * 1000;
		stream_set_timeout($this->socket, $seconds, $microseconds);
		stream_set_blocking($this->socket, true);
		return true;
	}

	public function disconnect() {
		if (is_resource($this->socket)) {
			fclose($this->socket);
		}
		$this->socket = null;
	}

	public function activate_alarm() {
		return $this->with_device_lock(function () {
			$steps = [];
			$audio_started = false;

			try {
				$steps[] = $this->set_volume($this->audio_volume, [
					'wait_after_write_ms' => 120,
				]);
			}
			catch (Throwable $throwable) {
				$steps[] = [
					'label' => 'set_volume_optional',
					'success' => false,
					'error' => $throwable->getMessage(),
				];
			}

			try {
				// Field verification shows the device sounds reliably after a plain 0x0D play command.
				$steps[] = $this->play_current_track([
					'wait_after_write_ms' => 180,
				]);
				$audio_started = true;
			}
			catch (Throwable $throwable) {
				$steps[] = [
					'label' => 'play_current_track_primary',
					'success' => false,
					'error' => $throwable->getMessage(),
				];
			}

			usleep(self::DEFAULT_SEQUENCE_STEP_DELAY_MS * 1000);
			$steps[] = $this->start_strobe($this->strobe_mode, [
				'wait_after_write_ms' => 120,
			]);

			if (!$audio_started && $this->audio_folder > 0 && $this->audio_track > 0) {
				try {
					$steps[] = $this->loop_track_in_folder($this->audio_folder, $this->audio_track, [
						'wait_after_write_ms' => 180,
					]);
					usleep(self::DEFAULT_SEQUENCE_STEP_DELAY_MS * 1000);
					$steps[] = $this->play_current_track([
						'wait_after_write_ms' => 180,
					]);
					$audio_started = true;
				}
				catch (Throwable $throwable) {
					$steps[] = [
						'label' => 'loop_track_in_folder_fallback',
						'success' => false,
						'error' => $throwable->getMessage(),
					];
				}
			}

			return [
				'action' => 'activate_alarm',
				'mode' => 'play_current_track_then_start_strobe',
				'audio_started' => $audio_started,
				'steps' => $steps,
			];
		});
	}

	public function clear_alarm() {
		return $this->with_device_lock(function () {
			$steps = [];
			$steps[] = $this->stop_playback([
				'wait_after_write_ms' => 120,
			]);
			usleep(self::DEFAULT_SEQUENCE_STEP_DELAY_MS * 1000);
			$steps[] = $this->stop_strobe([
				'wait_after_write_ms' => 120,
			]);
			return [
				'action' => 'clear_alarm',
				'mode' => 'stop_playback_then_stop_strobe',
				'steps' => $steps,
			];
		});
	}

	public function query_online() {
		return $this->send_command(0x3F, 0x00, 0x00, 0x00, [
			'expect_response' => true,
			'busy_retry' => true,
			'label' => 'query_online',
		]);
	}

	public function query_playback_status() {
		return $this->send_command(0x42, 0x00, 0x00, 0x00, [
			'expect_response' => true,
			'busy_retry' => true,
			'label' => 'query_playback_status',
		]);
	}

	public function query_volume() {
		return $this->send_command(0x43, 0x00, 0x00, 0x00, [
			'expect_response' => true,
			'busy_retry' => true,
			'label' => 'query_volume',
		]);
	}

	public function query_sound_light_status() {
		return $this->send_command(0x70, 0x00, 0x00, 0x00, [
			'expect_response' => true,
			'busy_retry' => true,
			'label' => 'query_sound_light_status',
		]);
	}

	public function set_volume($volume, array $options = []) {
		$volume = $this->sanitize_volume($volume, $this->audio_volume);
		return $this->send_command(0x06, 0x00, 0x00, $volume, array_merge([
			'expect_response' => false,
			'label' => 'set_volume',
		], $options));
	}

	public function play_current_track(array $options = []) {
		return $this->send_command(0x0D, 0x00, 0x00, 0x00, array_merge([
			'expect_response' => false,
			'label' => 'play_current_track',
		], $options));
	}

	public function loop_track_in_folder($folder, $track, array $options = []) {
		return $this->send_command(0x10, self::DEFAULT_AUDIO_FEEDBACK, $folder, $track, array_merge([
			'expect_response' => true,
			'busy_retry' => true,
			'label' => 'loop_track_in_folder',
		], $options));
	}

	public function loop_current_track(array $options = []) {
		return $this->send_command(0x19, 0x00, 0x00, 0x00, array_merge([
			'expect_response' => false,
			'label' => 'loop_current_track',
		], $options));
	}

	public function stop_playback(array $options = []) {
		return $this->send_command(0x16, 0x00, 0x00, 0x00, array_merge([
			'expect_response' => false,
			'label' => 'stop_playback',
		], $options));
	}

	public function start_strobe($mode = self::DEFAULT_STROBE_MODE, array $options = []) {
		return $this->send_command(0xC2, 0x00, 0x00, $mode, array_merge([
			'expect_response' => false,
			'label' => 'start_strobe',
		], $options));
	}

	public function stop_strobe(array $options = []) {
		return $this->send_command(0xC2, 0x00, 0x00, 0x06, array_merge([
			'expect_response' => false,
			'label' => 'stop_strobe',
		], $options));
	}

	public function send_command($command, $feedback = 0x00, $param1 = 0x00, $param2 = 0x00, array $options = []) {
		$frame = $this->build_frame($command, $feedback, $param1, $param2);
		return $this->send_frame($frame, $options);
	}

	public function build_frame($command, $feedback = 0x00, $param1 = 0x00, $param2 = 0x00) {
		$checksum = $this->calculate_checksum($command, $feedback, $param1, $param2);
		return pack(
			'C*',
			self::START_BYTE,
			self::VERSION,
			self::LENGTH,
			$command & 0xFF,
			$feedback & 0xFF,
			$param1 & 0xFF,
			$param2 & 0xFF,
			($checksum >> 8) & 0xFF,
			$checksum & 0xFF,
			self::END_BYTE
		);
	}

	public function send_frame($frame, array $options = []) {
		return $this->with_device_lock(function () use ($frame, $options) {
			$options = array_merge([
				'expect_response' => true,
				'busy_retry' => false,
				'max_attempts' => 3,
				'busy_retry_delay_ms' => self::DEFAULT_BUSY_RETRY_DELAY_MS,
				'fresh_connection' => true,
				'wait_after_write_ms' => 50,
				'response_idle_grace_ms' => self::DEFAULT_RESPONSE_IDLE_GRACE_MS,
				'label' => '',
			], $options);

			$attempt = 0;
			while ($attempt < (int) $options['max_attempts']) {
				$attempt++;

				try {
					if (!empty($options['fresh_connection'])) {
						$this->disconnect();
					}
					$this->connect();
					$this->drain_socket();
					$this->last_request_hex = self::binary_to_hex($frame);
					$bytes = @fwrite($this->socket, $frame);
					if ($bytes === false || $bytes !== strlen($frame)) {
						throw new RuntimeException('Failed to write command to alarm device');
					}

					@fflush($this->socket);
					if ((int) $options['wait_after_write_ms'] > 0) {
						usleep((int) $options['wait_after_write_ms'] * 1000);
					}

					$response = '';
					$response_frames = [];
					if (!empty($options['expect_response'])) {
						$response = $this->read_response((int) $options['response_idle_grace_ms']);
						$response_frames = $this->parse_frames($response);

						$device_error = $this->first_device_error($response_frames);
						if ($device_error !== null) {
							$is_busy = ((int) $device_error['code'] === 0x01);
							if ($is_busy && !empty($options['busy_retry']) && $attempt < (int) $options['max_attempts']) {
								$this->disconnect();
								usleep((int) $options['busy_retry_delay_ms'] * 1000);
								continue;
							}

							$this->last_response_hex = self::binary_to_hex($response);
							throw new RuntimeException('Alarm device error: ' . $device_error['message']);
						}
					}

					$this->last_response_hex = self::binary_to_hex($response);

					$result = [
						'endpoint' => $this->endpoint(),
						'request_hex' => $this->last_request_hex,
						'response_hex' => $this->last_response_hex,
						'response_expected' => !empty($options['expect_response']),
						'response_received' => ($response !== ''),
					];

					if (!empty($response_frames)) {
						$result['response_frames'] = $response_frames;
					}
					if (!empty($options['label'])) {
						$result['label'] = (string) $options['label'];
					}
					if (!empty($options['expect_response']) && $response === '') {
						$result['warning'] = 'No response received from alarm device within timeout.';
					}

					if (!empty($options['fresh_connection'])) {
						$this->disconnect();
					}

					return $result;
				}
				catch (RuntimeException $exception) {
					$this->disconnect();
					if ($attempt >= (int) $options['max_attempts']) {
						throw $exception;
					}
				}
			}

			throw new RuntimeException('Alarm device command failed');
		});
	}

	private function with_device_lock(callable $callback) {
		$this->acquire_device_lock();
		try {
			return $callback();
		}
		finally {
			$this->release_device_lock();
		}
	}

	private function acquire_device_lock() {
		if ($this->lock_depth > 0 && is_resource($this->lock_handle)) {
			$this->lock_depth++;
			return;
		}

		$lock_path = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . self::DEFAULT_LOCK_FILE;
		$handle = @fopen($lock_path, 'c');
		if (!$handle) {
			throw new RuntimeException('Unable to open alarm device lock file: ' . $lock_path);
		}
		if (!@flock($handle, LOCK_EX)) {
			@fclose($handle);
			throw new RuntimeException('Unable to acquire alarm device lock');
		}

		$this->lock_handle = $handle;
		$this->lock_depth = 1;
	}

	private function release_device_lock($force = false) {
		if ($this->lock_depth <= 0 || !is_resource($this->lock_handle)) {
			$this->lock_depth = 0;
			$this->lock_handle = null;
			return;
		}

		if (!$force && $this->lock_depth > 1) {
			$this->lock_depth--;
			return;
		}

		$this->lock_depth = 0;
		@flock($this->lock_handle, LOCK_UN);
		@fclose($this->lock_handle);
		$this->lock_handle = null;
	}

	private function drain_socket() {
		if (!$this->is_connected()) {
			return;
		}

		stream_set_blocking($this->socket, false);
		try {
			while (true) {
				$chunk = @fread($this->socket, 1024);
				if ($chunk === false || $chunk === '') {
					break;
				}
			}
		}
		finally {
			if (is_resource($this->socket)) {
				stream_set_blocking($this->socket, true);
			}
		}
	}

	private function read_response($idle_grace_ms = self::DEFAULT_RESPONSE_IDLE_GRACE_MS) {
		if (!$this->is_connected()) {
			return '';
		}

		$buffer = '';
		$started_at = microtime(true);
		$last_data_at = null;
		stream_set_blocking($this->socket, false);

		try {
			while ((microtime(true) - $started_at) * 1000 < $this->timeout_ms) {
				$chunk = @fread($this->socket, 1024);
				if ($chunk !== false && $chunk !== '') {
					$buffer .= $chunk;
					$last_data_at = microtime(true);
				}

				if ($last_data_at !== null) {
					$idle_ms = (microtime(true) - $last_data_at) * 1000;
					if ($idle_ms >= $idle_grace_ms) {
						break;
					}
				}

				if (feof($this->socket)) {
					break;
				}

				usleep(20000);
			}
		}
		finally {
			if (is_resource($this->socket)) {
				stream_set_blocking($this->socket, true);
			}
		}

		return $buffer;
	}

	private function parse_frames($binary) {
		$frames = [];
		if ($binary === null || $binary === '') {
			return $frames;
		}

		$offset = 0;
		$length = strlen($binary);
		while ($offset < $length) {
			$start = strpos($binary, chr(self::START_BYTE), $offset);
			if ($start === false) {
				break;
			}

			if ($start + 2 >= $length) {
				break;
			}

			$payload_length = ord($binary[$start + 2]);
			$frame_length = $payload_length + 4;
			if ($frame_length < 10) {
				$frame_length = 10;
			}
			if ($start + $frame_length > $length) {
				break;
			}

			$frame = substr($binary, $start, $frame_length);
			$frames[] = $this->parse_frame($frame);
			$offset = $start + $frame_length;
		}

		return $frames;
	}

	private function parse_frame($frame) {
		$result = [
			'hex' => self::binary_to_hex($frame),
			'length' => strlen($frame),
			'valid' => false,
		];

		if (strlen($frame) < 10) {
			$result['description'] = 'Frame is shorter than the expected 10-byte protocol payload.';
			return $result;
		}

		$bytes = array_values(unpack('C*', $frame));
		$command = $bytes[3] ?? 0x00;
		$feedback = $bytes[4] ?? 0x00;
		$param1 = $bytes[5] ?? 0x00;
		$param2 = $bytes[6] ?? 0x00;
		$checksum = (($bytes[7] ?? 0x00) << 8) | ($bytes[8] ?? 0x00);
		$expected_checksum = $this->calculate_checksum($command, $feedback, $param1, $param2);

		$result['valid'] = (($bytes[0] ?? null) === self::START_BYTE) && (($bytes[count($bytes) - 1] ?? null) === self::END_BYTE);
		$result['start_byte'] = sprintf('0x%02X', $bytes[0] ?? 0x00);
		$result['address'] = sprintf('0x%02X', $bytes[1] ?? 0x00);
		$result['protocol_length'] = $bytes[2] ?? 0;
		$result['command_byte'] = $command;
		$result['command_hex'] = sprintf('0x%02X', $command);
		$result['feedback_byte'] = $feedback;
		$result['feedback_hex'] = sprintf('0x%02X', $feedback);
		$result['param1'] = $param1;
		$result['param1_hex'] = sprintf('0x%02X', $param1);
		$result['param2'] = $param2;
		$result['param2_hex'] = sprintf('0x%02X', $param2);
		$result['checksum_hex'] = sprintf('0x%04X', $checksum);
		$result['checksum_valid'] = ($checksum === $expected_checksum);

		$description = $this->describe_frame($command, $feedback, $param1, $param2);
		if (!empty($description)) {
			$result = array_merge($result, $description);
		}

		return $result;
	}

	private function first_device_error(array $frames) {
		foreach ($frames as $frame) {
			if (($frame['command_byte'] ?? null) !== 0x40) {
				continue;
			}

			$code = (int) ($frame['param2'] ?? 0x00);
			return [
				'code' => $code,
				'message' => $this->device_error_message($code),
				'frame' => $frame,
			];
		}

		return null;
	}

	private function result_indicates_playing($result) {
		if (empty($result['response_frames']) || !is_array($result['response_frames'])) {
			return false;
		}

		foreach ($result['response_frames'] as $frame) {
			if (($frame['command_byte'] ?? null) !== 0x42) {
				continue;
			}

			$status = (int) (($frame['playback_status']['code'] ?? $frame['param2'] ?? 0x00) & 0xFF);
			return $status === 0x01;
		}

		return false;
	}

	private function describe_frame($command, $feedback, $param1, $param2) {
		switch ($command) {
			case 0x3F:
				return [
					'description' => ($param2 === 0x08)
						? 'Device online query response: FLASH storage is online.'
						: 'Device online query response.',
					'online' => ($param2 === 0x08),
				];
			case 0x42:
				return [
					'description' => 'Playback status query response.',
					'playback_status' => [
						'code' => $param2,
						'text' => $this->playback_status_text($param2),
					],
				];
			case 0x43:
				return [
					'description' => 'Volume query response.',
					'volume' => $param2,
				];
			case 0x70:
				return [
					'description' => 'Sound and strobe status query response.',
					'sound_light_status' => [
						'track' => $param1,
						'audio_active' => ($param1 > 0),
						'light_mode_code' => $param2,
						'light_mode_text' => $this->light_mode_text($param2, true),
					],
				];
			case 0x41:
				return [
					'description' => 'ACK: command accepted by alarm device.',
					'acknowledged' => true,
				];
			case 0x40:
				return [
					'description' => 'Alarm device error response.',
					'error' => [
						'code' => $param2,
						'message' => $this->device_error_message($param2),
					],
				];
			case 0x3E:
				return [
					'description' => 'Playback completed notification.',
					'completed_track' => $param2,
				];
			default:
				return [
					'description' => 'Protocol frame received from alarm device.',
				];
		}
	}

	private function playback_status_text($code) {
		switch ((int) $code) {
			case 0x01:
				return 'playing';
			case 0x02:
				return 'paused';
			case 0x00:
			default:
				return 'stopped';
		}
	}

	private function light_mode_text($code, $from_status_query = false) {
		$code = (int) $code;
		if (!$from_status_query && $code > 0x0F) {
			$code = ($code >> 4) & 0x0F;
		}

		switch ($code) {
			case 0x00:
				return 'flash while audio is playing';
			case 0x01:
				return 'slow flash while audio is playing';
			case 0x02:
				return 'steady on while audio is playing';
			case 0x03:
				return 'continuous fast flash';
			case 0x04:
				return 'continuous slow flash';
			case 0x05:
				return 'continuous steady on';
			case 0x06:
				return 'off';
			default:
				return 'unknown';
		}
	}

	private function device_error_message($code) {
		switch ((int) $code) {
			case 0x01:
				return 'Device is busy or still initializing the file system.';
			case 0x03:
				return 'Device reported an incomplete serial frame.';
			case 0x04:
				return 'Device reported a checksum error.';
			case 0x05:
				return 'Requested file index is out of range.';
			case 0x06:
				return 'Requested audio file was not found on the device.';
			case 0x08:
				return 'Command parameters are invalid for this device.';
			default:
				return 'Unknown device error code 0x' . strtoupper(str_pad(dechex((int) $code & 0xFF), 2, '0', STR_PAD_LEFT)) . '.';
		}
	}

	private function calculate_checksum($command, $feedback, $param1, $param2) {
		$sum = self::VERSION + self::LENGTH + ($command & 0xFF) + ($feedback & 0xFF) + ($param1 & 0xFF) + ($param2 & 0xFF);
		return (0xFFFF - $sum + 1) & 0xFFFF;
	}

	private function sanitize_byte($value, $default) {
		$value = (int) $value;
		if ($value < 0 || $value > 0xFF) {
			return $default;
		}
		return $value;
	}

	private function sanitize_volume($value, $default) {
		$value = (int) $value;
		if ($value < 0 || $value > 30) {
			return $default;
		}
		return $value;
	}

	public static function binary_to_hex($binary) {
		if ($binary === null || $binary === '') {
			return '';
		}
		return strtoupper(trim(chunk_split(bin2hex($binary), 2, ' ')));
	}
}
