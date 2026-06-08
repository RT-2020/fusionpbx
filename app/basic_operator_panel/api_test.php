<?php
/*
	FusionPBX
	调度终端 API 测试页面
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

?>
<!DOCTYPE html>
<html>
<head>
	<meta charset="UTF-8">
	<title>调度终端 API 测试</title>
	<style>
		body {
			font-family: Arial, sans-serif;
			padding: 20px;
			background: #f5f5f5;
		}
		.container {
			max-width: 1000px;
			margin: 0 auto;
			background: white;
			padding: 30px;
			border-radius: 8px;
			box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
		}
		h1 {
			color: #333;
			margin-bottom: 30px;
		}
		.api-section {
			margin-bottom: 30px;
			padding: 20px;
			border: 1px solid #e0e0e0;
			border-radius: 6px;
		}
		.api-section h2 {
			margin: 0 0 16px 0;
			color: #2196F3;
			font-size: 18px;
		}
		button {
			padding: 10px 20px;
			background: #4CAF50;
			color: white;
			border: none;
			border-radius: 4px;
			cursor: pointer;
			font-size: 14px;
			margin-right: 10px;
		}
		button:hover {
			background: #45a049;
		}
		.result {
			margin-top: 12px;
			padding: 12px;
			background: #f9f9f9;
			border-left: 4px solid #4CAF50;
			border-radius: 4px;
			font-family: monospace;
			font-size: 13px;
			white-space: pre-wrap;
			max-height: 400px;
			overflow-y: auto;
		}
		.result.error {
			border-left-color: #f44336;
			background: #ffebee;
			color: #c62828;
		}
	</style>
</head>
<body>

<div class="container">
	<h1>📡 调度终端 API 测试</h1>
	
	<!-- 获取分机列表 -->
	<div class="api-section">
		<h2>1. 获取分机列表</h2>
		<p>测试获取所有启用的分机</p>
		<button onclick="testGetExtensions()">测试</button>
		<div id="result-extensions" class="result" style="display: none;"></div>
	</div>

	<!-- 获取呼叫分组 -->
	<div class="api-section">
		<h2>2. 获取呼叫分组</h2>
		<p>测试从数据库获取 call_group 配置</p>
		<button onclick="testGetCallGroups()">测试</button>
		<div id="result-groups" class="result" style="display: none;"></div>
	</div>

	<!-- 获取 SIP 配置 -->
	<div class="api-section">
		<h2>3. 获取 SIP 配置</h2>
		<p>测试获取调度员的默认 SIP 配置</p>
		<button onclick="testGetSipConfig()">测试</button>
		<div id="result-sip-config" class="result" style="display: none;"></div>
	</div>

	<!-- 获取活动通话 -->
	<div class="api-section">
		<h2>4. 获取活动通话</h2>
		<p>测试获取当前所有活动通话</p>
		<button onclick="testGetActiveCalls()">测试</button>
		<div id="result-active-calls" class="result" style="display: none;"></div>
	</div>

	<!-- 保存通话日志 -->
	<div class="api-section">
		<h2>5. 保存通话日志</h2>
		<p>测试保存通话日志到服务器</p>
		<button onclick="testSaveLog()">测试</button>
		<div id="result-save-log" class="result" style="display: none;"></div>
	</div>

	<!-- 系统信息 -->
	<div class="api-section">
		<h2>系统信息</h2>
		<p><strong>Domain UUID:</strong> <?php echo $_SESSION['domain_uuid'] ?? 'N/A'; ?></p>
		<p><strong>Domain Name:</strong> <?php echo $_SESSION['domain_name'] ?? 'N/A'; ?></p>
		<p><strong>User:</strong> <?php echo $_SESSION['user']['username'] ?? 'N/A'; ?></p>
		<p><strong>Extension:</strong> <?php echo $_SESSION['user']['extension'][0]['user'] ?? 'N/A'; ?></p>
	</div>
</div>

<script src="<?php echo PROJECT_PATH; ?>/resources/jquery/jquery-3.4.1.min.js"></script>
<script>
function showResult(id, data, isError) {
	var el = $('#' + id);
	el.removeClass('error');
	if (isError) {
		el.addClass('error');
	}
	el.text(JSON.stringify(data, null, 2)).show();
}

function testGetExtensions() {
	$.ajax({
		url: 'dispatcher_api.php?action=get_extensions',
		type: 'GET',
		dataType: 'json',
		success: function(response) {
			showResult('result-extensions', response, false);
		},
		error: function(xhr, status, error) {
			showResult('result-extensions', {error: error, status: xhr.status}, true);
		}
	});
}

function testGetCallGroups() {
	$.ajax({
		url: 'dispatcher_api.php?action=get_call_groups',
		type: 'GET',
		dataType: 'json',
		success: function(response) {
			showResult('result-groups', response, false);
		},
		error: function(xhr, status, error) {
			showResult('result-groups', {error: error, status: xhr.status}, true);
		}
	});
}

function testGetSipConfig() {
	$.ajax({
		url: 'dispatcher_api.php?action=get_sip_config',
		type: 'GET',
		dataType: 'json',
		success: function(response) {
			showResult('result-sip-config', response, false);
		},
		error: function(xhr, status, error) {
			showResult('result-sip-config', {error: error, status: xhr.status}, true);
		}
	});
}

function testGetActiveCalls() {
	$.ajax({
		url: 'dispatcher_api.php?action=get_active_calls',
		type: 'GET',
		dataType: 'json',
		success: function(response) {
			showResult('result-active-calls', response, false);
		},
		error: function(xhr, status, error) {
			showResult('result-active-calls', {error: error, status: xhr.status}, true);
		}
	});
}

function testSaveLog() {
	$.ajax({
		url: 'dispatcher_api.php?action=save_call_log',
		type: 'POST',
		data: {
			log_type: 'test',
			participants: '["1001", "1002", "1003"]',
			duration: 120,
			start_time: new Date().toISOString(),
			end_time: new Date().toISOString()
		},
		dataType: 'json',
		success: function(response) {
			showResult('result-save-log', response, false);
		},
		error: function(xhr, status, error) {
			showResult('result-save-log', {error: error, status: xhr.status}, true);
		}
	});
}
</script>

</body>
</html>

<?php
//include the footer
require_once "resources/footer.php";
?>

