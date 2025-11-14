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
		
		// 获取目标分机的通道UUID
		$channels_cmd = 'show channels as json';
		$channels_result = event_socket::api($channels_cmd);
		$channels = json_decode($channels_result, true);
		
		$target_channel_uuid = null;
		if (is_array($channels) && isset($channels['rows'])) {
			foreach ($channels['rows'] as $channel) {
				// 查找park的通道（destination_number为目标分机）
				if (isset($channel['destination_number']) && 
					$channel['destination_number'] == $destination &&
					strpos($channel['application'], 'park') !== false) {
					$target_channel_uuid = $channel['uuid'];
					break;
				}
			}
		}
		
		if (!$target_channel_uuid) {
			echo json_encode(['success' => false, 'error' => 'Target channel not found']);
			break;
		}
		
		// 桥接通道：将park的通道桥接到调度员
		$bridge_cmd = 'uuid_bridge ' . $target_channel_uuid . ' user/' . $source . '@' . $_SESSION['domain_name'];
		$bridge_result = event_socket::api($bridge_cmd);
		
		error_log("Emergency Bridge Debug - Command: " . $bridge_cmd);
		error_log("Emergency Bridge Debug - Result: " . $bridge_result);
		
		if (stripos($bridge_result, '-ERR') !== false) {
			echo json_encode(['success' => false, 'error' => 'Bridge failed: ' . $bridge_result]);
			break;
		}
		
		// 清除session中的急呼信息
		unset($_SESSION['emergency_bridge'][$destination]);
		
		echo json_encode([
			'success' => true,
			'channel_uuid' => $target_channel_uuid,
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
	
	default:
		echo json_encode([
			'error' => 'Invalid action'
		]);
		break;
}

?>
