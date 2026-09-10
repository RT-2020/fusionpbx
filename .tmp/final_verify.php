<?php
// 最终验证: 检查关键自定义功能标记是否都在当前工作区
$repo = 'D:/Project/Project/博创/fusionpbx/fusionpbx';
chdir($repo);

$checks = [
    // [文件, 搜索标记, 说明]
    ['app/conferences/conference_edit.php', "call_mode", '会议呼叫模式'],
    ['app/conferences/conference_edit.php', "conference_authorized_extensions", '会议拨入授权'],
    ['app/conferences/app_config.php', "call_mode_targets", '会议字段定义'],
    ['app/xml_cdr/resources/classes/xml_cdr.php', 'parse recording details from bridge', '组呼录音归档-主解析'],
    ['app/xml_cdr/resources/classes/xml_cdr.php', 'fallback: when a parsed recording file', '组呼录音归档-fallback'],
    ['app/ring_groups/ring_group_edit.php', 'rg_emergency_enable', '振铃组紧急呼叫'],
    ['app/destinations/resources/classes/destinations.php', "'application'] = 'set'", "目的地 set 选项"],
    ['themes/default/template.php', 'menu_open_ids', '菜单展开记忆'],
    ['themes/default/css.php', "menu_side_state = 'expanded'", '菜单强制展开'],
    ['resources/footer.php', "menu_side_state', 'expanded'", 'footer 强制展开'],
    ['resources/footer.php', '// $settings_array[\'theme\'][\'footer\']', '隐藏版权 footer'],
    ['app/event_guard/resources/dashboard/config.php', '事件守卫', '事件守卫中文名'],
    ['app/active_calls/resources/classes/active_calls_service.php', 'class', '活跃通话服务'],
    ['app/dialplans/resources/switch/conf/dialplan/245_group-page.xml', '<action', '组呼 dialplan'],
    ['app/dialplans/resources/switch/conf/dialplan/246_all-page.xml', '<action', '全呼 dialplan'],
    ['resources/templates/engine/smarty/Smarty.class.php', 'class Smarty', 'Smarty 引擎'],
    ['resources/tcpdf/include/barcodes/pdf417.php', 'ord($code[$i])', 'pdf417 PHP8 修复'],
    ['core/websockets/resources/classes/invalid_handshake_exception.php', 'class', 'websocket 异常类'],
];

$pass = 0; $fail = 0;
foreach ($checks as [$file, $needle, $desc]) {
    if (file_exists($file) && strpos(file_get_contents($file), $needle) !== false) {
        $pass++; echo "✓ $desc\n";
    } else {
        $fail++; echo "✗ 缺失: $desc ($file)\n";
    }
}
echo "\n=== $pass 通过 / $fail 缺失 ===\n";
