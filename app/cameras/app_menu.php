<?php

	$y = 0;
	$apps[$x]['menu'][$y]['title']['en-us'] = "Cameras";
		$apps[$x]['menu'][$y]['title']['zh-cn'] = "摄像头管理";
	$apps[$x]['menu'][$y]['uuid'] = "d3e4f5a6-b7c8-4d9e-0f1a-2b3c4d5e6f7a";
	$apps[$x]['menu'][$y]['parent_uuid'] = "bc96d773-ee57-0cdd-c3ac-2d91aba61b55"; // Accounts
	$apps[$x]['menu'][$y]['category'] = "internal";
	$apps[$x]['menu'][$y]['path'] = "/app/cameras/cameras.php";
	$apps[$x]['menu'][$y]['order'] = "110";
	$apps[$x]['menu'][$y]['groups'][] = "superadmin";
	$apps[$x]['menu'][$y]['groups'][] = "admin";

?>
