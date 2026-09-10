<?php
/**
 * Merge zh-cn translation entries from 5.4 branch into 5.5.7 files.
 * Usage: php .tmp/merge_zhcn.php
 */

$repo_root = dirname(__DIR__);
$files_needing_merge = [];

$excluded = ['basic_operator_panel', 'extensions', 'base_stations', 'cameras'];
exec('cd ' . escapeshellarg($repo_root) . ' && git diff 5.5.7..5.4 --name-only -- "*/app_languages.php"', $diff_files);

foreach ($diff_files as $file) {
    $skip = false;
    foreach ($excluded as $ex) {
        if (strpos($file, $ex) !== false) {
            $skip = true;
            break;
        }
    }
    if (!$skip) {
        $files_needing_merge[] = $file;
    }
}

echo "Files to merge: " . count($files_needing_merge) . "\n";

$merged = 0;
$skipped = 0;

foreach ($files_needing_merge as $rel_path) {
    $full_path = $repo_root . '/' . $rel_path;

    // Get 5.4 version content
    $source_lines = [];
    exec('cd ' . escapeshellarg($repo_root) . ' && git show 5.4:' . escapeshellarg($rel_path), $source_lines);
    $source_content = implode("\n", $source_lines);

    // Extract zh-cn entries from 5.4: key -> full line
    $zhcn_from_54 = [];
    preg_match_all("/\\\$text\['([^']+)'\]\['zh-cn'\]\s*=\s*\"([^\"]*)\";/", $source_content, $matches);
    for ($i = 0; $i < count($matches[0]); $i++) {
        $zhcn_from_54[$matches[1][$i]] = $matches[0][$i];
    }

    if (empty($zhcn_from_54)) {
        $skipped++;
        continue;
    }

    // Read current 5.5.7 file
    if (!file_exists($full_path)) {
        echo "SKIP (not found): $rel_path\n";
        $skipped++;
        continue;
    }
    $target_content = file_get_contents($full_path);
    $target_lines = explode("\n", $target_content);

    // Phase 1: Replace existing zh-cn lines, track which keys still need insertion
    $result_lines = [];
    $replaced = 0;
    $remaining_zhcn = $zhcn_from_54; // copy

    for ($i = 0; $i < count($target_lines); $i++) {
        $line = $target_lines[$i];
        if (preg_match("/^\\\$text\['([^']+)'\]\['zh-cn'\]/", $line, $m)) {
            $key = $m[1];
            if (isset($remaining_zhcn[$key])) {
                $result_lines[] = $remaining_zhcn[$key];
                unset($remaining_zhcn[$key]);
                $replaced++;
                continue;
            }
        }
        $result_lines[] = $line;
    }

    // Phase 2: Insert remaining zh-cn entries (keys in 5.4 but missing zh-cn in 5.5.7)
    $added = 0;
    if (!empty($remaining_zhcn)) {
        // Build map: key -> last line index in result_lines for that key
        $key_last_line = [];
        $current_key = '';
        for ($i = 0; $i < count($result_lines); $i++) {
            if (preg_match("/\\\$text\['([^']+)'\]\['([^']+)'\]/", $result_lines[$i], $m)) {
                $current_key = $m[1];
                $key_last_line[$current_key] = $i;
            }
        }

        // Plan insertions: index -> array of lines
        $insertions = [];
        foreach ($remaining_zhcn as $key => $zhcn_line) {
            if (isset($key_last_line[$key])) {
                $idx = $key_last_line[$key] + 1;
                if (!isset($insertions[$idx])) {
                    $insertions[$idx] = [];
                }
                $insertions[$idx][] = $zhcn_line;
                $added++;
            }
        }

        // Apply insertions in reverse order to preserve indices
        if (!empty($insertions)) {
            krsort($insertions);
            foreach ($insertions as $idx => $lines) {
                array_splice($result_lines, $idx, 0, $lines);
            }
        }
    }

    // Write back
    $new_content = implode("\n", $result_lines);
    file_put_contents($full_path, $new_content);

    echo "OK: $rel_path (replaced=$replaced, added=$added)\n";
    $merged++;
}

echo "\nDone. Merged: $merged, Skipped: $skipped\n";
