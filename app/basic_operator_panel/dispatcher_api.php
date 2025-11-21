<?php
/*
	FusionPBX
	调度终端 API 接口
*/

//includes files
require_once dirname(__DIR__, 2) . "/resources/require.php";
require_once "resources/check_auth.php";

//check permissions
if (permission_exists('operator_panel_view')) {
	//access granted
}
else {
	echo json_encode(['error' => 'access denied']);
	exit;
}

//set content type
header('Content-Type: application/json');

//get action
$action = $_GET['action'] ?? $_POST['action'] ?? '';

//process request
switch ($action) {
	
	case 'reloadxml':
		//重载FreeSWITCH的XML配置
		$esl = event_socket::create();
		if ($esl && $esl->is_connected()) {
			$response = $esl->request("api reloadxml");
			echo json_encode([
				'success' => true,
				'message' => 'Reloaded XML configuration',
				'response' => $response
			]);
		} else {
			echo json_encode([
				'success' => false,
				'error' => 'Failed to connect to FreeSWITCH'
			]);
		}
		break;
		
	case 'get_extensions':
		//获取所有分机列表
		$sql = "select ";
		$sql .= "e.extension, ";
		$sql .= "e.number_alias, ";
		$sql .= "e.effective_caller_id_name, ";
		$sql .= "e.description, ";
		$sql .= "e.call_group ";
		$sql .= "from v_extensions as e ";
		$sql .= "where e.enabled = 'true' ";
		$sql .= "and e.domain_uuid = :domain_uuid ";
		$sql .= "order by e.extension asc ";
		
		$parameters['domain_uuid'] = $_SESSION['domain_uuid'];
		$database = new database;
		$extensions = $database->select($sql, $parameters);
		
		echo json_encode([
			'success' => true,
			'data' => $extensions
		]);
		break;
	
	case 'get_call_groups':
		//获取呼叫组配置
		$sql = "select distinct call_group ";
		$sql .= "from v_extensions ";
		$sql .= "where domain_uuid = :domain_uuid ";
		$sql .= "and call_group is not null ";
		$sql .= "and call_group != '' ";
		$sql .= "order by call_group ";
		
		$parameters['domain_uuid'] = $_SESSION['domain_uuid'];
		$database = new database;
		$call_groups = $database->select($sql, $parameters);
		
		//获取每个组的成员
		$groups = [];
		if (is_array($call_groups)) {
			foreach ($call_groups as $row) {
				$group_name = $row['call_group'];
				
				$sql = "select extension ";
				$sql .= "from v_extensions ";
				$sql .= "where domain_uuid = :domain_uuid ";
				$sql .= "and call_group = :call_group ";
				$sql .= "and enabled = 'true' ";
				$sql .= "order by extension ";
				
				$parameters['domain_uuid'] = $_SESSION['domain_uuid'];
				$parameters['call_group'] = $group_name;
				$members = $database->select($sql, $parameters);
				
				$extensions = [];
				if (is_array($members)) {
					foreach ($members as $member) {
						$extensions[] = $member['extension'];
					}
				}
				
				$groups[$group_name] = [
					'name' => $group_name,
					'extensions' => $extensions
				];
			}
		}
		
		echo json_encode([
			'success' => true,
			'data' => $groups
		]);
		break;
	
	case 'save_call_log':
		//保存通话日志
		$log_type = $_POST['log_type'] ?? ''; // trunk, group, conference
		$participants = $_POST['participants'] ?? '';
		$duration = $_POST['duration'] ?? 0;
		$start_time = $_POST['start_time'] ?? '';
		$end_time = $_POST['end_time'] ?? '';
		
		//可以保存到自定义表或使用现有的 CDR 表
		//这里仅做示例
		$log_data = [
			'log_type' => $log_type,
			'participants' => $participants,
			'duration' => $duration,
			'start_time' => $start_time,
			'end_time' => $end_time,
			'operator' => $_SESSION['user']['username'],
			'domain_uuid' => $_SESSION['domain_uuid']
		];
		
		//TODO: 保存到数据库
		//可以创建一个专门的调度日志表
		
		echo json_encode([
			'success' => true,
			'message' => 'Log saved'
		]);
		break;
	
	case 'get_sip_config':
		//获取调度员 SIP 配置（如果在数据库中存储）
		//从用户设置或默认设置中读取
		
		$sip_config = [
			'uri' => 'sip:' . ($_SESSION['user']['extension'][0]['user'] ?? '1000') . '@' . $_SESSION['domain_name'],
			'wsServers' => 'ws://' . $_SESSION['domain_name'] . ':5066',
			'authUser' => $_SESSION['user']['extension'][0]['user'] ?? '1000',
			'displayName' => $_SESSION['user']['username'] ?? '调度员'
		];
		
		echo json_encode([
			'success' => true,
			'data' => $sip_config
		]);
		break;
	
	case 'get_active_calls':
		//获取当前活动通话
		$switch_result = event_socket::api('show channels as json');
		
		if ($switch_result !== false) {
			$channels = json_decode($switch_result, true);
			echo json_encode([
				'success' => true,
				'data' => $channels
			]);
		} else {
			echo json_encode([
				'success' => false,
				'error' => 'Failed to get active calls'
			]);
		}
		break;

	case 'check_transfer':
		// 检查转接目标是否已建立通话（用于前端轮询确认）
		$target_ext = $_POST['target_ext'] ?? $_GET['target_ext'] ?? '';
		if ($target_ext === '') {
			echo json_encode(['success' => false, 'error' => 'target_ext required']);
			break;
		}
		$channels_result = event_socket::api('show channels as json');
		if ($channels_result === false) {
			echo json_encode(['success' => false, 'error' => 'esl_unavailable']);
			break;
		}
		$channels = json_decode($channels_result, true);
		$found = false;
		$detail = null;
		if (is_array($channels) && isset($channels['rows'])) {
			foreach ($channels['rows'] as $row) {
				$dest = $row['destination_number'] ?? '';
				$state = $row['callstate'] ?? '';
				if ($dest == $target_ext && strtoupper($state) === 'ACTIVE') {
					$found = true;
					$detail = [
						'uuid' => $row['uuid'] ?? '',
						'cid_num' => $row['cid_num'] ?? '',
						'cid_name' => $row['cid_name'] ?? ''
					];
					break;
				}
			}
		}
		echo json_encode(['success' => $found, 'detail' => $detail]);
		break;

	case 'force_hangup':
		// 强制挂断：根据 uri/direction 查找并终止匹配的通道
		$uri = $_POST['uri'] ?? $_GET['uri'] ?? '';
		$direction = $_POST['direction'] ?? $_GET['direction'] ?? '';
		if ($uri === '') {
			echo json_encode(['success' => false, 'error' => 'uri required']);
			break;
		}
		if (!preg_match('/sip:(\d+)@/i', $uri, $m)) {
			echo json_encode(['success' => false, 'error' => 'invalid uri']);
			break;
		}
		$ext = $m[1];
		$channels_result = event_socket::api('show channels as json');
		if ($channels_result === false) {
			echo json_encode(['success' => false, 'error' => 'esl_unavailable']);
			break;
		}
		$channels = json_decode($channels_result, true);
		$killed = 0;
		$uuids = [];
		if (is_array($channels) && isset($channels['rows'])) {
			foreach ($channels['rows'] as $row) {
				$dest = $row['destination_number'] ?? '';
				$cid = $row['cid_num'] ?? '';
				$state = strtoupper($row['callstate'] ?? '');
				$dir = strtolower($row['direction'] ?? '');
				if ($state !== 'ACTIVE' && $state !== 'RINGING' && $state !== 'EARLY' && $state !== 'HELD') continue;
				if ($direction && $dir && $direction !== $dir) continue;
				if ($dest == $ext || $cid == $ext) {
					$uuid = $row['uuid'] ?? '';
					if ($uuid) { $uuids[] = $uuid; }
				}
			}
		}
		foreach ($uuids as $u) {
			$resp = event_socket::api('uuid_kill ' . $u);
			if ($resp !== false && stripos($resp, '-ERR') === false) { $killed++; }
		}
		echo json_encode(['success' => true, 'killed' => $killed]);
		break;
	
	case 'initiate_emergency_call':
		// 发起急呼（调度员→用户）
		$target_extension = $_POST['target_extension'] ?? '';
		$emergency_type = $_POST['emergency_type'] ?? 'single'; // single, group, broadcast
		$targets = $_POST['targets'] ?? []; // 目标分机数组
		
		if (empty($target_extension) && empty($targets)) {
			echo json_encode(['success' => false, 'error' => 'No target specified']);
			exit;
		}
		
		// 检查权限
		if (!permission_exists('operator_panel_emergency')) {
			echo json_encode(['success' => false, 'error' => 'Permission denied']);
			exit;
		}
		
		// 广播急呼需要特殊权限
		if ($emergency_type === 'broadcast' && !permission_exists('operator_panel_emergency_broadcast')) {
			echo json_encode(['success' => false, 'error' => 'Broadcast permission denied']);
			exit;
		}
		
		// 创建急呼记录
		$emergency_uuid = uuid();
		$sql = "INSERT INTO v_emergency_calls (";
		$sql .= "emergency_call_uuid, domain_uuid, direction, caller_extension, ";
		$sql .= "emergency_type, status, insert_user";
		$sql .= ") VALUES (";
		$sql .= ":emergency_call_uuid, :domain_uuid, :direction, :caller_extension, ";
		$sql .= ":emergency_type, :status, :insert_user";
		$sql .= ")";
		
		$parameters = [
			'emergency_call_uuid' => $emergency_uuid,
			'domain_uuid' => $_SESSION['domain_uuid'],
			'direction' => 'dispatcher_to_user',
			'caller_extension' => $_SESSION['user']['extension'][0]['user'] ?? '',
			'emergency_type' => $emergency_type,
			'status' => 'initiated',
			'insert_user' => $_SESSION['user']['user_uuid']
		];
		
		$database = new database;
		$database->execute($sql, $parameters);
		
		echo json_encode([
			'success' => true,
			'emergency_uuid' => $emergency_uuid
		]);
		break;

	case 'log_emergency_call':
		// 记录急呼状态更新
		$emergency_uuid = $_POST['emergency_uuid'] ?? '';
		$call_uuid = $_POST['call_uuid'] ?? '';
		$status = $_POST['status'] ?? '';
		$recording_path = $_POST['recording_path'] ?? '';
		
		if (!is_uuid($emergency_uuid)) {
			echo json_encode(['success' => false, 'error' => 'Invalid UUID']);
			exit;
		}
		
		$sql = "UPDATE v_emergency_calls SET ";
		$fields = [];
		$parameters = ['emergency_call_uuid' => $emergency_uuid];
		
		if (!empty($call_uuid)) {
			$fields[] = "call_uuid = :call_uuid";
			$parameters['call_uuid'] = $call_uuid;
		}
		if (!empty($status)) {
			$fields[] = "status = :status";
			$parameters['status'] = $status;
			if ($status === 'answered') {
				$fields[] = "answer_time = NOW()";
			} elseif ($status === 'completed' || $status === 'failed') {
				$fields[] = "end_time = NOW()";
			}
		}
		if (!empty($recording_path)) {
			$fields[] = "recording_path = :recording_path";
			$parameters['recording_path'] = $recording_path;
		}
		
		$sql .= implode(", ", $fields);
		$sql .= " WHERE emergency_call_uuid = :emergency_call_uuid";
		
		$database = new database;
		$database->execute($sql, $parameters);
		
		echo json_encode(['success' => true]);
		break;

	case 'bridge_emergency_call':
		// 桥接急呼：将已park的通道桥接到调度员
		$destination = $_POST['destination'] ?? '';
		$target_channel_uuid = $_POST['target_channel_uuid'] ?? '';
		
		if (empty($destination)) {
			echo json_encode(['success' => false, 'error' => 'Destination is required']);
			break;
		}
		
		// 检查session中是否有急呼信息
		if (!isset($_SESSION['emergency_bridge'][$destination])) {
			echo json_encode(['success' => false, 'error' => 'No emergency call found for destination']);
			break;
		}
		
		$emergency_info = $_SESSION['emergency_bridge'][$destination];
		$source = $emergency_info['source'];
		$job_uuid = $emergency_info['job_uuid'];
		
		// 检查超时（5分钟）
		if (time() - $emergency_info['timestamp'] > 300) {
			unset($_SESSION['emergency_bridge'][$destination]);
			echo json_encode(['success' => false, 'error' => 'Emergency call timed out']);
			break;
		}
		
		// 获取目标分机的通道UUID（优先使用前端提供的 UUID）
		if (empty($target_channel_uuid)) {
			$channels_cmd = 'show channels as json';
			$channels_result = event_socket::api($channels_cmd);
			$channels = json_decode($channels_result, true);
			
			if (is_array($channels) && isset($channels['rows'])) {
				foreach ($channels['rows'] as $channel) {
					// 查找park的通道（destination_number为目标分机）
					if (isset($channel['destination_number']) && 
						$channel['destination_number'] == $destination &&
						isset($channel['application']) && strpos($channel['application'], 'park') !== false) {
						$target_channel_uuid = $channel['uuid'];
						break;
					}
				}
			}
		}
		
		if (!$target_channel_uuid) {
			echo json_encode(['success' => false, 'error' => 'Target channel not found']);
			break;
		}
		
		// 为调度员通道生成UUID，便于后续跟踪
		$dispatcher_uuid = uuid();
		$domain_name = $_SESSION['domain_name'];
		
		// 构造调度员 leg 的急呼参数（与 exec.php 中保持一致）
		$params = [];
		$params[] = 'origination_uuid=' . $dispatcher_uuid;
		$params[] = 'origination_caller_id_name=' . $destination;
		$params[] = 'origination_caller_id_number=' . $destination;
		$params[] = 'sip_h_X-Emergency-Call=true';
		$params[] = 'sip_h_Alert-Info=<http://fusionpbx.com>;info=emergency;answer-after=15';
		$params_string = '{' . implode(',', $params) . '}';
		
		// 调度员 leg：呼叫调度分机到 park，随后通过 uuid_bridge 桥接到已park的目标通道
		$originate_cmd = 'bgapi originate ' . $params_string . 'user/' . $source . '@' . $domain_name . ' &park()';
		$originate_result = event_socket::api($originate_cmd);
		
		error_log("Emergency Bridge Debug - Originate Command: " . $originate_cmd);
		error_log("Emergency Bridge Debug - Originate Result: " . $originate_result);
		
		if ($originate_result === false || stripos($originate_result, '-ERR') !== false) {
			echo json_encode(['success' => false, 'error' => 'Dispatcher originate failed: ' . $originate_result]);
			break;
		}
		
		// 等待调度员通道创建完成
		$max_attempts = 10; // 最多等待约2秒
		$attempt = 0;
		$dispatcher_ready = false;
		while ($attempt < $max_attempts) {
			$attempt++;
			$exists = trim(event_socket::api('uuid_exists ' . $dispatcher_uuid));
			if ($exists === 'true') {
				$dispatcher_ready = true;
				break;
			}
			usleep(200000); // 200ms
		}
		
		if (!$dispatcher_ready) {
			echo json_encode(['success' => false, 'error' => 'Dispatcher channel not found']);
			break;
		}
		
		// 使用 uuid_bridge 将调度员通道与目标通道桥接
		$bridge_cmd = 'uuid_bridge ' . $dispatcher_uuid . ' ' . $target_channel_uuid;
		$bridge_result = event_socket::api($bridge_cmd);
		
		error_log("Emergency Bridge Debug - Bridge Command: " . $bridge_cmd);
		error_log("Emergency Bridge Debug - Bridge Result: " . $bridge_result);
		
		if ($bridge_result === false || stripos($bridge_result, '-ERR') !== false) {
			echo json_encode(['success' => false, 'error' => 'Bridge failed: ' . $bridge_result]);
			break;
		}
		
		// 清除session中的急呼信息
		unset($_SESSION['emergency_bridge'][$destination]);
		
		echo json_encode([
			'success' => true,
			'channel_uuid' => $target_channel_uuid,
			'dispatcher_uuid' => $dispatcher_uuid,
			'bridge_result' => $bridge_result
		]);
		break;

	case 'get_emergency_history':
		// 获取急呼历史记录
		$sql = "SELECT * FROM v_emergency_calls ";
		$sql .= "WHERE domain_uuid = :domain_uuid ";
		$sql .= "ORDER BY start_time DESC LIMIT 100";
		
		$parameters = ['domain_uuid' => $_SESSION['domain_uuid']];
		$database = new database;
		$history = $database->select($sql, $parameters, 'all');
		
		echo json_encode([
			'success' => true,
			'data' => $history
		]);
		break;
	
	case 'get_conferences':
	case 'get_emergency_conferences':
		// 获取会议列表（支持按紧急状态和呼叫模式筛选）
		$emergency_filter = $_GET['emergency_filter'] ?? 'all';  // 'true'/'false'/'all'
		$call_mode_filter = $_GET['call_mode_filter'] ?? 'all';   // 'group_call'/'all_call'/'single_call'/'all'
		
		// 为了向后兼容，如果调用的是 get_emergency_conferences，默认筛选紧急会议
		if ($action === 'get_emergency_conferences' && $emergency_filter === 'all') {
			$emergency_filter = 'true';
		}

		// 获取紧急会议列表
		$sql = "select conference_uuid, conference_extension, conference_name, call_mode, ";
		$sql .= "call_mode_targets, conference_description ";
		$sql .= "from v_conferences ";
		$sql .= "where domain_uuid = :domain_uuid ";
		$sql .= "and conference_enabled = 'true' ";
		
		// 按紧急状态筛选
		if ($emergency_filter === 'true') {
			$sql .= "and emergency_enabled = 'true' ";
		} else if ($emergency_filter === 'false') {
			$sql .= "and emergency_enabled = 'false' ";
		}
		// emergency_filter === 'all' 时不添加筛选条件
		
		// 按呼叫模式筛选
		if ($call_mode_filter !== 'all') {
			$sql .= "and call_mode = :call_mode ";
		}
		
		$sql .= "order by conference_name asc ";
		
		$parameters['domain_uuid'] = $_SESSION['domain_uuid'];
		if ($call_mode_filter !== 'all') {
			$parameters['call_mode'] = $call_mode_filter;
		}
		
		$database = new database;
		$conferences = $database->select($sql, $parameters, 'all');
		
		$result = [];
		if (!empty($conferences)) {
			// 获取所有启用的分机（用于 all_call 和验证）
			$sql_ext = "select extension from v_extensions ";
			$sql_ext .= "where domain_uuid = :domain_uuid and enabled = 'true' ";
			$sql_ext .= "order by extension asc ";
			$parameters_ext = ['domain_uuid' => $_SESSION['domain_uuid']];
			$all_extensions_rows = $database->select($sql_ext, $parameters_ext, 'all');
			$all_extensions = [];
			if (!empty($all_extensions_rows)) {
				foreach ($all_extensions_rows as $e) {
					$all_extensions[] = $e['extension'];
				}
			}
			
			foreach ($conferences as $conf) {
				$call_mode = $conf['call_mode'] ?? 'single_call';
				$participants = [];
				
				if ($call_mode === 'all_call') {
					// 全呼：返回所有启用分机
					$participants = $all_extensions;
				} else if ($call_mode === 'group_call' || $call_mode === 'single_call') {
					// 群呼/单呼：解析 call_mode_targets
					$targets_raw = $conf['call_mode_targets'] ?? '';
					if (!empty($targets_raw)) {
						$targets = array_filter(array_map('trim', explode(',', $targets_raw)));
						// 过滤出在启用分机列表中的目标
						foreach ($targets as $t) {
							if (in_array($t, $all_extensions, true)) {
								$participants[] = $t;
							}
						}
					}
				}
				
				$result[] = [
					'conference_uuid' => $conf['conference_uuid'],
					'extension' => $conf['conference_extension'],
					'name' => $conf['conference_name'],
					'call_mode' => $call_mode,
					'participants' => $participants,
					'description' => $conf['conference_description'] ?? '',
					'emergency_enabled' => $conf['emergency_enabled'] ?? 'false'
				];
			}
		}
		
		echo json_encode([
			'success' => true,
			'items' => $result
		]);
		break;
	
	default:
		echo json_encode([
			'error' => 'Invalid action'
		]);
		break;
}

?>
