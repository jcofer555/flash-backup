<?php

require_once __DIR__ . '/rebuild_cron.php';
define('SCHEDULES_CFG',  '/boot/config/plugins/flash-backup/schedules.cfg');
define('CRON_PATTERN',   '/^([\*\/0-9,-]+\s+){4}[\*\/0-9,-]+$/');

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
// load_schedules() — guarded, realpath-normalized; returns empty array if missing
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
// check_duplicate() — explicit destination conflict detection
// ------------------------------------------------------------------------------
function check_duplicate(array $schedules, string $new_dest): void {
    foreach ($schedules as $existing_id => $s) {
        if (empty($s['SETTINGS'])) continue;

        $existing_settings = json_decode(stripslashes($s['SETTINGS']), true);
        if (!is_array($existing_settings)) continue;

        $existing_dest = trim($existing_settings['BACKUP_DESTINATION'] ?? '');
        if ($existing_dest !== '' && $existing_dest === $new_dest) {
            respond(409, [
                'error'       => 'Duplicate schedule detected',
                'conflict_id' => $existing_id,
            ]);
        }
    }
}

// ------------------------------------------------------------------------------
// append_schedule() — guarded append of new INI block
// ------------------------------------------------------------------------------
function append_schedule(string $cfg, string $id, string $type, string $cron, string $settings_json): void {
    $real = realpath($cfg);

    // If file doesn't exist yet, use the raw path for first write
    $target = ($real !== false) ? $real : $cfg;

    $block  = "\n[{$id}]\n";
    $block .= "CRON=\"{$cron}\"\n";
    $block .= "ENABLED=\"yes\"\n";
    $block .= "SETTINGS=\"{$settings_json}\"\n";
    $block .= "TYPE=\"{$type}\"\n";

    if (file_put_contents($target, $block, FILE_APPEND) === false) {
        respond(500, ['error' => 'Failed to write schedule']);
    }
}

// ------------------------------------------------------------------------------
// main() — explicit entrypoint, all state explicit
// ------------------------------------------------------------------------------
function main(): void {
    $type     = trim($_POST['type']     ?? '');
    $cron     = trim($_POST['cron']     ?? '');
    $settings = $_POST['settings']      ?? [];

    if (!is_array($settings)) {
        $settings = [];
    }

    // --- Input validation ---
    if (!preg_match(CRON_PATTERN, $cron)) {
        respond(400, ['error' => 'Invalid cron expression']);
    }

    $new_dest = trim($settings['BACKUP_DESTINATION'] ?? '');
    if ($new_dest === '') {
        respond(400, ['error' => 'Backup destination is required']);
    }

    // --- Load existing schedules and check for duplicates ---
    $schedules = load_schedules(SCHEDULES_CFG);
    check_duplicate($schedules, $new_dest);

    // --- Encode settings for INI storage ---
    $settings_json = addcslashes(
        json_encode($settings, JSON_UNESCAPED_SLASHES),
        '"'
    );

    // --- Generate unique ID and append ---
    $id = 'schedule_' . time();
    append_schedule(SCHEDULES_CFG, $id, $type, $cron, $settings_json);

    rebuild_cron();

    respond(200, [
        'success' => true,
        'id'      => $id,
    ]);
}

main();