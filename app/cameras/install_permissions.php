<?php
/*
	FusionPBX Cameras Module - Permissions Installer
	Run this script to install cameras permissions into the database
*/

require_once dirname(__DIR__, 2) . "/resources/require.php";

// Check if user is superadmin
if (!if_group("superadmin")) {
    echo "Access denied. You must be a superadmin to run this script.\n";
    exit;
}

$database = new database;

echo "<h2>Cameras Module - Permissions Installation</h2>\n";
echo "<pre>\n";

// 1. Get group UUIDs
echo "Step 1: Getting group UUIDs...\n";
$sql = "SELECT group_uuid, group_name FROM v_groups WHERE domain_uuid IS NULL AND group_name IN ('admin', 'superadmin')";
$groups = $database->select($sql, null, 'all');

$group_uuids = [];
foreach ($groups as $group) {
    $group_uuids[$group['group_name']] = $group['group_uuid'];
    echo "  Found: {$group['group_name']} = {$group['group_uuid']}\n";
}

if (!isset($group_uuids['admin']) || !isset($group_uuids['superadmin'])) {
    echo "ERROR: Could not find admin or superadmin groups!\n";
    exit;
}

// 2. Define permissions
echo "\nStep 2: Defining permissions...\n";
$app_uuid = 'c4d5e6f7-a8b9-4c0d-1e2f-3a4b5c6d7e8f';
$menu_uuid = 'd3e4f5a6-b7c8-4d9e-0f1a-2b3c4d5e6f7a';

$permissions = [
    [
        'uuid' => 'e4f5a6b7-c8d9-4e0f-1a2b-3c4d5e6f7a8b',
        'name' => 'camera_view',
        'menu_uuid' => $menu_uuid,
        'groups' => ['superadmin', 'admin']
    ],
    [
        'uuid' => 'f5a6b7c8-d9e0-4f1a-2b3c-4d5e6f7a8b9c',
        'name' => 'camera_add',
        'menu_uuid' => null,
        'groups' => ['superadmin', 'admin']
    ],
    [
        'uuid' => 'a6b7c8d9-e0f1-4f2a-3b4c-5d6e7f8a9b0c',
        'name' => 'camera_edit',
        'menu_uuid' => null,
        'groups' => ['superadmin', 'admin']
    ],
    [
        'uuid' => 'b7c8d9e0-f1a2-4f3b-4c5d-6e7f8a9b0c1d',
        'name' => 'camera_delete',
        'menu_uuid' => null,
        'groups' => ['superadmin', 'admin']
    ],
    [
        'uuid' => 'c8d9e0f1-a2b3-4f4c-5d6e-7f8a9b0c1d2e',
        'name' => 'camera_all',
        'menu_uuid' => null,
        'groups' => ['superadmin']
    ],
    [
        'uuid' => 'd9e0f1a2-b3c4-4f5d-6e7f-8a9b0c1d2e3f',
        'name' => 'camera_password_view',
        'menu_uuid' => null,
        'groups' => ['superadmin']
    ]
];

// 3. Insert permissions
echo "\nStep 3: Inserting permissions into v_permissions...\n";
foreach ($permissions as $permission) {
    // Check if permission exists
    $sql = "SELECT permission_uuid FROM v_permissions WHERE permission_name = :permission_name";
    $existing_uuid = $database->select($sql, ['permission_name' => $permission['name']], 'column');

    if (empty($existing_uuid)) {
        // Insert new permission
        $sql = "INSERT INTO v_permissions (permission_uuid, permission_name, app_uuid, menu_uuid) ";
        $sql .= "VALUES (:permission_uuid, :permission_name, :app_uuid, :menu_uuid)";
        $params = [
            'permission_uuid' => $permission['uuid'],
            'permission_name' => $permission['name'],
            'app_uuid' => $app_uuid,
            'menu_uuid' => $permission['menu_uuid']
        ];
        $database->execute($sql, $params);
        echo "  Created: {$permission['name']}\n";
    } else {
        echo "  Exists: {$permission['name']}\n";
    }
}

// 4. Assign permissions to groups
echo "\nStep 4: Assigning permissions to groups...\n";
foreach ($permissions as $permission) {
    foreach ($permission['groups'] as $group_name) {
        $group_uuid = $group_uuids[$group_name];

        // Check if assignment exists
        $sql = "SELECT count(*) FROM v_group_permissions ";
        $sql .= "WHERE group_uuid = :group_uuid AND permission_uuid = :permission_uuid";
        $count = $database->select($sql, [
            'group_uuid' => $group_uuid,
            'permission_uuid' => $permission['uuid']
        ], 'column');

        if ($count == 0) {
            // Insert group permission
            $sql = "INSERT INTO v_group_permissions (group_permission_uuid, group_uuid, permission_uuid) ";
            $sql .= "VALUES (:group_permission_uuid, :group_uuid, :permission_uuid)";
            $params = [
                'group_permission_uuid' => uuid(),
                'group_uuid' => $group_uuid,
                'permission_uuid' => $permission['uuid']
            ];
            $database->execute($sql, $params);
            echo "  Assigned: {$permission['name']} -> {$group_name}\n";
        } else {
            echo "  Already assigned: {$permission['name']} -> {$group_name}\n";
        }
    }
}

echo "\n</pre>\n";
echo "<h3>Installation Complete!</h3>\n";
echo "<p>Please <a href='/core/user_settings_logout.php'>logout</a> and login again.</p>\n";
echo "<p><a href='/app/cameras/cameras.php'>Go to Cameras Module</a></p>\n";
?>
