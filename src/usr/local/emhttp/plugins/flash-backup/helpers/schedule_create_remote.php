<?php
require_once 'rebuild_cron_remote.php';

$cfg = '/boot/config/plugins/flash-backup/schedules-remote.cfg';

$type     = $_POST['type'] ?? '';
$cron     = trim($_POST['cron'] ?? '');
$settings = $_POST['settings'] ?? [];

if (!is_array($settings)) {
    $settings = [];
}

if (!preg_match('/^([\*\/0-9,-]+\s+){4}[\*\/0-9,-]+$/', $cron)) {
    http_response_code(400);
    exit("Invalid cron");
}

$schedules = [];
if (file_exists($cfg)) {
    $schedules = parse_ini_file($cfg, true, INI_SCANNER_RAW);
}

// ---- Compute new fingerprint based on RCLONE_CONFIG_REMOTE ----
$newRemote = trim($settings['RCLONE_CONFIG_REMOTE'] ?? '');

if ($newRemote === '') {
    http_response_code(400);
    exit("Rclone config is required");
}

// ---- Check for duplicates based on RCLONE_CONFIG_REMOTE ----
foreach ($schedules as $existingId => $s) {
    if (empty($s['SETTINGS'])) continue;

    $existingSettings = json_decode(stripslashes($s['SETTINGS']), true);
    if (!is_array($existingSettings)) continue;

    $existingRemote = trim($existingSettings['RCLONE_CONFIG_REMOTE'] ?? '');

    if ($existingRemote !== '' && $existingRemote === $newRemote) {
        http_response_code(409);
        echo json_encode([
            'error'       => 'Duplicate remote schedule detected',
            'conflict_id' => $existingId
        ]);
        exit;
    }
}

$id = 'schedule_remote_' . time();

$settingsJson = json_encode($settings, JSON_UNESCAPED_SLASHES);
$settingsJson = addcslashes($settingsJson, '"');

$block  = "\n[$id]\n";
$block .= "TYPE=\"$type\"\n";
$block .= "CRON=\"$cron\"\n";
$block .= "ENABLED=\"yes\"\n";
$block .= "SETTINGS=\"$settingsJson\"\n";

file_put_contents($cfg, $block, FILE_APPEND);

rebuild_cron_remote();

echo json_encode([
    'success' => true,
    'id'      => $id
]);