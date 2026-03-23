<?php

require_once __DIR__ . '/rebuild_cron_remote.php';
// Path to the remote schedules config file
define('REMOTE_SCHEDULES_CFG', '/boot/config/plugins/flash-backup/schedules-remote.cfg');
// Regex pattern for validating a standard 5-field cron expression
define('REMOTE_CRON_PATTERN',  '/^([\*\/0-9,-]+\s+){4}[\*\/0-9,-]+$/');

// ------------------------------------------------------------------------------
// respond() — JSON response with explicit HTTP code, then exit
// ------------------------------------------------------------------------------
function respond(int $code, array $payload): void
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

// ------------------------------------------------------------------------------
// load_schedules()
// ------------------------------------------------------------------------------
function load_schedules(string $cfg): array
{
    $real = realpath($cfg);
    if ($real === false || !file_exists($real)) {
        respond(404, ['error' => 'Remote schedules file not found']);
    }
    $schedules = parse_ini_file($real, true, INI_SCANNER_RAW);
    if (!is_array($schedules)) {
        respond(500, ['error' => 'Failed to parse remote schedules file']);
    }
    return $schedules;
}

// ------------------------------------------------------------------------------
// write_schedules() — tmp then rename
// ------------------------------------------------------------------------------
function write_schedules(string $cfg, array $schedules): void
{
    $real = realpath($cfg);
    if ($real === false) {
        respond(500, ['error' => 'Cannot resolve remote schedules file path']);
    }

    $tmp = $real . '.tmp';
    $out = '';
    foreach ($schedules as $id => $fields) {
        $out .= "[{$id}]\n";
        // Sort keys so the file layout is deterministic regardless of insertion order
        ksort($fields);
        foreach ($fields as $key => $val) {
            $out .= "{$key}=\"{$val}\"\n";
        }
        $out .= "\n";
    }

    if (file_put_contents($tmp, $out) === false) {
        respond(500, ['error' => 'Failed to write temporary remote schedules file']);
    }
    if (!rename($tmp, $real)) {
        @unlink($tmp);
        respond(500, ['error' => 'Failed to commit remote schedules file update']);
    }
}

// ------------------------------------------------------------------------------
// main()
// ------------------------------------------------------------------------------
function main(): void
{
    $id       = trim($_POST['id']    ?? '');
    $cron     = trim($_POST['cron']  ?? '');
    $settings = $_POST['settings']   ?? [];

    if (!is_array($settings)) {
        $settings = [];
    }

    // Allowlist: only store fields that belong in a remote schedule
    $allowed = [
        'B2_BUCKET_NAME',           // legacy — kept so old saved schedules can still be read
        'BACKUPS_TO_KEEP_REMOTE',
        'BUCKET_NAMES',             // base64-encoded JSON map of per-remote bucket names
        'DRY_RUN_REMOTE',
        'MINIMAL_BACKUP_REMOTE',
        'NOTIFICATION_SERVICE_REMOTE',
        'NOTIFICATIONS_REMOTE',
        'PUSHOVER_USER_KEY_REMOTE',
        'RCLONE_CONFIG_REMOTE',
        'REMOTE_PATH_IN_CONFIG',
        'WEBHOOK_DISCORD_REMOTE',
        'WEBHOOK_GOTIFY_REMOTE',
        'WEBHOOK_NTFY_REMOTE',
        'WEBHOOK_PUSHOVER_REMOTE',
        'WEBHOOK_SLACK_REMOTE',
    ];
    $settings = array_intersect_key($settings, array_flip($allowed));

    // Always exclude UI-only fields that must not be persisted
    $exclude = ['csrf_token', 'CRON_EXPRESSION'];
    $settings = array_diff_key($settings, array_flip($exclude));

    // Input validation
    if ($id === '') {
        respond(400, ['error' => 'Missing schedule ID']);
    }

    if (!preg_match(REMOTE_CRON_PATTERN, $cron)) {
        respond(400, ['error' => 'Invalid cron expression']);
    }

    // Load and verify the schedule exists
    $schedules = load_schedules(REMOTE_SCHEDULES_CFG);

    if (!isset($schedules[$id])) {
        respond(404, ['error' => 'Remote schedule not found']);
    }

    // Encode settings as escaped JSON for safe INI storage
    $settings_json = addcslashes(
        json_encode($settings, JSON_UNESCAPED_SLASHES),
        '"'
    );

    // Apply update
    $schedules[$id]['CRON']     = $cron;
    $schedules[$id]['SETTINGS'] = $settings_json;

    // Write
    write_schedules(REMOTE_SCHEDULES_CFG, $schedules);

    // Rebuild cron jobs so the updated schedule takes effect
    rebuild_cron_remote();

    respond(200, ['success' => true]);
}

main();
