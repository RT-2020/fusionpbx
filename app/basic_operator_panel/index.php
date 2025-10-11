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
<script language="JavaScript" type="text/javascript" src="<?php echo PROJECT_PATH; ?>/app/basic_operator_panel/resources/jssip-client.js"></script>
<script language="JavaScript" type="text/javascript" src="<?php echo PROJECT_PATH; ?>/app/basic_operator_panel/resources/dispatcher-control.js"></script>
<script language="JavaScript" type="text/javascript" src="<?php echo PROJECT_PATH; ?>/app/basic_operator_panel/resources/dispatcher-logger.js"></script>
<script type="text/javascript">

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

		if (this.xmlHttp.readyState == 4 && (this.xmlHttp.status == 200 || !/^http/.test(window.location.href)))
			//this.el.innerHTML = this.xmlHttp.responseText;
			document.getElementById('ajax_response').innerHTML = this.xmlHttp.responseText;
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
	}
	else if (window.attachEvent) {
		window.attachEvent('onload', requestTime);
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
		interval_timer_id = setInterval( function() {
			url = source_url;
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
		}, refresh);
	}

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

//used by call control and ajax refresh functions
	function send_cmd(url) {
		if (window.XMLHttpRequest) {// code for IE7+, Firefox, Chrome, Opera, Safari
			xmlhttp=new XMLHttpRequest();
		}
		else {// code for IE6, IE5
			xmlhttp=new ActiveXObject("Microsoft.XMLHTTP");
		}
		xmlhttp.open("GET",url,false);
		xmlhttp.send(null);
		document.getElementById('cmd_response').innerHTML=xmlhttp.responseText;
	}

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

	function get_transfer_cmd(uuid, destination) {
		url = "exec.php?cmd=uuid_transfer&uuid=" + uuid + "&destination=" + destination
		return url;
	}

	function get_originate_cmd(source, destination) {
		url = "exec.php?cmd=originate&source=" + source + "&destination=" + destination
		return url;
	}

	function get_eavesdrop_cmd(ext, chan_uuid, destination) {
		url = "exec.php?cmd=uuid_eavesdrop&ext=" + ext + "&chan_uuid=" + chan_uuid + "&destination=" + destination;
		return url;
	}

	function get_record_cmd(uuid) {
		url = "exec.php?cmd=uuid_record&uuid=" + uuid;
		return url;
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
</style>

<?php

//create simple array of users own extensions
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
					<button id="group-call-btn" class="dispatcher-tool-btn" title="组呼" disabled onclick="openGroupCallModal()">
						<i class="fas fa-users"></i>
					</button>
					<button id="broadcast-btn" class="dispatcher-tool-btn" title="全呼" disabled onclick="startBroadcastCall()">
						<i class="fas fa-bullhorn"></i>
					</button>
					<button id="conference-btn" class="dispatcher-tool-btn" title="多方会议" disabled onclick="openConferenceModal()">
						<i class="fas fa-video"></i>
					</button>
					<button id="trunk-mode-btn" class="dispatcher-tool-btn" title="中继模式" onclick="toggleTrunkMode()">
						<i class="fas fa-exchange-alt"></i>
					</button>
					<button id="manage-groups-btn" class="dispatcher-tool-btn" title="分组管理" onclick="openGroupsModal()">
						<i class="fas fa-cog"></i>
					</button>
				</div>
			</td>
		</tr>
	</table>
</div>

<?php

echo "<div id='ajax_response'></div>\n";
echo "<div id='cmd_response' style='display: none;'></div>\n";

// 调度功能模态对话框
?>

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
				<label>WebSocket URL:</label>
				<input type="text" id="sip-ws" class="form-control" placeholder="<?php echo (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'wss://192.168.2.200:7443' : 'ws://192.168.2.200:5066'; ?>" />
			</div>
			<div class="form-group">
				<label>SIP URI:</label>
				<input type="text" id="sip-uri" class="form-control" placeholder="sip:1000@192.168.2.200" />
			</div>
			<div class="form-group">
				<label>用户名:</label>
				<input type="text" id="sip-user" class="form-control" placeholder="1000" />
			</div>
			<div class="form-group">
				<label>密码:</label>
				<input type="password" id="sip-password" class="form-control" placeholder="密码" />
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
		</div>
		<div class="modal-footer">
			<button class="btn btn-success" onclick="startGroupCallWithSelected()">呼叫选中的分机</button>
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

<!-- 分组管理模态对话框 -->
<div id="groups-modal" class="dispatcher-modal" style="display: none;">
	<div class="modal-overlay" onclick="closeGroupsModal()"></div>
	<div class="modal-content">
		<div class="modal-header">
			<h3>管理呼叫分组</h3>
			<button class="modal-close-btn" onclick="closeGroupsModal()">×</button>
		</div>
		<div class="modal-body">
			<div id="groups-list">
				<!-- 分组列表 -->
			</div>
		</div>
		<div class="modal-footer">
			<button class="btn btn-primary" onclick="addNewGroup()">新建分组</button>
			<button class="btn btn-secondary" onclick="closeGroupsModal()">关闭</button>
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

$(document).ready(function() {
	// 初始化调度控制
	dispatcherControl = new DispatcherControl();
	dispatcherLogger = new DispatcherLogger();
	
	// 从localStorage恢复SIP注册状态
	restoreSipStatus();
	
	// 加载分组列表
	updateGroupsList();
	
	// 监听SIP状态更新
	dispatcherControl.on('registered', function() {
		updateSipStatus('online', '已注册');
		enableDispatcherFunctions();
		saveSipStatus('online', '已注册'); // 保存状态
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
				updateSipStatus(data.status, data.text);
				// 检查SIP客户端实际状态
				if (dispatcherControl.sipClient && dispatcherControl.sipClient.isRegistered) {
					enableDispatcherFunctions();
				}
			}
		} catch (e) {
			console.error('恢复SIP状态失败:', e);
		}
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
		uri: $('#sip-uri').val(),
		wsServers: $('#sip-ws').val(),
		authUser: $('#sip-user').val(),
		password: $('#sip-password').val(),
		displayName: $('#sip-display-name').val()
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
			alert('注册失败: ' + error.message);
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
	$('#group-call-btn, #broadcast-btn, #conference-btn').prop('disabled', false);
}

// 禁用调度功能按钮
function disableDispatcherFunctions() {
	$('#group-call-btn, #broadcast-btn, #conference-btn').prop('disabled', true);
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
	
	if (selectedExts.length === 0) {
		alert('请至少选择一个分机');
		return;
	}
	
	// 使用选中的分机发起组呼
	dispatcherControl.startGroupCallWithExtensions(selectedExts);
	closeGroupCallModal();
	
	// 显示组呼状态栏
	$('#group-call-status').show();
	$('#group-call-status-text').text('组呼进行中 - 呼叫 ' + selectedExts.length + ' 个分机');
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
	updateConferenceManagePanel();
	$('#conference-manage-modal').show();
}

// 关闭会议管理面板
function closeConferenceManageModal() {
	$('#conference-manage-modal').hide();
}

// 更新会议管理面板
function updateConferenceManagePanel() {
	var participants = dispatcherControl.conferenceParticipants;
	var list = $('#conference-participants-list');
	list.empty();
	
	$('#conference-manage-count').text(participants.length);
	
	if (participants.length === 0) {
		list.html('<p style="text-align: center; color: #999; padding: 20px;">暂无参与者</p>');
		return;
	}
	
	participants.forEach(function(p) {
		var item = $('<div class="participant-item"></div>');
		var info = $('<div class="participant-info"></div>');
		info.html('<strong>' + p.extension + '</strong><br><small>状态: ' + (p.muted ? '已静音' : '正常') + '</small>');
		
		var actions = $('<div class="participant-actions"></div>');
		
		if (p.muted) {
			actions.append('<button class="btn btn-sm btn-success" onclick="unmuteParticipant(\'' + p.extension + '\')">取消静音</button>');
		} else {
			actions.append('<button class="btn btn-sm btn-warning" onclick="muteParticipant(\'' + p.extension + '\')">静音</button>');
		}
		
		actions.append('<button class="btn btn-sm btn-danger" onclick="kickParticipant(\'' + p.extension + '\')">踢出</button>');
		
		item.append(info).append(actions);
		list.append(item);
	});
	
	// 更新会议时长
	if (dispatcherControl.conferenceStartTime) {
		var duration = Math.floor((Date.now() - dispatcherControl.conferenceStartTime) / 1000);
		var minutes = Math.floor(duration / 60);
		var seconds = duration % 60;
		$('#conference-manage-duration').text(minutes + ':' + (seconds < 10 ? '0' : '') + seconds);
	}
}

// 静音参与者
function muteParticipant(extension) {
	dispatcherControl.muteConferenceParticipant(extension);
	updateConferenceManagePanel();
}

// 取消静音参与者
function unmuteParticipant(extension) {
	dispatcherControl.unmuteConferenceParticipant(extension);
	updateConferenceManagePanel();
}

// 踢出参与者
function kickParticipant(extension) {
	if (confirm('确认踢出参与者 ' + extension + '？')) {
		dispatcherControl.kickConferenceParticipant(extension);
		updateConferenceManagePanel();
	}
}

// 全部静音
function muteAllParticipants() {
	dispatcherControl.muteAllConferenceParticipants();
	updateConferenceManagePanel();
}

// 全部取消静音
function unmuteAllParticipants() {
	dispatcherControl.unmuteAllConferenceParticipants();
	updateConferenceManagePanel();
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

// 打开分组管理模态对话框
function openGroupsModal() {
	updateGroupsList();
	$('#groups-modal').show();
}

// 关闭分组管理模态对话框
function closeGroupsModal() {
	$('#groups-modal').hide();
}

// 更新分组列表
function updateGroupsList() {
	var groups = dispatcherControl.callGroups;
	var html = '';
	
	for (var groupId in groups) {
		if (groups.hasOwnProperty(groupId)) {
			var group = groups[groupId];
			html += '<div class="group-item">' +
				'<div class="group-item-header">' +
				'<span class="group-item-name">' + group.name + ' (' + groupId + ')</span>' +
				'<div class="group-item-actions">' +
				'<button class="btn btn-sm btn-danger" onclick="deleteGroup(\'' + groupId + '\')">删除</button>' +
				'</div>' +
				'</div>' +
				'<div class="group-extensions">成员: ' + (group.extensions.length > 0 ? group.extensions.join(', ') : '无') + '</div>' +
				'<div style="margin-top: 8px;">' +
				'<button class="btn btn-sm btn-primary" onclick="addExtensionToGroupDialog(\'' + groupId + '\')">添加成员</button> ' +
				'<button class="btn btn-sm btn-success" onclick="startGroupCallById(\'' + groupId + '\')">发起组呼</button>' +
				'</div>' +
				'</div>';
		}
	}
	
	if (html === '') {
		html = '<p style="text-align: center; color: #999; padding: 20px;">暂无分组，请点击下方按钮创建</p>';
	}
	
	$('#groups-list').html(html);
}

// 添加新分组
function addNewGroup() {
	var groupId = prompt('请输入组ID（英文字母、数字）:');
	if (!groupId) return;
	
	if (dispatcherControl.callGroups[groupId]) {
		alert('该组已存在');
		return;
	}
	
	var groupName = prompt('请输入组名称:');
	if (!groupName) return;
	
	dispatcherControl.addCallGroup(groupId, groupName);
	updateGroupsList();
}

// 添加成员到组
function addExtensionToGroupDialog(groupId) {
	var extension = prompt('请输入要添加的分机号:');
	if (extension) {
		dispatcherControl.addExtensionToGroup(groupId, extension.trim());
		updateGroupsList();
		alert('成员已添加');
	}
}

// 删除分组
function deleteGroup(groupId) {
	if (confirm('确认删除该分组？')) {
		dispatcherControl.deleteCallGroup(groupId);
		updateGroupsList();
	}
}

// 按组ID发起组呼
function startGroupCallById(groupId) {
	dispatcherControl.startGroupCall(groupId);
	closeGroupsModal();
	$('#group-call-status').show();
	$('#group-call-status-text').text('组呼进行中...');
}

// 使面板可拖拽（已废弃，保留以防需要）
function makeDispatcherDraggable() {
	var panel = document.getElementById('dispatcher-control-panel');
	if (!panel) return; // 如果面板不存在则返回
	var header = panel.querySelector('.dispatcher-header');
	var isDragging = false;
	var currentX;
	var currentY;
	var initialX;
	var initialY;
	var xOffset = 0;
	var yOffset = 0;

	header.addEventListener('mousedown', dragStart);
	document.addEventListener('mousemove', drag);
	document.addEventListener('mouseup', dragEnd);

	function dragStart(e) {
		initialX = e.clientX - xOffset;
		initialY = e.clientY - yOffset;

		if (e.target === header || header.contains(e.target)) {
			isDragging = true;
			header.classList.add('dragging');
		}
	}

	function drag(e) {
		if (isDragging) {
			e.preventDefault();
			currentX = e.clientX - initialX;
			currentY = e.clientY - initialY;

			xOffset = currentX;
			yOffset = currentY;

			setTranslate(currentX, currentY, panel);
		}
	}

	function dragEnd(e) {
		initialX = currentX;
		initialY = currentY;
		isDragging = false;
		header.classList.remove('dragging');
	}

	function setTranslate(xPos, yPos, el) {
		el.style.transform = 'translate3d(' + xPos + 'px, ' + yPos + 'px, 0)';
	}
}

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
</script>

<?php
echo "<br><br>\n";

//include the footer
	require_once "resources/footer.php";

?>