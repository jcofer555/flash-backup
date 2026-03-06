<?php

define('SCHEDULES_CFG',   '/boot/config/plugins/flash-backup/schedules.cfg');
define('CRON_FILE',       '/boot/config/plugins/flash-backup/flash-backup.cron');
define('RUN_SCHEDULE_PHP', '/usr/local/emhttp/plugins/flash-backup/helpers/run_schedule.php');

// ------------------------------------------------------------------------------
// rebuild_cron() — deterministic cron file rebuild, atomic write
// ------------------------------------------------------------------------------
function rebuild_cron(): void {
    if (!file_exists(SCHEDULES_CFG)) {
        file_put_contents(CRON_FILE, '');
        return;
    }

    $schedules = parse_ini_file(SCHEDULES_CFG, true, INI_SCANNER_RAW);
    if (!is_array($schedules)) {
        file_put_contents(CRON_FILE, '');
        return;
    }

    $out = "# Flash backup schedules\n";

    foreach ($schedules as $id => $s) {
        $enabled = strtolower((string)($s['ENABLED'] ?? 'yes')) === 'yes';
        if (!$enabled) continue;

        $cron = trim((string)($s['CRON'] ?? ''));
        if ($cron === '') continue;

        $out .= "{$cron} sh -c 'SCHEDULE_ID={$id} /usr/bin/php -f " . RUN_SCHEDULE_PHP . " '\n";
    }

    file_put_contents(CRON_FILE, $out);

    exec('update_cron');
}