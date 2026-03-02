<?php

define('SCHEDULE_CFGS', [
    '/boot/config/plugins/flash-backup/schedules.cfg',
    '/boot/config/plugins/flash-backup/schedules-remote.cfg',
]);

// ------------------------------------------------------------------------------
// respond() — deterministic JSON response with explicit HTTP code, then exit
// ------------------------------------------------------------------------------
function respond(int $code, array $payload): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

// ------------------------------------------------------------------------------
// load_schedules() — guarded, realpath-normalized; returns empty array on failure
// ------------------------------------------------------------------------------
function load_schedules(string $cfg): array {
    $real = realpath($cfg);
    if ($real === false || !file_exists($real)) {
        return [];
    }
    $schedules = parse_ini_file($real, true, INI_SCANNER_RAW);
    return is_array($schedules) ? $schedules : [];
}

// ------------------------------------------------------------------------------
// main() — explicit entrypoint, all state explicit
// ------------------------------------------------------------------------------
function main(): void {
    $crons = [];

    foreach (SCHEDULE_CFGS as $cfg) {
        $schedules = load_schedules($cfg);

        foreach ($schedules as $id => $s) {
            $cron    = trim($s['CRON'] ?? '');
            $enabled = strtolower((string)($s['ENABLED'] ?? 'yes')) === 'yes';

            if ($cron === '') continue;

            $crons[] = [
                'id'      => $id,
                'cron'    => $cron,
                'enabled' => $enabled,
            ];
        }
    }

    respond(200, $crons);
}

main();