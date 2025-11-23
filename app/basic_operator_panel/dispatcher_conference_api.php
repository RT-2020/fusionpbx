<?php
/*
	FusionPBX
	Version: MPL 1.1

	The contents of this file are subject to the Mozilla Public License Version
	1.1 (the "License"); you may not use this file except in compliance with
	the License. You may obtain a copy of the License at
	http://www.mozilla.org/MPL/

	Software distributed under the License is distributed on an "AS IS" basis,
	WITHOUT WARRANTY OF ANY KIND, either express or implied. See the License
	for the specific language governing rights and limitations under the
	License.

	The Original Code is FusionPBX

	The Initial Developer of the Original Code is
	Mark J Crane <markjcrane@fusionpbx.com>
	Copyright (C) 2024
	All Rights Reserved.
*/

// 启用错误报告
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// 捕获所有错误并返回JSON
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    if (!(error_reporting() & $errno)) {
        return false;
    }
    if (!headers_sent()) {
        header('Content-Type: application/json');
    }
    echo json_encode([
        'success' => false,
        'error' => 'PHP Error',
        'message' => $errstr,
        'file' => basename($errfile),
        'line' => $errline
    ]);
    exit;
});

// 捕获致命错误
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (!headers_sent()) {
            header('Content-Type: application/json');
        }
        echo json_encode([
            'success' => false,
            'error' => 'Fatal Error',
            'message' => $error['message'],
            'file' => basename($error['file']),
            'line' => $error['line']
        ]);
    }
});

try {
    // 引入必要文件 - 使用正确的FusionPBX引入方式
    require_once dirname(__DIR__, 2) . "/resources/require.php";
    require_once "resources/check_auth.php";
    
    // 设置响应头
    header('Content-Type: application/json');
    
    // 检查权限
    if (!permission_exists('operator_panel_view')) {
        echo json_encode(['success' => false, 'error' => 'Permission denied']);
        exit;
    }
    
    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    $domain_uuid = $_SESSION['domain_uuid'] ?? null;
    
    if (!$domain_uuid) {
        echo json_encode(['success' => false, 'error' => 'No domain_uuid in session']);
        exit;
    }
    
    switch($action) {
        case 'create_conference':
            // 创建会议室并返回会议号
            try {
                $conference_result = createConferenceRoom($domain_uuid);
                if ($conference_result) {
                    echo json_encode(['success' => true, 'conference_room' => $conference_result['conference_room'], 'context' => $conference_result['context']]);
                } else {
                    echo json_encode(['success' => false, 'error' => 'Failed to create conference room - check error logs for details']);
                }
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'error' => 'Exception: ' . $e->getMessage()]);
            }
            break;
            
        case 'dispatcher_join_conference':
            // 调度员加入会议（自动接听）
            $conference_room = $_POST['conference_room'] ?? '';
            $extension = $_POST['extension'] ?? '';
            
            if (empty($conference_room) || empty($extension)) {
                echo json_encode(['success' => false, 'error' => 'Missing parameters']);
                exit;
            }
            
            $result = dispatcherJoinConference($conference_room, $extension, $domain_uuid);
            echo json_encode(['success' => $result, 'extension' => $extension]);
            break;
            
        case 'invite_to_conference':
            // 邀请用户加入会议
            $conference_room = $_POST['conference_room'] ?? '';
            $extension = $_POST['extension'] ?? '';
            
            if (empty($conference_room) || empty($extension)) {
                echo json_encode(['success' => false, 'error' => 'Missing parameters']);
                exit;
            }
            
            $result = inviteToConference($conference_room, $extension, $domain_uuid);
            echo json_encode(['success' => $result, 'extension' => $extension]);
            break;
            
        case 'kick_participant':
            // 踢出参与者
            $conference_room = $_POST['conference_room'] ?? '';
            $member_id = $_POST['member_id'] ?? '';
            
            if (empty($conference_room) || empty($member_id)) {
                echo json_encode(['success' => false, 'error' => 'Missing parameters']);
                exit;
            }
            
            $result = kickParticipant($conference_room, $member_id);
            echo json_encode(['success' => $result]);
            break;
            
        case 'mute_participant':
            // 静音参与者
            $conference_room = $_POST['conference_room'] ?? '';
            $member_id = $_POST['member_id'] ?? '';
            
            if (empty($conference_room) || empty($member_id)) {
                echo json_encode(['success' => false, 'error' => 'Missing parameters']);
                exit;
            }
            
            $result = muteParticipant($conference_room, $member_id);
            echo json_encode(['success' => $result]);
            break;
            
        case 'unmute_participant':
            // 取消静音
            $conference_room = $_POST['conference_room'] ?? '';
            $member_id = $_POST['member_id'] ?? '';
            
            if (empty($conference_room) || empty($member_id)) {
                echo json_encode(['success' => false, 'error' => 'Missing parameters']);
                exit;
            }
            
            $result = unmuteParticipant($conference_room, $member_id);
            echo json_encode(['success' => $result]);
            break;
            
        case 'get_conference_members':
            // 获取会议成员列表
            $conference_room = $_GET['conference_room'] ?? '';
            
            if (empty($conference_room)) {
                echo json_encode(['success' => false, 'error' => 'Missing conference_room']);
                exit;
            }
            
            $members = getConferenceMembers($conference_room);
            echo json_encode(['success' => true, 'members' => $members]);
            break;
            
        case 'check_dialplan':
            // 检查dialplan是否存在
            $conference_room = $_GET['conference_room'] ?? '';
            $context = $_GET['context'] ?? 'default';
            
            if (empty($conference_room)) {
                echo json_encode(['success' => false, 'error' => 'Missing conference_room']);
                exit;
            }
            
            $esl = event_socket::create();
            if ($esl && $esl->is_connected()) {
                $cmd = "api dialplan_exists $conference_room $context";
                $response = $esl->request($cmd);
                echo json_encode([
                    'success' => true, 
                    'conference_room' => $conference_room,
                    'context' => $context,
                    'exists' => strpos($response, 'true') !== false,
                    'response' => $response
                ]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Failed to connect to FreeSWITCH']);
            }
            break;
            
        case 'end_conference':
            // 结束会议
            $conference_room = $_POST['conference_room'] ?? '';
            
            if (empty($conference_room)) {
                echo json_encode(['success' => false, 'error' => 'Missing conference_room']);
                exit;
            }
            
            $result = endConference($conference_room);
            echo json_encode(['success' => $result]);
            break;
            
        case 'test_conference':
            // 测试会议功能
            $esl = event_socket::create();
            if ($esl && $esl->is_connected()) {
                $response = $esl->request("api conference list");
                echo json_encode([
                    'success' => true,
                    'connected' => true,
                    'conferences' => $response
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'connected' => false,
                    'error' => 'Cannot connect to FreeSWITCH'
                ]);
            }
            break;
            
        default:
            echo json_encode(['success' => false, 'error' => 'Unknown action: ' . $action]);
            break;
    }
    
} catch (Exception $e) {
    if (!headers_sent()) {
        header('Content-Type: application/json');
    }
    echo json_encode([
        'success' => false,
        'error' => 'Exception',
        'message' => $e->getMessage(),
        'file' => basename($e->getFile()),
        'line' => $e->getLine()
    ]);
}

// ============ 函数定义区域 ============

// 创建会议室（简化版：不创建 dialplan，使用 inline 方式）
function createConferenceRoom($domain_uuid) {
    // 生成唯一的会议室号（使用时间戳+随机数，类似插入讲话）
    $conference_room = 'group-' . time() . '-' . substr(md5(uniqid()), 0, 6);
    
    // 获取域名
    $sql = "SELECT domain_name FROM v_domains WHERE domain_uuid = :domain_uuid";
    $parameters['domain_uuid'] = $domain_uuid;
    $database = new database;
    
    // 检查数据库连接
    if (!$database->db) {
        error_log("Database connection failed in createConferenceRoom");
        return false;
    }
    
    $domain_name = $database->select($sql, $parameters, 'column');
    
    if (empty($domain_name)) {
        error_log("Failed to get domain_name for uuid: $domain_uuid");
        return false;
    }
    
    // 使用 default context
    $context = 'default';
    
    // 记录会议室创建（不再创建 dialplan）
    error_log("Created conference room (inline mode): $conference_room for domain: $domain_name");
    
    // *** 关键修改：不再创建 dialplan，直接返回会议室号 ***
    // 使用 inline 方式，像插入讲话一样直接加入会议室
    // 不需要 reloadxml，不需要等待，即时生效！
    
    // 直接返回会议号和context
    return [
        'conference_room' => $conference_room,
        'context' => $context
    ];
}

// 邀请用户加入会议（通过FreeSWITCH originate命令）
// *** 关键修复：移除mute标志，设置Caller ID ***
// 调度员加入会议（带自动接听）
function dispatcherJoinConference($conference_room, $extension, $domain_uuid) {
    // 获取域名
    $sql = "SELECT domain_name FROM v_domains WHERE domain_uuid = :domain_uuid";
    $parameters['domain_uuid'] = $domain_uuid;
    $database = new database;
    
    // 检查数据库连接
    if (!$database->db) {
        error_log("Database connection failed in dispatcherJoinConference");
        return false;
    }
    
    $domain_name = $database->select($sql, $parameters, 'column');
    
    if (empty($domain_name)) {
        error_log("Failed to get domain_name for uuid: $domain_uuid");
        return false;
    }
    
    // 构造呼叫字符串 - 使用 inline 方式（类似插入讲话）
    $caller_id_name = "组呼会议";
    $caller_id_number = "group-call";
    
    $dial_string = "{";
    $dial_string .= "origination_caller_id_name='".$caller_id_name."',";
    $dial_string .= "origination_caller_id_number=".$caller_id_number.",";
    $dial_string .= "sip_auto_answer=true,";  // 自动接听
    $dial_string .= "hangup_after_bridge=false";
    $dial_string .= "}user/$extension@$domain_name";
    
    // *** 关键：使用 inline 方式直接加入会议室，不依赖 dialplan ***
    $app = "&conference($conference_room@default)";
    
    // 记录
    error_log("Dispatcher joining conference (inline mode) - Room: $conference_room, Extension: $extension");
    
    // 使用 event_socket 发送 originate 命令
    $cmd = "originate $dial_string $app";
    $response = event_socket::api($cmd);
    
    error_log("=== Dispatcher Join Conference Debug ===");
    error_log("Extension: $extension");
    error_log("Conference Room: $conference_room");
    error_log("Command: $cmd");
    error_log("Response Type: " . gettype($response));
    error_log("Response: " . print_r($response, true));
    error_log("========================================");
    
    // 修复：处理数组响应
    if (is_array($response)) {
        $response_str = isset($response['body']) ? $response['body'] : json_encode($response);
    } else {
        $response_str = (string)$response;
    }
    
    return (strpos($response_str, '+OK') !== false || 
            strpos($response_str, 'SUCCESS') !== false ||
            strpos($response_str, 'QUEUED') !== false ||
            strpos($response_str, '-ERR') === false);
}

function inviteToConference($conference_room, $extension, $domain_uuid) {
    // 获取域名
    $sql = "SELECT domain_name FROM v_domains WHERE domain_uuid = :domain_uuid";
    $parameters['domain_uuid'] = $domain_uuid;
    $database = new database;
    
    // 检查数据库连接
    if (!$database->db) {
        error_log("Database connection failed in inviteToConference");
        return false;
    }
    
    $domain_name = $database->select($sql, $parameters, 'column');
    
    if (empty($domain_name)) {
        error_log("Failed to get domain_name for uuid: $domain_uuid");
        return false;
    }
    
    // 构造呼叫字符串 - 使用 inline 方式（类似插入讲话）
    $caller_id_name = "调度中心";
    $caller_id_number = "dispatch";
    
    $dial_string = "{";
    $dial_string .= "origination_caller_id_name='".$caller_id_name."',";
    $dial_string .= "origination_caller_id_number=".$caller_id_number.",";
    $dial_string .= "hangup_after_bridge=false";
    $dial_string .= "}user/$extension@$domain_name";
    
    // *** 关键：使用 inline 方式直接加入会议室，不依赖 dialplan ***
    $app = "&conference($conference_room@default)";
    
    // 记录
    error_log("Inviting extension to conference (inline mode) - Room: $conference_room, Extension: $extension");
    
    // 使用 event_socket 发送 originate 命令（使用bgapi非阻塞）
    $cmd = "bgapi originate $dial_string $app";
    $response = event_socket::api($cmd);
    
    error_log("=== Invite to Conference Debug ===");
    error_log("Extension: $extension");
    error_log("Conference Room: $conference_room");
    error_log("Command: $cmd");
    error_log("Response Type: " . gettype($response));
    error_log("Response: " . print_r($response, true));
    error_log("==================================");
    
    // 修复：处理数组响应
    if (is_array($response)) {
        $response_str = isset($response['body']) ? $response['body'] : json_encode($response);
    } else {
        $response_str = (string)$response;
    }
    
    return (strpos($response_str, '+OK') !== false || 
            strpos($response_str, 'SUCCESS') !== false ||
            strpos($response_str, 'QUEUED') !== false ||
            strpos($response_str, '-ERR') === false);
}

// 踢出参与者
function kickParticipant($conference_room, $member_id) {
    $esl = event_socket::create();
    if (!$esl || !$esl->is_connected()) {
        error_log("Failed to connect to event socket for kick");
        return false;
    }
    
    $cmd = "api conference $conference_room kick $member_id";
    $response = $esl->request($cmd);
    
    error_log("Kick command: $cmd, response: $response");
    
    return (strpos($response, '+OK') !== false || strpos($response, 'OK') !== false);
}

// 静音参与者
function muteParticipant($conference_room, $member_id) {
    $esl = event_socket::create();
    if (!$esl || !$esl->is_connected()) {
        error_log("Failed to connect to event socket for mute");
        return false;
    }
    
    $cmd = "api conference $conference_room mute $member_id";
    $response = $esl->request($cmd);
    
    return (strpos($response, '+OK') !== false || strpos($response, 'OK') !== false);
}

// 取消静音
function unmuteParticipant($conference_room, $member_id) {
    $esl = event_socket::create();
    if (!$esl || !$esl->is_connected()) {
        error_log("Failed to connect to event socket for unmute");
        return false;
    }
    
    $cmd = "api conference $conference_room unmute $member_id";
    $response = $esl->request($cmd);
    
    return (strpos($response, '+OK') !== false || strpos($response, 'OK') !== false);
}

// 获取会议成员
function getConferenceMembers($conference_room) {
    $esl = event_socket::create();
    if (!$esl || !$esl->is_connected()) {
        error_log("Failed to connect to event socket for get members");
        return [];
    }
    
    $cmd = "api conference $conference_room xml_list";
    $response = $esl->request($cmd);
    
    // 添加调试日志
    error_log("Get conference members - Room: $conference_room");
    error_log("FreeSWITCH response length: " . strlen($response));
    
    // 解析XML响应
    $members = parseConferenceMembersXML($response);
    error_log("Parsed members count: " . count($members));
    
    return $members;
}

// 解析会议成员XML
function parseConferenceMembersXML($xml_string) {
    $members = [];
    
    // 移除 Content-Type 头信息
    $xml_string = preg_replace('/^Content-Type:.*?\n\n/s', '', $xml_string);
    
    if (strpos($xml_string, '<conference') !== false) {
        try {
            $xml = @simplexml_load_string($xml_string);
            if ($xml && isset($xml->conference->members->member)) {
                foreach ($xml->conference->members->member as $member) {
                    $flags_str = '';
                    if (isset($member->flags)) {
                        if (isset($member->flags->flag)) {
                            foreach ($member->flags->flag as $flag) {
                                $flags_str .= (string)$flag . ',';
                            }
                        }
                    }
                    
                    // 尝试获取更准确的分机号
                    // 优先使用 caller_id_number
                    $number = (string)$member->caller_id_number;
                    
                    // 如果 caller_id_number 无效或为 generic 值，尝试使用 username (如果存在)
                    // 注意：XML结构中可能不直接包含 username，但通常会有
                    if ((empty($number) || $number == '0000000000' || $number == 'anonymous') && isset($member->username)) {
                        $number = (string)$member->username;
                    }
                    
                    $members[] = [
                        'id' => (string)$member->id,
                        'uuid' => (string)$member->uuid,
                        'caller_id_number' => $number,
                        'caller_id_name' => (string)$member->caller_id_name,
                        'flags' => $flags_str,
                        'muted' => (strpos($flags_str, 'can_speak') === false),
                        'can_hear' => (strpos($flags_str, 'can_hear') !== false)
                    ];
                }
            }
        } catch (Exception $e) {
            // XML解析失败，返回空数组
            error_log('Failed to parse conference XML: ' . $e->getMessage());
        }
    }
    return $members;
}

// 结束会议
function endConference($conference_room) {
    // 结束会议
    $esl = event_socket::create();
    if ($esl && $esl->is_connected()) {
        $cmd = "api conference $conference_room hup all";
        $response = $esl->request($cmd);
        
        error_log("End conference command: $cmd, response: $response");
    }
    
    // 删除临时dialplan
    $sql = "DELETE FROM v_dialplans WHERE dialplan_number = :conference_room AND dialplan_description LIKE '%临时调度会议%'";
    $parameters['conference_room'] = $conference_room;
    $database = new database;
    $database->execute($sql, $parameters);
    
    error_log("Deleted dialplan for conference room: $conference_room");
    
    // 重载dialplan
    if ($esl && $esl->is_connected()) {
        $esl->request("api reloadxml");
        error_log("Reloaded dialplan after deleting conference room: $conference_room");
    }
    
    return true;
}
