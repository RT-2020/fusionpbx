<?php
/*
	FusionPBX
	调度终端功能测试页面
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

//add multi-lingual support
$language = new text;
$text = $language->get();

//set the title
$document['title'] = '调度终端功能测试';

//include the header
require_once "resources/header.php";

?>

<!DOCTYPE html>
<html>
<head>
	<meta charset="UTF-8">
	<title>调度终端功能测试</title>
	<link rel="stylesheet" type="text/css" href="<?php echo PROJECT_PATH; ?>/resources/jquery/jquery-ui.min.css">
	<link rel="stylesheet" type="text/css" href="<?php echo PROJECT_PATH; ?>/app/basic_operator_panel/resources/dispatcher.css">
	<style>
		body {
			font-family: Arial, sans-serif;
			padding: 20px;
			background: #f5f5f5;
		}
		.test-container {
			max-width: 1200px;
			margin: 0 auto;
			background: white;
			padding: 30px;
			border-radius: 8px;
			box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
		}
		.test-section {
			margin-bottom: 30px;
			padding: 20px;
			border: 1px solid #e0e0e0;
			border-radius: 6px;
		}
		.test-section h2 {
			margin: 0 0 16px 0;
			color: #333;
			font-size: 20px;
		}
		.test-section h3 {
			margin: 16px 0 12px 0;
			color: #666;
			font-size: 16px;
		}
		.test-btn {
			padding: 10px 20px;
			background: #4CAF50;
			color: white;
			border: none;
			border-radius: 4px;
			cursor: pointer;
			margin-right: 10px;
			margin-bottom: 10px;
			font-size: 14px;
		}
		.test-btn:hover {
			background: #45a049;
		}
		.test-btn.secondary {
			background: #2196F3;
		}
		.test-btn.secondary:hover {
			background: #1976D2;
		}
		.test-btn.danger {
			background: #f44336;
		}
		.test-btn.danger:hover {
			background: #d32f2f;
		}
		.test-result {
			margin-top: 12px;
			padding: 12px;
			background: #f9f9f9;
			border-left: 4px solid #4CAF50;
			border-radius: 4px;
			font-size: 13px;
		}
		.test-result.error {
			border-left-color: #f44336;
			background: #ffebee;
		}
		.test-input {
			padding: 8px 12px;
			border: 1px solid #ddd;
			border-radius: 4px;
			font-size: 14px;
			margin-right: 10px;
			width: 200px;
		}
		#test-log {
			height: 200px;
			overflow-y: auto;
			background: #1e1e1e;
			color: #0f0;
			padding: 12px;
			border-radius: 4px;
			font-family: 'Courier New', monospace;
			font-size: 12px;
			margin-top: 12px;
		}
	</style>
</head>
<body>

<div class="test-container">
	<h1>📡 调度终端功能测试</h1>
	<p>本页面用于测试调度终端的各项功能</p>

	<!-- SIP 注册测试 -->
	<div class="test-section">
		<h2>1. SIP 注册测试</h2>
		<div>
			<input type="text" class="test-input" id="test-uri" placeholder="SIP URI" value="sip:5001@192.168.2.200" />
			<input type="text" class="test-input" id="test-ws" placeholder="WebSocket" value="ws://192.168.2.200:5066" />
		</div>
		<div style="margin-top: 10px;">
			<input type="text" class="test-input" id="test-user" placeholder="用户名" value="5001" />
			<input type="password" class="test-input" id="test-pwd" placeholder="密码" value="1234" />
		</div>
		<div style="margin-top: 12px;">
			<button class="test-btn" onclick="testRegister()">注册</button>
			<button class="test-btn secondary" onclick="testUnregister()">注销</button>
			<button class="test-btn secondary" onclick="testDiagnose()">诊断</button>
		</div>
		<div id="register-result" class="test-result" style="display: none;"></div>
	</div>

	<!-- 基本呼叫测试 -->
	<div class="test-section">
		<h2>2. 基本呼叫测试</h2>
		<div>
			<input type="text" class="test-input" id="test-target" placeholder="目标号码" value="1413" />
			<button class="test-btn" onclick="testMakeCall()">拨打</button>
			<button class="test-btn danger" onclick="testHangup()">挂断</button>
		</div>
		<div id="call-result" class="test-result" style="display: none;"></div>
	</div>

	<!-- 组呼测试 -->
	<div class="test-section">
		<h2>3. 组呼测试</h2>
		<div>
			<input type="text" class="test-input" id="test-group-exts" placeholder="分机号(逗号分隔)" value="1001,1002,1003" />
			<button class="test-btn" onclick="testGroupCall()">发起组呼</button>
			<button class="test-btn danger" onclick="testEndGroupCall()">结束组呼</button>
		</div>
		<div id="group-result" class="test-result" style="display: none;"></div>
	</div>

	<!-- 会议测试 -->
	<div class="test-section">
		<h2>4. 多方会议测试</h2>
		<div>
			<input type="text" class="test-input" id="test-conf-exts" placeholder="分机号(逗号分隔)" value="1001,1002" />
			<button class="test-btn" onclick="testConference()">发起会议</button>
			<button class="test-btn danger" onclick="testEndConference()">结束会议</button>
		</div>
		<div id="conf-result" class="test-result" style="display: none;"></div>
	</div>

	<!-- 中继测试 -->
	<div class="test-section">
		<h2>5. 中继汇接测试</h2>
		<h3>人工中继</h3>
		<p>使用外线号码（如 01234567890）拨打进来，测试人工中继功能</p>
		<button class="test-btn" onclick="setTrunkMode('manual')">启用人工中继</button>
		
		<h3>自动中继</h3>
		<p>使用外线号码拨打进来，系统会提示输入目标号码并自动转接</p>
		<button class="test-btn secondary" onclick="testAutoTrunk()">启用自动中继</button>
		<div id="trunk-result" class="test-result" style="display: none;"></div>
	</div>

	<!-- 日志查看 -->
	<div class="test-section">
		<h2>6. 通话日志</h2>
		<button class="test-btn secondary" onclick="showLogsPanel()">查看日志</button>
		<button class="test-btn secondary" onclick="testExportLogs()">导出日志</button>
	</div>

	<!-- 控制台日志 -->
	<div class="test-section">
		<h2>控制台日志</h2>
		<div id="test-log"></div>
		<button class="test-btn secondary" onclick="clearTestLog()" style="margin-top: 8px;">清空</button>
	</div>
</div>

<script language="JavaScript" type="text/javascript" src="<?php echo PROJECT_PATH; ?>/resources/jquery/jquery-3.4.1.min.js"></script>
<script language="JavaScript" type="text/javascript" src="<?php echo PROJECT_PATH; ?>/resources/jquery/jquery-ui.min.js"></script>
<script language="JavaScript" type="text/javascript" src="<?php echo PROJECT_PATH; ?>/app/basic_operator_panel/resources/jssip.min.js"></script>
<script language="JavaScript" type="text/javascript" src="<?php echo PROJECT_PATH; ?>/app/basic_operator_panel/resources/jssip-client.js"></script>
<script language="JavaScript" type="text/javascript" src="<?php echo PROJECT_PATH; ?>/app/basic_operator_panel/resources/dispatcher-control.js"></script>
<script language="JavaScript" type="text/javascript" src="<?php echo PROJECT_PATH; ?>/app/basic_operator_panel/resources/dispatcher-logger.js"></script>

<script>
var testSipClient;
var testDispatcher;

// 初始化
$(document).ready(function() {
	testSipClient = new JsSipClient();
	testDispatcher = new DispatcherControl();
	dispatcherLogger = new DispatcherLogger();
	
	// 重定向 console.log 到页面
	var originalLog = console.log;
	console.log = function() {
		originalLog.apply(console, arguments);
		var msg = Array.prototype.slice.call(arguments).join(' ');
		var time = new Date().toLocaleTimeString();
		$('#test-log').append('<div>' + time + ' | ' + msg + '</div>');
		$('#test-log').scrollTop($('#test-log')[0].scrollHeight);
	};
	
	log('测试页面已加载');
});

function log(msg) {
	console.log(msg);
}

// 测试注册
function testRegister() {
	var config = {
		uri: $('#test-uri').val(),
		wsServers: $('#test-ws').val(),
		authUser: $('#test-user').val(),
		password: $('#test-pwd').val(),
		displayName: '调度员测试'
	};

	log('开始注册...');
	showResult('register-result', '注册中...', false);

	testSipClient.register(config)
		.then(function() {
			log('✅ 注册成功');
			showResult('register-result', '✅ 注册成功', false);
		})
		.catch(function(error) {
			log('❌ 注册失败: ' + error.message);
			showResult('register-result', '❌ 注册失败: ' + error.message, true);
		});
}

// 测试注销
function testUnregister() {
	log('开始注销...');
	testSipClient.unregister()
		.then(function() {
			log('✅ 注销成功');
			showResult('register-result', '✅ 注销成功', false);
		})
		.catch(function(error) {
			log('❌ 注销失败: ' + error.message);
		});
}

// 测试诊断
function testDiagnose() {
	testSipClient.diagnoseConnection();
}

// 测试拨打电话
function testMakeCall() {
	var target = $('#test-target').val();
	if (!target) {
		alert('请输入目标号码');
		return;
	}

	var serverHost = testSipClient.ua ? testSipClient.ua.configuration.uri.host : '192.168.2.200';
	var fullTarget = 'sip:' + target + '@' + serverHost;

	log('拨打电话到: ' + fullTarget);
	showResult('call-result', '拨号中...', false);

	testSipClient.makeCall(fullTarget, { audio: true, video: false })
		.then(function(result) {
			log('✅ 呼叫已发起, Session ID: ' + result.sessionId);
			showResult('call-result', '✅ 呼叫已发起，等待接听...', false);
		})
		.catch(function(error) {
			log('❌ 拨打失败: ' + error.message);
			showResult('call-result', '❌ 拨打失败: ' + error.message, true);
		});
}

// 测试挂断
function testHangup() {
	log('挂断所有通话...');
	testSipClient.hangupAll()
		.then(function() {
			log('✅ 已挂断');
			showResult('call-result', '✅ 已挂断', false);
		});
}

// 测试组呼
function testGroupCall() {
	var exts = $('#test-group-exts').val();
	if (!exts) {
		alert('请输入分机号');
		return;
	}

	var extensions = exts.split(',').map(function(e) { return e.trim(); });
	log('发起组呼，目标: ' + extensions.join(', '));
	showResult('group-result', '发起组呼中...', false);

	// 创建临时组
	testDispatcher.callGroups['test'] = {
		name: '测试组',
		extensions: extensions
	};

	testDispatcher.startGroupCall('test');
	showResult('group-result', '✅ 组呼已发起，共 ' + extensions.length + ' 个目标', false);
}

// 测试结束组呼
function testEndGroupCall() {
	log('结束组呼...');
	testDispatcher.endGroupCall();
	showResult('group-result', '✅ 组呼已结束', false);
}

// 测试会议
function testConference() {
	var exts = $('#test-conf-exts').val();
	if (!exts) {
		alert('请输入分机号');
		return;
	}

	var extensions = exts.split(',').map(function(e) { return e.trim(); });
	if (extensions.length < 2) {
		alert('至少需要2个参与者');
		return;
	}

	log('发起会议，参与者: ' + extensions.join(', '));
	showResult('conf-result', '发起会议中...', false);

	testDispatcher.startConference(extensions);
	showResult('conf-result', '✅ 会议已发起，共 ' + extensions.length + ' 个参与者', false);
}

// 测试结束会议
function testEndConference() {
	log('结束会议...');
	testDispatcher.endConference();
	showResult('conf-result', '✅ 会议已结束', false);
}

// 测试自动中继
function testAutoTrunk() {
	testDispatcher.toggleAutoTrunkMode();
	log('自动中继模式: ' + (testDispatcher.autoTrunkMode ? '已开启' : '已关闭'));
	showResult('trunk-result', '自动中继模式: ' + (testDispatcher.autoTrunkMode ? '已开启' : '已关闭'), false);
}

// 测试导出日志
function testExportLogs() {
	log('导出日志...');
	dispatcherLogger.exportLogs('csv');
}

// 显示结果
function showResult(id, message, isError) {
	var el = $('#' + id);
	el.removeClass('error');
	if (isError) {
		el.addClass('error');
	}
	el.html(message).show();
}

// 清空测试日志
function clearTestLog() {
	$('#test-log').html('');
}
</script>

<!-- 来电提示 -->
<div id="dispatcher-alerts"></div>

<!-- 组呼面板 -->
<div id="dispatcher-group-call-panel"></div>

<!-- 会议面板 -->
<div id="dispatcher-conference-panel"></div>

<!-- 中继面板 -->
<div id="dispatcher-trunk-panel"></div>

<!-- 日志模态框 -->
<div id="dispatcher-logs-modal" style="display: none;"></div>

<!-- 状态指示器 -->
<div id="group-call-indicator">
	<span class="calling-indicator"></span>组呼进行中
</div>
<div id="conference-indicator">
	<span class="calling-indicator"></span>会议进行中
</div>

<script>
// 添加全局函数
function showLogsPanel() {
	if (dispatcherLogger) {
		dispatcherLogger.showLogsPanel();
	}
}

function closeLogsPanel() {
	$('#dispatcher-logs-modal').hide();
	$('.modal-overlay').remove();
}

function setTrunkMode(mode) {
	if (mode === 'manual') {
		testDispatcher.autoTrunkMode = false;
		log('切换到人工中继模式');
		showResult('trunk-result', '✅ 已切换到人工中继模式', false);
	}
}
</script>

</body>
</html>

<?php
//include the footer
require_once "resources/footer.php";
?>

