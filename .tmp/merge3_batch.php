<?php
// 批量三方合并: base=5.4.4 ours=5.5.7 theirs=5.4(用户自定义)
$repo = 'D:/Project/Project/博创/fusionpbx/fusionpbx';
chdir($repo);
$tmp = $repo . '/.tmp/m3';
@mkdir($tmp, 0777, true);

// 排除: 核心认证文件保留 5.5.7 原版
$exclude = [
    'core/authentication/resources/classes/authentication.php',
];

$files = [];
exec('git diff 5.4.4 5.4 --name-only', $all);
foreach ($all as $f) {
    if (preg_match('#^(\.gitignore|\.om|\.tmp|AGENTS|CLAUDE|\.claude|docs/)#i', $f)) continue;
    if (preg_match('#(base_stations|cameras|basic_operator_panel)#', $f)) continue;
    if (preg_match('#^app/extensions/#', $f)) continue;
    if (preg_match('#(app_languages|app_menu)\.php$#', $f)) continue;
    if (preg_match('#^resources/templates/provision/#', $f)) continue;
    if (preg_match('#^themes/#', $f)) continue;
    if (preg_match('#^app/conferences/#', $f)) continue;
    if (preg_match('#\.lua$#', $f)) continue;
    if (preg_match('#\.docx$#', $f)) continue;
    if (in_array($f, $exclude)) continue;
    $files[] = $f;
}

function rev($rev, $f) {
    $c = shell_exec('git show ' . escapeshellarg($rev) . ':' . escapeshellarg($f) . ' 2>nul');
    return $c;
}
function md5s($s) { return $s === null || $s === '' ? 'MISSING' : md5(str_replace("\r\n", "\n", $s)); }

$copied = 0; $clean = 0; $conflict = []; $skipped = 0;
global $tmp;

foreach ($files as $f) {
    $base = rev('5.4.4', $f); $theirs = rev('5.4', $f); $ours = rev('5.5.7', $f);
    $hb = md5s($base); $ht = md5s($theirs); $ho = md5s($ours);

    if ($ht === $ho) { $skipped++; continue; }            // 上游一致
    if ($hb === 'MISSING' && $ht !== 'MISSING') {          // 5.4 新增文件
        $dest = $repo . '/' . $f;
        @mkdir(dirname($dest), 0777, true);
        file_put_contents($dest, $theirs);
        $copied++;
        echo "新增复制: $f\n";
        continue;
    }
    if ($hb === $ho) {                                     // 纯自定义
        $dest = $repo . '/' . $f;
        @mkdir(dirname($dest), 0777, true);
        file_put_contents($dest, $theirs);
        $copied++;
        echo "纯自定义复制: $f\n";
        continue;
    }
    // 双方都改 → 三方合并
    $safe = str_replace(['/', '\\', '{', '}', '$'], '_', $f);
    file_put_contents("$tmp/b_$safe", $base);
    file_put_contents("$tmp/o_$safe", $ours);
    file_put_contents("$tmp/t_$safe", $theirs);
    $cmd = "git merge-file -p " . escapeshellarg("$tmp/o_$safe") . " " . escapeshellarg("$tmp/b_$safe") . " " . escapeshellarg("$tmp/t_$safe") . " > " . escapeshellarg("$tmp/m_$safe") . " 2>nul";
    exec($cmd, $o, $code);
    if ($code === 0) {
        // 零冲突 → 应用
        file_put_contents($repo . '/' . $f, file_get_contents("$tmp/m_$safe"));
        $clean++;
        echo "干净合并: $f\n";
    } else {
        $conflict[] = [$f, $code];
        echo "冲突($code处): $f\n";
    }
}

echo "\n=== 汇总: 复制 $copied, 干净合并 $clean, 冲突 " . count($conflict) . ", 跳过 $skipped ===\n";
