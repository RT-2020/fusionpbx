<?php
// 分类剩余未处理文件: 对比 base(5.4.4)/theirs(5.4)/ours(5.5.7)
$repo = 'D:/Project/Project/博创/fusionpbx/fusionpbx';
chdir($repo);

$files = [];
exec('git diff 5.4.4 5.4 --name-only', $all);
foreach ($all as $f) {
    // 跳过已处理类别
    if (preg_match('#^(\.gitignore|\.om|\.tmp|AGENTS|CLAUDE|\.claude|docs/)#i', $f)) continue;
    if (preg_match('#(base_stations|cameras|basic_operator_panel)#', $f)) continue;
    if (preg_match('#^app/extensions/#', $f)) continue;
    if (preg_match('#(app_languages|app_menu)\.php$#', $f)) continue;
    if (preg_match('#^resources/templates/provision/#', $f)) continue;
    if (preg_match('#^themes/#', $f)) continue;
    if (preg_match('#^app/conferences/#', $f)) continue;
    if (preg_match('#\.lua$#', $f)) continue;
    if (preg_match('#\.docx$#', $f)) continue;
    $files[] = $f;
}

function md5rev($rev, $f) {
    $c = shell_exec('git show ' . escapeshellarg($rev) . ':' . escapeshellarg($f) . ' 2>nul');
    return $c === null || $c === false || $c === '' ? 'MISSING' : md5(str_replace("\r\n", "\n", $c));
}

$groups = ['upstream_same' => [], 'pure_custom' => [], 'both_changed' => [], 'custom_missing' => []];
foreach ($files as $f) {
    $base = md5rev('5.4.4', $f);
    $theirs = md5rev('5.4', $f);
    $ours = md5rev('5.5.7', $f);
    if ($base === 'MISSING' && $theirs !== 'MISSING') { $groups['custom_missing'][] = $f; continue; } // 5.4新增文件
    if ($theirs === $ours) { $groups['upstream_same'][] = $f; continue; }          // 上游已一致
    if ($base === $ours) { $groups['pure_custom'][] = $f; continue; }              // 上游没动,纯自定义
    $groups['both_changed'][] = $f;                                                // 双方都改
}

echo "== 上游已一致(跳过) " . count($groups['upstream_same']) . " 个 ==\n";
echo "== 纯自定义(直接复制) " . count($groups['pure_custom']) . " 个 ==\n";
foreach ($groups['pure_custom'] as $f) echo "  $f\n";
echo "== 双方都改(需三方合并) " . count($groups['both_changed']) . " 个 ==\n";
foreach ($groups['both_changed'] as $f) echo "  $f\n";
echo "== 5.4新增文件(直接复制) " . count($groups['custom_missing']) . " 个 ==\n";
foreach ($groups['custom_missing'] as $f) echo "  $f\n";
