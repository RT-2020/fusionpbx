<?php
$repo = 'D:/Project/Project/博创/fusionpbx/fusionpbx';
chdir($repo);
$files = [
    'app/conferences/app_languages.php',
    'app/dialplan_outbound/app_languages.php',
    'app/xml_cdr/app_languages.php',
    'core/dashboard/app_languages.php',
    'core/domain_settings/app_languages.php',
    'core/user_settings/app_languages.php',
    'resources/app_languages.php',
];
foreach ($files as $f) {
    $old = shell_exec('git show 5.4:' . escapeshellarg($f));
    $old = str_replace("\r\n", "\n", $old);
    $new = str_replace("\r\n", "\n", file_get_contents($f));

    $re = '/\$text\[[\'"]([a-z0-9_-]+)[\'"]\]\[[\'"]zh-cn[\'"]\]\s*=\s*"([^"]*)"/';
    preg_match_all($re, $old, $mo, PREG_SET_ORDER);
    preg_match_all($re, $new, $mn, PREG_SET_ORDER);

    $haveKeys = []; $haveVals = [];
    foreach ($mn as $m) { $haveKeys[$m[1]] = $m[2]; $haveVals[$m[2]] = true; }

    foreach ($mo as $m) {
        if (!isset($haveVals[$m[2]])) {
            $state = isset($haveKeys[$m[1]])
                ? 'key在但值=' . $haveKeys[$m[1]]
                : 'key不存在';
            echo "$f [{$m[1]}] 5.4=\"{$m[2]}\" ($state)\n";
        }
    }
}
