<?php

class operator_panel_recording {

	public static function get_recordings_dir(): string {
		$dir = trim((string) ($_SESSION['switch']['recordings']['dir'] ?? ''));
		if ($dir !== '') {
			return rtrim($dir, '/');
		}

		$esl = event_socket::create();
		if ($esl && $esl->is_connected()) {
			$esl_dir = trim((string) $esl->request('api global_getvar recordings_dir'));
			if ($esl_dir !== '') {
				return rtrim($esl_dir, '/');
			}
		}

		return '/var/lib/freeswitch/recordings';
	}

	public static function session_archive_path(string $domain_name = '', string $file_token = '${uuid}'): string {
		$segments = [self::get_recordings_dir()];
		$domain_name = self::clean_path_segment($domain_name);
		if ($domain_name !== '') {
			$segments[] = $domain_name;
		}
		$segments[] = 'archive';
		$segments[] = date('Y');
		$segments[] = date('M');
		$segments[] = date('d');

		return implode('/', $segments) . '/' . self::clean_file_token($file_token) . '.wav';
	}

	public static function add_originate_recording_params(array $params, string $recording_path): array {
		$recording_path = trim($recording_path);
		if ($recording_path === '') {
			return $params;
		}

		$params[] = 'recording_follow_transfer=true';
		$params[] = 'media_bug_answer_req=true';
		$params[] = 'record_session=true';
		$params[] = 'recording_path=' . $recording_path;
		$params[] = "execute_on_answer='" . self::escape_single_quotes('record_session ' . $recording_path) . "'";

		return $params;
	}

	public static function enable_record_session(string $uuid, string $recording_path, array $related_uuids = []): array {
		$uuid = self::clean_uuid($uuid);
		$recording_path = trim($recording_path);

		if ($uuid === '' || $recording_path === '') {
			return [
				'success' => false,
				'error' => 'invalid_recording_target',
				'channel_uuid' => $uuid,
				'recording_path' => $recording_path,
				'responses' => [],
			];
		}

		$targets = array_values(array_unique(array_filter(array_merge([$uuid], array_map([self::class, 'clean_uuid'], $related_uuids)))));
		$responses = [];

		foreach ($targets as $target_uuid) {
			foreach ([
				'uuid_setvar ' . $target_uuid . ' recording_follow_transfer true',
				'uuid_setvar ' . $target_uuid . ' media_bug_answer_req true',
				'uuid_setvar ' . $target_uuid . ' recording_path ' . $recording_path,
			] as $cmd) {
				$response = event_socket::api($cmd);
				$responses[] = [
					'command' => $cmd,
					'response' => $response,
					'success' => self::esl_success($response),
				];
			}
		}

		$broadcast_cmd = 'uuid_broadcast ' . $uuid . ' record_session::' . $recording_path . ' aleg';
		$broadcast_response = event_socket::api($broadcast_cmd);
		$broadcast_success = self::esl_success($broadcast_response);
		$responses[] = [
			'command' => $broadcast_cmd,
			'response' => $broadcast_response,
			'success' => $broadcast_success,
		];

		return [
			'success' => $broadcast_success,
			'channel_uuid' => $uuid,
			'related_uuids' => $targets,
			'recording_path' => $recording_path,
			'responses' => $responses,
		];
	}

	public static function esl_success($result): bool {
		return $result !== false && stripos((string) $result, '-ERR') === false;
	}

	protected static function clean_uuid(string $uuid): string {
		return preg_replace('/[^-A-Fa-f0-9]/', '', trim($uuid));
	}

	protected static function clean_path_segment(string $value): string {
		return trim(preg_replace('/[^0-9A-Za-z_.-]/', '', $value));
	}

	protected static function clean_file_token(string $value): string {
		$value = trim($value);
		if ($value === '') {
			return '${uuid}';
		}
		return preg_replace('/[^0-9A-Za-z_.${}-]/', '', $value);
	}

	protected static function escape_single_quotes(string $value): string {
		return str_replace("'", "\\'", $value);
	}
}
