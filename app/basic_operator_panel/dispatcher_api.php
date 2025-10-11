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
	
	default:
		echo json_encode([
			'error' => 'Invalid action'
		]);
		break;
}

?>

