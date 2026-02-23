<?php
function rebuild_cron() {
    $cfg = '/boot/config/plugins/flash-backup/schedules.cfg';
    $cronFile = '/boot/config/plugins/flash-backup/flash-backup.cron';

    if (!file_exists($cfg)) {
        file_put_contents($cronFile, "");
        return;
    }

    $schedules = parse_ini_file($cfg, true, INI_SCANNER_RAW);

    $out = "# Flash backup schedules\n";

    foreach ($schedules as $id => $s) {

        // Normalize ENABLED (handles yes/no, true/false, quoted/unquoted)
        $enabled = strtolower((string)($s['ENABLED'] ?? 'yes')) === 'yes';
        if (!$enabled) {
            continue;
        }

        $cron = trim((string)($s['CRON'] ?? ''));
        if ($cron === '') {
            continue;
        }

        $out .= $cron . " php ";
        $out .= "/usr/local/emhttp/plugins/flash-backup/helpers/run_schedule.php $id\n";
    }

    file_put_contents($cronFile, $out);

    exec('update_cron');
}
