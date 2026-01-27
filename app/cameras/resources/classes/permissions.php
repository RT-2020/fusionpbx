<?php
/*
	FusionPBX
	Version: MPL 1.1

	Permissions Fix Script for Cameras Module
	Run this script to ensure permissions are properly created in the database
*/

//includes files
	require_once dirname(__DIR__, 4) . "/resources/require.php";

//check permissions
	if (!permission_exists('superadmin')) {
		echo "access denied";
		exit;
	}

//initialize the database object
	$database = new database;

//permissions to insert
	$permissions = [
		[
			'permission_name' => 'camera_view',
			'menu_uuid' => 'd3e4f5a6-b7c8-4d9e-0f1a-2b3c4d5e6f7a',
			'groups' => ['superadmin', 'admin']
		],
		[
			'permission_name' => 'camera_add',
			'menu_uuid' => null,
			'groups' => ['superadmin', 'admin']
		],
		[
			'permission_name' => 'camera_edit',
			'menu_uuid' => null,
			'groups' => ['superadmin', 'admin']
		],
		[
			'permission_name' => 'camera_delete',
			'menu_uuid' => null,
			'groups' => ['superadmin', 'admin']
		],
		[
			'permission_name' => 'camera_all',
			'menu_uuid' => null,
			'groups' => ['superadmin']
		],
		[
			'permission_name' => 'camera_password_view',
			'menu_uuid' => null,
			'groups' => ['superadmin']
		]
	];

//get app uuid
	$app_uuid = 'c4d5e6f7-a8b9-4c0d-1e2f-3a4b5c6d7e8f';

//insert permissions
	foreach ($permissions as $permission) {
		//check if permission exists
		$sql = "select permission_uuid from v_permissions ";
		$sql .= "where permission_name = :permission_name ";
		$parameters['permission_name'] = $permission['permission_name'];
		$permission_uuid = $database->select($sql, $parameters, 'column');
		unset($sql, $parameters);

		//insert if not exists
		if (empty($permission_uuid)) {
			$permission_uuid = uuid();
			$sql = "insert into v_permissions ";
			$sql .= "(permission_uuid, permission_name, app_uuid, menu_uuid) ";
			$sql .= "values (:permission_uuid, :permission_name, :app_uuid, :menu_uuid)";
			$parameters['permission_uuid'] = $permission_uuid;
			$parameters['permission_name'] = $permission['permission_name'];
			$parameters['app_uuid'] = $app_uuid;
			$parameters['menu_uuid'] = $permission['menu_uuid'];
			$database->execute($sql, $parameters);
			unset($sql, $parameters);
			echo "Created permission: " . $permission['permission_name'] . "\n";
		} else {
			echo "Permission exists: " . $permission['permission_name'] . "\n";
		}

		//assign to groups
		foreach ($permission['groups'] as $group_name) {
			//get group uuid
			$sql = "select group_uuid from v_groups ";
			$sql .= "where domain_uuid is null ";
			$sql .= "and group_name = :group_name ";
			$parameters['group_name'] = $group_name;
			$group_uuid = $database->select($sql, $parameters, 'column');
			unset($sql, $parameters);

			if (!empty($group_uuid)) {
				//check if assignment exists
				$sql = "select count(*) from v_group_permissions ";
				$sql .= "where group_uuid = :group_uuid ";
				$sql .= "and permission_uuid = :permission_uuid ";
				$parameters['group_uuid'] = $group_uuid;
				$parameters['permission_uuid'] = $permission_uuid;
				$count = $database->select($sql, $parameters, 'column');
				unset($sql, $parameters);

				//insert if not exists
				if ($count == 0) {
					$sql = "insert into v_group_permissions ";
					$sql .= "(group_permission_uuid, group_uuid, permission_uuid) ";
					$sql .= "values (:group_permission_uuid, :group_uuid, :permission_uuid)";
					$parameters['group_permission_uuid'] = uuid();
					$parameters['group_uuid'] = $group_uuid;
					$parameters['permission_uuid'] = $permission_uuid;
					$database->execute($sql, $parameters);
					unset($sql, $parameters);
					echo "  Assigned to group: " . $group_name . "\n";
				}
			}
		}
	}

	echo "\nPermissions created successfully!\n";
	echo "Please <a href='/core/user_settings_logout.php'>logout</a> and login again.\n";

?>
