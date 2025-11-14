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
	Portions created by the Initial Developer are Copyright (C) 2008-2024
	the Initial Developer. All Rights Reserved.

	Contributor(s):
	Mark J Crane <markjcrane@fusionpbx.com>
*/

//includes files
	require_once dirname(__DIR__, 2) . "/resources/require.php";
	require_once "resources/check_auth.php";

//check permissions
	if (permission_exists('conference_add') || permission_exists('conference_edit')) {
		//access granted
	}
	else {
		echo "access denied";
		exit;
	}

//add multi-lingual support
	$language = new text;
	$text = $language->get();

//set the defaults
	$conference_name = '';
	$conference_extension = '';
	$conference_pin_number = '';
	$conference_flags = '';
	$conference_account_code = '';
	$conference_description = '';
    // call mode defaults
    $call_mode = 'single_call';
    $call_mode_group_uuid = null;
    $call_mode_targets = '';
    $call_mode_exclude_caller = 'true';

//action add or update
	if (!empty($_REQUEST["id"]) && is_uuid($_REQUEST["id"])) {
		$action = "update";
		$conference_uuid = $_REQUEST["id"];
	}
	else {
		$action = "add";
	}

//get http post variables and set them to php variables
	if (!empty($_POST)) {
		$dialplan_uuid = $_POST["dialplan_uuid"] ?? null;
		$conference_name = $_POST["conference_name"];
		$conference_extension = $_POST["conference_extension"];
		$conference_pin_number = $_POST["conference_pin_number"];
		$conference_profile = $_POST["conference_profile"];
		$conference_flags = $_POST["conference_flags"];
		$conference_email_address = $_POST["conference_email_address"] ?? null;
		$conference_account_code = $_POST["conference_account_code"];
		$conference_order = $_POST["conference_order"];
		$conference_description = $_POST["conference_description"];
		$conference_enabled = $_POST["conference_enabled"] ?? 'false';
        $enforce_authorized_callers = $_POST["enforce_authorized_callers"] ?? 'false';

        // call mode inputs
        $call_mode = $_POST["call_mode"] ?? 'single_call';
        $call_mode_group_uuid = $_POST["call_mode_group_uuid"] ?? null;
        $call_mode_targets_input = $_POST["call_mode_targets_input"] ?? '';
        $call_mode_targets_select = $_POST["call_mode_targets_select"] ?? [];
        $exclude_caller = $_POST["exclude_caller"] ?? 'true';
        $authorized_extensions_select = $_POST["authorized_extensions_select"] ?? [];
        $authorized_extensions_input = $_POST["authorized_extensions_input"] ?? '';

		//set the context for users that do not have the permission
		if (permission_exists('conference_context')) {
			$conference_context = $_POST["conference_context"];
		}
		else if ($action == 'add') {
			$conference_context = $_SESSION['domain_name'];
		}

		//sanitize the conference name
		$conference_name = preg_replace("/[^A-Za-z0-9\- ]/", "", $conference_name);
		//$conference_name = str_replace(" ", "-", $conference_name);
	}

//delete the user from the v_conference_users
	if (!empty($_GET["a"]) && $_GET["a"] == "delete" && permission_exists("conference_delete")) {

		$user_uuid = $_REQUEST["user_uuid"];
		$conference_uuid = $_REQUEST["id"];

		$p = permissions::new();
		$p->add('conference_user_delete', 'temp');

		$array['conference_users'][0]['domain_uuid'] = $_SESSION['domain_uuid'];
		$array['conference_users'][0]['conference_uuid'] = $conference_uuid;
		$array['conference_users'][0]['user_uuid'] = $user_uuid;

		$database = new database;
		$database->app_name = 'conferences';
		$database->app_uuid = 'b81412e8-7253-91f4-e48e-42fc2c9a38d9';
		$database->delete($array);
		$response = $database->message;
		unset($array);

		$p->delete('conference_user_delete', 'temp');

		message::add($text['confirm-delete']);
		header("Location: conference_edit.php?id=".$conference_uuid);
		exit;
	}

//add the user to the v_conference_users
	if (!empty($_REQUEST["user_uuid"]) && is_uuid($_REQUEST["user_uuid"]) && is_uuid($_REQUEST["id"]) && (empty($_GET["a"]) || $_GET["a"] != "delete")) {
		//set the variables
			$user_uuid = $_REQUEST["user_uuid"];
			$conference_uuid = $_REQUEST["id"];

		//assign the user to the extension
			$array['conference_users'][0]['conference_user_uuid'] = uuid();
			$array['conference_users'][0]['domain_uuid'] = $_SESSION['domain_uuid'];
			$array['conference_users'][0]['conference_uuid'] = $conference_uuid;
			$array['conference_users'][0]['user_uuid'] = $user_uuid;

			$p = permissions::new();
			$p->add('conference_user_add', 'temp');

			$database = new database;
			$database->app_name = 'conferences';
			$database->app_uuid = 'b81412e8-7253-91f4-e48e-42fc2c9a38d9';
			$database->save($array);
			$response = $database->message;
			unset($array);

			$p->delete('conference_user_add', 'temp');

		//send a message
			message::add($text['confirm-add']);
			header("Location: conference_edit.php?id=".urlencode($conference_uuid));
			exit;
	}

//process http post variables
	if (!empty($_POST) && empty($_POST["persistformvar"])) {

		//get the conference id
			if ($action == "add") {
				$conference_uuid = uuid();
				$dialplan_uuid = uuid();
			}
			if ($action == "update") {
				$conference_uuid = $_POST["conference_uuid"];
			}

		//validate the token
			$token = new token;
			if (!$token->validate($_SERVER['PHP_SELF'])) {
				message::add($text['message-invalid_token'],'negative');
				header('Location: conferences.php');
				exit;
			}

		//check for all required data
			$msg = '';
			//if (empty($dialplan_uuid)) { $msg .= "Please provide: Dialplan UUID<br>\n"; }
			if (empty($conference_name)) { $msg .= "".$text['confirm-name']."<br>\n"; }
			if (empty($conference_extension)) { $msg .= "".$text['confirm-extension']."<br>\n"; }
			//if (empty($conference_pin_number)) { $msg .= "Please provide: Pin Number<br>\n"; }
			if (empty($conference_profile)) { $msg .= "".$text['confirm-profile']."<br>\n"; }
			//if (empty($conference_flags)) { $msg .= "Please provide: Flags<br>\n"; }
			//if (empty($conference_order)) { $msg .= "Please provide: Order<br>\n"; }
			//if (empty($conference_description)) { $msg .= "Please provide: Description<br>\n"; }
			if (empty($conference_enabled)) { $msg .= "".$text['confirm-enabled']."<br>\n"; }
                // validate call_mode and target group
                $allowed_call_modes = ['single_call','group_call','all_call'];
                if (!in_array($call_mode, $allowed_call_modes, true)) {
                    $call_mode = 'single_call';
                }
                if ($call_mode !== 'group_call') {
                    $call_mode_group_uuid = null;
                }
                $targets_list = [];
                $raw = $call_mode_targets_input;
                $raw = str_replace(["\r","\n","\t"], ' ', $raw);
                $raw = preg_replace('/[，、；;]+/', ',', $raw);
                $raw = preg_replace('/\s+/', ',', $raw);
                $raw = trim($raw, " ,");
                $typed_list = [];
                if (strlen($raw) > 0) { $typed_list = array_filter(array_map('trim', explode(',', $raw)), function($v){ return $v !== ''; }); }
                if (!is_array($call_mode_targets_select)) { $call_mode_targets_select = []; }
                $targets_list = array_values(array_unique(array_merge($call_mode_targets_select, $typed_list)));
        if ($call_mode === 'group_call') {
                    if (empty($targets_list) && empty($call_mode_group_uuid)) {
                        $msg .= "".$text['message-targets-empty']."<br>\n";
                    } else {
                        $sql = "select extension from v_extensions where domain_uuid = :domain_uuid and enabled = 'true'";
                        $parameters = [];
                        $parameters['domain_uuid'] = $_SESSION['domain_uuid'];
                        $database = new database;
                        $rows = $database->select($sql, $parameters ?? null, 'all');
                        $valid_exts = [];
                        if (!empty($rows)) { foreach ($rows as $r) { $valid_exts[] = $r['extension']; } }
                        unset($sql, $parameters, $rows);
                        $invalids = [];
                        foreach ($targets_list as $t) { if (!in_array($t, $valid_exts, true)) { $invalids[] = $t; } }
                        if (!empty($invalids)) {
                            $msg .= sprintf($text['message-targets-invalid'], escape(implode(',', $invalids)))."<br>\n";
                }
            }
        }

        // validate authorized extensions when enforce is enabled
        if ($enforce_authorized_callers === 'true') {
            if (!is_array($authorized_extensions_select)) { $authorized_extensions_select = []; }
            if (!empty($authorized_extensions_select)) {
                $sql = "select extension_uuid from v_extensions where domain_uuid = :domain_uuid and enabled = 'true'";
                $parameters = [];
                $parameters['domain_uuid'] = $_SESSION['domain_uuid'];
                $database = new database;
                $rows = $database->select($sql, $parameters ?? null, 'all');
                $valid_ext_uuids = [];
                if (!empty($rows)) { foreach ($rows as $r) { $valid_ext_uuids[] = $r['extension_uuid']; } }
                unset($sql, $parameters, $rows);
                $authorized_extensions_select = array_values(array_unique(array_filter($authorized_extensions_select, function($v) use ($valid_ext_uuids){ return in_array($v, $valid_ext_uuids, true); }))); 
            }
            $authorized_extension_uuids_from_input = [];
            $raw_auth = str_replace(["\r","\n","\t"], ' ', $authorized_extensions_input);
            $raw_auth = preg_replace('/[，、；;]+/', ',', $raw_auth);
            $raw_auth = preg_replace('/\s+/', ',', $raw_auth);
            $raw_auth = trim($raw_auth, " ,");
            $input_nums = [];
            if (strlen($raw_auth) > 0) { $input_nums = array_filter(array_map('trim', explode(',', $raw_auth)), function($v){ return $v !== ''; }); }
            if (!empty($input_nums)) {
                $sql = "select extension_uuid, extension, number_alias from v_extensions where domain_uuid = :domain_uuid and enabled = 'true'";
                $parameters = [];
                $parameters['domain_uuid'] = $_SESSION['domain_uuid'];
                $database = new database;
                $rows = $database->select($sql, $parameters ?? null, 'all');
                if (!empty($rows)) {
                    $nums_set = array_flip($input_nums);
                    foreach ($rows as $r) {
                        $ext = $r['extension'] ?? '';
                        $num = $r['number_alias'] ?? '';
                        if (isset($nums_set[$ext]) || (!empty($num) && isset($nums_set[$num]))) {
                            $authorized_extension_uuids_from_input[] = $r['extension_uuid'];
                        }
                    }
                }
                unset($sql, $parameters, $rows);
            }
            $authorized_extension_uuids_final = array_values(array_unique(array_merge($authorized_extensions_select, $authorized_extension_uuids_from_input)));
        }
			if (!empty($msg) && empty($_POST["persistformvar"])) {
				$document['title'] = $text['title-conference'];
				require_once "resources/header.php";
				require_once "resources/persist_form_var.php";
				echo "<div align='center'>\n";
				echo "<table><tr><td>\n";
				echo $msg."<br />";
				echo "</td></tr></table>\n";
				persistformvar($_POST);
				echo "</div>\n";
				require_once "resources/footer.php";
				return;
			}

		//add or update the database
			if (empty($_POST["persistformvar"])) {

				//update the conference extension
					$array['conferences'][0]['domain_uuid'] = $_SESSION['domain_uuid'];
					$array['conferences'][0]['conference_uuid'] = $conference_uuid;
					$array['conferences'][0]['dialplan_uuid'] = $dialplan_uuid;
					$array['conferences'][0]['conference_name'] = $conference_name;
					$array['conferences'][0]['conference_extension'] = $conference_extension;
					$array['conferences'][0]['conference_pin_number'] = $conference_pin_number;
					$array['conferences'][0]['conference_profile'] = $conference_profile;
					$array['conferences'][0]['conference_flags'] = $conference_flags;
					if (permission_exists('conference_email_address')) {
						$array['conferences'][0]['conference_email_address'] = $conference_email_address;
					}
					if (permission_exists('conference_account_code')) {
						$array['conferences'][0]['conference_account_code'] = $conference_account_code;
					}
					$array['conferences'][0]['conference_order'] = $conference_order;
				$array['conferences'][0]['conference_description'] = $conference_description;
				$array['conferences'][0]['conference_context'] = $conference_context;
                $array['conferences'][0]['conference_enabled'] = $conference_enabled;
                $array['conferences'][0]['enforce_authorized_callers'] = ($enforce_authorized_callers === 'true') ? 'true' : 'false';
                    // call mode persistence
                    $array['conferences'][0]['call_mode'] = $call_mode;
                    if (!empty($call_mode_group_uuid)) {
                        $array['conferences'][0]['call_mode_group_uuid'] = $call_mode_group_uuid;
                    }
                    $call_mode_targets = implode(',', $targets_list);
                    $call_mode_exclude_caller = ($exclude_caller === 'true') ? 'true' : 'false';
                    $array['conferences'][0]['call_mode_targets'] = $call_mode_targets;
                    $array['conferences'][0]['call_mode_exclude_caller'] = $call_mode_exclude_caller;

                //conference pin number
                    $pin_number = (!empty($conference_pin_number)) ? '+'.$conference_pin_number : '';

				//build the xml
					$dialplan_xml = "<extension name=\"".xml::sanitize($conference_name)."\" continue=\"\" uuid=\"".xml::sanitize($dialplan_uuid)."\">\n";
					$dialplan_xml .= "	<condition field=\"destination_number\" expression=\"^".xml::sanitize($conference_extension)."$\">\n";
					$dialplan_xml .= "		<action application=\"answer\" data=\"\"/>\n";
					$dialplan_xml .= "		<action application=\"set\" data=\"conference_uuid=".xml::sanitize($conference_uuid)."\" inline=\"true\"/>\n";
					//$dialplan_xml .= "		<action application=\"set\" data=\"conference_name=".xml::sanitize($conference_name)."\" inline=\"true\"/>\n";
					$dialplan_xml .= "		<action application=\"set\" data=\"conference_extension=".xml::sanitize($conference_extension)."\" inline=\"true\"/>\n";
					$dialplan_xml .= "		<action application=\"conference\" data=\"".xml::sanitize($conference_extension)."@".$_SESSION['domain_name']."@".xml::sanitize($conference_profile.$pin_number)."+flags{'".xml::sanitize($conference_flags)."'}\"/>\n";
					$dialplan_xml .= "	</condition>\n";
					$dialplan_xml .= "</extension>\n";

				//update the conference dialplan
					$array['dialplans'][0]['dialplan_uuid'] = $dialplan_uuid;
					$array['dialplans'][0]['domain_uuid'] = $_SESSION['domain_uuid'];
					$array['dialplans'][0]['dialplan_name'] = $conference_name;
					$array['dialplans'][0]['dialplan_number'] = $conference_extension;
					if (isset($conference_context)) {
						$array['dialplans'][0]["dialplan_context"] = $conference_context;
					}
					$array['dialplans'][0]['app_uuid'] = 'b81412e8-7253-91f4-e48e-42fc2c9a38d9';
                    if ($call_mode !== 'single_call') {
                        $conference_action_lines  = "\t\t<action application=\"set\" data=\"conference_profile=".xml::sanitize($conference_profile.$pin_number)."\" inline=\"true\"/>\n";
                        $conference_action_lines .= "\t\t<action application=\"set\" data=\"conference_flags='".xml::sanitize($conference_flags)."'\" inline=\"true\"/>\n";
                        $conference_action_lines .= "\t\t<action application=\"set\" data=\"call_mode=".xml::sanitize($call_mode)."\" inline=\"true\"/>\n";
                        if ($call_mode === 'group_call' && !empty($call_mode_group_uuid)) {
                            $conference_action_lines .= "\t\t<action application=\"set\" data=\"call_mode_group_uuid=".xml::sanitize($call_mode_group_uuid)."\" inline=\"true\"/>\n";
                        }
                        $conference_action_lines .= "\t\t<action application=\"set\" data=\"call_mode_targets=".xml::sanitize($call_mode_targets)."\" inline=\"true\"/>\n";
                        $conference_action_lines .= "\t\t<action application=\"set\" data=\"exclude_caller=".xml::sanitize($call_mode_exclude_caller)."\" inline=\"true\"/>\n";
                        if ($enforce_authorized_callers === 'true') {
                            $conference_action_lines .= "\t\t<action application=\"set\" data=\"enforce_authorized_callers=true\" inline=\"true\"/>\n";
                        }
                        $conference_action_lines .= "\t\t<action application=\"lua\" data=\"app/conference_invite/index.lua\"/>\n";

                        $dialplan_xml = preg_replace('/\t\t<action application=\"conference\".*\n/', $conference_action_lines, $dialplan_xml, 1);
                    }
                    if ($call_mode === 'single_call' && $enforce_authorized_callers === 'true') {
                        $auth_action_lines  = "\t\t<action application=\"set\" data=\"conference_profile=".xml::sanitize($conference_profile.$pin_number)."\" inline=\"true\"/>\n";
                        $auth_action_lines .= "\t\t<action application=\"set\" data=\"conference_flags='".xml::sanitize($conference_flags)."'\" inline=\"true\"/>\n";
                        $auth_action_lines .= "\t\t<action application=\"set\" data=\"call_mode=single_call\" inline=\"true\"/>\n";
                        $auth_action_lines .= "\t\t<action application=\"set\" data=\"enforce_authorized_callers=true\" inline=\"true\"/>\n";
                        $auth_action_lines .= "\t\t<action application=\"lua\" data=\"app/conference_authorize/check.lua\"/>\n";
                        $dialplan_xml = preg_replace('/(\t\t<action application=\"conference\".*\n)/', $auth_action_lines.'$1', $dialplan_xml, 1);
                    }
                    $array['dialplans'][0]['dialplan_xml'] = $dialplan_xml;

                    // persist authorized extensions whitelist
                    if ($enforce_authorized_callers === 'true') {
                        $sql = "delete from v_conference_authorized_extensions where domain_uuid = :domain_uuid and conference_uuid = :conference_uuid";
                        $parameters = [];
                        $parameters['domain_uuid'] = $_SESSION['domain_uuid'];
                        $parameters['conference_uuid'] = $conference_uuid;
                        $database = new database;
                        $p_del = permissions::new();
                        $p_del->add('conference_authorized_extension_delete', 'temp');
                        $database->execute($sql, $parameters ?? null);
                        unset($sql, $parameters);
                        $p_del->delete('conference_authorized_extension_delete', 'temp');
                        if (!empty($authorized_extension_uuids_final)) {
                            $p = permissions::new();
                            $p->add('conference_authorized_extension_add', 'temp');
                            foreach ($authorized_extension_uuids_final as $ext_uuid) {
                                $array_auth['conference_authorized_extensions'][] = [
                                    'conference_authorized_extension_uuid' => uuid(),
                                    'domain_uuid' => $_SESSION['domain_uuid'],
                                    'conference_uuid' => $conference_uuid,
                                    'extension_uuid' => $ext_uuid,
                                ];
                            }
                            $database = new database;
                            $database->app_name = 'conferences';
                            $database->app_uuid = 'b81412e8-7253-91f4-e48e-42fc2c9a38d9';
                            $database->save($array_auth);
                            unset($array_auth);
                            $p->delete('conference_authorized_extension_add', 'temp');
                        }
                    }
					$array['dialplans'][0]['dialplan_continue'] = 'false';
					$array['dialplans'][0]['dialplan_order'] = '333';
					$array['dialplans'][0]['dialplan_enabled'] = $conference_enabled;
					$array['dialplans'][0]['dialplan_description'] = $conference_description;

					$p = permissions::new();
					$p->add('dialplan_add', 'temp');
					$p->add('dialplan_edit', 'temp');

					$database = new database;
					$database->app_name = 'conferences';
					$database->app_uuid = 'b81412e8-7253-91f4-e48e-42fc2c9a38d9';
					$database->save($array);
					$response = $database->message;
					unset($array);

					$p->delete('dialplan_add', 'temp');
					$p->delete('dialplan_edit', 'temp');

				//delete the dialplan details
					$sql = "delete from v_dialplan_details ";
					$sql .= "where dialplan_uuid = :dialplan_uuid ";
					//$sql .= "and domain_uuid = :domain_uuid ";
					//$parameters['domain_uuid'] = $_SESSION['domain_uuid'];
					$parameters['dialplan_uuid'] = $dialplan_uuid;
					$database = new database;
					$database->execute($sql, $parameters ?? null);
					unset($sql, $parameters);

				//add the message
					message::add($text['confirm-update']);

				//apply settings reminder
					$_SESSION["reload_xml"] = true;

				//clear the cache
					$cache = new cache;
					$cache->delete("dialplan:".$_SESSION["domain_name"]);

				//clear the destinations session array
					if (isset($_SESSION['destinations']['array'])) {
						unset($_SESSION['destinations']['array']);
					}

				//redirect the browser
					header("Location: conferences.php");
					exit;

			}
	}

//pre-populate the form
	if (!empty($_GET) && empty($_POST["persistformvar"])) {
		$conference_uuid = $_GET["id"];
		$sql = "select * from v_conferences ";
		$sql .= "where domain_uuid = :domain_uuid ";
		$sql .= "and conference_uuid = :conference_uuid ";
		$parameters['domain_uuid'] = $_SESSION['domain_uuid'];
		$parameters['conference_uuid'] = $conference_uuid;
		$database = new database;
		$row = $database->select($sql, $parameters ?? null, 'row');
		if (!empty($row)) {
			$dialplan_uuid = $row["dialplan_uuid"];
			$conference_name = $row["conference_name"];
			$conference_extension = $row["conference_extension"];
			$conference_pin_number = $row["conference_pin_number"];
			$conference_profile = $row["conference_profile"];
			$conference_flags = $row["conference_flags"];
			$conference_email_address = $row["conference_email_address"];
			$conference_account_code = $row["conference_account_code"];
			$conference_order = $row["conference_order"];
			$conference_description = $row["conference_description"];
			$conference_context = $row["conference_context"];
			$conference_enabled = $row["conference_enabled"];
            $enforce_authorized_callers = $row["enforce_authorized_callers"] ?? 'false';
            // call mode
            $call_mode = $row["call_mode"] ?? $call_mode;
            $call_mode_group_uuid = $row["call_mode_group_uuid"] ?? null;
            $call_mode_targets = $row["call_mode_targets"] ?? $call_mode_targets;
            $call_mode_exclude_caller = $row["call_mode_exclude_caller"] ?? $call_mode_exclude_caller;
            $conference_name = str_replace("-", " ", $conference_name);
		}
		unset($sql, $parameters, $row);
	}

//set the defaults
	if (empty($conference_context)) { $conference_context = $_SESSION['domain_name']; }
	if (empty($conference_enabled)) { $conference_enabled = 'true'; }

//get the conference profiles
	$sql = "select * ";
	$sql .= "from v_conference_profiles ";
	$sql .= "where profile_enabled = 'true' ";
	$sql .= "and profile_name <> 'sla' ";
	$database = new database;
	$conference_profiles = $database->select($sql, null, 'all');
	unset($sql);

//get conference users
	$sql = "select * from v_conference_users as e, v_users as u ";
	$sql .= "where e.user_uuid = u.user_uuid  ";
	$sql .= "and u.user_enabled = 'true' ";
	$sql .= "and e.domain_uuid = :domain_uuid ";
	$sql .= "and e.conference_uuid = :conference_uuid ";
	$parameters['domain_uuid'] = $_SESSION['domain_uuid'];
	$parameters['conference_uuid'] = $conference_uuid ?? null;
	$database = new database;
	$conference_users = $database->select($sql, $parameters ?? null, 'all');
	unset($sql, $parameters);

//get the users
	$sql = "select * from v_users ";
	$sql .= "where domain_uuid = :domain_uuid ";
	$sql .= "and user_enabled = 'true' ";
	$parameters['domain_uuid'] = $_SESSION['domain_uuid'];
	$database = new database;
	$users = $database->select($sql, $parameters ?? null, 'all');
	unset($sql, $parameters);

//get the extensions (domain)
    $sql = "select extension_uuid, extension, number_alias, description from v_extensions ";
    $sql .= "where domain_uuid = :domain_uuid and enabled = 'true' ";
    $sql .= "order by natural_sort(number_alias) asc, natural_sort(extension) asc ";
    $parameters['domain_uuid'] = $_SESSION['domain_uuid'];
    $database = new database;
    $extensions = $database->select($sql, $parameters ?? null, 'all');
    unset($sql, $parameters);

//get authorized extensions whitelist
    $authorized_extension_uuids = [];
    if (!empty($conference_uuid)) {
        $sql = "select extension_uuid from v_conference_authorized_extensions where domain_uuid = :domain_uuid and conference_uuid = :conference_uuid";
        $parameters = [];
        $parameters['domain_uuid'] = $_SESSION['domain_uuid'];
        $parameters['conference_uuid'] = $conference_uuid;
        $database = new database;
        $rows = $database->select($sql, $parameters ?? null, 'all');
        if (!empty($rows)) { foreach ($rows as $r) { $authorized_extension_uuids[] = $r['extension_uuid']; } }
        unset($sql, $parameters, $rows);
    }

//set the default
	if (empty($conference_profile)) { $conference_profile = "default"; }

//create token
	$object = new token;
	$token = $object->create($_SERVER['PHP_SELF']);

//show the header
	$document['title'] = $text['title-conference'];
	require_once "resources/header.php";

//show the content
	echo "<form method='post' name='frm' id='frm'>\n";

	echo "<div class='action_bar' id='action_bar'>\n";
	echo "	<div class='heading'>";
	echo "		<b>".$text['title-conference']."</b>";
	echo "	</div>\n";
	echo "	<div class='actions'>\n";

	echo button::create(['type'=>'button','label'=>$text['button-back'],'icon'=>$settings->get('theme', 'button_icon_back'),'id'=>'btn_back','style'=>'margin-right: 15px;','link'=>'conferences.php']);
	if ($action == 'update') {
		if (permission_exists('conference_cdr_view')) {
			echo button::create(['type'=>'button','label'=>$text['button-cdr'],'icon'=>'list','link'=>PROJECT_PATH.'/app/conference_cdr/conference_cdr.php?id='.urlencode($conference_uuid)]);
		}
		if (permission_exists('conference_interactive_view')) {
			echo button::create(['type'=>'button','label'=>$text['button-view'],'icon'=>$settings->get('theme', 'button_icon_view'),'style'=>'','link'=>'../conferences_active/conference_interactive.php?c='.urlencode($conference_extension)]);
		}
		else if (permission_exists('conference_active_view')) {
			echo button::create(['type'=>'button','label'=>$text['button-view'],'icon'=>$settings->get('theme', 'button_icon_view'),'style'=>'','link'=>'../conferences_active/conferences_active.php']);
		}
	}
	echo button::create(['type'=>'submit','label'=>$text['button-save'],'icon'=>$settings->get('theme', 'button_icon_save'),'id'=>'btn_save']);
	echo "	</div>\n";
	echo "	<div style='clear: both;'></div>\n";
	echo "</div>\n";

	echo $text['description']."\n";
	echo "<br /><br />\n";

	echo "<div class='card'>\n";
	echo "<table width='100%' border='0' cellpadding='0' cellspacing='0'>\n";

	echo "<tr>\n";
	echo "<td width='30%' class='vncellreq' valign='top' align='left' nowrap='nowrap'>\n";
	echo "	".$text['label-name']."\n";
	echo "</td>\n";
	echo "<td width='70%' class='vtable' align='left'>\n";
	echo "	<input class='formfld' type='text' name='conference_name' maxlength='255' value=\"".escape($conference_name)."\">\n";
	echo "<br />\n";
	echo "".$text['description-name']."\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "<tr>\n";
	echo "<td class='vncellreq' valign='top' align='left' nowrap='nowrap'>\n";
	echo "	".$text['label-extension']."\n";
	echo "</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "	<input class='formfld' type='text' name='conference_extension' maxlength='255' value=\"".escape($conference_extension)."\" required='required' placeholder=\"".($_SESSION['conference']['extension_range']['text'] ?? '')."\">\n";
	echo "<br />\n";
	echo "".$text['description-extension']."\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "<tr>\n";
	echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>\n";
	echo "	".$text['label-pin']."\n";
	echo "</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "	<input class='formfld' type='number' name='conference_pin_number' maxlength='255' value=\"".escape($conference_pin_number)."\">\n";
	echo "<br />\n";
	echo "".$text['description-pin']."\n";
	echo "</td>\n";
	echo "</tr>\n";

	if (permission_exists('conference_user_add') || permission_exists('conference_user_edit')) {
		if ($action == "update") {
			echo "	<tr>";
			echo "		<td class='vncell' valign='top'>".$text['label-user_list']."</td>";
			echo "		<td class='vtable'>";

			if (!empty($conference_users)) {
				echo "		<table width='50%'>\n";
				foreach ($conference_users as $field) {
					echo "		<tr>\n";
					echo "			<td class='vtable'>".escape($field['username'])."</td>\n";
					echo "			<td>\n";
					echo "				<a href='conference_edit.php?id=".urlencode($conference_uuid)."&domain_uuid=".$_SESSION['domain_uuid']."&user_uuid=".urlencode($field['user_uuid'])."&a=delete' alt='delete' onclick=\"return confirm('".$text['confirm-delete-2']."')\">$v_link_label_delete</a>\n";
					echo "			</td>\n";
					echo "		</tr>\n";
				}
				echo "		</table>\n";
				echo "		<br />\n";
			}

			echo "			<select name=\"user_uuid\" class='formfld'>\n";
			echo "			<option value=\"\"></option>\n";
			foreach ($users as $field) {
				echo "			<option value='".escape($field['user_uuid'])."'>".escape($field['username'])."</option>\n";
			}
			echo "			</select>";
			echo button::create(['type'=>'submit','label'=>$text['button-add'],'icon'=>$settings->get('theme', 'button_icon_add')]);

			echo "			<br>\n";
			echo "			".$text['description-user-add']."\n";
			echo "			<br />\n";
			echo "		</td>";
			echo "	</tr>";
		}
	}

	echo "<tr>\n";
	echo "<td class='vncellreq' valign='top' align='left' nowrap='nowrap'>\n";
	echo "	".$text['table-profile']."\n";
	echo "</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "	<select class='formfld' name='conference_profile'>\n";
	foreach ($conference_profiles as $row) {
		if ($conference_profile === $row['profile_name']) {
			echo "		<option value='".escape($row['profile_name'])."' selected='selected'>".escape($row['profile_name'])."</option>\n";
		}
		else {
			echo "		<option value='".escape($row['profile_name'])."'>".escape($row['profile_name'])."</option>\n";
		}
	}
	echo "	</select>\n";
	echo "<br />\n";
	echo "".$text['description-profile']."\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "<tr>\n";
	echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>\n";
	echo "	".$text['label-flags']."\n";
	echo "</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "	<input class='formfld' type='text' name='conference_flags' maxlength='255' value=\"".escape($conference_flags)."\">\n";
	echo "<br />\n";
	echo "".$text['description-flags']."\n";
	echo "</td>\n";
	echo "</tr>\n";

    // call mode selection
    echo "<tr>\n";
    echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>\n";
    echo "\t".$text['label-call_mode']."\n";
    echo "</td>\n";
    echo "<td class='vtable' align='left'>\n";
    echo "\t<select class='formfld' name='call_mode' id='call_mode'>\n";
    echo "\t\t<option value='single_call' ".($call_mode === 'single_call' ? "selected='selected'" : null).">".$text['option-single_call']."</option>\n";
    echo "\t\t<option value='group_call' ".($call_mode === 'group_call' ? "selected='selected'" : null).">".$text['option-group_call']."</option>\n";
    echo "\t\t<option value='all_call' ".($call_mode === 'all_call' ? "selected='selected'" : null).">".$text['option-all_call']."</option>\n";
    echo "\t</select>\n";
    echo "<br />\n";
    echo "".$text['description-call_mode']."\n";
    echo "</td>\n";
    echo "</tr>\n";

    echo "<tr id='row_target_group' style='".($call_mode === 'group_call' ? "" : "display: none;")."'>\n";
    echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>\n";
    echo "\t".$text['label-call_mode_targets']."\n";
    echo "</td>\n";
    echo "<td class='vtable' align='left'>\n";
    $targets_set = array_filter(array_map('trim', explode(',', $call_mode_targets ?? '')));
    if (!empty($extensions) && is_array($extensions)) {
        echo "<div id='call_mode_targets_select' style='max-height: 160px; overflow: auto; border: 1px solid #ddd; padding: 6px;'>";
        foreach ($extensions as $e) {
            $label = $e['number_alias'] ?? $e['extension'];
            if (!empty($e['description'])) { $label .= " ".'['.$e['description'].']'; }
            $checked = in_array($e['extension'], $targets_set, true) ? "checked='checked'" : null;
            echo "<label style='display:inline-block;margin:3px 10px 3px 0;'>";
            echo "<input type='checkbox' name='call_mode_targets_select[]' value='".escape($e['extension'])."' ".$checked."> ".escape($label);
            echo "</label>";
        }
        echo "</div>";
    }
    echo "\t<input class='formfld' type='text' name='call_mode_targets_input' id='call_mode_targets_input' value=\"".escape($call_mode_targets)."\" style='width: 60%; margin-left: 10px;' placeholder='1000,1001,1002'>\n";
    echo "<br />\n";
    echo "".$text['description-call_mode_targets']."\n";
    echo "</td>\n";
    echo "</tr>\n";

    echo "<tr id='row_exclude_caller' style='".($call_mode === 'group_call' ? "" : "display: none;")."'>\n";
    echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>\n";
    echo "\t".$text['label-exclude_caller']."\n";
    echo "</td>\n";
    echo "<td class='vtable' align='left'>\n";
	echo "\t<label><input type='checkbox' name='exclude_caller' value='true' ".($call_mode_exclude_caller === 'true' ? "checked='checked'" : null).">".$text['description-exclude_caller']."</label>\n";
	echo "</td>\n";
    echo "</tr>\n";

    echo "<tr>\n";
    echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>\n";
    echo "\t".$text['label-enforce_authorized_callers']."\n";
    echo "</td>\n";
    echo "<td class='vtable' align='left'>\n";
    echo "\t<label><input type='checkbox' id='enforce_authorized_callers' name='enforce_authorized_callers' value='true' ".($enforce_authorized_callers === 'true' ? "checked='checked'" : null).">".$text['description-enforce_authorized_callers']."</label>\n";
    echo "</td>\n";
    echo "</tr>\n";

    echo "<tr id='row_authorized_extensions' style='".($enforce_authorized_callers === 'true' ? "" : "display: none;")."'>\n";
    echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>\n";
    echo "\t".$text['label-authorized_extensions']."\n";
    echo "</td>\n";
    echo "<td class='vtable' align='left'>\n";
    if (!empty($extensions) && is_array($extensions)) {
        echo "<div id='authorized_extensions_select' style='max-height: 160px; overflow: auto; border: 1px solid #ddd; padding: 6px;'>";
        foreach ($extensions as $e) {
            $label = $e['number_alias'] ?? $e['extension'];
            if (!empty($e['description'])) { $label .= " ".'['.$e['description'].']'; }
            $checked = in_array($e['extension_uuid'], $authorized_extension_uuids, true) ? "checked='checked'" : null;
            echo "<label style='display:inline-block;margin:3px 10px 3px 0;'>";
            echo "<input type='checkbox' name='authorized_extensions_select[]' value='".escape($e['extension_uuid'])."' ".$checked."> ".escape($label);
            echo "</label>";
        }
        echo "</div>";
    }
    echo "\t<input class='formfld' type='text' name='authorized_extensions_input' id='authorized_extensions_input' value=\"\" style='width: 60%; margin-left: 10px;' placeholder='1000,1001,1002'>\n";
    echo "<br />\n";
    echo "".$text['description-authorized_extensions']."\n";
    echo "</td>\n";
    echo "</tr>\n";

	if (permission_exists('conference_email_address')) {
		echo "<tr>\n";
		echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>\n";
		echo "	".$text['label-email_address']."\n";
		echo "</td>\n";
		echo "<td class='vtable' align='left'>\n";
		echo "	<input class='formfld' type='text' name='conference_email_address' maxlength='255' value=\"".escape($conference_email_address)."\">\n";
		echo "<br />\n";
		echo "".$text['description-email_address']."\n";
		echo "</td>\n";
		echo "</tr>\n";
	}

	if (permission_exists('conference_account_code')) {
		echo "<tr>\n";
		echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>\n";
		echo "	".$text['label-account_code']."\n";
		echo "</td>\n";
		echo "<td class='vtable' align='left'>\n";
		echo "	<input class='formfld' type='text' name='conference_account_code' maxlength='255' value=\"".escape($conference_account_code)."\">\n";
		echo "<br />\n";
		echo "".$text['description-account_code']."\n";
		echo "</td>\n";
		echo "</tr>\n";
	}

	echo "<tr>\n";
	echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>\n";
	echo "	".$text['label-order']."\n";
	echo "</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "	<select name='conference_order' class='formfld'>\n";
	if (!empty($dialplan_order) && strlen(htmlspecialchars($dialplan_order) ?? '') != 0) {
		echo "		<option selected='selected' value='".htmlspecialchars($dialplan_order)."'>".htmlspecialchars($dialplan_order)."</option>\n";
	}
	$i=0;
	while($i<=999) {
		if (strlen($i) == 1) { echo "		<option value='00$i'>00$i</option>\n"; }
		if (strlen($i) == 2) { echo "		<option value='0$i'>0$i</option>\n"; }
		if (strlen($i) == 3) { echo "		<option value='$i'>$i</option>\n"; }
		$i++;
	}
	echo "	</select>\n";
	echo "<br />\n";
	echo "".$text['description-order']."\n";
	echo "</td>\n";
	echo "</tr>\n";

	if (permission_exists('conference_context')) {
		echo "<tr>\n";
		echo "<td class='vncellreq' valign='top' align='left' nowrap='nowrap'>\n";
		echo "	".$text['label-context']."\n";
		echo "</td>\n";
		echo "<td class='vtable' align='left'>\n";
		echo "	<input class='formfld' type='text' name='conference_context' maxlength='255' value=\"".escape($conference_context)."\" required='required'>\n";
		echo "<br />\n";
		echo $text['description-enter-context']."\n";
		echo "</td>\n";
		echo "</tr>\n";
	}

	echo "<tr>\n";
	echo "<td class='vncellreq' valign='top' align='left' nowrap='nowrap'>\n";
	echo "	".$text['table-enabled']."\n";
	echo "</td>\n";
	echo "<td class='vtable' align='left'>\n";
	if (substr($_SESSION['theme']['input_toggle_style']['text'], 0, 6) == 'switch') {
		echo "	<label class='switch'>\n";
		echo "		<input type='checkbox' id='conference_enabled' name='conference_enabled' value='true' ".($conference_enabled == 'true' ? "checked='checked'" : null).">\n";
		echo "		<span class='slider'></span>\n";
		echo "	</label>\n";
	}
	else {
		echo "	<select class='formfld' id='conference_enabled' name='conference_enabled'>\n";
		echo "		<option value='true' ".($conference_enabled == 'true' ? "selected='selected'" : null).">".$text['option-true']."</option>\n";
		echo "		<option value='false' ".($conference_enabled == 'false' ? "selected='selected'" : null).">".$text['option-false']."</option>\n";
		echo "	</select>\n";
	}
	echo "<br />\n";
	echo "".$text['description-conference-enable']."\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "<tr>\n";
	echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>\n";
	echo "	".$text['label-description']."\n";
	echo "</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "	<input class='formfld' type='text' name='conference_description' maxlength='255' value=\"".escape($conference_description)."\">\n";
	echo "<br />\n";
	echo "".$text['description-info']."\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "</table>";
	echo "</div>\n";
	echo "<br><br>";

	if ($action == "update") {
		echo "<input type='hidden' name='dialplan_uuid' value='".escape($dialplan_uuid ?? '')."'>\n";
		echo "<input type='hidden' name='conference_uuid' value='".escape($conference_uuid)."'>\n";
	}
	echo "<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>\n";

    echo "</form>";

// toggle target group visibility based on call_mode
    echo "<script>\n";
    echo "function toggle_target_group(){var m=document.getElementById('call_mode').value;var r=document.getElementById('row_target_group');var e=document.getElementById('row_exclude_caller');r.style.display=(m==='group_call')?'':'none';e.style.display=(m==='group_call')?'':'none';}\n";
    echo "function toggle_authorized_extensions(){var c=document.getElementById('enforce_authorized_callers');var a=document.getElementById('row_authorized_extensions');a.style.display=(c && c.checked)?'':'none';}\n";
    echo "document.getElementById('call_mode').addEventListener('change', toggle_target_group);\n";
    echo "var ec=document.getElementById('enforce_authorized_callers'); if(ec){ ec.addEventListener('change', toggle_authorized_extensions); }\n";
    echo "window.addEventListener('load', toggle_target_group);\n";
    echo "window.addEventListener('load', toggle_authorized_extensions);\n";
    echo "function norm(s){return s.replace(/[\\s，、；;]+/g, ',').replace(/,+/g, ',').replace(/^,|,$/g,'');}\n";
    echo "var uuidToNumber={};\n";
    echo "(function(){\n";
    foreach (($extensions ?? []) as $e) {
        $num_js = escape($e['number_alias'] ?? $e['extension']);
        $uuid_js = escape($e['extension_uuid']);
        echo "uuidToNumber['$uuid_js']='$num_js';\n";
    }
    echo "})();\n";
    echo "function syncTargetsInput(){var box=document.getElementById('call_mode_targets_select');if(!box)return;var inputs=box.querySelectorAll(\"input[type=checkbox][name='call_mode_targets_select[]']\");var vals=[];inputs.forEach(function(ch){if(ch.checked)vals.push(ch.value);});var txt=document.getElementById('call_mode_targets_input');if(txt)txt.value=vals.join(',');}\n";
    echo "function applyTargetsChecksFromInput(){var txt=document.getElementById('call_mode_targets_input');if(!txt)return;var set=new Set(norm(txt.value).split(',').filter(Boolean).map(function(s){return s.trim();}));var box=document.getElementById('call_mode_targets_select');if(!box)return;var inputs=box.querySelectorAll(\"input[type=checkbox][name='call_mode_targets_select[]']\");inputs.forEach(function(ch){ch.checked=set.has(ch.value);});}\n";
    echo "function syncAuthorizedInput(){var box=document.getElementById('authorized_extensions_select');if(!box)return;var inputs=box.querySelectorAll(\"input[type=checkbox][name='authorized_extensions_select[]']\");var vals=[];inputs.forEach(function(ch){if(ch.checked){var num=uuidToNumber[ch.value];if(num)vals.push(num);}});var txt=document.getElementById('authorized_extensions_input');if(txt)txt.value=vals.join(',');}\n";
    echo "function applyAuthorizedChecksFromInput(){var txt=document.getElementById('authorized_extensions_input');if(!txt)return;var set=new Set(norm(txt.value).split(',').filter(Boolean).map(function(s){return s.trim();}));var box=document.getElementById('authorized_extensions_select');if(!box)return;var inputs=box.querySelectorAll(\"input[type=checkbox][name='authorized_extensions_select[]']\");inputs.forEach(function(ch){var num=uuidToNumber[ch.value];ch.checked=!!num && set.has(num);});}\n";
    echo "(function(){var box1=document.getElementById('call_mode_targets_select');if(box1){box1.addEventListener('change', syncTargetsInput);}var ti=document.getElementById('call_mode_targets_input');if(ti){ti.addEventListener('input', applyTargetsChecksFromInput);}var box2=document.getElementById('authorized_extensions_select');if(box2){box2.addEventListener('change', syncAuthorizedInput);}var ai=document.getElementById('authorized_extensions_input');if(ai){ai.addEventListener('input', applyAuthorizedChecksFromInput);}applyTargetsChecksFromInput();syncAuthorizedInput();})();\n";
    echo "</script>\n";

//include the footer
	require_once "resources/footer.php";

?>
