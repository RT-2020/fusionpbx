<?php
// 自动解决三方合并冲突: 两侧内容相同(去空白)的假冲突自动取一侧; 真冲突列出待手工
$repo = 'D:/Project/Project/博创/fusionpbx/fusionpbx';
$tmp = $repo . '/.tmp/m3';

$results = [];
foreach (glob("$tmp/m_*") as $mf) {
    $content = file_get_contents($mf);
    if (strpos($content, '<<<<<<<') === false) continue;

    $lines = explode("\n", $content);
    $out = []; $mode = 0; $ours = []; $theirs = []; $autoResolved = 0; $manualKept = 0;
    foreach ($lines as $line) {
        if (preg_match('#^<<<<<<<#', $line)) { $mode = 1; $ours = []; $theirs = []; continue; }
        if (preg_match('#^=======#', $line) && $mode === 1) { $mode = 2; continue; }
        if (preg_match('#^>>>>>>>#', $line) && $mode === 2) {
            $mode = 0;
            $normOurs = array_map('trim', array_filter($ours, fn($l) => trim($l) !== ''));
            $normTheirs = array_map('trim', array_filter($theirs, fn($l) => trim($l) !== ''));
            if ($normOurs === $normTheirs) {
                // 假冲突(空白/行尾差异) → 取 theirs 原文
                foreach ($theirs as $l) $out[] = $l;
                $autoResolved++;
            } else {
                foreach (['<<<<<<< ours'] as $l) $out[] = $l;
                foreach ($ours as $l) $out[] = $l;
                $out[] = '=======';
                foreach ($theirs as $l) $out[] = $l;
                $out[] = '>>>>>>> theirs';
                $manualKept++;
            }
            continue;
        }
        if ($mode === 1) { $ours[] = $line; continue; }
        if ($mode === 2) { $theirs[] = $line; continue; }
        $out[] = $line;
    }
    $new = implode("\n", $out);
    file_put_contents($mf, $new);
    $results[] = [$mf, $autoResolved, $manualKept];
}

$totalAuto = 0; $totalManual = 0; $manualFiles = 0;
foreach ($results as [$mf, $a, $m]) {
    $totalAuto += $a; $totalManual += $m;
    if ($m > 0) { $manualFiles++; echo "剩余手工: " . basename($mf) . " ($m 处)\n"; }
}
echo "\n=== 自动解决 $totalAuto 处, 剩余手工 $totalManual 处分布在 $manualFiles 个文件 ===\n";
