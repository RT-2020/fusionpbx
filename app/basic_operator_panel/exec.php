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

//check permissions
	if (permission_exists('operator_panel_view')) {
		//access granted
	}
	else {
		echo "access denied";
		exit;
	}

//authorized referrer
// 	if(stristr($_SERVER["HTTP_REFERER"], '/index.php') === false) {
// 		if(stristr($_SERVER["HTTP_REFERER"], '/index_inc.php') === false) {
// 			echo " access denied";
// 			exit;
// 		}
// 	}

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

		if ($switch_cmd == 'originate') {
			$source = preg_replace($num_pattern,'',$_REQUEST['source']);
			$destination = preg_replace($num_pattern,'',$_REQUEST['destination']);
			$emergency = $_REQUEST['emergency'] ?? 'false'; // 急呼标识
			
				// 急呼模式：先呼叫目标分机（被叫），然后自动接听并连接到调度员（主叫）
				// 使用特殊的拨号计划，确保急呼的特殊行为
				$api_cmd = 'bgapi originate ' . $params_string . ' user/' . $destination . '@' . $_SESSION['domain_name'] . ' &park()';
				
				// 记录原始命令，用于后续桥接
				$_SESSION['emergency_bridge'][$destination] = [
					'source' => $source,
					'destination' => $destination,
					'job_uuid' => '',
					'timestamp' => time()
				];
			} else {
				// 普通模式：直接呼叫
				$api_cmd = 'bgapi originate ' . $params_string . ' user/' . $source . '@' . $_SESSION['domain_name'] . ' ' . $destination . ' XML ' . trim($_SESSION['user_context']);
			}
		}
			else if ($switch_cmd == 'uuid_record') {
				$uuid = preg_replace($uuid_pattern,'',$_REQUEST['uuid']);
				$api_cmd = 'uuid_record ' . $uuid . ' start ' . $_SESSION['switch']['recordings']['dir'] . '/' . $_SESSION['domain_name'] . '/archive/' . date('Y/M/d') . '/' . $uuid . '.wav';
			}
			else if ($switch_cmd == 'uuid_transfer') {
				$uuid = preg_replace($uuid_pattern,'',$_REQUEST['uuid']);
				$destination = preg_replace($num_pattern,'',$_REQUEST['destination']);
				$api_cmd = 'uuid_transfer ' . $uuid . ' ' . $destination . ' XML ' . trim($_SESSION['user_context']);
			}
		else if ($switch_cmd == 'uuid_eavesdrop') {
			$chan_uuid = preg_replace($uuid_pattern,'',$_REQUEST['chan_uuid']);
			$ext = preg_replace($num_pattern,'',$_REQUEST['ext']);
			$destination = preg_replace($num_pattern,'',$_REQUEST['destination']);
			$mode = trim($_REQUEST['mode'] ?? 'listen');

			$language = new text;
			$text = $language->get();

		// 根据模式选择不同的 FreeSWITCH 应用
		$caller_id_name = $text['label-eavesdrop'];
		$app = '&eavesdrop(' . $chan_uuid . ')'; // 默认监听
		$domain_name = $_SESSION['domain_name'];
		
		if ($mode == 'three-way') {
			// 使用 conference（会议室）方案实现真正的三方通话
			// 这是 FusionPBX 标准的三方通话实现方式
			$caller_id_name = $text['label-three_way'] ?? '插入讲话';
			
			// 步骤1: 创建临时会议室（使用时间戳+随机数避免冲突）
			$conference_name = 'barge-' . time() . '-' . substr(md5(uniqid()), 0, 8);
			
			// 步骤2: 将现有通话转移到会议室
			// uuid_transfer 命令将指定 UUID 的通话转移到会议室
			// 使用 '-both' 参数将通话的两端都转移到会议室
			$transfer_cmd = 'uuid_transfer ' . $chan_uuid . ' -both conference:' . $conference_name . '@default inline';
			$transfer_result = event_socket::api($transfer_cmd);
			
			// 调试输出
			error_log("三方通话 - 会议室: $conference_name, 转移结果: $transfer_result");
			
			// 如果转移失败，输出错误并退出
			if (stripos($transfer_result, '-ERR') !== false) {
				echo "转移到会议室失败: " . $transfer_result . "\n会议室: " . $conference_name;
				return;
			}
			
			// 步骤3: 呼叫插入方并加入会议室
			// 使用 conference 应用，profile 设置为 default
			// 会议室参数说明：
			// - +flags{mute|deaf} 可以控制静音/静听
			// - 不带任何标志表示正常模式（能听能说）
			$app = '&conference(' . $conference_name . '@default)';
			
			// 设置会议室参数和 WebRTC 音频参数
			// conference_member_flags 确保成员能正常说话和听（不静音、不静听）
			$api_cmd = 'originate {origination_caller_id_name=' . $caller_id_name . ',origination_caller_id_number=' . $ext . ',sip_auto_answer=true,conference_member_flags=,originate_timeout=10}user/' . $destination . '@' . $domain_name . ' ' . $app;
		}
		else {
			// 监听模式使用 eavesdrop
			$api_cmd = 'originate {origination_caller_id_name=' . $caller_id_name . ',origination_caller_id_number=' . $ext . ',sip_auto_answer=true,originate_timeout=10}user/' . $destination . '@' . $domain_name . ' ' . $app;
		}
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
		
		// 输出FreeSWITCH返回结果（用于调试），get_channel_uuid 除外
		if ($switch_cmd != 'get_channel_uuid') {
			echo $switch_result;
		}
		
		// 特殊处理get_channel_uuid命令
		if ($switch_cmd == 'get_channel_uuid') {
			$destination = $_REQUEST['destination'];
			$channels = json_decode($switch_result, true);
			
			// 添加调试日志
			error_log("Channel UUID Debug - Looking for destination: " . $destination);
			error_log("Channel UUID Debug - Total channels: " . (is_array($channels) && isset($channels['rows']) ? count($channels['rows']) : 0));
			
			if (is_array($channels) && isset($channels['rows'])) {
				foreach ($channels['rows'] as $channel) {
					// 记录所有通道信息用于调试
					error_log("Channel UUID Debug - Channel: " . json_encode($channel));
					
					// 检查是否是目标分机的通道（多种方式）
					$found = false;
					
					// 优先检查park通道（急呼场景）
					if (isset($channel['application']) && $channel['application'] === 'park' && 
						isset($channel['name']) && strpos($channel['name'], $destination) !== false) {
						$found = true;
						error_log("Channel UUID Debug - Found park channel for " . $destination);
					}
					
					// 方式1：检查destination_number
					if (!$found && isset($channel['destination_number']) && $channel['destination_number'] == $destination) {
						$found = true;
					}
					
					// 方式2：检查caller_id_number（对于急呼场景）
					if (!$found && isset($channel['caller_id_number']) && $channel['caller_id_number'] == $destination) {
						$found = true;
					}
					
					// 方式3：检查presence_id（某些情况下可能使用）
					if (!$found && isset($channel['presence_id']) && strpos($channel['presence_id'], $destination) !== false) {
						$found = true;
					}
					
					// 方式4：检查channel_name中是否包含目标分机
					if (!$found && isset($channel['name']) && strpos($channel['name'], $destination) !== false) {
						$found = true;
					}
					
					if ($found) {
						// 找到目标分机的通道，返回UUID
						error_log("Channel UUID Debug - Found channel for " . $destination . ": " . $channel['uuid']);
						echo $channel['uuid'];
						return;
					}
				}
			}
			
			// 没有找到目标分机的通道
			error_log("Channel UUID Debug - No channel found for: " . $destination);
			echo 'false';
			return;
		}
		
		// 添加调试日志
		if ($switch_cmd == 'originate' && $emergency === 'true') {
			error_log("Emergency Call Debug - Command: " . $api_cmd);
			error_log("Emergency Call Debug - Response: " . $switch_result);
			error_log("Emergency Call Debug - Source: " . $source . ", Destination: " . $destination);
			error_log("Emergency Call Debug - Parameters: " . $params_string);
			
			// 检查响应是否包含Job-UUID
			if (preg_match('/Job-UUID:\s*([a-f0-9\-]+)/i', $switch_result, $matches)) {
				$job_uuid = $matches[1];
				error_log("Emergency Call Debug - Job UUID: " . $job_uuid);
				
				// 更新session中的Job-UUID
				if (isset($_SESSION['emergency_bridge'][$destination])) {
					$_SESSION['emergency_bridge'][$destination]['job_uuid'] = $job_uuid;
				}
				
				// 立即检查通道状态
				$channels_cmd = 'show channels as json';
				$channels_result = event_socket::api($channels_cmd);
				$channels = json_decode($channels_result, true);
				
				if (is_array($channels) && isset($channels['rows'])) {
					error_log("Emergency Call Debug - Active channels after originate: " . count($channels['rows']));
					foreach ($channels['rows'] as $channel) {
						if (isset($channel['destination_number']) && 
							($channel['destination_number'] == $destination || $channel['destination_number'] == $source)) {
							error_log("Emergency Call Debug - Found related channel: " . json_encode($channel));
						}
					}
				}
			} else {
				error_log("Emergency Call Debug - No Job UUID found in response");
			}
		}
		
		// 添加uuid_exists调试日志
		if ($switch_cmd == 'uuid_exists') {
			error_log("UUID Exists Debug - Command: " . $api_cmd);
			error_log("UUID Exists Debug - Response: " . $switch_result);
			error_log("UUID Exists Debug - UUID: " . $uuid);
		}
		
		// 如果失败，追加目标分机的联系信息与用户状态帮助定位（临时调试）
		if (stripos($switch_result, '-ERR') !== false) {
			$domain_name = $_SESSION['domain_name'];
			$contact_info = @event_socket::api('sofia_contact ' . $destination . '@' . $domain_name);
			$user_status = @event_socket::api('sofia status user ' . $destination . '@' . $domain_name);
			echo "\nCONTACT: " . trim((string)$contact_info);
			echo "\nUSER_STATUS: " . trim((string)$user_status);
			echo "\nCMD: " . $api_cmd;
			
			// 添加错误日志
			error_log("Emergency Call Failed - Destination: " . $destination . ", Error: " . $switch_result);
			error_log("Emergency Call Failed - Contact: " . trim((string)$contact_info));
			error_log("Emergency Call Failed - Status: " . trim((string)$user_status));
		}

		/*
			//record stop
			if ($action == "record") {
				if (trim($_GET["action2"]) == "stop") {
					$x=0;
					while (true) {
						if ($x > 0) {
							$dest_file = $_SESSION['switch']['recordings']['dir']."/archive/".date("Y")."/".date("M")."/".date("d")."/".$_GET["uuid"]."_".$x.".wav";
						}
						else {
							$dest_file = $_SESSION['switch']['recordings']['dir']."/archive/".date("Y")."/".date("M")."/".date("d")."/".$_GET["uuid"].".wav";
						}
						if (!file_exists($dest_file)) {
							rename($_SESSION['switch']['recordings']['dir']."/archive/".date("Y")."/".date("M")."/".date("d")."/".$_GET["uuid"].".wav", $dest_file);
							break;
						}
						$x++;
					}
				}
			}
			*/
		}

?>