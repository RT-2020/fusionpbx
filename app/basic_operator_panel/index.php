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
	Portions created by the Initial Developer are Copyright (C) 2008-2023
	the Initial Developer. All Rights Reserved.

	Contributor(s):
	Mark J Crane <markjcrane@fusionpbx.com>
	Luis Daniel Lucio Quiroz <dlucio@okay.com.mx>
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

//set user status
	if (isset($_REQUEST['status']) && $_REQUEST['status'] != '') {

		//validate the user status
			$user_status = $_REQUEST['status'];
			switch ($user_status) {
				case "Available" :
					break;
				case "Available (On Demand)" :
					break;
				case "On Break" :
					break;
				case "Do Not Disturb" :
					break;
				case "Logged Out" :
					break;
				default :
					$user_status = '';
			}

		//update the status
			if (permission_exists("user_setting_edit")) {
				//add the user_edit permission
				$p = permissions::new();
				$p->add("user_edit", "temp");

				//update the database user_status
				$array['users'][0]['user_uuid'] = $_SESSION['user']['user_uuid'];
				$array['users'][0]['domain_uuid'] = $_SESSION['user']['domain_uuid'];
				$array['users'][0]['user_status'] = $user_status;
				$database = new database;
				$database->app_name = 'operator_panel';
				$database->app_uuid = 'dd3d173a-5d51-4231-ab22-b18c5b712bb2';
				$database->save($array);

				//remove the temporary permission
				$p->delete("user_edit", "temp");

				unset($array);
			}

		//if call center app is installed then update the user_status
			if (is_dir($_SERVER["DOCUMENT_ROOT"].PROJECT_PATH.'/app/call_centers')) {
				//get the call center agent uuid
					$sql = "select call_center_agent_uuid from v_call_center_agents ";
					$sql .= "where domain_uuid = :domain_uuid ";
					$sql .= "and user_uuid = :user_uuid ";
					$parameters['domain_uuid'] = $_SESSION['user']['domain_uuid'];
					$parameters['user_uuid'] = $_SESSION['user']['user_uuid'];
					$database = new database;
					$call_center_agent_uuid = $database->select($sql, $parameters, 'column');
					unset($sql, $parameters);

				//update the user_status
					if (is_uuid($call_center_agent_uuid)) {
						$esl = event_socket::create();
						$switch_cmd = "callcenter_config agent set status ".$call_center_agent_uuid." '".$user_status."'";
						$switch_result = event_socket::api($switch_cmd);
					}

				//update the user state
					if (is_uuid($call_center_agent_uuid)) {
						$cmd = "api callcenter_config agent set state ".$call_center_agent_uuid." Waiting";
						$response = event_socket::api($cmd);
					}

				//update do not disturb
					if ($user_status == "Do Not Disturb") {
						$x = 0;
						foreach ($_SESSION['user']['extension'] as $row) {
							//build the array
							$array['extensions'][$x]['extension_uuid'] = $row['extension_uuid'];
							$array['extensions'][$x]['dial_string'] = '!USER_BUSY';
							$array['extensions'][$x]['do_not_disturb'] = 'true';

							//delete extension from the cache
							$cache = new cache;
							if (!empty($row['extension'])) {
								$cache->delete("directory:".$row['extension']."@".$_SESSION['user']['domain_name']);
							}
							if (!empty($number_alias)) {
								$cache->delete("directory:".$row['number_alias']."@".$_SESSION['user']['domain_name']);
							}

							//incrment
							$x++;
						}
					}
					else {
						$x = 0;
						foreach($_SESSION['user']['extension'] as $row) {
							//build the array
							$array['extensions'][$x]['extension_uuid'] = $row['extension_uuid'];
							$array['extensions'][$x]['dial_string'] = null;
							$array['extensions'][$x]['do_not_disturb'] = 'false';

							//delete extension from the cache
							$cache = new cache;
							if (!empty($row['extension'])) {
								$cache->delete("directory:".$row['extension']."@".$_SESSION['user']['domain_name']);
							}
							if (!empty($number_alias)) {
								$cache->delete("directory:".$row['number_alias']."@".$_SESSION['user']['domain_name']);
							}

							//incrment
							$x++;
						}
					}

				//grant temporary permissions
					$p = permissions::new();
					$p->add('extension_edit', 'temp');

				//execute update
					$database = new database;
					$database->app_name = 'calls';
					$database->app_uuid = '19806921-e8ed-dcff-b325-dd3e5da4959d';
					$database->save($array);
					unset($array);

				//revoke temporary permissions
					$p->delete('extension_edit', 'temp');

				//delete extension from the cache
					$cache = new cache;
					if (!empty($extension)) {
						$cache->delete("directory:".$extension."@".$this->domain_name);
					}
					if (!empty($number_alias)) {
						$cache->delete("directory:".$number_alias."@".$this->domain_name);
					}
			}

		//stop execution
			exit;
	}

//set the title
	$document['title'] = $text['title-operator_panel'];

//include the header
	require_once "resources/header.php";

?>

<!-- virtual_drag function holding elements -->
<input type='hidden' class='formfld' id='vd_call_id' value=''>
<input type='hidden' class='formfld' id='vd_ext_from' value=''>
<input type='hidden' class='formfld' id='vd_ext_to' value=''>
<input type='hidden' class='formfld' id='sort1' value=''>

<!-- autocomplete for contact lookup -->
<link rel="stylesheet" type="text/css" href="<?php echo PROJECT_PATH; ?>/resources/jquery/jquery-ui.min.css">
<link rel="stylesheet" type="text/css" href="<?php echo PROJECT_PATH; ?>/app/basic_operator_panel/resources/dispatcher.css">
<script language="JavaScript" type="text/javascript" src="<?php echo PROJECT_PATH; ?>/resources/jquery/jquery-ui.min.js"></script>
<script language="JavaScript" type="text/javascript" src="<?php echo PROJECT_PATH; ?>/app/basic_operator_panel/resources/jssip.min.js"></script>
<script language="JavaScript" type="text/javascript" src="<?php echo PROJECT_PATH; ?>/app/basic_operator_panel/resources/dispatcher-utils.js"></script>
<script language="JavaScript" type="text/javascript" src="<?php echo PROJECT_PATH; ?>/app/basic_operator_panel/resources/jssip-client.js"></script>
<script language="JavaScript" type="text/javascript" src="<?php echo PROJECT_PATH; ?>/app/basic_operator_panel/resources/dispatcher-control.js"></script>
<script language="JavaScript" type="text/javascript" src="<?php echo PROJECT_PATH; ?>/app/basic_operator_panel/resources/dispatcher-logger.js"></script>
<script language="JavaScript" type="text/javascript" src="<?php echo PROJECT_PATH; ?>/app/basic_operator_panel/resources/emergency-audio.js"></script>
<script type="text/javascript">

<?php
// 从数据库或配置文件中获取 TURN 服务器配置
// 示例配置，请替换为实际的动态获取逻辑
$turn_config_json = json_encode([
    'urls' => 'turn:your-turn-server.com:3478',
    'username' => 'your-username',
    'password' => 'your-password'
]);
?>

window.turnConfig = <?php echo $turn_config_json; ?>;

window.dispatcherEmergencyAudio = { src: '<?php echo PROJECT_PATH; ?>/app/basic_operator_panel/resources/sounds/emergency_alert.mp3' };

<?php
//determine refresh rate
$refresh_default = 1500; //milliseconds
$refresh = is_numeric($_SESSION['operator_panel']['refresh']['numeric']) ? $_SESSION['operator_panel']['refresh']['numeric'] : $refresh_default;
if ($refresh >= 0.5 && $refresh <= 120) { //convert seconds to milliseconds
	$refresh = $refresh * 1000;
}
else if ($refresh < 0.5 || ($refresh > 120 && $refresh < 500)) {
	$refresh = $refresh_default; //use default
}
else {
	//>= 500, must be milliseconds
}
unset($refresh_default);
?>

//ajax refresh
	var refresh = <?php echo $refresh; ?>;
	var source_url = 'resources/content.php?' <?php if (isset($_GET['debug'])) { echo " + '&debug'"; } ?>;
	var interval_timer_id;

	function loadXmlHttp(url, id) {
		var f = this;
		f.xmlHttp = null;
		/*@cc_on @*/ // used here and below, limits try/catch to those IE browsers that both benefit from and support it
		/*@if(@_jscript_version >= 5) // prevents errors in old browsers that barf on try/catch & problems in IE if Active X disabled
		try {f.ie = window.ActiveXObject}catch(e){f.ie = false;}
		@end @*/
		if (window.XMLHttpRequest&&!f.ie||/^http/.test(window.location.href))
			f.xmlHttp = new XMLHttpRequest(); // Firefox, Opera 8.0+, Safari, others, IE 7+ when live - this is the standard method
		else if (/(object)|(function)/.test(typeof createRequest))
			f.xmlHttp = createRequest(); // ICEBrowser, perhaps others
		else {
			f.xmlHttp = null;
			 // Internet Explorer 5 to 6, includes IE 7+ when local //
			/*@cc_on @*/
			/*@if(@_jscript_version >= 5)
			try{f.xmlHttp=new ActiveXObject("Msxml2.XMLHTTP");}
			catch (e){try{f.xmlHttp=new ActiveXObject("Microsoft.XMLHTTP");}catch(e){f.xmlHttp=null;}}
			@end @*/
		}
		if(f.xmlHttp != null){
			f.el = document.getElementById(id);
			f.xmlHttp.open("GET",url,true);
			f.xmlHttp.onreadystatechange = function(){f.stateChanged();};
			f.xmlHttp.send(null);
		}
	}

	loadXmlHttp.prototype.stateChanged=function () {
		var url = new URL(this.xmlHttp.responseURL);
		if (/login\.php$/.test(url.pathname)) {
			// You are logged out. Stop refresh!
			refresh_stop();
			url.searchParams.set('path', '<?php echo $_SERVER['REQUEST_URI']; ?>');
			window.location.href = url.href;
			return;
		}

        if (this.xmlHttp.readyState == 4 && (this.xmlHttp.status == 200 || !/^http/.test(window.location.href))) {
            document.getElementById('ajax_response').innerHTML = this.xmlHttp.responseText;
            try {
                if (window.dispatcherControl && typeof window.dispatcherControl.updateUI === 'function') {
                    window.dispatcherControl.updateUI();
                }
            } catch (e) {}
        }
		if (document.getElementById('sort')) {
			if (document.getElementById('sort').value != "")
				document.getElementById('sort1').value=document.getElementById('sort').value;
		}
	}

	var requestTime = function() {
		var url = source_url;
		url += '&vd_ext_from=' + document.getElementById('vd_ext_from').value;
		url += '&vd_ext_to=' + document.getElementById('vd_ext_to').value;
		url += '&group=' + ((document.getElementById('group')) ? document.getElementById('group').value : '');
		url += '&filter=' + ((document.getElementById('search')) ? document.getElementById('search').value : '');
		url += '&eavesdrop_dest=' + ((document.getElementById('eavesdrop_dest')) ? document.getElementById('eavesdrop_dest').value : '');
		if (document.getElementById('sort1'))
			if (document.getElementById('sort1').value == '1') url += '&sort';
		<?php
		if (isset($_GET['debug'])) {
			echo "url += '&debug';";
		}
		?>
		new loadXmlHttp(url, 'ajax_response');
		refresh_start();
	}

if (window.addEventListener) {
    window.addEventListener('load', requestTime, false);
    window.addEventListener('load', function(){ try{ resourceHeartbeat_start() }catch(e){} }, false);
}
else if (window.attachEvent) {
    window.attachEvent('onload', requestTime);
    window.attachEvent('onload', function(){ try{ resourceHeartbeat_start() }catch(e){} });
}


//drag/drop functionality
	var ie_workaround = false;

	function drag(ev, from_ext) {
		refresh_stop();
		try {
			ev.dataTransfer.setData("Call", ev.target.id);
			ev.dataTransfer.setData("From", from_ext);
			virtual_drag_reset();
		}
		catch (err) {
			// likely internet explorer being used, do workaround
			virtual_drag(ev.target.id, from_ext);
			ie_workaround = true;
		}
	}

	function allowDrop(ev, target_id) {
		ev.preventDefault();
	}

	function discardDrop(ev, target_id) {
		ev.preventDefault();
	}

	function drop(ev, to_ext) {
		ev.preventDefault();
		if (ie_workaround) { // potentially set on drag() function above
			var call_id = document.getElementById('vd_call_id').value;
			var from_ext = document.getElementById('vd_ext_from').value;
			virtual_drag_reset();
		}
		else {
			var call_id = ev.dataTransfer.getData("Call");
			var from_ext = ev.dataTransfer.getData("From");
		}
		var to_ext = to_ext;
		var cmd;

		if (call_id != '') {
			cmd = get_transfer_cmd(call_id, to_ext); //transfer a call
		}
		else {
			if (from_ext != to_ext) { // prevent user from dragging extention onto self
				cmd = get_originate_cmd(from_ext, to_ext); //make a call
			}
		}

		if (cmd != '') { send_cmd(cmd) }

		refresh_start();
	}

//refresh controls
    function refresh_stop() {
        clearInterval(interval_timer_id);
        if (document.getElementById('refresh_state')) { document.getElementById('refresh_state').innerHTML = "<?php echo button::create(['type'=>'button','title'=>$text['label-refresh_enable'],'icon'=>'pause','onclick'=>'refresh_start()']); ?>"; }
    }

    function refresh_start() {
        if (document.getElementById('refresh_state')) { document.getElementById('refresh_state').innerHTML = "<?php echo button::create(['type'=>'button','title'=>$text['label-refresh_pause'],'icon'=>'sync-alt fa-spin','onclick'=>'refresh_stop()']); ?>"; }
        refresh_stop();
        var throttled = (window.DispatcherUtils && DispatcherUtils.throttle) ? DispatcherUtils.throttle(requestTime, Math.max(500, refresh)) : requestTime;
        interval_timer_id = setInterval(function(){ throttled() }, refresh);
    }

    // 已移除增量更新逻辑，统一通过控制器刷新与差分渲染

//call or transfer to destination
	function go_destination(from_ext, destination, which, call_id) {
		call_id = typeof call_id !== 'undefined' ? call_id : '';
		if (destination != '') {
			if (!isNaN(parseFloat(destination)) && isFinite(destination)) {
				if (call_id == '') {
					cmd = get_originate_cmd(from_ext, destination); //make a call
				}
				else {
					cmd = get_transfer_cmd(call_id, destination);
				}
				if (cmd != '') {
					send_cmd(cmd);
					$('#destination_'+from_ext+'_'+which).removeAttr('onblur');
					toggle_destination(from_ext, which);
				}
			}
		}
	}

//hangup call
	function hangup_call(call_id) {
		if (call_id != '') {
			send_cmd('exec.php?cmd=uuid_kill&call_id=' + call_id)
		}
	}

//eavesdrop call
	function eavesdrop_call(ext, chan_uuid) {
		if (ext != '' && chan_uuid != '') {
			// 进入监听模式前，先挂断所有插入讲话通话（避免重复听到声音）
			try {
				if (window.dispatcherControl && dispatcherControl.sipClient && dispatcherControl.sipClient.hangupByType) {
					console.log('🔄 监听模式：挂断所有插入讲话通话');
					dispatcherControl.sipClient.hangupByType('three-way');
				}
			} catch (e) {
				console.warn('挂断插入讲话通话失败:', e);
			}
			
			cmd = get_eavesdrop_cmd(ext, chan_uuid, document.getElementById('eavesdrop_dest').value);
			if (cmd != '') {
				send_cmd(cmd);
			}
		}
	}

//record call
	function record_call(chan_uuid) {
		if (chan_uuid != '') {
			cmd = get_record_cmd(chan_uuid);
			if (cmd != '') {
				send_cmd(cmd);
			}
		}
	}

// 统一通过工具模块发送命令
function send_cmd(url, callback){ try { if (window.DispatcherUtils && DispatcherUtils.sendCmd){ DispatcherUtils.sendCmd(url, callback) } } catch(e){} }

//hide/show destination input field
	function toggle_destination(ext, which) {
		refresh_stop();
		if (which == 'call') {
			if ($('#destination_'+ext+'_call').is(':visible')) {
				$('#destination_'+ext+'_call').val('');
				$('#destination_'+ext+'_call').autocomplete('destroy');
				$('#destination_'+ext+'_call').hide(0, function() {
					$('.call_control').children().attr('onmouseout', "refresh_start();");
					$('.destination_control').attr('onmouseout', "refresh_start();");
					refresh_start();
				});
			}
			else {
				$('#destination_'+ext+'_call').show(0, function() {
					$('#destination_'+ext+'_call').trigger('focus');
					$('#destination_'+ext+'_call').autocomplete({
						source: "autocomplete.php",
						minLength: 3,
						select: function(event, ui) {
							$('#destination_'+ext+'_call').val(ui.item.value);
							$('#frm_destination_'+ext+'_call').submit();
						}
					});
					$('.call_control').children().removeAttr('onmouseout');
					$('.destination_control').removeAttr('onmouseout');
				});
			}
		}
		else if (which == 'transfer') {
			if ($('#destination_'+ext+'_transfer').is(':visible')) {
				$('#destination_'+ext+'_transfer').val('');
				$('#destination_'+ext+'_transfer').autocomplete('destroy');
				$('#destination_'+ext+'_transfer').hide(0, function() {
					$('#op_caller_details_'+ext).show();
					$('.call_control').children().attr('onmouseout', "refresh_start();");
					$('.destination_control').attr('onmouseout', "refresh_start();");
					refresh_start();
				});
			}
			else {
				$('#op_caller_details_'+ext).hide(0, function() {
					$('#destination_'+ext+'_transfer').show(0, function() {
						$('#destination_'+ext+'_transfer').trigger('focus');
						$('#destination_'+ext+'_transfer').autocomplete({
							source: "autocomplete.php",
							minLength: 3,
							select: function(event, ui) {
								$('#destination_'+ext+'_transfer').val(ui.item.value);
								$('#frm_destination_'+ext+'_transfer').submit();
							}
						});
						$('.call_control').children().removeAttr('onmouseout');
						$('.destination_control').removeAttr('onmouseout');
					});
				});
			}
		}
	}

function get_transfer_cmd(uuid, destination){ return DispatcherUtils.getTransferCmd(uuid, destination) }
function get_originate_cmd(source, destination){ return DispatcherUtils.getOriginateCmd(source, destination) }
function get_eavesdrop_cmd(ext, chan_uuid, destination, mode){ return DispatcherUtils.getEavesdropCmd(ext, chan_uuid, destination, mode) }
function get_record_cmd(uuid){ return DispatcherUtils.getRecordCmd(uuid) }

// 直呼封装：使用落地分机对目标分机外呼
function call_direct(ext) {
    var operator_ext = (document.getElementById('eavesdrop_dest')) ? document.getElementById('eavesdrop_dest').value : '';
    if (!operator_ext) {
        DispatcherUtils.alert('未检测到落地分机，请先在顶部选择或绑定分机', 'warn');
        return;
    }
    if (operator_ext === ext) {
        DispatcherUtils.alert('不能对自身分机发起直呼', 'warn');
        return;
    }
    var url = get_originate_cmd(operator_ext, ext);
    send_cmd(url, function(response, status) {
        console.log('call_direct response:', response.substring(0, 200));
        if (response.indexOf('-ERR') !== -1 || response.indexOf('access denied') !== -1) {
            DispatcherUtils.alert('发起失败: ' + response.substring(0, 200), 'error');
        } else {
            console.log('已发起呼叫');
        }
    });
}

// 无阻塞通话：忙则插入/监听，闲则直呼
function unblocked_call(ext, chan_uuid) {
    var operator_ext = (document.getElementById('eavesdrop_dest')) ? document.getElementById('eavesdrop_dest').value : '';
    if (!operator_ext) {
        DispatcherUtils.alert('未检测到落地分机，请先在顶部选择或绑定分机', 'warn');
        return;
    }
    if (operator_ext === ext) {
        DispatcherUtils.alert('不能对自身分机发起直呼', 'warn');
        return;
    }
		var url = '';
		if (chan_uuid && chan_uuid !== '') {
			// 目标分机忙 -> 使用 three-way 插入讲话（双向）
			// 一次性自动接听插入支路（浏览器JsSIP）
			try { window.__autoAnswerBargeNext = true; } catch (e) {}
			try {
				if (window.dispatcherControl && dispatcherControl.sipClient && dispatcherControl.sipClient.unlockAudioPlayback) {
					dispatcherControl.sipClient.unlockAudioPlayback();
				}
			} catch (e) {}
			url = get_eavesdrop_cmd(ext, chan_uuid, operator_ext, 'three-way');
		} else {
			// 目标分机空闲 -> 由调度分机直接外呼
			url = get_originate_cmd(operator_ext, ext);
		}
    if (!url) {
        DispatcherUtils.alert('无法构造请求，请检查参数', 'error');
        return;
    }
	console.log('unblocked_call', { operator_ext: operator_ext, target_ext: ext, chan_uuid: chan_uuid });
	console.log('send_cmd', url);
	send_cmd(url, function(response, status) {
		console.log('unblocked_call response:', response.substring(0, 200));
    if (response.indexOf('-ERR') !== -1 || response.indexOf('access denied') !== -1) {
        DispatcherUtils.alert('发起失败: ' + response.substring(0, 200), 'error');
    } else {
        console.log('已发起插入/呼叫');
    }
	});
}

// 临时存储：插入讲话时的目标信息
var __threeWayTarget = { ext: null, chan_uuid: null };

// 打开插入分机选择对话框
function openThreeWayExtModal(ext, chan_uuid) {
	// 调试日志：显示接收到的参数
	console.log('openThreeWayExtModal 调用参数:', {
		ext: ext,
		ext_type: typeof ext,
		chan_uuid: chan_uuid,
		chan_uuid_type: typeof chan_uuid,
		chan_uuid_length: chan_uuid ? chan_uuid.length : 0
	});
	
	__threeWayTarget.ext = ext;
	__threeWayTarget.chan_uuid = chan_uuid;
	
	// 确认存储
	console.log('已存储到 __threeWayTarget:', __threeWayTarget);
	
	// 加载分机列表
	var selector = document.getElementById('three-way-ext-selector');
	if (!selector) return;
	
	selector.innerHTML = '<option value="">加载中...</option>';
	
	// 从 dispatcher API 获取分机列表
	$.ajax({
		url: 'dispatcher_api.php?action=get_extensions',
		type: 'GET',
		dataType: 'json',
		success: function(response) {
			if (response.success && response.data) {
				selector.innerHTML = '';
				
				// 当前落地分机（默认选中）
				var currentExt = (document.getElementById('eavesdrop_dest')) ? 
					document.getElementById('eavesdrop_dest').value : '';
				
				response.data.forEach(function(extData) {
					var option = document.createElement('option');
					option.value = extData.extension;
					var label = extData.extension;
					if (extData.effective_caller_id_name) {
						label += ' (' + extData.effective_caller_id_name + ')';
					}
					option.text = label;
					if (extData.extension === currentExt) {
						option.selected = true;
					}
					selector.appendChild(option);
				});
			}
		},
		error: function() {
			selector.innerHTML = '<option value="">加载失败</option>';
		}
	});
	
	// 显示对话框
	document.getElementById('three-way-ext-modal').style.display = 'flex';
}

// 关闭插入分机选择对话框
function closeThreeWayExtModal() {
	document.getElementById('three-way-ext-modal').style.display = 'none';
	__threeWayTarget = { ext: null, chan_uuid: null };
}

// 确认并执行插入讲话
function confirmThreeWayExt() {
    var selector = document.getElementById('three-way-ext-selector');
    var selectedExt = selector ? selector.value : '';
    
    if (!selectedExt) {
        DispatcherUtils.alert('请选择一个分机', 'warn');
        return;
    }
	
	// 调试日志：确认 __threeWayTarget 的值
	console.log('confirmThreeWayExt - __threeWayTarget:', __threeWayTarget);
	console.log('confirmThreeWayExt - selectedExt:', selectedExt);
	
	// 保存 __threeWayTarget 的值（因为 closeThreeWayExtModal 会清空它）
	var targetExt = __threeWayTarget.ext;
	var targetChanUuid = __threeWayTarget.chan_uuid;
	
	var remember = document.getElementById('three-way-remember-ext');
	if (remember && remember.checked) {
		// 更新默认落地分机
		var eavesdropDest = document.getElementById('eavesdrop_dest');
		if (eavesdropDest) {
			eavesdropDest.value = selectedExt;
		}
	}
	
	// 关闭对话框
	closeThreeWayExtModal();
	
	// 调用原有的插入讲话逻辑，传入选定的分机（使用保存的值）
	three_way_call_with_ext(targetExt, targetChanUuid, selectedExt);
}

// 内部插入讲话执行函数（接受自定义 operator_ext）
function three_way_call_with_ext(ext, chan_uuid, operator_ext) {
	// 详细的参数日志
	console.log('three_way_call_with_ext 接收参数:', {
		ext: ext,
		ext_type: typeof ext,
		chan_uuid: chan_uuid,
		chan_uuid_type: typeof chan_uuid,
		chan_uuid_value: JSON.stringify(chan_uuid),
		operator_ext: operator_ext,
		operator_ext_type: typeof operator_ext
	});
	
    if (!operator_ext) {
        DispatcherUtils.alert('未选择插入分机', 'warn');
        return;
    }
	
	// 进入插入讲话模式前，先挂断所有监听通话（避免重复听到声音）
	try {
		if (window.dispatcherControl && dispatcherControl.sipClient && dispatcherControl.sipClient.hangupByType) {
			console.log('🔄 插入讲话模式：挂断所有监听通话');
			dispatcherControl.sipClient.hangupByType('eavesdrop');
		}
	} catch (e) {
		console.warn('挂断监听通话失败:', e);
	}
	
	// 一次性自动接听插入支路（浏览器JsSIP）
	try { window.__autoAnswerBargeNext = true; } catch (e) {}
	try {
		if (window.dispatcherControl && dispatcherControl.sipClient && dispatcherControl.sipClient.unlockAudioPlayback) {
			dispatcherControl.sipClient.unlockAudioPlayback();
		}
	} catch (e) {}
	
	var url = get_eavesdrop_cmd(ext, chan_uuid, operator_ext, 'three-way');
	console.log('three_way_call_with_ext', { operator_ext: operator_ext, target_ext: ext, chan_uuid: chan_uuid });
	console.log('构造的命令URL:', url);
	
	send_cmd(url, function(response, status) {
		console.log('three_way_call response:', response.substring(0, 200));
        if (response.indexOf('-ERR') !== -1 || response.indexOf('access denied') !== -1) {
            DispatcherUtils.alert('插入讲话失败，可能原因：\n1. 原通话已结束\n2. 所选分机不可用\n3. 权限不足\n\n详细信息: ' + response.substring(0, 150), 'error');
        } else {
            console.log('已发起三方插入');
        }
	});
}

// 插入讲话：三方通话模式（弹出分机选择对话框）
	function three_way_call(ext, chan_uuid) {
		// 弹出分机选择对话框
		openThreeWayExtModal(ext, chan_uuid);
	}

// 强拆（双路）: 同时拆除两端通话
	function hangup_both(uuid_a, uuid_b) {
		if (uuid_a) {
			send_cmd('exec.php?cmd=uuid_kill&call_id=' + uuid_a);
		}
		if (uuid_b && uuid_b !== uuid_a) {
			send_cmd('exec.php?cmd=uuid_kill&call_id=' + uuid_b);
		}
	}

//virtual functions
	function virtual_drag(call_id, ext) {
		if (document.getElementById('vd_ext_from').value != '' && document.getElementById('vd_ext_to').value != '') {
			virtual_drag_reset();
		}

		if (call_id != '') {
			document.getElementById('vd_call_id').value = call_id;
		}

		if (ext != '') {
			if (document.getElementById('vd_ext_from').value == '') {
				document.getElementById('vd_ext_from').value = ext;
				document.getElementById(ext).style.borderStyle = 'dotted';
				if (document.getElementById('vd_ext_to').value != '') {
					document.getElementById(document.getElementById('vd_ext_to').value).style.borderStyle = '';
					document.getElementById('vd_ext_to').value = '';
				}
			}
			else {
				document.getElementById('vd_ext_to').value = ext;
				if (document.getElementById('vd_ext_from').value != document.getElementById('vd_ext_to').value) {
					if (document.getElementById('vd_call_id').value != '') {
						cmd = get_transfer_cmd(document.getElementById('vd_call_id').value, document.getElementById('vd_ext_to').value); //transfer a call
					}
					else {
						cmd = get_originate_cmd(document.getElementById('vd_ext_from').value, document.getElementById('vd_ext_to').value); //originate a call
					}
					if (cmd != '') {
						//alert(cmd);
						send_cmd(cmd);
					}
				}
				virtual_drag_reset();
			}
		}
	}

	function virtual_drag_reset(vd_var) {
		if (!(vd_var === undefined)) {
			document.getElementById(vd_var).value = '';
		}
		else {
			document.getElementById('vd_call_id').value = '';
			if (document.getElementById('vd_ext_from').value != '') {
				document.getElementById(document.getElementById('vd_ext_from').value).style.borderStyle = '';
				document.getElementById('vd_ext_from').value = '';
			}
			if (document.getElementById('vd_ext_to').value != '') {
				document.getElementById(document.getElementById('vd_ext_to').value).style.borderStyle = '';
				document.getElementById('vd_ext_to').value = '';
			}
		}
	}

</script>

<style type="text/css">
	TABLE {
		border-spacing: 0px;
		border-collapse: collapse;
		border: none;
		}

    #dispatcher-lines-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:8px; }
    .line-card { border:1px solid #ddd; border-radius:6px; padding:8px; }
    .line-status { display:flex; align-items:center; gap:6px; font-size:12px; }
    .status-ok { background:#e8f5e9; }
    .status-warn { background:#fff8e1; }
    .status-held { background:#e3f2fd; }
    .status-error { background:#fdecea; }
    @media (max-width: 480px) { #dispatcher-lines-grid { grid-template-columns:repeat(1,1fr); } }
    @media (min-width: 481px) and (max-width: 1366px) { #dispatcher-lines-grid { grid-template-columns:repeat(2,1fr); } }
    @media (min-width: 1367px) { #dispatcher-lines-grid { grid-template-columns:repeat(3,1fr); } }
</style>

<?php

// create simple array of users own extensions
unset($_SESSION['user']['extensions']);
if (is_array($_SESSION['user']['extension'])) {
	foreach ($_SESSION['user']['extension'] as $assigned_extensions) {
		$_SESSION['user']['extensions'][] = $assigned_extensions['user'];
	}
}

?>

<!-- 固定的调度状态栏和工具栏（不受AJAX刷新影响） -->
<div id="dispatcher-fixed-toolbar" style="margin-bottom: 10px; padding: 10px; background: #f5f5f5; border-radius: 4px;">
	<table width="100%" cellpadding="0" cellspacing="0">
		<tr>
			<td width="50%">
				<div class="dispatcher-status-inline">
					<span id="sip-status-indicator" class="status-offline">●</span>
					<span id="sip-status-text">未注册</span>
					<button id="sip-register-btn" class="btn btn-sm" onclick="openSipRegisterModal()">注册</button>
				</div>
			</td>
			<td width="50%" align="right">
                <div class="dispatcher-toolbar">
					<button id="batch-call-btn" class="dispatcher-tool-btn" title="批量呼叫" onclick="openBatchCallModal()">
						<i class="fas fa-users"></i>
					</button>
				<button id="conference-btn" class="dispatcher-tool-btn" title="多方会议" disabled onclick="openConferenceModal()">
					<i class="fas fa-video"></i>
				</button>
				<button id="emergency-btn" class="dispatcher-tool-btn emergency-btn" title="急呼" disabled onclick="openEmergencyModal()">
					<i class="fas fa-exclamation-triangle"></i>
				</button>
				<button id="trunk-mode-btn" class="dispatcher-tool-btn" title="中继模式" onclick="toggleTrunkMode()">
						<i class="fas fa-exchange-alt"></i>
					</button>
                    <button id="check-mic-btn" class="dispatcher-tool-btn" title="检查麦克风权限" onclick="checkMicrophonePermission()">
                        <i class="fas fa-microphone"></i>
                    </button>
                    <button id="audio-test-btn" class="dispatcher-tool-btn" title="声音测试" onclick="testEmergencyAudio()">
                        <i class="fas fa-volume-up"></i>
                    </button>
                    <button id="audio-mute-btn" class="dispatcher-tool-btn" title="静音切换" onclick="toggleEmergencyMute()">
                        <i class="fas fa-volume-mute"></i>
                    </button>
                </div>
			</td>
		</tr>
	</table>
</div>

<?php

echo "<div id='ajax_response'></div>\n";
echo "<div id=\"dispatcher-alerts\" style=\"display:none; position:fixed; right:16px; bottom:96px; z-index:9999; max-width:420px;\"></div>\n";
echo "<div id='cmd_response' style='display: none;'></div>\n";

// 调度功能模态对话框
?>

<div id="dispatcher-lines-wrapper" style="margin:10px 0;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
        <div style="font-weight:600;">通话线路</div>
        <div>
            <button class="btn btn-sm btn-danger" onclick="hangupAllLines()">全部挂断</button>
        </div>
    </div>
    <div id="dispatcher-lines-grid" style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;"></div>
    <div id="dispatcher-busy-queue" style="display:none;color:#b71c1c;background:#fdecea;border:1px solid #f5c2c7;padding:6px 10px;border-radius:4px;margin-top:6px;">调度忙，来电已排队</div>
    <div id="dispatcher-lines-toast" style="display:none;position:fixed;right:16px;bottom:16px;background:#333;color:#fff;padding:8px 12px;border-radius:4px;opacity:0.9;"></div>
 </div>

<!-- SIP 注册模态对话框 -->
<div id="sip-register-modal" class="dispatcher-modal" style="display: none;">
	<div class="modal-overlay" onclick="closeSipRegisterModal()"></div>
	<div class="modal-content">
		<div class="modal-header">
			<h3>SIP 注册</h3>
			<button class="modal-close-btn" onclick="closeSipRegisterModal()">×</button>
		</div>
		<div class="modal-body">
			<div class="alert alert-info" style="margin-bottom: 15px; padding: 10px; background: #e3f2fd; border-radius: 5px; font-size: 12px;">
				<strong>⚠️ 重要提示：</strong>
				<?php if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on'): ?>
					当前使用 HTTPS 访问，必须使用 <strong>WSS</strong> 协议
				<?php else: ?>
					当前使用 HTTP 访问，可以使用 <strong>WS</strong> 协议
				<?php endif; ?>
			</div>
			<div class="form-group">
				<label>麦克风权限：</label>
				<div class="alert alert-info" style="padding: 10px; background: #fff3cd; border-radius: 5px; font-size: 12px;">
					<i class="fas fa-info-circle"></i> 
					使用通话功能需要麦克风权限。如果浏览器提示，请选择"允许"。
					<br><small>
						如果已拒绝权限，请点击地址栏左侧的麦克风图标，选择"允许"，然后刷新页面。
					</small>
				</div>
			</div>
			<div class="form-group">
				<label>快速选择账号:</label>
				<select id="sip-account-select" class="form-control" onchange="loadSelectedAccount()">
					<option value="0">调度员1 (5001)</option>
					<option value="1">调度员2 (5002)</option>
					<option value="2">调度员3 (5003)</option>
					<option value="-1">自定义配置</option>
				</select>
			</div>
			<div class="form-group">
				<label>WebSocket URL:</label>
				<input type="text" id="sip-ws" class="form-control" value="wss://192.168.2.200:7443" placeholder="<?php echo (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'wss://192.168.2.200:7443' : 'ws://192.168.2.200:5066'; ?>" />
			</div>
			<div class="form-group">
				<label>SIP URI:</label>
				<input type="text" id="sip-uri" class="form-control" value="sip:5001@192.168.2.200" placeholder="sip:1000@192.168.2.200" />
			</div>
			<div class="form-group">
				<label>用户名:</label>
				<input type="text" id="sip-user" class="form-control" value="5001" placeholder="1000" />
			</div>
			<div class="form-group">
				<label>密码:</label>
				<input type="password" id="sip-password" class="form-control" value="1234" placeholder="密码" />
			</div>
			<div class="form-group">
				<label>显示名称:</label>
				<input type="text" id="sip-display-name" class="form-control" value="调度员" />
			</div>
		</div>
		<div class="modal-footer">
			<button class="btn btn-primary" onclick="doSipRegister()">注册</button>
			<button class="btn btn-secondary" onclick="closeSipRegisterModal()">取消</button>
		</div>
	</div>
</div>

<!-- 组呼模态对话框 -->
<div id="group-call-modal" class="dispatcher-modal" style="display: none;">
	<div class="modal-overlay" onclick="closeGroupCallModal()"></div>
	<div class="modal-content" style="max-width: 600px;">
		<div class="modal-header">
			<h3>发起组呼</h3>
			<button class="modal-close-btn" onclick="closeGroupCallModal()">×</button>
		</div>
		<div class="modal-body">
			<div class="form-group">
				<label>分组筛选:</label>
				<select id="group-call-filter" class="form-control" onchange="filterGroupCallExtensions()">
					<option value="">全部分机</option>
					<!-- 动态加载分组选项 -->
				</select>
			</div>
			<div class="form-group">
				<label>选择呼叫的分机:</label>
				<div style="margin-bottom: 10px;">
					<button class="btn btn-sm btn-secondary" onclick="selectAllExtensions()">全选</button>
					<button class="btn btn-sm btn-secondary" onclick="deselectAllExtensions()">取消全选</button>
				</div>
				<div id="group-call-extension-list" style="max-height: 300px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; border-radius: 4px;">
					<!-- 显示分机复选框列表 -->
				</div>
			</div>
			<div class="form-group" style="margin-top: 15px;">
				<label>添加外线号码（可选）：</label>
				<input type="text" id="external-numbers" class="form-control" 
					   placeholder="多个号码用逗号分隔，如: 13812345678,02188888888">
				<small class="form-text text-muted">支持手机号、固定电话等外线号码</small>
			</div>
		</div>
		<div class="modal-footer">
			<button class="btn btn-success" onclick="startGroupCallWithSelected()">开始呼叫</button>
			<button class="btn btn-secondary" onclick="closeGroupCallModal()">取消</button>
		</div>
	</div>
</div>

<!-- 会议模态对话框 -->
<div id="conference-modal" class="dispatcher-modal" style="display: none;">
	<div class="modal-overlay" onclick="closeConferenceModal()"></div>
	<div class="modal-content">
		<div class="modal-header">
			<h3>发起多方会议</h3>
			<button class="modal-close-btn" onclick="closeConferenceModal()">×</button>
		</div>
		<div class="modal-body">
			<div id="extension-checklist">
				<!-- 显示可选分机的复选框列表 -->
			</div>
		</div>
		<div class="modal-footer">
			<button class="btn btn-primary" onclick="startConference()">发起会议</button>
			<button class="btn btn-secondary" onclick="closeConferenceModal()">取消</button>
		</div>
	</div>
</div>

<!-- 批量呼叫模态对话框 -->
<div id="batch-call-modal" class="dispatcher-modal" style="display: none;">
	<div class="modal-overlay" onclick="closeBatchCallModal()"></div>
	<div class="modal-content" style="max-width: 600px;">
		<div class="modal-header">
			<h3><i class="fas fa-users"></i> 发起批量呼叫</h3>
			<button class="modal-close-btn" onclick="closeBatchCallModal()">×</button>
		</div>
		<div class="modal-body">
			<div class="form-group">
				<label>呼叫类型:</label>
				<select id="batch-call-type" class="form-control" onchange="updateBatchCallTargets()">
					<option value="group">组呼 (Group Call)</option>
					<option value="broadcast">全呼 (Broadcast)</option>
				</select>
			</div>
			<div class="form-group">
				<label>选择会议:</label>
				<select id="batch-call-target" class="form-control">
					<option value="">加载中...</option>
				</select>
				<small style="color: #666;" id="batch-call-desc">选择一个会议以加载成员</small>
			</div>
		</div>
		<div class="modal-footer">
			<button class="btn btn-success" onclick="initiateBatchCall()">
				<i class="fas fa-phone"></i> 发起呼叫
			</button>
			<button class="btn btn-secondary" onclick="closeBatchCallModal()">取消</button>
		</div>
	</div>
</div>

<!-- 插入讲话分机选择对话框 -->
<div id="three-way-ext-modal" class="dispatcher-modal" style="display: none;">
	<div class="modal-overlay" onclick="closeThreeWayExtModal()"></div>
	<div class="modal-content" style="max-width: 500px;">
		<div class="modal-header">
			<h3>选择插入分机</h3>
			<button class="modal-close-btn" onclick="closeThreeWayExtModal()">×</button>
		</div>
		<div class="modal-body">
			<div class="form-group">
				<label>选择用于插入的分机：</label>
				<select id="three-way-ext-selector" class="form-control" style="width: 100%;">
					<!-- 动态加载分机列表 -->
				</select>
			</div>
			<div class="form-group" style="margin-top: 10px;">
				<label style="display: flex; align-items: center;">
					<input type="checkbox" id="three-way-remember-ext" style="margin-right: 8px;">
					记住此选择（设为默认落地分机）
				</label>
			</div>
		</div>
		<div class="modal-footer">
			<button class="btn btn-primary" onclick="confirmThreeWayExt()">确认插入</button>
			<button class="btn btn-secondary" onclick="closeThreeWayExtModal()">取消</button>
		</div>
	</div>
</div>

<!-- 急呼模态对话框 -->
<div id="emergency-modal" class="dispatcher-modal" style="display: none;">
	<div class="modal-overlay" onclick="closeEmergencyModal()"></div>
	<div class="modal-content" style="max-width: 600px;">
		<div class="modal-header" style="background: #dc3545; color: white;">
			<h3><i class="fas fa-exclamation-triangle"></i> 急呼</h3>
			<button class="modal-close-btn" onclick="closeEmergencyModal()">×</button>
		</div>
		<div class="modal-body">
			<div class="alert alert-warning" style="padding: 10px; margin-bottom: 15px;">
				<strong>⚠️ 急呼说明：</strong><br>
				- 被叫将收到特殊振铃提示<br>
				- 15秒内未接听将自动应答（优先扬声器模式）<br>
				- 通话将自动录音
			</div>
			<div class="form-group">
				<label>急呼类型:</label>
				<select id="emergency-type" class="form-control" onchange="updateEmergencyTargets()">
					<option value="single">单个用户</option>
					<option value="group">组呼</option>
					<option value="broadcast">全呼</option>
				</select>
			</div>
			<div class="form-group" id="emergency-single-target">
				<label>目标分机:</label>
				<input type="text" id="emergency-target-ext" class="form-control" placeholder="输入分机号">
			</div>
			<div class="form-group" id="emergency-group-target" style="display: none;">
				<label>选择会议:</label>
				<select id="emergency-group-select" class="form-control">
					<option value="">加载中...</option>
				</select>
				<small style="color: #666;">将呼叫会议中配置的所有成员</small>
			</div>
			<div class="form-group" id="emergency-broadcast-confirm" style="display: none;">
				<label>选择会议:</label>
				<select id="emergency-broadcast-select" class="form-control">
					<option value="">加载中...</option>
				</select>
				<small style="color: #666;">将呼叫会议中配置的所有成员</small>
			</div>
		</div>
		<div class="modal-footer">
			<button class="btn btn-danger" onclick="initiateEmergencyCall()">
				<i class="fas fa-phone"></i> 发起急呼
			</button>
			<button class="btn btn-secondary" onclick="closeEmergencyModal()">取消</button>
		</div>
	</div>
</div>

<!-- 组呼状态栏 -->
<div id="group-call-status" class="call-status-bar" style="display: none;">
	<span id="group-call-status-text">组呼进行中</span>
	<button class="btn btn-sm btn-danger" onclick="endGroupCall()">结束</button>
</div>

<!-- 会议状态栏 -->
<div id="conference-status" class="call-status-bar" style="display: none;">
	<span id="conference-status-text">会议进行中</span>
	<button class="btn btn-sm btn-warning" onclick="manageConference()">管理</button>
	<button class="btn btn-sm btn-danger" onclick="endConference()">结束</button>
</div>

<!-- 会议管理面板 -->
<div id="conference-manage-modal" class="dispatcher-modal" style="display: none;">
	<div class="modal-overlay" onclick="closeConferenceManageModal()"></div>
	<div class="modal-content" style="max-width: 700px;">
		<div class="modal-header">
			<h3>会议管理</h3>
			<button class="modal-close-btn" onclick="closeConferenceManageModal()">×</button>
		</div>
		<div class="modal-body">
			<div class="conference-info">
				<p><strong>会议时长：</strong><span id="conference-manage-duration">00:00</span></p>
				<p><strong>参与人数：</strong><span id="conference-manage-count">0</span></p>
			</div>
			<div class="conference-controls" style="margin: 15px 0;">
				<button class="btn btn-sm btn-warning" onclick="muteAllParticipants()">全部静音</button>
				<button class="btn btn-sm btn-success" onclick="unmuteAllParticipants()">全部取消静音</button>
			</div>
			<div id="conference-participants-list" style="max-height: 400px; overflow-y: auto;">
				<!-- 参与者列表 -->
			</div>
		</div>
		<div class="modal-footer">
			<button class="btn btn-danger" onclick="endConferenceFromManage()">结束会议</button>
			<button class="btn btn-secondary" onclick="closeConferenceManageModal()">关闭</button>
		</div>
	</div>
</div>

<!-- 中继桥接面板 -->
<div id="dispatcher-trunk-panel"></div>

<!-- 来电提示 -->
<div id="dispatcher-alerts"></div>

<!-- 日志模态框 -->
<div id="dispatcher-logs-modal" style="display: none;"></div>

<script type="text/javascript">
// 全局调度控制实例
var dispatcherControl;
var dispatcherLogger;
var emergencyAudio;

// 默认SIP配置 - 提供多个备用账号
var DEFAULT_SIP_ACCOUNTS = [
	{
		ws: 'wss://192.168.2.200:7443',
		uri: 'sip:5001@192.168.2.200',
		user: '5001',
		password: '1234',
		displayName: '调度员1'
	},
	{
		ws: 'wss://192.168.2.200:7443',
		uri: 'sip:5002@192.168.2.200',
		user: '5002',
		password: '1234',
		displayName: '调度员2'
	},
	{
		ws: 'wss://192.168.2.200:7443',
		uri: 'sip:5003@192.168.2.200',
		user: '5003',
		password: '1234',
		displayName: '调度员3'
	}
];

// 默认使用第一个账号
var DEFAULT_SIP_CONFIG = DEFAULT_SIP_ACCOUNTS[0];

// 初始化SIP注册表单默认值
function initSipRegisterForm() {
	$('#sip-ws').val(DEFAULT_SIP_CONFIG.ws);
	$('#sip-uri').val(DEFAULT_SIP_CONFIG.uri);
	$('#sip-user').val(DEFAULT_SIP_CONFIG.user);
	$('#sip-password').val(DEFAULT_SIP_CONFIG.password);
	$('#sip-display-name').val(DEFAULT_SIP_CONFIG.displayName);
}

// 加载选中的账号配置
function loadSelectedAccount() {
	var selectedIndex = parseInt($('#sip-account-select').val());
	
	if (selectedIndex === -1) {
		// 自定义配置，清空表单让用户手动填写
		$('#sip-ws').val('');
		$('#sip-uri').val('');
		$('#sip-user').val('');
		$('#sip-password').val('');
		$('#sip-display-name').val('');
		return;
	}
	
	var account = DEFAULT_SIP_ACCOUNTS[selectedIndex];
	$('#sip-ws').val(account.ws);
	$('#sip-uri').val(account.uri);
	$('#sip-user').val(account.user);
	$('#sip-password').val(account.password);
	$('#sip-display-name').val(account.displayName);
}

$(document).ready(function() {
	// 初始化调度控制
    dispatcherControl = new DispatcherControl();
    dispatcherLogger = new DispatcherLogger();
    emergencyAudio = new EmergencyAudioService();
	
	// 初始化SIP注册表单
	initSipRegisterForm();
	
	// 从localStorage恢复SIP注册状态
	restoreSipStatus();
	
	// 加载分组列表 (已移除手动分组，改为批量呼叫面板动态加载)
	// updateGroupsList();
	
	// 监听SIP状态更新
	dispatcherControl.on('registered', function() {
		updateSipStatus('online', '已注册');
		enableDispatcherFunctions();
		saveSipStatus('online', '已注册'); // 保存状态
		if (dispatcherControl.renderLinesGrid) { dispatcherControl.renderLinesGrid(); }
	});
	
	dispatcherControl.on('unregistered', function() {
		updateSipStatus('offline', '未注册');
		disableDispatcherFunctions();
		saveSipStatus('offline', '未注册'); // 保存状态
	});
	
	dispatcherControl.on('registrationFailed', function() {
		updateSipStatus('offline', '注册失败');
		saveSipStatus('offline', '注册失败'); // 保存状态
	});
	
	dispatcherControl.on('connecting', function() {
		updateSipStatus('connecting', '连接中...');
	});
});

// 保存SIP状态到localStorage
function saveSipStatus(status, text) {
	localStorage.setItem('dispatcher_sip_status', JSON.stringify({
		status: status,
		text: text,
		timestamp: Date.now()
	}));
}

// 从localStorage恢复SIP状态
function restoreSipStatus() {
	var saved = localStorage.getItem('dispatcher_sip_status');
	if (saved) {
		try {
			var data = JSON.parse(saved);
			// 如果状态在5分钟内且为在线状态，尝试恢复
			if (Date.now() - data.timestamp < 300000 && data.status === 'online') {
				console.log('恢复SIP状态:', data.status, data.text);
				updateSipStatus(data.status, data.text);
				
				// *** 关键修复：检查dispatcherControl是否实际已注册 ***
				if (dispatcherControl && dispatcherControl.isRegistered && dispatcherControl.isRegistered()) {
					console.log('✅ SIP实际已连接，启用功能按钮');
					enableDispatcherFunctions();
				} else {
					console.log('⚠️ localStorage显示已注册，但实际未连接，清除状态');
					// 清除错误的状态
					localStorage.removeItem('dispatcher_sip_status');
					updateSipStatus('offline', '未注册');
					disableDispatcherFunctions();
				}
			} else {
				// 状态过期或非在线状态
				disableDispatcherFunctions();
			}
		} catch (e) {
			console.error('恢复SIP状态失败:', e);
			disableDispatcherFunctions();
		}
	} else {
		// 默认状态
		disableDispatcherFunctions();
	}
}

// 更新SIP状态显示
function updateSipStatus(status, text) {
	$('#sip-status-indicator').removeClass('status-offline status-connecting status-online').addClass('status-' + status);
	$('#sip-status-text').text(text);
	if (status === 'online') {
		$('#sip-register-btn').text('注销').attr('onclick', 'doSipUnregister()');
	} else {
		$('#sip-register-btn').text('注册').attr('onclick', 'openSipRegisterModal()');
	}
}

// 打开SIP注册模态对话框
function openSipRegisterModal() {
	$('#sip-register-modal').show();
}

// 关闭SIP注册模态对话框
function closeSipRegisterModal() {
	$('#sip-register-modal').hide();
}

// 执行SIP注册
function doSipRegister() {
	var config = {
		uri: $('#sip-uri').val() || DEFAULT_SIP_CONFIG.uri,
		wsServers: $('#sip-ws').val() || DEFAULT_SIP_CONFIG.ws,
		authUser: $('#sip-user').val() || DEFAULT_SIP_CONFIG.user,
		password: $('#sip-password').val() || DEFAULT_SIP_CONFIG.password,
		displayName: $('#sip-display-name').val() || DEFAULT_SIP_CONFIG.displayName
	};

	if (!config.uri || !config.wsServers || !config.authUser || !config.password) {
		alert('请填写所有必填项');
		return;
	}

	dispatcherControl.register(config)
		.then(function() {
			closeSipRegisterModal();
		})
		.catch(function(error) {
			// 改进错误提示
			var errorMsg = '注册失败: ' + error.message;
			
			// 检查是否是账号已被占用
			if (error.message.indexOf('403') !== -1 || 
				error.message.indexOf('Forbidden') !== -1 ||
				error.message.indexOf('already') !== -1) {
				errorMsg += '\n\n可能原因：\n1. 该账号已在其他设备登录\n2. 密码错误\n\n建议：\n- 尝试使用其他调度员账号（5002或5003）\n- 或联系管理员';
			}
			
			alert(errorMsg);
		});
}

// 执行SIP注销
function doSipUnregister() {
	dispatcherControl.unregister()
		.then(function() {
			updateSipStatus('offline', '已注销');
			disableDispatcherFunctions();
		});
}

// 启用调度功能按钮
function enableDispatcherFunctions() {
	$('#group-call-btn, #broadcast-btn, #conference-btn, #emergency-btn').prop('disabled', false);
}

// 禁用调度功能按钮
function disableDispatcherFunctions() {
	$('#group-call-btn, #broadcast-btn, #conference-btn, #emergency-btn').prop('disabled', true);
}

// 快速拨号
function quickDial() {
	var number = $('#quick-dial-number').val();
	if (!number) {
		alert('请输入号码');
		return;
	}

	var serverHost = dispatcherControl.getServerHost();
	var target = 'sip:' + number + '@' + serverHost;

	dispatcherControl.sipClient.makeCall(target, { audio: true, video: false })
		.then(function() {
			$('#quick-dial-number').val('');
			alert('呼叫已发起');
		})
		.catch(function(error) {
			alert('拨打失败: ' + error.message);
		});
}

// 切换中继模式
function toggleTrunkMode() {
	var btn = $('#trunk-mode-btn');
	if (btn.hasClass('active')) {
		dispatcherControl.autoTrunkMode = false;
		btn.removeClass('active');
		alert('中继模式已关闭');
	} else {
		dispatcherControl.autoTrunkMode = true;
		btn.addClass('active');
		alert('中继模式已启用 - 来电将自动转接');
	}
}

function hangupAllLines() {
	if (!dispatcherControl || !dispatcherControl.sipClient) return;
	dispatcherControl.sipClient.hangupAll().then(function(){
		if (dispatcherControl.renderLinesGrid) { dispatcherControl.renderLinesGrid(); }
	}).catch(function(){});
}

// 打开组呼模态对话框
function openGroupCallModal() {
	// 加载分组选项
	var groups = dispatcherControl.callGroups;
	var filterSelect = $('#group-call-filter');
	filterSelect.find('option:not(:first)').remove();
	
	for (var groupId in groups) {
		if (groups.hasOwnProperty(groupId)) {
			filterSelect.append('<option value="' + groupId + '">' + groups[groupId].name + '</option>');
		}
	}
	
	// 加载全部分机
	loadGroupCallExtensions('');
	
	$('#group-call-modal').show();
}

// 加载分机列表
function loadGroupCallExtensions(groupId) {
	var extensions = dispatcherControl.getAllExtensions();
	var list = $('#group-call-extension-list');
	list.empty();
	
	// 如果选择了分组，只显示该分组的分机
	if (groupId && dispatcherControl.callGroups[groupId]) {
		extensions = dispatcherControl.callGroups[groupId].extensions;
	}
	
	if (extensions.length === 0) {
		list.html('<p style="text-align: center; color: #999; padding: 20px;">没有可用的分机</p>');
		return;
	}
	
	extensions.forEach(function(ext) {
		var item = $('<div class="extension-checkbox-item"></div>');
		var checkbox = $('<input type="checkbox" class="group-call-ext-checkbox" value="' + ext + '">');
		var label = $('<label class="extension-checkbox-label">' + ext + '</label>');
		label.click(function() {
			checkbox.prop('checked', !checkbox.prop('checked'));
		});
		item.append(checkbox).append(label);
		list.append(item);
	});
}

// 分组筛选
function filterGroupCallExtensions() {
	var groupId = $('#group-call-filter').val();
	loadGroupCallExtensions(groupId);
}

// 全选
function selectAllExtensions() {
	$('.group-call-ext-checkbox').prop('checked', true);
}

// 取消全选
function deselectAllExtensions() {
	$('.group-call-ext-checkbox').prop('checked', false);
}

// 发起选中分机的组呼
function startGroupCallWithSelected() {
	var selectedExts = [];
	$('.group-call-ext-checkbox:checked').each(function() {
		selectedExts.push($(this).val());
	});
	
	// 获取外线号码
	var externalNumbers = $('#external-numbers').val().trim();
	if (externalNumbers) {
		var numbers = externalNumbers.split(',').map(function(n) {
			return n.trim();
		}).filter(function(n) {
			return n.length > 0;
		});
		selectedExts = selectedExts.concat(numbers);
	}
	
	if (selectedExts.length === 0) {
		alert('请至少选择一个分机或输入一个外线号码');
		return;
	}
	
	// 使用选中的分机发起组呼
	dispatcherControl.startGroupCallWithExtensions(selectedExts);
	closeGroupCallModal();
	
	// 清空外线号码输入框
	$('#external-numbers').val('');
	
	// 显示组呼状态栏
	$('#group-call-status').show();
	$('#group-call-status-text').text('组呼进行中 - 呼叫 ' + selectedExts.length + ' 个号码');
}

// 关闭组呼模态对话框
function closeGroupCallModal() {
	$('#group-call-modal').hide();
}

// 结束组呼
function endGroupCall() {
	dispatcherControl.endGroupCall();
	$('#group-call-status').hide();
}

// 全呼
function startBroadcastCall() {
	if (confirm('确认向所有分机发起全呼？')) {
		dispatcherControl.startBroadcastCall();
		$('#group-call-status').show();
		$('#group-call-status-text').text('全呼进行中...');
	}
}

// 打开会议模态对话框
function openConferenceModal() {
	var extensions = dispatcherControl.getAllExtensions();
	var checklist = $('#extension-checklist');
	checklist.empty();
	
	extensions.forEach(function(ext) {
		var item = $('<div class="extension-checkbox-item"></div>');
		var checkbox = $('<input type="checkbox" value="' + ext + '">');
		var label = $('<label class="extension-checkbox-label">' + ext + '</label>');
		label.click(function() {
			checkbox.prop('checked', !checkbox.prop('checked'));
		});
		item.append(checkbox).append(label);
		checklist.append(item);
	});
	
	$('#conference-modal').show();
}

// 关闭会议模态对话框
function closeConferenceModal() {
	$('#conference-modal').hide();
}

// 发起会议
function startConference() {
	var selectedExts = [];
	$('#extension-checklist input[type="checkbox"]:checked').each(function() {
		selectedExts.push($(this).val());
	});
	
	if (selectedExts.length < 2) {
		alert('至少选择2个分机');
		return;
	}
	
	dispatcherControl.startConference(selectedExts);
	closeConferenceModal();
	
	// 显示会议状态栏
	$('#conference-status').show();
	$('#conference-status-text').text('会议进行中 - ' + selectedExts.length + '人参与');
}

// 管理会议（打开管理面板）
function manageConference() {
	// 启动实时更新
	startConferenceManagePanelUpdate();
	$('#conference-manage-modal').show();
}

// 关闭会议管理面板
function closeConferenceManageModal() {
	$('#conference-manage-modal').hide();
	// 停止实时更新
	stopConferenceManagePanelUpdate();
}

// 会议管理面板更新定时器
var conferenceManagePanelInterval = null;

// 启动会议管理面板实时更新
function startConferenceManagePanelUpdate() {
	// 清除之前的定时器
	stopConferenceManagePanelUpdate();
	
	// 立即更新一次
	updateConferenceManagePanelWithRefresh();
	
	// 每2秒更新一次
	conferenceManagePanelInterval = setInterval(function() {
		updateConferenceManagePanelWithRefresh();
	}, 2000);
}

// 停止会议管理面板实时更新
function stopConferenceManagePanelUpdate() {
	if (conferenceManagePanelInterval) {
		clearInterval(conferenceManagePanelInterval);
		conferenceManagePanelInterval = null;
	}
}

// 从服务器刷新数据后更新面板
function updateConferenceManagePanelWithRefresh() {
	if (!dispatcherControl.conferenceRoom) {
		return;
	}
	
	// 从服务器获取最新数据
	$.ajax({
		url: 'dispatcher_conference_api.php',
		type: 'GET',
		data: {
			action: 'get_conference_members',
			conference_room: dispatcherControl.conferenceRoom
		},
		dataType: 'json',
		success: function(response) {
			if (response.success) {
				// 更新数据
				dispatcherControl.conferenceParticipants = response.members;
				// 更新UI
				updateConferenceManagePanel();
			}
		},
		error: function(error) {
			console.error('获取会议成员失败:', error);
		}
	});
}

// 更新会议管理面板
function updateConferenceManagePanel() {
	var participants = dispatcherControl.conferenceParticipants || [];
	var list = $('#conference-participants-list');
	list.empty();
	
	// 获取authUser（修复：使用正确的路径）
	var authUser = null;
	if (dispatcherControl.config && dispatcherControl.config.authUser) {
		authUser = dispatcherControl.config.authUser;
	} else if (dispatcherControl.sipClient && dispatcherControl.sipClient.ua && dispatcherControl.sipClient.ua._configuration) {
		// 从UA中获取authUser
		authUser = dispatcherControl.sipClient.ua._configuration.authorization_user;
	}
	
	// 如果仍然无法获取，使用默认值
	if (!authUser) {
		authUser = $('#sip-user').val() || 'unknown';
	}
	
	console.log('会议管理面板 - authUser:', authUser, '参与者数量:', participants.length);
	
	// 过滤掉调度员自己
	var filtered = participants.filter(function(p) {
		return p.caller_id_number !== authUser;
	});
	
	$('#conference-manage-count').text(filtered.length);
	
	if (filtered.length === 0) {
		list.html('<p style="text-align: center; color: #999; padding: 20px;">暂无参与者</p>');
		return;
	}
	
	filtered.forEach(function(p) {
		var item = $('<div class="participant-item"></div>');
		var info = $('<div class="participant-info"></div>');
		var displayName = p.caller_id_name || p.caller_id_number;
		var statusText = p.muted ? '已静音' : '正常';
		info.html('<strong>' + displayName + '</strong><br><small>号码: ' + p.caller_id_number + ' | 状态: ' + statusText + '</small>');
		
		var actions = $('<div class="participant-actions"></div>');
		
		if (p.muted) {
			actions.append('<button class="btn btn-sm btn-success" onclick="unmuteParticipantById(\'' + p.id + '\')">取消静音</button>');
		} else {
			actions.append('<button class="btn btn-sm btn-warning" onclick="muteParticipantById(\'' + p.id + '\')">静音</button>');
		}
		
		actions.append('<button class="btn btn-sm btn-danger" onclick="kickParticipantById(\'' + p.id + '\')">踢出</button>');
		
		item.append(info).append(actions);
		list.append(item);
	});
	
	// 更新会议时长
	if (dispatcherControl.groupCallStartTime) {
		var duration = Math.floor((Date.now() - dispatcherControl.groupCallStartTime) / 1000);
		var minutes = Math.floor(duration / 60);
		var seconds = duration % 60;
		$('#conference-manage-duration').text(minutes + ':' + (seconds < 10 ? '0' : '') + seconds);
	}
}

// 静音参与者（基于member_id）
function muteParticipantById(memberId) {
	$.post('dispatcher_conference_api.php', {
		action: 'mute_participant',
		conference_room: dispatcherControl.conferenceRoom,
		member_id: memberId
	}, function(response) {
		console.log('静音参与者:', memberId, response);
		// 修复：刷新数据后更新UI
		setTimeout(function() {
			updateConferenceManagePanelWithRefresh();
		}, 500);
	});
}

// 取消静音参与者（基于member_id）
function unmuteParticipantById(memberId) {
	$.post('dispatcher_conference_api.php', {
		action: 'unmute_participant',
		conference_room: dispatcherControl.conferenceRoom,
		member_id: memberId
	}, function(response) {
		console.log('取消静音参与者:', memberId, response);
		// 修复：刷新数据后更新UI
		setTimeout(function() {
			updateConferenceManagePanelWithRefresh();
		}, 500);
	});
}

// 踢出参与者（基于member_id）
function kickParticipantById(memberId) {
	if (confirm('确认踢出该参与者？')) {
		$.post('dispatcher_conference_api.php', {
			action: 'kick_participant',
			conference_room: dispatcherControl.conferenceRoom,
			member_id: memberId
		}, function(response) {
			console.log('踢出参与者:', memberId, response);
			// 修复：刷新数据后更新UI
			setTimeout(function() {
				updateConferenceManagePanelWithRefresh();
			}, 500);
		});
	}
}

// 旧版函数保留兼容（已废弃，使用基于 member_id 的新版本）
function muteParticipant(extension) {
	console.warn('muteParticipant() is deprecated, use muteParticipantById()');
}

// 旧版函数保留兼容（已废弃）
function unmuteParticipant(extension) {
	console.warn('unmuteParticipant() is deprecated, use unmuteParticipantById()');
}

// 踢出参与者（旧版，已废弃）
function kickParticipant(extension) {
	console.warn('kickParticipant() is deprecated, use kickParticipantById()');
}

// 全部静音
function muteAllParticipants() {
	var participants = dispatcherControl.conferenceParticipants || [];
	
	// 修复：使用正确的authUser获取路径
	var authUser = null;
	if (dispatcherControl.config && dispatcherControl.config.authUser) {
		authUser = dispatcherControl.config.authUser;
	}
	
	var filtered = participants.filter(function(p) {
		return p.caller_id_number !== authUser;
	});
	
	filtered.forEach(function(p) {
		$.post('dispatcher_conference_api.php', {
			action: 'mute_participant',
			conference_room: dispatcherControl.conferenceRoom,
			member_id: p.id
		});
	});
	
	// 修复：刷新数据后更新UI
	setTimeout(function() {
		updateConferenceManagePanelWithRefresh();
	}, 800);
}

// 全部取消静音
function unmuteAllParticipants() {
	var participants = dispatcherControl.conferenceParticipants || [];
	
	// 修复：使用正确的authUser获取路径
	var authUser = null;
	if (dispatcherControl.config && dispatcherControl.config.authUser) {
		authUser = dispatcherControl.config.authUser;
	}
	
	var filtered = participants.filter(function(p) {
		return p.caller_id_number !== authUser;
	});
	
	filtered.forEach(function(p) {
		$.post('dispatcher_conference_api.php', {
			action: 'unmute_participant',
			conference_room: dispatcherControl.conferenceRoom,
			member_id: p.id
		});
	});
	
	// 修复：刷新数据后更新UI
	setTimeout(function() {
		updateConferenceManagePanelWithRefresh();
	}, 800);
}

// 从管理面板结束会议
function endConferenceFromManage() {
	closeConferenceManageModal();
	endConference();
}

// 结束会议
function endConference() {
	dispatcherControl.endConference();
	$('#conference-status').hide();
}

// 旧的分组管理模态对话框函数已移除，使用 batch-call-modal 替代

// 直接呼叫分机
function callExtensionDirect(extension) {
	// 检查SIP注册状态
	if (!dispatcherControl || !dispatcherControl.isRegistered()) {
		alert('请先注册SIP账号');
		return;
	}
	
	// 从localStorage获取服务器地址
	var sipConfig = localStorage.getItem('sip_config');
	var serverHost = '192.168.2.200'; // 默认值
	if (sipConfig) {
		try {
			var config = JSON.parse(sipConfig);
			var match = config.uri.match(/@([^:]+)/);
			if (match) {
				serverHost = match[1];
			}
		} catch(e) {
			console.error('解析SIP配置失败:', e);
		}
	}
	
	var sipUri = 'sip:' + extension + '@' + serverHost;
	
	console.log('发起直接呼叫到:', sipUri);
	
	// 使用JsSIP客户端直接拨号
	dispatcherControl.sipClient.makeCall(sipUri, {
		audio: true,
		video: false
	}).then(function(result) {
		console.log('呼叫已发起，session ID:', result.sessionId);
		showDirectCallStatus(extension, result.sessionId);
	}).catch(function(error) {
		console.error('呼叫失败:', error);
		
		// 提供更详细的错误信息
		var errorMessage = error.message || '呼叫失败';
		
		if (errorMessage.includes('麦克风权限')) {
			errorMessage += '\n\n提示：点击地址栏左侧的麦克风图标，选择"允许"，然后重试。';
		}
		
		alert(errorMessage);
	});
}

// 显示直接呼叫状态
function showDirectCallStatus(extension, sessionId) {
	// 显示通话中状态
	var statusHtml = '<div id="direct-call-status-' + sessionId + '" class="call-status-bar">';
	statusHtml += '与 ' + extension + ' 通话中 ';
	statusHtml += '<button class="btn btn-sm btn-danger" onclick="hangupDirectCall(\'' + sessionId + '\')">挂断</button>';
	statusHtml += '</div>';
	$('body').append(statusHtml);
}

// 挂断直接呼叫
function hangupDirectCall(sessionId) {
	if (dispatcherControl && dispatcherControl.sipClient) {
		dispatcherControl.sipClient.hangup(sessionId).then(function() {
			// 确保状态栏被移除
			$('#direct-call-status-' + sessionId).remove();
			console.log('已挂断直接呼叫并清理状态:', sessionId);
		}).catch(function(error) {
			console.error('挂断直接呼叫失败:', error);
			// 即使挂断失败，也尝试清理状态栏
			$('#direct-call-status-' + sessionId).remove();
		});
	}
}

// 检查麦克风权限
function checkMicrophonePermission() {
	if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
		alert('您的浏览器不支持音频访问功能');
		return;
	}
	
	navigator.permissions.query({ name: 'microphone' })
		.then(function(permissionStatus) {
			if (permissionStatus.state === 'granted') {
				alert('麦克风权限已授予 ✓');
			} else if (permissionStatus.state === 'denied') {
				alert('麦克风权限被拒绝 ✗\n\n请按以下步骤操作：\n1. 点击地址栏左侧的锁或麦克风图标\n2. 在麦克风权限中选择"允许"\n3. 刷新页面');
			} else {
				alert('麦克风权限未设置，将在首次使用时提示');
			}
		})
		.catch(function(error) {
			// 浏览器不支持permissions API，尝试直接获取权限
			navigator.mediaDevices.getUserMedia({ audio: true })
				.then(function(stream) {
					stream.getTracks().forEach(function(track) { track.stop(); });
					alert('麦克风权限正常 ✓');
				})
				.catch(function(err) {
					alert('无法访问麦克风，请检查浏览器权限设置');
				});
		});
}

function testEmergencyAudio(){
    try{ if (dispatcherControl && dispatcherControl.sipClient && dispatcherControl.sipClient.unlockAudioPlayback){ dispatcherControl.sipClient.unlockAudioPlayback() } }catch(e){}
    try{ emergencyAudio.test() }catch(e){}
}

function toggleEmergencyMute(){
    try{
        emergencyAudio.setMuted(!emergencyAudio.muted)
        var btn=document.getElementById('audio-mute-btn');
        if(btn){ if(emergencyAudio.muted){ btn.style.color='#b71c1c' } else { btn.style.color='' } }
    }catch(e){}
}

// ============ 批量呼叫相关函数 ============

// 打开批量呼叫模态框
function openBatchCallModal() {
	$('#batch-call-modal').show();
	// 默认加载组呼列表
	$('#batch-call-type').val('group');
	updateBatchCallTargets();
}

// 关闭批量呼叫模态框
function closeBatchCallModal() {
	$('#batch-call-modal').hide();
}

// 更新批量呼叫目标列表
function updateBatchCallTargets() {
	var type = $('#batch-call-type').val();
	var callMode = (type === 'broadcast') ? 'all_call' : 'group_call';
	var select = $('#batch-call-target');
	var desc = $('#batch-call-desc');
	
	select.html('<option value="">加载中...</option>');
	desc.text('正在加载会议列表...');
	
	$.ajax({
		url: 'dispatcher_api.php?action=get_conferences&emergency_filter=false&call_mode_filter=' + callMode,
		type: 'GET',
		dataType: 'json',
		success: function(response) {
			select.empty();
			if (response.success && response.items && response.items.length > 0) {
				response.items.forEach(function(conf) {
					var label = conf.name + ' (' + conf.extension + ')';
					if (conf.participants && conf.participants.length > 0) {
						label += ' - ' + conf.participants.length + '人';
					}
					select.append(
						'<option value="' + conf.conference_uuid + '" ' +
						'data-participants="' + JSON.stringify(conf.participants).replace(/"/g, '&quot;') + '">' +
						label + '</option>'
					);
				});
				desc.text('请选择一个会议以发起呼叫');
			} else {
				select.append('<option value="">暂无会议</option>');
				desc.text('未找到该类型的会议配置');
			}
		},
		error: function() {
			select.html('<option value="">加载失败</option>');
			desc.text('加载会议列表失败');
		}
	});
}

// 发起批量呼叫
function initiateBatchCall() {
	var selectedOption = $('#batch-call-target option:selected');
	if (!selectedOption.val()) {
		DispatcherUtils.alert('请选择一个会议', 'warn');
		return;
	}
	
	try {
		var participantsJson = selectedOption.attr('data-participants');
		var targets = JSON.parse(participantsJson);
		
		if (!targets || targets.length === 0) {
			DispatcherUtils.alert('该会议没有配置参与分机', 'warn');
			return;
		}
		
		// 获取调度员分机号
		var operatorExt = $('#eavesdrop_dest').val();
		if (!operatorExt && dispatcherControl && dispatcherControl.config) {
			operatorExt = dispatcherControl.config.authUser;
		}
		
		// 记录原始数量
		var originalCount = targets.length;

		// [新增] 基于DOM的在线分机过滤
		// 获取页面上所有在线分机 (class="op_ext" 且没有 "ur_ext")
		var onlineExtensions = [];
		$('.op_ext').each(function() {
			// 排除未注册的分机 (如果有 ur_ext 类)
			if (!$(this).hasClass('ur_ext')) {
				var ext = $(this).attr('id');
				if (ext) onlineExtensions.push(String(ext));
			}
		});
		
		// 只保留在线的分机
		if (onlineExtensions.length > 0) {
			targets = targets.filter(function(ext) {
				return onlineExtensions.indexOf(String(ext)) !== -1;
			});
		} else {
			console.warn('[批量呼叫] ⚠️ 前端未检测到任何在线分机，跳过在线过滤(可能导致呼叫离线分机)');
		}
		
		// 过滤掉调度员自己的分机
		if (operatorExt) {
			// 统一转换为字符串进行比较
			var operatorExtStr = String(operatorExt);
			var countBeforeExclude = targets.length;
			
			targets = targets.filter(function(ext) {
				return String(ext) !== operatorExtStr;
			});
			
			if (targets.length < countBeforeExclude) {
				console.log('批量呼叫: 已排除调度员自身分机 ' + operatorExt);
			}
		}
		
		// 验证过滤后是否还有目标
		if (targets.length === 0) {
			DispatcherUtils.alert('过滤后没有可呼叫的分机', 'warn');
			return;
		}
		
		// 使用 dispatcherControl 发起呼叫
		dispatcherControl.startGroupCallWithExtensions(targets);
		
		closeBatchCallModal();
		$('#group-call-status').show();
		var typeText = ($('#batch-call-type').val() === 'broadcast') ? '全呼' : '组呼';
		$('#group-call-status-text').text(typeText + '进行中 (呼叫 ' + targets.length + ' 个分机)...');
		
	} catch (e) {
		console.error('解析会议参与者失败:', e);
		DispatcherUtils.alert('发起呼叫失败', 'error');
	}
}

// 已移除旧拖拽逻辑，减少全局事件监听与重排

// 切换面板显示
function toggleDispatcherPanel() {
	$('#dispatcher-control-panel').toggleClass('minimized');
	$('.dispatcher-body').slideToggle();
}

// 显示日志面板
function showLogsPanel() {
	if (dispatcherLogger) {
		dispatcherLogger.showLogsPanel();
	}
}

// 更新统计显示
function updateStatisticsDisplay() {
	if (dispatcherLogger) {
		var stats = dispatcherLogger.getStatistics();
		$('#dispatcher-total-calls').text(stats.totalCalls);
	}
}

// 记录通话结束
function logCallEnd(type, data) {
	if (dispatcherLogger) {
		dispatcherLogger.logCall(type, data);
		updateStatisticsDisplay();
	}
}

// 关闭日志面板
function closeLogsPanel() {
	$('#dispatcher-logs-modal').hide();
	$('.modal-overlay').remove();
}

// ============ 急呼相关函数 ============

// 打开急呼模态对话框
// 打开急呼模态对话框
function openEmergencyModal() {
	$('#emergency-modal').show();
	// 触发一次类型更新，加载对应的会议列表
	updateEmergencyTargets();
}

// 关闭急呼模态对话框
function closeEmergencyModal() {
	$('#emergency-modal').hide();
}

// 更新急呼目标选择界面
function updateEmergencyTargets() {
	var type = $('#emergency-type').val();
	$('#emergency-single-target').hide();
	$('#emergency-group-target').hide();
	$('#emergency-broadcast-confirm').hide();
	
	if (type === 'single') {
		$('#emergency-single-target').show();
	} else if (type === 'group') {
		$('#emergency-group-target').show();
		// 加载组呼类型的紧急会议 (call_mode = group_call)
		loadEmergencyConferences('group_call', '#emergency-group-select');
	} else if (type === 'broadcast') {
		$('#emergency-broadcast-confirm').show();
		// 加载全呼类型的紧急会议 (call_mode = all_call)
		loadEmergencyConferences('all_call', '#emergency-broadcast-select');
	}
}

// 辅助函数：加载紧急会议列表
function loadEmergencyConferences(callMode, selectId) {
	var select = $(selectId);
	select.html('<option value="">加载中...</option>');
	
	$.ajax({
		url: 'dispatcher_api.php?action=get_conferences&emergency_filter=true&call_mode_filter=' + callMode,
		type: 'GET',
		dataType: 'json',
		success: function(response) {
			select.empty();
			if (response.success && response.items && response.items.length > 0) {
				response.items.forEach(function(conf) {
					var label = conf.name + ' (' + conf.extension + ')';
					if (conf.participants && conf.participants.length > 0) {
						label += ' - ' + conf.participants.length + '人';
					}
					select.append(
						'<option value="' + conf.conference_uuid + '" ' +
						'data-participants="' + JSON.stringify(conf.participants).replace(/"/g, '&quot;') + '">' +
						label + '</option>'
					);
				});
			} else {
				select.append('<option value="">暂无会议</option>');
			}
		},
		error: function() {
			select.html('<option value="">加载失败</option>');
			console.error('加载紧急会议失败');
		}
	});
}

// 发起急呼
function initiateEmergencyCall() {
	var type = $('#emergency-type').val();
	var targets = [];
	var targetExt = '';
	
	if (type === 'single') {
		targetExt = $('#emergency-target-ext').val().trim();
        if (!targetExt) {
            DispatcherUtils.alert('请输入目标分机号', 'warn');
            return;
        }
		// 检查是否对自己发起急呼
		var operatorExt = $('#eavesdrop_dest').val() || (window.dispatcherControl && dispatcherControl.config && dispatcherControl.config.authUser);
		if (operatorExt && targetExt === operatorExt) {
			DispatcherUtils.alert('不能对自己发起急呼', 'warn');
			return;
		}
		targets = [targetExt];
	} else if (type === 'group') {
		// 从会议选项中获取参与者列表
		var selectedOption = $('#emergency-group-select option:selected');
		if (!selectedOption.val()) {
			DispatcherUtils.alert('请选择一个会议', 'warn');
			return;
		}
		try {
			var participantsJson = selectedOption.attr('data-participants');
			targets = JSON.parse(participantsJson);
			if (!targets || targets.length === 0) {
				DispatcherUtils.alert('该会议没有配置参与分机', 'warn');
				return;
			}
		} catch (e) {
			DispatcherUtils.alert('解析会议参与者失败', 'error');
			return;
		}
	} else if (type === 'broadcast') {
		// 从会议选项中获取参与者列表
		var selectedOption = $('#emergency-broadcast-select option:selected');
		if (!selectedOption.val()) {
			DispatcherUtils.alert('请选择一个会议', 'warn');
			return;
		}
		try {
			var participantsJson = selectedOption.attr('data-participants');
			targets = JSON.parse(participantsJson);
			if (!targets || targets.length === 0) {
				DispatcherUtils.alert('该会议没有配置参与分机', 'warn');
				return;
			}
		} catch (e) {
			DispatcherUtils.alert('解析会议参与者失败', 'error');
			return;
		}
	}
	
    if (targets.length === 0) {
        DispatcherUtils.alert('没有可用的目标', 'warn');
        return;
    }
	
	// 获取调度员分机
	var operatorExt = $('#eavesdrop_dest').val();
	var dispatcherExt = (window.dispatcherControl && dispatcherControl.config && dispatcherControl.config.authUser) ? dispatcherControl.config.authUser : operatorExt;
    if (!dispatcherExt) {
        DispatcherUtils.alert('未检测到调度终端分机', 'warn');
        return;
    }
	
	// 对于组呼/全呼，过滤掉调度员自己的分机
	if (type !== 'single' && dispatcherExt) {
		var originalCount = targets.length;

		// [新增] 基于DOM的在线分机过滤
		var onlineExtensions = [];
		$('.op_ext').each(function() {
			if (!$(this).hasClass('ur_ext')) {
				var ext = $(this).attr('id');
				if (ext) onlineExtensions.push(String(ext));
			}
		});
		
		// 只保留在线的分机
		if (onlineExtensions.length > 0) {
			targets = targets.filter(function(ext) {
				return onlineExtensions.indexOf(String(ext)) !== -1;
			});
		} else {
			console.warn('[急呼] ⚠️ 前端未检测到任何在线分机，跳过在线过滤');
		}
		
		// 过滤调度员自己
		var dispatcherExtStr = String(dispatcherExt);
		var countBeforeExclude = targets.length;
		
		targets = targets.filter(function(ext) {
			return String(ext) !== dispatcherExtStr;
		});
		
		if (targets.length < countBeforeExclude) {
			console.log('急呼: 已排除调度员自身分机 ' + dispatcherExt);
		}
		
		if (targets.length === 0) {
			DispatcherUtils.alert('过滤后没有可呼叫的分机', 'warn');
			return;
		}
	}
	
	closeEmergencyModal();
	
	// 显示急呼状态栏
	showEmergencyStatus(type, targets);
	
	$.post('dispatcher_api.php', {
		action: 'initiate_emergency_call',
		emergency_type: type,
		targets: targets
	}, function(response) {
		if (response.success) {
			var emergencyUuid = response.emergency_uuid;
			
			// 更新进度
			updateEmergencyProgress(10, '正在发起急呼...');
			
			// 逐个发起急呼：优先尝试前端 JsSIP originate，失败则回退到原有 originate 流程
			var useFrontendOriginate = (window.dispatcherControl && typeof window.dispatcherControl.startEmergencyCall === 'function');
			
			// 先将所有目标标记为响铃中
			targets.forEach(function(ext) {
				updateTargetStatus(ext, 'ringing');
			});
			
			if (useFrontendOriginate) {
				try {
					window.dispatcherControl.startEmergencyCall(type, targets, emergencyUuid);
				} catch (e) {
					console.error('startEmergencyCall 调用失败，将回退到原有 originate 流程:', e);
					useFrontendOriginate = false;
				}
			}
			
			if (!useFrontendOriginate) {
				// 回退：逐个通过 exec.php originate + bridge 方式发起
				targets.forEach(function(ext, index) {
					setTimeout(function() {
						// 更新目标状态为响铃中
						updateTargetStatus(ext, 'ringing');
						
						// 发起急呼
						initiateEmergencyToExtension(ext, dispatcherExt, emergencyUuid, function(success) {
							if (success) {
								updateTargetStatus(ext, 'answered');
							} else {
								updateTargetStatus(ext, 'failed');
							}
							
							// 更新总体进度
							var progress = calculateEmergencyProgress();
							var completed = Object.keys(window.emergencyTargets).filter(function(e) {
								return window.emergencyTargets[e] === 'answered' || window.emergencyTargets[e] === 'failed';
							}).length;
							
							updateEmergencyProgress(
								10 + (progress * 0.9), 
								`已完成 ${completed}/${targets.length} 个急呼`
							);
							
							// 如果所有急呼都完成，3秒后隐藏状态栏
							if (progress === 100) {
								setTimeout(function() {
									hideEmergencyStatus();
								}, 3000);
							}
						});
					}, index * 500); // 每个呼叫间隔500ms
				});
			}
	    } else {
	    	DispatcherUtils.alert('发起急呼失败: ' + (response.error || '未知错误'), 'error');
	    	hideEmergencyStatus();
	    }
	}, 'json');
}

// 对单个分机发起急呼
function initiateEmergencyToExtension(targetExt, operatorExt, emergencyUuid, callback) {
	// 使用FreeSWITCH的originate命令发起呼叫，让被叫用户自动接听
	var url = 'exec.php?cmd=originate&source=' + operatorExt + '&destination=' + targetExt + '&emergency=true';
	
	// 添加调试日志
	console.log('Initiating emergency call:', {
		target: targetExt,
		operator: operatorExt,
		uuid: emergencyUuid,
		url: url
	});
	
	send_cmd(url, function(response, status) {
		console.log('Emergency call to ' + targetExt + ':', response);
		console.log('Response status:', status);
		
		// 记录急呼状态
		if (response.indexOf('-ERR') === -1) {
			// 解析Job-UUID
			var jobUuidMatch = response.match(/Job-UUID:\s*([a-f0-9\-]+)/i);
			var jobUuid = jobUuidMatch ? jobUuidMatch[1] : '';
			
			console.log('Parsed Job-UUID:', jobUuid);
			
			if (!jobUuid) {
				console.error('Failed to parse Job-UUID from response:', response);
				if (typeof callback === 'function') {
					callback(false);
				}
				return;
			}
			
			$.ajax({
				url: 'dispatcher_api.php',
				type: 'POST',
				data: {
					action: 'log_emergency_call',
					emergency_uuid: emergencyUuid,
					call_uuid: jobUuid,
					status: 'initiated'
				},
				success: function(logResponse) {
					console.log('Log initiated response:', logResponse);
				},
				error: function(xhr, status, error) {
					console.error('Failed to log initiated status:', error);
				}
			});
			
			// 由于现在直接桥接，不需要等待被叫接听后再连接调度员
			// 直接标记为已发起，等待FreeSWITCH完成桥接
			$.ajax({
				url: 'dispatcher_api.php',
				type: 'POST',
				data: {
					action: 'log_emergency_call',
					emergency_uuid: emergencyUuid,
					call_uuid: jobUuid,
					status: 'ringing'
				},
				success: function(logResponse) {
					console.log('Log ringing response:', logResponse);
				},
				error: function(xhr, status, error) {
					console.error('Failed to log ringing status:', error);
				}
			});
			
			// 改进状态检查，增加延迟重试机制
			var checkCount = 0;
			var maxChecks = 15; // 增加最大检查次数（15秒）
			var initialDelay = 500; // 初始延迟500毫秒
			var currentDelay = initialDelay;
			
			var checkInterval = setInterval(function() {
				checkCount++;
				console.log('Checking call status, attempt:', checkCount, 'delay:', currentDelay + 'ms');
				
				// 检查通话状态
				$.ajax({
					url: 'exec.php',
					type: 'POST',
					data: {
						cmd: 'get_channel_uuid',
						destination: targetExt
					},
					success: function(response) {
						console.log('Channel UUID response:', response);
						
						// 处理空响应或无效响应
						if (!response || response.trim() === '' || response.trim() === 'false') {
							console.warn('Channel UUID not found for:', targetExt, 'attempt:', checkCount);
							response = 'false';
						}
						
						if (response !== 'false') {
							// 找到通话UUID，表示通话已建立
							console.log('Call established for:', targetExt, 'UUID:', response);
							clearInterval(checkInterval);
							
							// 对于急呼，需要桥接到调度员
							$.ajax({
								url: 'dispatcher_api.php',
								type: 'POST',
								data: {
									action: 'bridge_emergency_call',
									destination: targetExt,
									target_channel_uuid: response
								},
								success: function(bridgeResponse) {
									console.log('Bridge response:', bridgeResponse);
									
									if (bridgeResponse.success) {
										// 桥接成功
										$.ajax({
											url: 'dispatcher_api.php',
											type: 'POST',
											data: {
												action: 'log_emergency_call',
												emergency_uuid: emergencyUuid,
												call_uuid: bridgeResponse.channel_uuid,
												status: 'answered'
											},
											success: function(logResponse) {
												console.log('Log answered response:', logResponse);
											},
											error: function(xhr, status, error) {
												console.error('Failed to log answered status:', error);
											}
										});
										
										// 更新前端状态显示
										if (typeof window.updateEmergencyCallStatus === 'function') {
											window.updateEmergencyCallStatus(targetExt, 'answered');
										}
										
										// 调用回调函数
										if (typeof callback === 'function') {
											callback(true);
										}
									} else {
										// 桥接失败
										console.error('Bridge failed:', bridgeResponse.error);
										$.ajax({
											url: 'dispatcher_api.php',
											type: 'POST',
											data: {
												action: 'log_emergency_call',
												emergency_uuid: emergencyUuid,
												call_uuid: jobUuid,
												status: 'failed'
											},
											success: function(logResponse) {
												console.log('Log failed response:', logResponse);
											},
											error: function(xhr, status, error) {
												console.error('Failed to log failed status:', error);
											}
										});
										
										// 更新前端状态显示
										if (typeof window.updateEmergencyCallStatus === 'function') {
											window.updateEmergencyCallStatus(targetExt, 'failed');
										}
										
										// 调用回调函数
										if (typeof callback === 'function') {
											callback(false);
										}
									}
								},
								error: function(xhr, status, error) {
									console.error('Error bridging call:', error);
									// 桥接请求失败
									$.ajax({
										url: 'dispatcher_api.php',
										type: 'POST',
										data: {
											action: 'log_emergency_call',
											emergency_uuid: emergencyUuid,
											call_uuid: jobUuid,
											status: 'failed'
										},
										success: function(logResponse) {
											console.log('Log failed response:', logResponse);
										},
										error: function(xhr, status, error) {
											console.error('Failed to log failed status:', error);
										}
									});
									
									// 更新前端状态显示
									if (typeof window.updateEmergencyCallStatus === 'function') {
										window.updateEmergencyCallStatus(targetExt, 'failed');
									}
									
									// 调用回调函数
									if (typeof callback === 'function') {
										callback(false);
									}
								}
							});
						} else if (checkCount >= maxChecks) {
							// 通话未找到或超时
							console.log('Call not found or timeout for:', targetExt, 'attempts:', checkCount);
							clearInterval(checkInterval);
							
							$.ajax({
								url: 'dispatcher_api.php',
								type: 'POST',
								data: {
									action: 'log_emergency_call',
									emergency_uuid: emergencyUuid,
									call_uuid: jobUuid,
									status: 'failed'
								},
								success: function(logResponse) {
									console.log('Log failed response:', logResponse);
								},
								error: function(xhr, status, error) {
									console.error('Failed to log failed status:', error);
								}
							});
							
							// 更新前端状态显示
							if (typeof window.updateEmergencyCallStatus === 'function') {
								window.updateEmergencyCallStatus(targetExt, 'failed');
							}
							
							// 调用回调函数
							if (typeof callback === 'function') {
								callback(false);
							}
						} else {
							// 通道未找到，但未达到最大检查次数，增加延迟时间
							currentDelay = Math.min(currentDelay * 1.2, 2000); // 每次增加20%，最大2秒
							clearInterval(checkInterval);
							checkInterval = setInterval(arguments.callee, currentDelay);
						}
					},
					error: function(xhr, status, error) {
						console.error('Error checking channel UUID:', error);
						// 网络错误时也增加延迟时间
						currentDelay = Math.min(currentDelay * 1.2, 2000);
						clearInterval(checkInterval);
						checkInterval = setInterval(arguments.callee, currentDelay);
					}
				});
			}, currentDelay);
		} else {
			console.error('Emergency call failed with error:', response);
			
			$.ajax({
				url: 'dispatcher_api.php',
				type: 'POST',
				data: {
					action: 'log_emergency_call',
					emergency_uuid: emergencyUuid,
					status: 'failed'
				},
				success: function(logResponse) {
					console.log('Log failed response:', logResponse);
				},
				error: function(xhr, status, error) {
					console.error('Failed to log failed status:', error);
				}
			});
			
			// 调用回调函数
			if (typeof callback === 'function') {
				callback(false);
			}
		}
	})
}

// 显示急呼状态栏
function showEmergencyStatus(type, targets) {
	// 移除已存在的状态栏
	var existingStatus = document.getElementById('emergency-status');
	if (existingStatus) {
		existingStatus.remove();
	}
	
	// 创建状态栏HTML
	var statusHtml = '<div id="emergency-status" class="emergency-status-bar">';
	statusHtml += '<div class="emergency-header">';
	statusHtml += '<i class="fas fa-exclamation-triangle"></i> ';
	statusHtml += '<span class="status-title">急呼进行中</span>';
	statusHtml += '<button class="emergency-close-btn" onclick="hideEmergencyStatus()">×</button>';
	statusHtml += '</div>';
	
	// 添加目标分机列表
	statusHtml += '<div class="emergency-targets">';
	statusHtml += '<div class="target-label">目标分机：</div>';
	statusHtml += '<div class="target-list">';
	
	// 初始化目标状态
	window.emergencyTargets = {};
	
	if (Array.isArray(targets)) {
		targets.forEach(function(ext) {
			statusHtml += '<div class="target-item" id="emergency-target-' + ext + '">';
			statusHtml += '<span class="target-extension">' + ext + '</span>';
			statusHtml += '<span class="target-status pending">等待中</span>';
			statusHtml += '</div>';
			window.emergencyTargets[ext] = 'pending';
		});
	} else if (typeof targets === 'string') {
		statusHtml += '<div class="target-item" id="emergency-target-' + targets + '">';
		statusHtml += '<span class="target-extension">' + targets + '</span>';
		statusHtml += '<span class="target-status pending">等待中</span>';
		statusHtml += '</div>';
		window.emergencyTargets[targets] = 'pending';
	}
	
	statusHtml += '</div>';
	statusHtml += '</div>';
	
	// 添加进度指示器
	statusHtml += '<div class="emergency-progress">';
	statusHtml += '<div class="progress-bar">';
	statusHtml += '<div class="progress-fill" id="emergency-progress-fill"></div>';
	statusHtml += '</div>';
	statusHtml += '<div class="progress-text" id="emergency-progress-text">准备发起急呼...</div>';
	statusHtml += '</div>';
	
	statusHtml += '</div>';
	
	// 添加到页面
	$('body').prepend(statusHtml);
	
	// 初始化进度
	updateEmergencyProgress(0, '准备发起急呼...');
}

// 结束急呼
function endEmergencyCall() {
	if (confirm('确认结束急呼？')) {
		$('#emergency-status').remove();
		// TODO: 挂断所有急呼通话
	}
}

// 启用急呼按钮（在SIP注册成功后调用）
function enableEmergencyFunction() {
	$('#emergency-btn').prop('disabled', false);
}

// 更新急呼状态显示
function updateEmergencyCallStatus(extension, status) {
	console.log('Emergency call status update:', extension, status);
	
	// 更新目标分机状态
	updateTargetStatus(extension, status);
	
	// 更新总体进度
	var progress = calculateEmergencyProgress();
	var completed = Object.keys(window.emergencyTargets).filter(function(e) {
		return window.emergencyTargets[e] === 'answered' || window.emergencyTargets[e] === 'failed';
	}).length;
	var total = Object.keys(window.emergencyTargets).length;
	
	updateEmergencyProgress(
		10 + (progress * 0.9), 
		`已完成 ${completed}/${total} 个急呼`
	);
	
	// 如果所有急呼都完成，3秒后隐藏状态栏
	if (progress === 100) {
		setTimeout(function() {
			hideEmergencyStatus();
		}, 3000);
	}
}

// 隐藏急呼状态栏
function hideEmergencyStatus() {
	var statusElement = document.getElementById('emergency-status');
	if (statusElement) {
		statusElement.style.display = 'none';
	}
}

// 更新急呼进度
function updateEmergencyProgress(percentage, text) {
	var progressFill = document.getElementById('emergency-progress-fill');
	var progressText = document.getElementById('emergency-progress-text');
	
	if (progressFill) {
		progressFill.style.width = percentage + '%';
	}
	
	if (progressText) {
		progressText.textContent = text;
	}
}

// 更新目标分机状态
function updateTargetStatus(extension, status) {
	var targetElement = document.getElementById('emergency-target-' + extension);
	if (targetElement) {
		var statusElement = targetElement.querySelector('.target-status');
		if (statusElement) {
			// 移除所有状态类
			statusElement.classList.remove('pending', 'ringing', 'answered', 'failed');
			
			// 添加新状态类和文本
			statusElement.classList.add(status);
			switch (status) {
				case 'pending':
					statusElement.textContent = '等待中';
					break;
				case 'ringing':
					statusElement.textContent = '响铃中';
					break;
				case 'answered':
					statusElement.textContent = '已接听';
					break;
				case 'failed':
					statusElement.textContent = '失败';
					break;
			}
		}
	}
	
	// 更新全局状态
	if (window.emergencyTargets) {
		window.emergencyTargets[extension] = status;
	}
}

// 计算急呼总体进度
function calculateEmergencyProgress() {
	if (!window.emergencyTargets) return 0;
	
	var targets = Object.keys(window.emergencyTargets);
	if (targets.length === 0) return 0;
	
	var completed = 0;
	targets.forEach(function(ext) {
		var status = window.emergencyTargets[ext];
		if (status === 'answered' || status === 'failed') {
			completed++;
		}
	});
	
	return Math.round((completed / targets.length) * 100);
}

// 诊断与验证
function __dispatcherDiagnostics(){
    try{
        var report = (dispatcherControl && dispatcherControl.sipClient && dispatcherControl.sipClient.getResourceReport) ? dispatcherControl.sipClient.getResourceReport() : {}
        console.log('[Diagnostics] ResourceReport:', report)
        console.log('[Diagnostics] Utils:', !!window.DispatcherUtils)
        console.log('[Diagnostics] EmergencyAudio:', !!window.EmergencyAudioService)
        return report
    }catch(e){ console.error(e); return {} }
}
</script>

<?php
echo "<br><br>\n";

//include the footer
	require_once "resources/footer.php";

?>
<script type="text/javascript">
    var heartbeat_timer_id;
    function resourceHeartbeat(){
        try{
            if (!window.DispatcherUtils || !DispatcherUtils.ResourceManager) { return; }
            var snap = DispatcherUtils.ResourceManager.verifyReleased()
            if (snap.modals > 0 || snap.overlays > 0) { DispatcherUtils.ResourceManager.destroyUiPopups() }
            if (snap.audioNodes > 0 && snap.emergencyAlerts === 0 && snap.activeSessions === 0) { DispatcherUtils.ResourceManager.destroyUiPopups() }
            try{ if (window.$ && $.get) { $.get('dispatcher_api.php', { action:'heartbeat', active:snap.activeSessions, alerts:snap.emergencyAlerts }) } }catch(e){}
        }catch(e){}
    }
    function resourceHeartbeat_start(){ try{ clearInterval(heartbeat_timer_id) }catch(e){}; heartbeat_timer_id = setInterval(resourceHeartbeat, 30000) }
    function resourceHeartbeat_stop(){ try{ clearInterval(heartbeat_timer_id) }catch(e){} }

    // Mute/Unmute active call line
    function mute_call(uuid, action) {
        // action: 'mute' or 'unmute'
        // direction: 'read' (mute the mic)
        var url = 'exec.php?cmd=uuid_audio&uuid=' + uuid + '&action=' + action + '&direction=read';
        $.ajax({
            url: url,
            type: 'GET',
            success: function(response) {
                console.log('Mute/Unmute response:', response);
            }
        });
    }
</script>
