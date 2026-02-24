<?php
require_once 'rebuild_cron_remote.php';

$cfg = '/boot/config/plugins/flash-backup/schedules-remote.cfg';
$id  = $_POST['id'] ?? '';

if (!$id || !file_exists($cfg)) {
    exit;
}

$schedules = parse_ini_file($cfg, true, INI_SCANNER_RAW);

if (!isset($schedules[$id])) {
    exit;
}

$current               = strtolower((string)($schedules[$id]['ENABLED'] ?? 'yes'));
$schedules[$id]['ENABLED'] = ($current === 'yes') ? 'no' : 'yes';

$out = '';
foreach ($schedules as $section => $values) {
    $out .= "[$section]\n";
    foreach ($values as $key => $value) {
        $out .= $key . '="' . (string)$value . '"' . "\n";
    }
    $out .= "\n";
}

file_put_contents($cfg, $out);

rebuild_cron_remote();