<?php
require_once 'rebuild_cron_remote.php';

$cfg = '/boot/config/plugins/flash-backup/schedules-remote.cfg';
$id  = $_POST['id'];

$schedules = parse_ini_file($cfg, true, INI_SCANNER_RAW);

unset($schedules[$id]);

$out = '';
foreach ($schedules as $k => $s) {
    $out .= "[$k]\n";

    foreach ($s as $kk => $vv) {
        $out .= $kk . '="' . $vv . '"' . "\n";
    }

    $out .= "\n";
}

file_put_contents($cfg, $out);
rebuild_cron_remote();