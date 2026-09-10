<?php

	$y = 0;
	$apps[$x]['menu'][$y]['title']['en-us'] = "Base Stations";
		$apps[$x]['menu'][$y]['title']['zh-cn'] = "基站管理";
	$apps[$x]['menu'][$y]['uuid'] = "f5a6b7c8-d9e0-4f1a-2b3c-4d5e6f7a8b9c";
	$apps[$x]['menu'][$y]['parent_uuid'] = "bc96d773-ee57-0cdd-c3ac-2d91aba61b55"; // Accounts
	$apps[$x]['menu'][$y]['category'] = "internal";
	$apps[$x]['menu'][$y]['path'] = "/app/base_stations/base_stations.php";
	$apps[$x]['menu'][$y]['order'] = "100";
	$apps[$x]['menu'][$y]['groups'][] = "superadmin";
	$apps[$x]['menu'][$y]['groups'][] = "admin";

?>
