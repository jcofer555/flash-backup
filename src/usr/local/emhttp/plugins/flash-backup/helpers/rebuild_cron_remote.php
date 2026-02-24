<?php
function rebuild_cron_remote() {
    $cfg      = '/boot/config/plugins/flash-backup/schedules-remote.cfg';
    $cronFile = '/boot/config/plugins/flash-backup/flash-backup-remote.cron';

    if (!file_exists($cfg)) {
        file_put_contents($cronFile, "");
        return;
    }

    $schedules = parse_ini_file($cfg, true, INI_SCANNER_RAW);

    $out = "# Flash backup remote schedules\n";

    foreach ($schedules as $id => $s) {

        $enabled = strtolower((string)($s['ENABLED'] ?? 'yes')) === 'yes';
        if (!$enabled) {
            continue;
        }

        $cron = trim((string)($s['CRON'] ?? ''));
        if ($cron === '') {
            continue;
        }

        $out .= $cron . " php ";
        $out .= "/usr/local/emhttp/plugins/flash-backup/helpers/run_schedule_remote.php $id\n";
    }

    file_put_contents($cronFile, $out);

    exec('update_cron');
}