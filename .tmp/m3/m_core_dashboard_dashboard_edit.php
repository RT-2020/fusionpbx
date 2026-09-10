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
	Portions created by the Initial Developer are Copyright (C) 2021-2025
	the Initial Developer. All Rights Reserved.
*/

//includes files
	require_once dirname(__DIR__, 2) . "/resources/require.php";
	require_once "resources/check_auth.php";

//check permissions
	if (!(permission_exists('dashboard_add') || permission_exists('dashboard_edit'))) {
		echo "access denied";
		exit;
	}

//add multi-lingual support
	$language = new text;
	$text = $language->get();

//set the defaults
	$domain_uuid = '';
	$dashboard_uuid = '';
	$dashboard_name = '';
	$dashboard_description = '';

//action add or update
	if (!empty($_REQUEST["id"]) && is_uuid($_REQUEST["id"])) {
		$action = "update";
		$dashboard_uuid = $_REQUEST["id"];
		$id = $_REQUEST["id"];
	}
	else {
		$action = "add";
		$domain_uuid = $_SESSION['domain_uuid'];
	}

//get http post variables and set them to php variables
	if (!empty($_POST)) {
		$domain_uuid = permission_exists('dashboard_domain') ? $_POST["domain_uuid"] : $_SESSION['domain_uuid'];
		$dashboard_name = $_POST["dashboard_name"] ?? '';
		$dashboard_enabled = $_POST["dashboard_enabled"];
		$dashboard_description = $_POST["dashboard_description"] ?? '';

		//define the regex patterns
		$uuid_pattern = '/[^-A-Fa-f0-9]/';
		$number_pattern = '/[^-A-Za-z0-9()*#]/';
		$text_pattern = '/[^a-zA-Z0-9 _\-\/.\?:\=#\n]/';

		//sanitize the data
		$domain_uuid = preg_replace($uuid_pattern, '', $domain_uuid);
		$dashboard_name = trim($dashboard_name);
		$dashboard_enabled = preg_replace($text_pattern, '', $dashboard_enabled);
		$dashboard_description = preg_replace($text_pattern, '', $dashboard_description);
	}

//process the user data and save it to the database
	if (count($_POST) > 0 && empty($_POST["persistformvar"])) {
		//validate the token
			$token = new token;
			if (!$token->validate($_SERVER['PHP_SELF'])) {
				message::add($text['message-invalid_token'],'negative');
				header('Location: dashboard.php');
				exit;
			}

		//check for all required data
			$msg = '';
			//if (empty($dashboard_name)) { $msg .= $text['message-required']." ".$text['label-dashboard_name']."<br>\n"; }
			//if (empty($dashboard_enabled)) { $msg .= $text['message-required']." ".$text['label-dashboard_enabled']."<br>\n"; }
			//if (empty($dashboard_description)) { $msg .= $text['message-required']." ".$text['label-dashboard_description']."<br>\n"; }
			if (!empty($msg) && empty($_POST["persistformvar"])) {
				require_once "resources/header.php";
				require_once "resources/persist_form_var.php";
				echo "<div align='center'>\n";
				echo "<table><tr><td>\n";
				echo $msg."<br />\n";
				echo "</td></tr></table>\n";
				persistformvar($_POST);
				echo "</div>\n";
				require_once "resources/footer.php";
				return;
			}

		//add the dashboard_uuid
			if (!is_uuid($_POST["dashboard_uuid"])) {
				$dashboard_uuid = uuid();
			}

		//prepare the array
			$array['dashboards'][0]['domain_uuid'] = $domain_uuid;
			$array['dashboards'][0]['dashboard_uuid'] = $dashboard_uuid;
			$array['dashboards'][0]['dashboard_name'] = $dashboard_name;
			$array['dashboards'][0]['dashboard_enabled'] = $dashboard_enabled;
			$array['dashboards'][0]['dashboard_description'] = $dashboard_description;

		//save the data
			$result = $database->save($array);

		//redirect the user
			if (isset($action)) {
				if ($action == "add") {
					$_SESSION["message"] = $text['message-add'];
				}
				if ($action == "update") {
					$_SESSION["message"] = $text['message-update'];
				}
				//header('Location: dashboard.php');
				header('Location: dashboard_edit.php?id='.urlencode($dashboard_uuid));
				return;
			}
	}

//pre-populate the form
	if (empty($_POST["persistformvar"])) {
		$sql = "select ";
		$sql .= " domain_uuid, ";
		$sql .= " dashboard_uuid, ";
		$sql .= " dashboard_name, ";
		$sql .= " dashboard_enabled, ";
		$sql .= " dashboard_description ";
		$sql .= "from v_dashboards ";
		$sql .= "where dashboard_uuid = :dashboard_uuid ";
		$parameters['dashboard_uuid'] = $dashboard_uuid;
		$row = $database->select($sql, $parameters ?? null, 'row');
		if (is_array($row) && @sizeof($row) != 0) {
			$domain_uuid = $row["domain_uuid"];
			$dashboard_name = $row["dashboard_name"];
			$dashboard_enabled = $row["dashboard_enabled"];
			$dashboard_description = $row["dashboard_description"];
		}
		unset($sql, $parameters, $row);
	}

//set the defaults
	$dashboard_enabled = $dashboard_enabled ?? true;

//create token
	$object = new token;
	$token = $object->create($_SERVER['PHP_SELF']);

//show the header
	$document['title'] = $text['title-dashboard'];
	require_once "resources/header.php";

//show the content
	echo "<form method='post' name='frm' id='frm'>\n";
	echo "<input class='formfld' type='hidden' name='dashboard_uuid' value='".escape($dashboard_uuid)."'>\n";

	echo "<div class='action_bar' id='action_bar'>\n";
	echo "	<div class='heading'><b>".$text['title-dashboard']."</b></div>\n";
	echo "	<div class='actions'>\n";
	echo button::create(['type'=>'button','label'=>$text['button-back'],'icon'=>$settings->get('theme', 'button_icon_back'),'id'=>'btn_back','collapse'=>'hide-xs','style'=>'margin-right: 15px;','link'=>'dashboard.php']);

	echo button::create(['type'=>'submit','label'=>$text['button-save'],'icon'=>$settings->get('theme', 'button_icon_save'),'id'=>'btn_save','collapse'=>'hide-xs']);
	echo "	</div>\n";
	echo "	<div style='clear: both;'></div>\n";
	echo "</div>\n";
	//echo $text['title_description-dashboard']."\n";
	//echo "<br /><br />\n";

	echo "<div class='card'>\n";
	echo "<table width='100%' border='0' cellpadding='0' cellspacing='0'>\n";

	echo "<tr>\n";
	echo "<td width='30%' class='vncell' valign='top' align='left' nowrap='nowrap'>\n";
	echo $text['label-dashboard_name'] ?? '';
	echo "\n";
	echo "</td>\n";
	echo "<td width='70%' class='vtable' style='position: relative;' align='left'>\n";
	echo "	<input class='formfld' type='text' name='dashboard_name' maxlength='255' value='".escape($dashboard_name)."'>\n";
	echo "<br />\n";
	echo $text['description-dashboard_name']."\n";
	echo "</td>\n";
	echo "</tr>\n";

<<<<<<< ours
	if (permission_exists('dashboard_domain')) {
=======
	echo "<tr>\n";
	echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>\n";
	echo "	".$text['label-dashboard_path']."\n";
	echo "</td>\n";
	echo "<td class='vtable' style='position: relative;' align='left'>\n";
	echo "	<select name='dashboard_path' class='formfld' id='dashboard_path' onchange=\"adjust_form();\">\n";
	echo "		<option value=''></option>\n";
	foreach($dashboard_tools as $key => $value) {
		echo "		<option value='$key' ".($key == $dashboard_path ? "selected='selected'" : null).">".$key."</option>\n";
	}
	echo "	</select>\n";
	echo "<br />\n";
	echo $text['description-dashboard_path']."\n";
	echo "</td>\n";
	echo "</tr>\n";

	if ($action == "add" || $dashboard_path == "dashboard/icon" || $dashboard_chart_type == "icon") {
		echo "	<tr class='type_icon'>"; // ".(($dashboard_path != 'dashboard/icon' || $dashboard_chart_type == "icon") ? "style='display: none;'" : null)."
		echo "		<td class='vncell'>".$text['label-icon']."</td>";
		echo "		<td class='vtable' style='vertical-align: bottom;'>";
		if (file_exists($_SERVER["PROJECT_ROOT"].'/resources/fontawesome/fa_icons.php')) {
			include $_SERVER["PROJECT_ROOT"].'/resources/fontawesome/fa_icons.php';
		}
		if (!empty($font_awesome_icons) && is_array($font_awesome_icons)) {
			echo "<table cellpadding='0' cellspacing='0' border='0'>\n";
			echo "	<tr>\n";
			echo "		<td>\n";
			echo "			<select class='formfld' name='dashboard_icon' id='selected_icon' onchange=\"$('#icons').slideUp(200); $('#icon_search').fadeOut(200, function() { $('#grid_icon').fadeIn(); }); adjust_form();\">\n";
			echo "				<option value=''></option>\n";
			foreach ($font_awesome_icons as $icon) {
				$selected = $dashboard_icon == implode(' ', $icon['classes']) ? "selected" : null;
				echo "			<option value='".escape(implode(' ', $icon['classes']))."' ".$selected.">".escape($icon['label'])."</option>\n";
			}
			echo "			</select>\n";
			echo "		</td>\n";
			echo "		<td style='padding: 0 0 0 5px;'>\n";
			echo "			<button id='grid_icon' type='button' class='btn btn-default list_control_icon' style='font-size: 15px; padding-top: 1px; padding-left: 3px;' onclick=\"load_icons(); $(this).fadeOut(200, function() { $('#icons').fadeIn(200); $('#icon_search').fadeIn(200).focus(); }); $('#dashboard_icon_color').show();\"><span class='fa-solid fa-th'></span></button>";
			echo "			<input id='icon_search' type='text' class='formfld' style='display: none;' onkeyup=\"if (this.value.length >= 3) { delay_submit(this.value); } else if (this.value == '') { load_icons(); } else { $('#icons').html(''); }\" placeholder=\"".$text['label-search']."\">\n";
			echo "		</td>\n";
			echo "	</tr>\n";
			echo "</table>\n";
			echo "<div id='icons' style='clear: both; display: none; margin-top: 8px; padding-top: 10px; color: #000; max-height: 400px; overflow: auto;'></div>";

			echo "<script>\n";
			//load icons by search
			echo "function load_icons(search) {\n";
			echo "	xhttp = new XMLHttpRequest();\n";
			echo "	xhttp.open('GET', '".PROJECT_PATH."/resources/fontawesome/fa_icons.php?output=icons' + (search ? '&search=' + search : ''), false);\n";
			echo "	xhttp.send();\n";
			echo "	document.getElementById('icons').innerHTML = xhttp.responseText;\n";
			echo "}\n";
			//delay kepress action for 1/2 second
			echo "var keypress_timer;\n";
			echo "function delay_submit(search) {\n";
			echo "	clearTimeout(keypress_timer);\n";
			echo "	keypress_timer = setTimeout(function(){\n";
			echo "		load_icons(search);\n";
			echo "	}, 500);\n";
			echo "}\n";
			echo "</script>\n";
		}
		else {
			echo "		<input type='text' class='formfld' name='dashboard_icon' value='".escape($dashboard_icon)."' onchange=\"adjust_form();\">";
		}
		echo			$text['description-dashboard_icon']."\n";
		echo "		</td>";
		echo "	</tr>";

		echo "<tr class='type_icon' id='dashboard_icon_color' ".(empty($dashboard_icon) && empty($dashboard_icon_color) ? "style='display: none;'" : null).">\n";
		echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>\n";
		echo $text['label-dashboard_icon_color']."\n";
		echo "</td>\n";
		echo "<td class='vtable' style='position: relative;' align='left'>\n";
		echo "	<input type='text' class='formfld colorpicker' name='dashboard_icon_color' value='".escape($dashboard_icon_color)."'>\n";
		echo "<br />\n";
		echo $text['description-dashboard_icon_color']."\n";
		echo "</td>\n";
		echo "</tr>\n";

		echo "<tr class='type_icon' ".($dashboard_path != 'dashboard/icon' ? "style='display: none;'" : null).">\n";
		echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>\n";
		echo "	".$text['label-link']."\n";
		echo "</td>\n";
		echo "<td class='vtable' align='left'>\n";
		echo "	<input class='formfld' type='text' id='dashboard_url' name='dashboard_url' maxlength='255' value='".escape($dashboard_url)."' onchange=\"adjust_form();\">\n";
		echo "<br />\n";
		echo $text['description-dashboard_url'] ?? '';
		echo "\n";
		echo "</td>\n";
		echo "</tr>\n";

		echo "<tr class='type_icon' id='dashboard_target' ".($dashboard_path != 'dashboard/icon' || empty($dashboard_url) ? "style='display: none;'" : null).">\n";
		echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>\n";
		echo $text['label-target']."\n";
		echo "</td>\n";
		echo "<td class='vtable' align='left'>\n";
		echo "	<select name='dashboard_target' class='formfld' onchange=\"adjust_form();\">\n";
		echo "		<option value='self'>".$text['label-current_window']."</option>\n";
		echo "		<option value='new' ".(!empty($dashboard_target) && $dashboard_target == 'new' ? "selected='selected'" : null).">".$text['label-new_window']."</option>\n";
		echo "	</select>\n";
		echo "<br />\n";
		echo $text['description-dashboard_target']."\n";
		echo "</td>\n";
		echo "</tr>\n";

		echo "<tr class='type_icon' id='dashboard_width' ".($dashboard_path != 'dashboard/icon' || $dashboard_target != 'new' ? "style='display: none;'" : null).">\n";
		echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>\n";
		echo "	".$text['label-width']."\n";
		echo "</td>\n";
		echo "<td class='vtable' align='left'>\n";
		echo "	<input class='formfld' type='text' name='dashboard_width' maxlength='255' value='".escape($dashboard_width)."'>\n";
		echo "<br />\n";
		echo $text['description-dashboard_width'] ?? '';
		echo "\n";
		echo "</td>\n";
		echo "</tr>\n";

		echo "<tr class='type_icon' id='dashboard_height' ".($dashboard_path != 'dashboard/icon' || $dashboard_target != 'new' ? "style='display: none;'" : null).">\n";
		echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>\n";
		echo "	".$text['label-height']."\n";
		echo "</td>\n";
		echo "<td class='vtable' align='left'>\n";
		echo "	<input class='formfld' type='text' name='dashboard_height' maxlength='255' value='".escape($dashboard_height)."'>\n";
		echo "<br />\n";
		echo $text['description-dashboard_height'] ?? '';
		echo "\n";
		echo "</td>\n";
		echo "</tr>\n";
	}

	if ($action == "add" || $dashboard_path == "dashboard/content") {
		echo "<tr class='type_content' ".($dashboard_path != 'dashboard/content' ? "style='display: none;'" : null).">\n";
		echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>\n";
		echo "	".$text['label-content']."\n";
		echo "</td>\n";
		echo "<td class='vtable' align='left'>\n";
		echo "	<textarea class='formfld' style='height: 100px;' name='dashboard_content'>".$dashboard_content."</textarea>\n";
		echo "<br />\n";
		echo $text['description-dashboard_content']."\n";
		echo "</td>\n";
		echo "</tr>\n";

		echo "<tr class='type_content' ".($dashboard_path != 'dashboard/content' ? "style='display: none;'" : null).">\n";
		echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>\n";
		echo "	".$text['label-dashboard_content_text_align']."\n";
		echo "</td>\n";
		echo "<td class='vtable' align='left'>\n";
		echo "	<select name='dashboard_content_text_align' class='formfld'>\n";
		echo "		<option value='left' ".(!empty($dashboard_content_text_align) && $dashboard_content_text_align == 'left' ? "selected='selected'" : null).">".$text['label-left']."</option>\n";
		echo "		<option value='right' ".(!empty($dashboard_content_text_align) && $dashboard_content_text_align == 'right' ? "selected='selected'" : null).">".$text['label-right']."</option>\n";
		echo "		<option value='center' ".(!empty($dashboard_content_text_align) && $dashboard_content_text_align == 'center' ? "selected='selected'" : null).">".$text['label-center']."</option>\n";
		echo "	</select>\n";		echo "<br />\n";
		echo $text['description-dashboard_content_text_align']."\n";
		echo "</td>\n";
		echo "</tr>\n";
	}

	if ($action == "add" || $dashboard_path == "dashboard/content" || $dashboard_path == "dashboard/icon") {
		echo "<tr class='type_icon type_content' ".($dashboard_path != 'dashboard/content' && $dashboard_path != 'dashboard/icon' ? "style='display: none;'" : null).">\n";
		echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>\n";
		echo "	".$text['label-details']."\n";
		echo "</td>\n";
		echo "<td class='vtable' align='left'>\n";
		echo "	<textarea class='formfld' style='height: 100px;' name='dashboard_content_details'>".$dashboard_content_details."</textarea>\n";
		echo "<br />\n";
		echo $text['description-dashboard_content_details']."\n";
		echo "</td>\n";
		echo "</tr>\n";
	}

	echo "<tr>\n";
	echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>\n";
	echo "	".$text['label-dashboard_groups']."\n";
	echo "</td>\n";
	echo "<td class='vtable' style='position: relative;' align='left'>\n";
	if (is_array($dashboard_groups) && sizeof($dashboard_groups) != 0) {
		echo "<table cellpadding='0' cellspacing='0' border='0'>\n";
		foreach($dashboard_groups as $field) {
			if (!empty($field['group_name'])) {
				echo "<tr>\n";
				echo "	<td class='vtable' style='white-space: nowrap; padding-right: 30px;' nowrap='nowrap'>\n";
				echo $field['group_name'].((!empty($field['domain_uuid'])) ? "@".$_SESSION['domains'][$field['domain_uuid']]['domain_name'] : null);
				echo "	</td>\n";
				if (permission_exists('dashboard_group_delete') || if_group("superadmin")) {
					echo "	<td class='list_control_icons' style='width: 25px;'>\n";
					echo 		"<a href='dashboard_edit.php?id=".escape($field['dashboard_group_uuid'])."&dashboard_group_uuid=".escape($field['dashboard_group_uuid'])."&dashboard_uuid=".escape($dashboard_uuid)."&a=delete' alt='".$text['button-delete']."' onclick=\"return confirm('".$text['confirm-delete']."')\">".$v_link_label_delete."</a>\n";
					echo "	</td>\n";
				}
				echo "</tr>\n";
			}
		}
		echo "</table>\n";
	}
	if (!empty($groups) && is_array($groups)) {
		if (!empty($dashboard_groups)) { echo "<br />\n"; }
		echo "<select name='dashboard_groups[0][group_uuid]' class='formfld' style='width: auto; margin-right: 3px;'>\n";
		echo "	<option value=''></option>\n";
		foreach ($groups as $row) {
			if ((!empty($field['group_level']) && $field['group_level'] <= $_SESSION['user']['group_level']) || empty($field['group_level'])) {
				if (empty($assigned_groups) || !in_array($row["group_uuid"], $assigned_groups)) {
					echo "	<option value='".$row['group_uuid']."'>".$row['group_name'].(!empty($row['domain_uuid']) ? "@".$_SESSION['domains'][$row['domain_uuid']]['domain_name'] : null)."</option>\n";
				}
			}
		}
		echo "</select>\n";
		echo button::create(['type'=>'submit','label'=>$text['button-add'],'icon'=>$settings->get('theme', 'button_icon_add')]);
	}
	echo "<br />\n";
	echo $text['description-dashboard_groups']."\n";
	echo "</td>\n";
	echo "</tr>\n";

	if (!empty($dashboard_chart_type)) {
		echo "<tr class='type_chart'>\n";
		echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>\n";
		echo $text['label-dashboard_chart_type']."\n";
		echo "</td>\n";
		echo "<td class='vtable' style='position: relative;' align='left'>\n";
		echo "	<select name='dashboard_chart_type' class='formfld'>\n";
		echo "		<option value='doughnut'>".$text['label-doughnut']."</option>\n";
		if ($dashboard_chart_type == 'line' || $dashboard_path == 'system/system_cpu_status') {
			echo "		<option value='line' ".($dashboard_chart_type == "line" ? "selected='selected'" : null).">".$text['label-line']."</option>\n";
		}
		if ($dashboard_chart_type == "icon" || in_array($dashboard_path, ['domains/domains', 'xml_cdr/missed_calls', 'voicemails/voicemails', 'xml_cdr/recent_calls', 'registrations/registrations'])) {
			echo "		<option value='icon' ".($dashboard_chart_type == "icon" ? "selected='selected'" : null).">".$text['label-icon']."</option>\n";
		}
		echo "		<option value='number' ".($dashboard_chart_type == "number" ? "selected='selected'" : null).">".$text['label-number']."</option>\n";
		if ($dashboard_chart_type == "progress_bar" || $dashboard_path == "system/system_status") {
			echo "		<option value='progress_bar' ".($dashboard_chart_type == "progress_bar" ? "selected='selected'" : null).">".$text['label-progress_bar']."</option>\n";
		}
		echo "	</select>\n";
		echo "<br />\n";
		echo $text['description-dashboard_chart_type']."\n";
		echo "</td>\n";
		echo "</tr>\n";
	}

	echo "<tr>\n";
	echo "<td width='30%' class='vncell' valign='top' align='left' nowrap='nowrap'>\n";
	echo $text['label-dashboard_label_enabled'] ?? '';
	echo "\n";
	echo "</td>\n";
	echo "<td class='vtable' style='position: relative;' align='left'>\n";
	if (substr($_SESSION['theme']['input_toggle_style']['text'], 0, 6) == 'switch') {
		echo "	<label class='switch'>\n";
		echo "		<input type='checkbox' id='dashboard_label_enabled' name='dashboard_label_enabled' value='true' ".(empty($dashboard_label_enabled) || $dashboard_label_enabled == 'true' ? "checked='checked'" : null)." onclick=\"$('.type_label').toggle();\">\n";
		echo "		<span class='slider'></span>\n";
		echo "	</label>\n";
	}
	else {
		echo "	<select class='formfld' id='dashboard_label_enabled' name='dashboard_label_enabled' onchange=\"$('.type_label').toggle();\">\n";
		echo "		<option value='false'>".$text['option-false']."</option>\n";
		echo "		<option value='true' ".(empty($dashboard_label_enabled) || $dashboard_label_enabled == 'true' ? "selected='selected'" : null).">".$text['option-true']."</option>\n";
		echo "	</select>\n";
	}
	echo "<br />\n";
	echo $text['description-dashboard_label_enabled']."\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "<tr class='type_label' ".($dashboard_label_enabled == 'false' ? "style='display: none;'" : null).">\n";
	echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>\n";
	echo $text['label-dashboard_label_text_color']."\n";
	echo "</td>\n";
	echo "<td class='vtable' style='position: relative;' align='left'>\n";
	echo "	<input type='text' class='formfld colorpicker' name='dashboard_label_text_color' value='".escape($dashboard_label_text_color)."'>\n";
	echo "<br />\n";
	echo $text['description-dashboard_label_text_color']."\n";
	echo "</td>\n";
	echo "</tr>\n";

	if ($action == "add" || $dashboard_path == "dashboard/icon") {
		echo "<tr class='type_icon type_label' ".($dashboard_path != 'dashboard/icon' || $dashboard_label_enabled == 'false' ? "style='display: none;'" : null).">\n";
		echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>\n";
		echo $text['label-dashboard_label_text_color_hover']."\n";
		echo "</td>\n";
		echo "<td class='vtable' style='position: relative;' align='left'>\n";
		echo "	<input type='text' class='formfld colorpicker' name='dashboard_label_text_color_hover' value='".escape($dashboard_label_text_color_hover)."'>\n";
		echo "<br />\n";
		echo $text['description-dashboard_label_text_color_hover']."\n";
		echo "</td>\n";
		echo "</tr>\n";
	}

	echo "<tr class='type_label' ".($dashboard_label_enabled == 'false' ? "style='display: none;'" : null).">\n";
	echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>\n";
	echo $text['label-dashboard_label_background_color']."\n";
	echo "</td>\n";
	echo "<td class='vtable' style='position: relative;' align='left'>\n";
	echo "	<input type='text' class='formfld colorpicker' name='dashboard_label_background_color' value='".escape($dashboard_label_background_color)."'>\n";
	echo "<br />\n";
	echo $text['description-dashboard_label_background_color']."\n";
	echo "</td>\n";
	echo "</tr>\n";

	if ($action == "add" || $dashboard_path == "dashboard/icon") {
		echo "<tr class='type_icon type_label' ".($dashboard_path != 'dashboard/icon' || $dashboard_label_enabled == 'false' ? "style='display: none;'" : null).">\n";
		echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>\n";
		echo $text['label-dashboard_label_background_color_hover']."\n";
		echo "</td>\n";
		echo "<td class='vtable' style='position: relative;' align='left'>\n";
		echo "	<input type='text' class='formfld colorpicker' name='dashboard_label_background_color_hover' value='".escape($dashboard_label_background_color_hover)."'>\n";
		echo "<br />\n";
		echo $text['description-dashboard_label_background_color_hover']."\n";
		echo "</td>\n";
		echo "</tr>\n";
	}

	if (!empty($dashboard_chart_type)) {
>>>>>>> theirs
		echo "<tr>\n";
		echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>\n";
		echo "	".$text['label-domain']."\n";
		echo "</td>\n";
<<<<<<< ours
		echo "<td class='vtable' align='left'>\n";
		echo "    <select class='formfld' name='domain_uuid'>\n";
		echo "		<option value=''>".$text['label-global']."</option>\n";
		foreach ($_SESSION['domains'] as $row) {
			echo "	<option value='".escape($row['domain_uuid'])."' ".($row['domain_uuid'] == $domain_uuid ? "selected='selected'" : null).">".escape($row['domain_name'])."</option>\n";
=======
		echo "<td class='vtable' style='position: relative;' align='left'>\n";
		echo "	<input type='text' class='formfld colorpicker' name='dashboard_number_text_color' value='".escape($dashboard_number_text_color)."'>\n";
		echo "<br />\n";
		echo $text['description-dashboard_number_text_color']."\n";
		echo "</td>\n";
		echo "</tr>\n";
	}

	if ($dashboard_chart_type == "icon" || in_array($dashboard_path, ['active_calls/active_calls', 'domains/domains', 'xml_cdr/missed_calls', 'voicemails/voicemails', 'xml_cdr/recent_calls', 'registrations/registrations'])) {
		echo "<tr class='type_icon'>\n";
		echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>\n";
		echo $text['label-dashboard_number_text_color_hover']."\n";
		echo "</td>\n";
		echo "<td class='vtable' style='position: relative;' align='left'>\n";
		echo "	<input type='text' class='formfld colorpicker' name='dashboard_number_text_color_hover' value='".escape($dashboard_number_text_color_hover)."'>\n";
		echo "<br />\n";
		echo $text['description-dashboard_number_text_color_hover']."\n";
		echo "</td>\n";
		echo "</tr>\n";

		echo "<tr>\n";
		echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>\n";
		echo $text['label-dashboard_number_background_color']."\n";
		echo "</td>\n";
		echo "<td class='vtable' style='position: relative;' align='left'>\n";
		echo "	<input type='text' class='formfld colorpicker' name='dashboard_number_background_color' value='".escape($dashboard_number_background_color)."'>\n";
		echo "<br />\n";
		echo $text['description-dashboard_number_background_color']."\n";
		echo "</td>\n";
		echo "</tr>\n";
	}

	echo "<tr>\n";
	echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>\n";
	echo $text['label-dashboard_background_color']."\n";
	echo "</td>\n";
	echo "<td class='vtable' style='position: relative;' align='left'>\n";
	if (!empty($dashboard_background_color) && is_array($dashboard_background_color)) {
		foreach ($dashboard_background_color as $c => $background_color) {
			echo "	<input type='text' class='formfld colorpicker' id='dashboard_background_color_".$c."' name='dashboard_background_color[]' value='".escape($background_color)."'>\n";
			if ($c < sizeof($dashboard_background_color) - 1) { echo "<br />\n"; }
		}
		//swap button
		if (!empty($dashboard_background_color) && is_array($dashboard_background_color) && sizeof($dashboard_background_color) > 1) {
			echo "	<input type='hidden' id='dashboard_background_color_temp'>\n";
			echo button::create(['type'=>'button','title'=>$text['button-swap'],'icon'=>'fa-solid fa-arrow-right-arrow-left fa-rotate-90','style'=>"z-index: 0; position: absolute; display: inline-block; margin: -14px 0 0 7px;",'onclick'=>"document.getElementById('dashboard_background_color_temp').value = document.getElementById('dashboard_background_color_0').value; document.getElementById('dashboard_background_color_0').value = document.getElementById('dashboard_background_color_1').value; document.getElementById('dashboard_background_color_1').value = document.getElementById('dashboard_background_color_temp').value; this.blur();"])."<br>\n";
		}
		else {
			echo "<br />\n";
		}
	}
	if (empty($dashboard_background_color) || (is_array($dashboard_background_color) && count($dashboard_background_color) < 2)) {
		echo "	<input type='text' class='formfld colorpicker' style='display: block;' name='dashboard_background_color[]' value='' onclick=\"document.getElementById('background_color_gradient').style.display = 'block';\">\n";
		if (empty($dashboard_background_color)) {
			echo "	<input id='background_color_gradient' style='display: none;' type='text' class='formfld colorpicker' name='dashboard_background_color[]'>\n";
		}
	}
	if (!empty($dashboard_background_color) && !is_array($dashboard_background_color)) {
		echo "	<input type='text' class='formfld colorpicker' name='dashboard_background_color[]' value='".escape([$dashboard_background_color])."'><br />\n";
		echo "	<input type='text' class='formfld colorpicker' name='dashboard_background_color[]' value=''><br />\n";
	}
	echo $text['description-dashboard_background_color']."\n";
	echo "</td>\n";
	echo "</tr>\n";

	if ($action == "add" || $dashboard_path == "dashboard/icon" || $dashboard_chart_type == "icon") {
		echo "<tr class='type_icon' ".($dashboard_path != "dashboard/icon" || $dashboard_chart_type != "icon" ? null : "style='display: none;'").">\n";
		echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>\n";
		echo $text['label-dashboard_background_color_hover']."\n";
		echo "</td>\n";
		echo "<td class='vtable' style='position: relative;' align='left'>\n";
		if (!empty($dashboard_background_color_hover) && is_array($dashboard_background_color_hover)) {
			foreach ($dashboard_background_color_hover as $c => $background_color) {
				echo "	<input type='text' class='formfld colorpicker' id='dashboard_background_color_hover_".$c."' name='dashboard_background_color_hover[]' value='".escape($background_color)."'>\n";
				if ($c < sizeof($dashboard_background_color_hover) - 1) { echo "<br />\n"; }
			}
			//swap button
			if (!empty($dashboard_background_color_hover) && is_array($dashboard_background_color_hover) && sizeof($dashboard_background_color_hover) > 1) {
				echo "	<input type='hidden' id='dashboard_background_color_hover_temp'>\n";
				echo button::create(['type'=>'button','title'=>$text['button-swap'],'icon'=>'fa-solid fa-arrow-right-arrow-left fa-rotate-90','style'=>"z-index: 0; position: absolute; display: inline-block; margin: -14px 0 0 7px;",'onclick'=>"document.getElementById('dashboard_background_color_hover_temp').value = document.getElementById('dashboard_background_color_hover_0').value; document.getElementById('dashboard_background_color_hover_0').value = document.getElementById('dashboard_background_color_hover_1').value; document.getElementById('dashboard_background_color_hover_1').value = document.getElementById('dashboard_background_color_hover_temp').value; this.blur();"])."<br>\n";
			}
			else {
				echo "<br />\n";
			}
		}
		if (empty($dashboard_background_color_hover) || (is_array($dashboard_background_color_hover) && count($dashboard_background_color_hover) < 2)) {
			echo "	<input type='text' class='formfld colorpicker' style='display: block;' name='dashboard_background_color_hover[]' value='' onclick=\"document.getElementById('background_color_hover_gradient').style.display = 'block';\">\n";
			if (empty($dashboard_background_color_hover)) {
				echo "	<input id='background_color_hover_gradient' style='display: none;' type='text' class='formfld colorpicker' name='dashboard_background_color_hover[]'>\n";
			}
		}
		if (!empty($dashboard_background_color_hover) && !is_array($dashboard_background_color_hover)) {
			echo "	<input type='text' class='formfld colorpicker' name='dashboard_background_color_hover[]' value='".escape([$dashboard_background_color_hover])."'><br />\n";
			echo "	<input type='text' class='formfld colorpicker' name='dashboard_background_color_hover[]' value=''><br />\n";
		}
		echo $text['description-dashboard_background_color_hover']."\n";
		echo "</td>\n";
		echo "</tr>\n";
	}

	if ($dashboard_details_state != "none") {
		echo "<tr>\n";
		echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>\n";
		echo $text['label-dashboard_detail_background_color']."\n";
		echo "</td>\n";
		echo "<td class='vtable' style='position: relative;' align='left'>\n";
		if (!empty($dashboard_detail_background_color) && is_array($dashboard_detail_background_color)) {
			foreach ($dashboard_detail_background_color as $c => $detail_background_color) {
				echo "	<input type='text' class='formfld colorpicker' id='dashboard_detail_background_color_".$c."' name='dashboard_detail_background_color[]' value='".escape($detail_background_color)."'>\n";
				if ($c < sizeof($dashboard_detail_background_color) - 1) { echo "<br />\n"; }
			}
			//swap button
			if (!empty($dashboard_detail_background_color) && is_array($dashboard_detail_background_color) && sizeof($dashboard_detail_background_color) > 1) {
				echo "	<input type='hidden' id='dashboard_detail_background_color_temp'>\n";
				echo button::create(['type'=>'button','title'=>$text['button-swap'],'icon'=>'fa-solid fa-arrow-right-arrow-left fa-rotate-90','style'=>"z-index: 0; position: absolute; display: inline-block; margin: -14px 0 0 7px;",'onclick'=>"document.getElementById('dashboard_detail_background_color_temp').value = document.getElementById('dashboard_detail_background_color_0').value; document.getElementById('dashboard_detail_background_color_0').value = document.getElementById('dashboard_detail_background_color_1').value; document.getElementById('dashboard_detail_background_color_1').value = document.getElementById('dashboard_detail_background_color_temp').value; this.blur();"])."<br>\n";
			}
			else {
				echo "<br />\n";
			}
		}
		if (empty($dashboard_detail_background_color) || (is_array($dashboard_detail_background_color) && count($dashboard_detail_background_color) < 2)) {
			echo "	<input type='text' class='formfld colorpicker' style='display: block;' name='dashboard_detail_background_color[]' value='' onclick=\"document.getElementById('detail_background_color_gradient').style.display = 'block';\">\n";
			if (empty($dashboard_detail_background_color)) {
				echo "	<input id='detail_background_color_gradient' style='display: none;' type='text' class='formfld colorpicker' name='dashboard_detail_background_color[]'>\n";
			}
		}
		if (!empty($dashboard_detail_background_color) && !is_array($dashboard_detail_background_color)) {
			echo "	<input type='text' class='formfld colorpicker' name='dashboard_detail_background_color[]' value='".escape([$dashboard_detail_background_color])."'><br />\n";
			echo "	<input type='text' class='formfld colorpicker' name='dashboard_detail_background_color[]' value=''><br />\n";
>>>>>>> theirs
		}
		echo "    </select>\n";
		echo "<br />\n";
		echo $text['description-domain_name']."\n";
		echo "</td>\n";
		echo "</tr>\n";
	}

	echo "<tr>\n";
	echo "<td class='vncellreq' valign='top' align='left' nowrap='nowrap'>\n";
	echo "	".$text['label-dashboard_enabled']."\n";
	echo "</td>\n";
	echo "<td class='vtable' style='position: relative;' align='left'>\n";
	if ($input_toggle_style_switch) {
		echo "	<span class='switch'>\n";
	}
	echo "		<select class='formfld' id='dashboard_enabled' name='dashboard_enabled'>\n";
	echo "			<option value='true' ".($dashboard_enabled == true ? "selected='selected'" : null).">".$text['option-true']."</option>\n";
	echo "			<option value='false' ".($dashboard_enabled == false ? "selected='selected'" : null).">".$text['option-false']."</option>\n";
	echo "		</select>\n";
	if ($input_toggle_style_switch) {
		echo "		<span class='slider'></span>\n";
		echo "	</span>\n";
	}
	echo "<br />\n";
	echo $text['description-dashboard_enabled']."\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "<tr>\n";
	echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>\n";
	echo "	".$text['label-dashboard_description']."\n";
	echo "</td>\n";
	echo "<td class='vtable' style='position: relative;' align='left'>\n";
	echo "	<input class='formfld' type='text' name='dashboard_description' maxlength='255' value='".escape($dashboard_description)."'>\n";
	echo "<br />\n";
	echo $text['description-dashboard_description']."\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "</table>\n";
	echo "</div>\n";
	echo "<br /><br />\n";

	echo "<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>\n";

	echo "</form>\n";

	if ($action == "update") {
		require_once "core/dashboard/dashboard_widget_list.php";
	}

//include the footer
	require_once "resources/footer.php";

?>
