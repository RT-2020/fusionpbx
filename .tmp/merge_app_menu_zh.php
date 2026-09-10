<?php
// 合并 5.4 分支 app_menu.php 的 zh-cn 菜单标题到当前 5.5.7 文件
// 对齐键: title['en-us'] 的值 (同一菜单项在两版本中英文标题几乎不变)

$repo = 'D:/Project/Project/博创/fusionpbx/fusionpbx';

$files = [];
exec('cd ' . escapeshellarg($repo) . ' && git diff 5.4.4 5.4 --name-only -- "app/*/app_menu.php" "core/*/app_menu.php"', $files);
$files = array_filter($files);

$stats = ['replaced' => 0, 'inserted' => 0, 'skipped_files' => 0, 'files' => 0];

foreach ($files as $rel) {
    $current = $repo . '/' . $rel;
    if (!file_exists($current)) { $stats['skipped_files']++; continue; }

    // 5.4 版本: en-us => zh-cn
    $oldContent = str_replace("\r\n", "\n", shell_exec('cd ' . escapeshellarg($repo) . ' && git show 5.4:' . escapeshellarg($rel)));
    $map = [];
    $key = null;
    foreach (explode("\n", $oldContent) as $line) {
        if (preg_match("/\['title'\]\['en-us'\]\s*=\s*\"(.*)\";\s*$/", $line, $m)) {
            $key = $m[1];
        } elseif (preg_match("/\['title'\]\['zh-cn'\]\s*=\s*\"(.*)\";\s*$/", $line, $m)) {
            if ($key !== null) { $map[$key] = $m[1]; $key = null; }
        }
    }
    if (empty($map)) { $stats['skipped_files']++; continue; }

    $newContent = str_replace("\r\n", "\n", file_get_contents($current));
    $newLines = explode("\n", $newContent);
    $out = [];
    $key = null;
    $pending = null;   // 当前标题组待写入的 zh-cn
    $replaced = 0; $inserted = 0;

    foreach ($newLines as $line) {
        $isEnUs = preg_match("/\['title'\]\['en-us'\]\s*=\s*\"(.*)\";\s*$/", $line, $m);
        $isZhCn = preg_match("/\['title'\]\['zh-cn'\]\s*=\s*\"(.*)\";\s*$/", $line, $m2);
        $isTitle = $isEnUs || $isZhCn || (bool)preg_match("/\['title'\]\['[a-z-]+'\]/", $line);

        if ($isEnUs) {
            // 新标题组开始: 上一组若还有 pending → 在此插入
            if ($pending !== null) {
                $out[] = "\t\t\$apps[\$x]['menu'][\$y]['title']['zh-cn'] = \"$pending\";";
                $inserted++; $pending = null;
            }
            $key = $m[1];
            $pending = isset($map[$key]) ? $map[$key] : null;
        } elseif ($isZhCn) {
            if ($key !== null && isset($map[$key])) {
                $line = "\t\t\$apps[\$x]['menu'][\$y]['title']['zh-cn'] = \"{$map[$key]}\";";
                $replaced++; $pending = null;
            }
        }
        $out[] = $line;
    }
    if ($pending !== null) { // 文件末尾
        $lastIdx = count($out) - 1;
        while ($lastIdx >= 0 && trim($out[$lastIdx]) === '') $lastIdx--;
        array_splice($out, $lastIdx + 1, 0, ["\t\t\$apps[\$x]['menu'][\$y]['title']['zh-cn'] = \"$pending\";"]);
        $inserted++;
    }

    if ($replaced + $inserted > 0) {
        file_put_contents($current, implode("\n", $out));
        $stats['files']++;
        $stats['replaced'] += $replaced;
        $stats['inserted'] += $inserted;
        echo "$rel: 替换 $replaced, 插入 $inserted\n";
    }
}

echo "\n=== 汇总: {$stats['files']} 文件, 替换 {$stats['replaced']}, 插入 {$stats['inserted']}, 跳过 {$stats['skipped_files']} ===\n";
