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
if (count($_GET) > 0) {
	//set the variables
		$switch_cmd = trim($_GET["cmd"] ?? '');
		$action = trim($_GET["action"] ?? '');
		$data = trim($_GET["data"] ?? '');
		$direction = trim($_GET["direction"] ?? '');

	//setup the event socket connection
		$esl = event_socket::create();

	//allow specific commands
		if (!empty($switch_cmd)) {
			$api_cmd = '';
			$uuid_pattern = '/[^-A-Fa-f0-9]/';
			$num_pattern = '/[^-A-Za-z0-9()*#]/';

			if ($switch_cmd == 'originate') {
				$source = preg_replace($num_pattern,'',$_GET['source']);
				$destination = preg_replace($num_pattern,'',$_GET['destination']);
				$api_cmd = 'bgapi originate {sip_auto_answer=true,origination_caller_id_number=' . $source . ',sip_h_Call-Info=_undef_}user/' . $source . '@' . $_SESSION['domain_name'] . ' ' . $destination . ' XML ' . trim($_SESSION['user_context']);
			}
			else if ($switch_cmd == 'uuid_record') {
				$uuid = preg_replace($uuid_pattern,'',$_GET['uuid']);
				$api_cmd = 'uuid_record ' . $uuid . ' start ' . $_SESSION['switch']['recordings']['dir'] . '/' . $_SESSION['domain_name'] . '/archive/' . date('Y/M/d') . '/' . $uuid . '.wav';
			}
			else if ($switch_cmd == 'uuid_transfer') {
				$uuid = preg_replace($uuid_pattern,'',$_GET['uuid']);
				$destination = preg_replace($num_pattern,'',$_GET['destination']);
				$api_cmd = 'uuid_transfer ' . $uuid . ' ' . $destination . ' XML ' . trim($_SESSION['user_context']);
			}
		else if ($switch_cmd == 'uuid_eavesdrop') {
			$chan_uuid = preg_replace($uuid_pattern,'',$_GET['chan_uuid']);
			$ext = preg_replace($num_pattern,'',$_GET['ext']);
			$destination = preg_replace($num_pattern,'',$_GET['destination']);
			$mode = trim($_GET['mode'] ?? 'listen');

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
				$call_id = preg_replace($uuid_pattern,'',$_GET['call_id']);
				$api_cmd = 'uuid_kill ' . $call_id;
			}
			else {
				echo 'access denied';
				return;
			}

		//run the command
		$switch_result = event_socket::api($api_cmd);
		
		// 输出FreeSWITCH返回结果（用于调试）
		echo $switch_result;
		
		// 如果失败，追加目标分机的联系信息与用户状态帮助定位（临时调试）
		if (stripos($switch_result, '-ERR') !== false) {
			$domain_name = $_SESSION['domain_name'];
			$contact_info = @event_socket::api('sofia_contact ' . $destination . '@' . $domain_name);
			$user_status = @event_socket::api('sofia status user ' . $destination . '@' . $domain_name);
			echo "\nCONTACT: " . trim((string)$contact_info);
			echo "\nUSER_STATUS: " . trim((string)$user_status);
			echo "\nCMD: " . $api_cmd;
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
}

?>