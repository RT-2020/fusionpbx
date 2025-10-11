<?php
/*
 * WSS 连接诊断工具
 * 帮助诊断 WebSocket Secure 连接问题
 */

// 禁用错误显示，避免干扰 JSON 输出
error_reporting(E_ALL);
ini_set('display_errors', 0);

header('Content-Type: application/json; charset=utf-8');

$diagnostics = [
    'timestamp' => date('Y-m-d H:i:s'),
    'checks' => [],
    'recommendations' => [],
    'status' => 'unknown'
];

// 1. 检查服务器协议
$diagnostics['checks']['server_protocol'] = [
    'name' => '服务器访问协议',
    'value' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'HTTPS' : 'HTTP',
    'status' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'warning' : 'ok',
    'message' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' 
        ? '使用 HTTPS 访问，必须使用 WSS 协议' 
        : '使用 HTTP 访问，可以使用 WS 协议（不推荐生产环境）'
];

// 2. 检查 FreeSWITCH WSS 端口连接
$host = $_SERVER['SERVER_ADDR'] ?? '127.0.0.1';
$wss_port = 7443;
$ws_port = 5066;

// 检查 WSS 端口
$wss_socket = @fsockopen($host, $wss_port, $errno, $errstr, 2);
$diagnostics['checks']['wss_port'] = [
    'name' => 'WSS 端口 (7443)',
    'value' => $wss_socket ? '开放' : '关闭',
    'status' => $wss_socket ? 'ok' : 'error',
    'message' => $wss_socket 
        ? "端口 $wss_port 可访问" 
        : "端口 $wss_port 无法访问: $errstr (错误代码: $errno)"
];
if ($wss_socket) {
    fclose($wss_socket);
}

// 检查 WS 端口
$ws_socket = @fsockopen($host, $ws_port, $errno, $errstr, 2);
$diagnostics['checks']['ws_port'] = [
    'name' => 'WS 端口 (5066)',
    'value' => $ws_socket ? '开放' : '关闭',
    'status' => $ws_socket ? 'ok' : 'warning',
    'message' => $ws_socket 
        ? "端口 $ws_port 可访问（仅用于 HTTP 访问）" 
        : "端口 $ws_port 无法访问"
];
if ($ws_socket) {
    fclose($ws_socket);
}

// 3. 检查 SSL/TLS 证书
$cert_paths = [
    '/etc/freeswitch/tls/wss.pem',
    '/etc/freeswitch/tls/agent.pem',
    '/usr/local/freeswitch/certs/wss.pem'
];

$cert_found = false;
$cert_path = '';
foreach ($cert_paths as $path) {
    if (file_exists($path)) {
        $cert_found = true;
        $cert_path = $path;
        break;
    }
}

$diagnostics['checks']['ssl_certificate'] = [
    'name' => 'SSL/TLS 证书',
    'value' => $cert_found ? $cert_path : '未找到',
    'status' => $cert_found ? 'ok' : 'error',
    'message' => $cert_found 
        ? "找到证书: $cert_path" 
        : '未找到 SSL 证书，WSS 连接将失败'
];

// 4. 检查 PHP 扩展
$required_extensions = ['openssl', 'sockets'];
$missing_extensions = [];

foreach ($required_extensions as $ext) {
    if (!extension_loaded($ext)) {
        $missing_extensions[] = $ext;
    }
}

$diagnostics['checks']['php_extensions'] = [
    'name' => 'PHP 扩展',
    'value' => empty($missing_extensions) ? '完整' : '缺失: ' . implode(', ', $missing_extensions),
    'status' => empty($missing_extensions) ? 'ok' : 'warning',
    'message' => empty($missing_extensions) 
        ? '所有必需的 PHP 扩展已安装' 
        : '缺少扩展: ' . implode(', ', $missing_extensions)
];

// 5. 检查 FreeSWITCH 进程
$fs_running = false;
$fs_check_commands = [
    'ps aux | grep -i "[f]reeswitch"',
    'systemctl status freeswitch 2>&1',
    'service freeswitch status 2>&1'
];

foreach ($fs_check_commands as $cmd) {
    $output = @shell_exec($cmd);
    if ($output && stripos($output, 'freeswitch') !== false) {
        $fs_running = true;
        break;
    }
}

$diagnostics['checks']['freeswitch_process'] = [
    'name' => 'FreeSWITCH 进程',
    'value' => $fs_running ? '运行中' : '未运行',
    'status' => $fs_running ? 'ok' : 'error',
    'message' => $fs_running 
        ? 'FreeSWITCH 服务正在运行' 
        : 'FreeSWITCH 服务未运行或无法检测'
];

// 6. 检查 Sofia Profile 配置
$sofia_status = @shell_exec('fs_cli -x "sofia status" 2>&1');
$wss_configured = false;

if ($sofia_status) {
    if (stripos($sofia_status, 'wss') !== false || stripos($sofia_status, '7443') !== false) {
        $wss_configured = true;
    }
}

$diagnostics['checks']['sofia_wss_config'] = [
    'name' => 'Sofia WSS 配置',
    'value' => $wss_configured ? '已配置' : '未配置',
    'status' => $wss_configured ? 'ok' : 'error',
    'message' => $wss_configured 
        ? 'Sofia Profile 已启用 WSS' 
        : 'Sofia Profile 未配置 WSS 绑定'
];

// 生成建议
$using_https = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';

if ($using_https && !$wss_socket) {
    $diagnostics['recommendations'][] = [
        'priority' => 'high',
        'title' => '启用 FreeSWITCH WSS',
        'message' => 'HTTPS 页面必须使用 WSS 连接。请配置 FreeSWITCH 的 wss-binding 参数。',
        'action' => '编辑 SIP Profile，添加: <param name="wss-binding" value=":7443"/>'
    ];
}

if (!$cert_found) {
    $diagnostics['recommendations'][] = [
        'priority' => 'high',
        'title' => '安装 SSL 证书',
        'message' => 'WSS 需要 SSL/TLS 证书。',
        'action' => '生成自签名证书: openssl req -x509 -newkey rsa:4096 -keyout /etc/freeswitch/tls/wss.pem -out /etc/freeswitch/tls/wss.pem -days 365 -nodes'
    ];
}

if (!$wss_configured) {
    $diagnostics['recommendations'][] = [
        'priority' => 'high',
        'title' => '配置 Sofia WSS',
        'message' => 'Sofia Profile 需要启用 WSS 支持。',
        'action' => '在 FusionPBX: Advanced → SIP Profiles → internal，添加 wss-binding 参数'
    ];
}

if (!$fs_running) {
    $diagnostics['recommendations'][] = [
        'priority' => 'critical',
        'title' => '启动 FreeSWITCH',
        'message' => 'FreeSWITCH 服务未运行。',
        'action' => '执行: systemctl start freeswitch 或 service freeswitch start'
    ];
}

if (!empty($missing_extensions)) {
    $diagnostics['recommendations'][] = [
        'priority' => 'medium',
        'title' => '安装 PHP 扩展',
        'message' => '缺少必需的 PHP 扩展。',
        'action' => '安装: apt-get install php-' . implode(' php-', $missing_extensions) . ' 或 yum install php-' . implode(' php-', $missing_extensions)
    ];
}

// 生成 WebSocket URL 建议
$ws_url_suggestion = '';
if ($using_https) {
    if ($wss_socket) {
        $ws_url_suggestion = "wss://{$host}:7443";
        $diagnostics['status'] = 'ok';
    } else {
        $ws_url_suggestion = "需要先配置 WSS";
        $diagnostics['status'] = 'error';
    }
} else {
    if ($ws_socket) {
        $ws_url_suggestion = "ws://{$host}:5066";
        $diagnostics['status'] = 'ok';
    } else {
        $ws_url_suggestion = "需要先启动 FreeSWITCH";
        $diagnostics['status'] = 'error';
    }
}

$diagnostics['websocket_url'] = $ws_url_suggestion;

// 获取当前配置
$config_file = __DIR__ . '/config.json';
if (file_exists($config_file)) {
    $current_config = json_decode(file_get_contents($config_file), true);
    $diagnostics['current_config'] = $current_config;
}

// 设置总体状态
$error_count = 0;
$warning_count = 0;

foreach ($diagnostics['checks'] as $check) {
    if ($check['status'] === 'error') {
        $error_count++;
    } elseif ($check['status'] === 'warning') {
        $warning_count++;
    }
}

if ($error_count > 0) {
    $diagnostics['status'] = 'error';
    $diagnostics['summary'] = "发现 $error_count 个错误，$warning_count 个警告";
} elseif ($warning_count > 0) {
    $diagnostics['status'] = 'warning';
    $diagnostics['summary'] = "发现 $warning_count 个警告";
} else {
    $diagnostics['status'] = 'ok';
    $diagnostics['summary'] = '所有检查通过';
}

echo json_encode($diagnostics, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

