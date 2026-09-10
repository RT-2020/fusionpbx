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
*/

if ($domains_processed == 1) {

	//base64 decode the description - added for backwards comptability with old versions of FusionPBX
		$sql = "select * from v_vars \n";
		$sql .= "where var_description like '%=';\n";
		$vars = $database->select($sql, null, 'all');
		if (!empty($vars)) {
			foreach($vars as $row) {
				$sql = "update v_vars ";
				$sql .= "set var_description = :var_description ";
				$sql .= "where var_uuid = :var_uuid ";
				$parameters['var_uuid'] = $row['var_uuid'];
				$parameters['var_description'] = base64_decode($row['var_description']);
				$database->execute($sql, $parameters);
				unset($sql, $parameters);
			}
		}
		unset($sql, $vars);

	//add the variables to the database
		$sql = "select count(*) from v_vars ";
		$num_rows = $database->select($sql, null, 'column');
		unset($sql);

		if ($num_rows == 0) {
			//get the xml
				if (file_exists('/usr/share/examples/fusionpbx/resources/templates/conf/vars.xml')) {
					$xml_file = '/usr/share/examples/fusionpbx/resources/templates/conf/vars.xml';
				}
				elseif (file_exists('/usr/local/share/fusionpbx/resources/templates/conf/vars.xml')) {
					$xml_file = '/usr/local/share/fusionpbx/resources/templates/conf/vars.xml';
				}
				elseif (file_exists('/usr/local/www/fusionpbx/app/switch/resources/conf/vars.xml')) {
					$xml_file = '/usr/local/www/fusionpbx/app/switch/resources/conf/vars.xml';
				}
				elseif (file_exists('/var/www/fusionpbx/app/switch/resources/conf/vars.xml')) {
					$xml_file = '/var/www/fusionpbx/app/switch/resources/conf/vars.xml';
				}
				else {
					 $xml_file = dirname(__DIR__, 2) . '/app/switch/resources/conf/vars.xml';
				}

			//load the xml and save it into an array
				$xml_string = file_get_contents($xml_file);
				$xml = simplexml_load_string($xml_string);
				$json = json_encode($xml);
				$variables = json_decode($json, true);
				//<X-PRE-PROCESS cmd="set" data="global_codec_prefs=G7221@32000h,G7221@16000h,G722,PCMU,PCMA" category="Codecs" enabled="true"/>
				$x = 0;
				foreach ($variables['X-PRE-PROCESS'] as $variable) {
					$var_category = $variable['@attributes']['category'];
					$data = explode('=', $variable['@attributes']['data'], 2);
					$var_name = $data[0];
					$var_value = $data[1];
					$var_command = $variable['@attributes']['cmd'];
					$var_enabled = $variable['@attributes']['enabled'];
					$var_order = '';
					$var_description = '';

					$array['vars'][$x]['var_category'] = $var_category;
					$array['vars'][$x]['var_uuid'] = uuid();
					$array['vars'][$x]['var_name'] = $var_name;
					$array['vars'][$x]['var_value'] = $var_value;
					$array['vars'][$x]['var_command'] = $var_command;
					$array['vars'][$x]['var_enabled'] = $var_enabled;
					$array['vars'][$x]['var_order'] = $var_order;
					$array['vars'][$x]['var_description'] = $var_description;
					$x++;
				}

			//grant temporary permissions
				$p = permissions::new();
				$p->add("var_add", "temp");
				$p->add("var_edit", "temp");

			//execute insert
				if (!empty($array)) {
					$database->save($array, false);
				}

			//revoke temporary permissions
				$p->delete("var_add", "temp");
				$p->delete("var_edit", "temp");
		}

	//set country depend variables as country code and international direct dialing code (exit code)
		if (!function_exists('set_country_vars')) {
			function set_country_vars($database, $x) {
				//include the countrries
				require "resources/countries.php";

				//get the country iso
				$sql = "select default_setting_value ";
				$sql .= "from v_default_settings ";
				$sql .= "where default_setting_name = 'iso_code' ";
				$sql .= "and default_setting_category = 'domain' ";
				$sql .= "and default_setting_subcategory = 'country' ";
				$sql .= "and default_setting_enabled = 'true';";
				$country_iso = $database->select($sql, null, 'column');
				unset($sql);

				if ($country_iso === null ) {
					return;
				}

				if (isset($countries[$country_iso])) {
					$country = $countries[$country_iso];

					//set default country iso code
					$sql = "select count(*) from v_vars ";
					$sql .= "where var_name = 'default_country' ";
					$sql .= "and var_category = 'Defaults' ";
					$num_rows = $database->select($sql, null, 'column');
					unset($sql);

					if ($num_rows == 0) {
						$array['vars'][$x]['var_uuid'] = uuid();
						$array['vars'][$x]['var_name'] = 'default_country';
						$array['vars'][$x]['var_value'] = $country["isocode"];
						$array['vars'][$x]['var_category'] = 'Defaults';
						$array['vars'][$x]['var_enabled'] = true;
						$array['vars'][$x]['var_order'] = $x;
						$array['vars'][$x]['var_description'] = null;
						$x++;
					}
					unset($num_rows);

					//set default country code
					$sql = "select count(*) from v_vars ";
					$sql .= "where var_name = 'default_countrycode' ";
					$sql .= "and var_category = 'Defaults' ";
					$num_rows = $database->select($sql, null, 'column');
					unset($sql);

					if ($num_rows == 0) {
						$array['vars'][$x]['var_uuid'] = uuid();
						$array['vars'][$x]['var_name'] = 'default_countrycode';
						$array['vars'][$x]['var_value'] = $country["countrycode"];
						$array['vars'][$x]['var_category'] = 'Defaults';
						$array['vars'][$x]['var_enabled'] = true;
						$array['vars'][$x]['var_order'] = $x;
						$array['vars'][$x]['var_description'] = null;
						$x++;
					}
					unset($num_rows);

					//set default international direct dialing code
					$sql = "select count(*) from v_vars ";
					$sql .= "where var_name = 'default_exitcode' ";
					$sql .= "and var_category = 'Defaults' ";
					$num_rows = $database->select($sql, null, 'column');
					unset($sql);

					if ($num_rows == 0) {
						$array['vars'][$x]['var_uuid'] = uuid();
						$array['vars'][$x]['var_name'] = 'default_exitcode';
						$array['vars'][$x]['var_value'] = $country["exitcode"];
						$array['vars'][$x]['var_category'] = 'Defaults';
						$array['vars'][$x]['var_enabled'] = true;
						$array['vars'][$x]['var_order'] = $x;
						$array['vars'][$x]['var_description'] = null;
						$x++;
					}
					unset($num_rows, $countries);
				}

				if (!empty($array)) {
					//grant temporary permissions
						$p = permissions::new();
						$p->add("var_add", "temp");

					//execute inserts
						$database->save($array, false);
						unset($array);

					//revoke temporary permissions
						$p->delete("var_add", "temp");
				}
			}
		}

	//set country code variables
		set_country_vars($database, $x);

	//ensure the sip profile port and domain variables exist - the sip profile xml templates reference them as $${internal_sip_port} etc. without them sofia falls back to port 5060 for every profile and the internal profile can no longer bind its port
		$required_vars = [
			['category' => 'Domain', 'name' => 'domain', 'value' => '$${local_ip_v4}'],
			['category' => 'SIP Profile: Internal', 'name' => 'internal_sip_port', 'value' => '5060'],
			['category' => 'SIP Profile: Internal', 'name' => 'internal_tls_port', 'value' => '5061'],
			['category' => 'SIP Profile: External', 'name' => 'external_sip_port', 'value' => '5080'],
			['category' => 'SIP Profile: External', 'name' => 'external_tls_port', 'value' => '5081'],
		];

	//get the names of the required variables that already exist
		$sql = "select var_name from v_vars ";
		$sql .= "where var_name in ('domain','internal_sip_port','internal_tls_port','external_sip_port','external_tls_port') ";
		$rows = $database->select($sql, null, 'all');
		$existing_vars = array_column($rows ?? [], 'var_name');
		unset($sql, $rows);

	//add the missing variables
		$vars_added = false;
		$x = 0;
		foreach ($required_vars as $var) {
			if (!in_array($var['name'], $existing_vars)) {
				$array['vars'][$x]['var_uuid'] = uuid();
				$array['vars'][$x]['var_category'] = $var['category'];
				$array['vars'][$x]['var_name'] = $var['name'];
				$array['vars'][$x]['var_value'] = $var['value'];
				$array['vars'][$x]['var_command'] = 'set';
				$array['vars'][$x]['var_enabled'] = 'true';
				$array['vars'][$x]['var_order'] = $x;
				$array['vars'][$x]['var_description'] = '';
				$x++;
				$vars_added = true;
			}
		}
		if ($vars_added) {
			//grant temporary permissions
				$p = permissions::new();
				$p->add("var_add", "temp");

			//execute insert
				$database->save($array, false);

			//revoke temporary permissions
				$p->delete("var_add", "temp");
		}
		unset($array, $x, $existing_vars, $required_vars);

	//save the vars.xml file
		save_var_xml();

	//when the port variables were just added the external profile may be bound to the internal profile port - move it back to its own port and start the internal profiles
		if ($vars_added && function_exists('event_socket_request_cmd')) {
			$internal_status = event_socket_request_cmd('api sofia status profile internal');
			if (strpos($internal_status ?? '', 'Invalid Profile!') !== false) {
				//reload the xml so the new variables are available to the profiles
					event_socket_request_cmd('api reloadxml');
				//restart the external profiles so they bind their own ports again
					$external_status = event_socket_request_cmd('api sofia status profile external');
					if (strpos($external_status ?? '', 'Invalid Profile!') === false) {
						event_socket_request_cmd('api sofia profile external restart');
					}
					$external_status = event_socket_request_cmd('api sofia status profile external-ipv6');
					if (strpos($external_status ?? '', 'Invalid Profile!') === false) {
						event_socket_request_cmd('api sofia profile external-ipv6 restart');
					}
					unset($external_status);
				//start the internal profiles
					event_socket_request_cmd('api sofia profile internal start');
					event_socket_request_cmd('api sofia profile internal-ipv6 start');
			}
			unset($internal_status);
		}

	}

?>
