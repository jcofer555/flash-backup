<?php
require_once 'rebuild_cron.php';

$cfg = '/boot/config/plugins/flash-backup/schedules.cfg';

$type     = $_POST['type'] ?? '';
$cron     = trim($_POST['cron'] ?? '');
$settings = $_POST['settings'] ?? [];

// Ensure settings is always an array
if (!is_array($settings)) {
    $settings = [];
}

// Validate cron
if (!preg_match('/^([\*\/0-9,-]+\s+){4}[\*\/0-9,-]+$/', $cron)) {
    http_response_code(400);
    exit("Invalid cron");
}

// Load existing schedules safely
$schedules = [];
if (file_exists($cfg)) {
    $schedules = parse_ini_file($cfg, true, INI_SCANNER_RAW);
}

// ---- Compute new fingerprint ----
$newDest = trim($settings['BACKUP_DESTINATION'] ?? '');

if ($newDest === '') {
    http_response_code(400);
    exit("Backup destination is required");
}

// ---- Check for duplicates ----
foreach ($schedules as $existingId => $s) {
    if (empty($s['SETTINGS'])) continue;

    $existingSettings = json_decode(stripslashes($s['SETTINGS']), true);
    if (!is_array($existingSettings)) continue;

    $existingDest = trim($existingSettings['BACKUP_DESTINATION'] ?? '');

    if ($existingDest !== '' && $existingDest === $newDest) {
        http_response_code(409);
        echo json_encode([
            'error'       => 'Duplicate schedule detected',
            'conflict_id' => $existingId
        ]);
        exit;
    }
}

// Generate unique ID
$id = 'schedule_' . time();

// ---- Encode settings safely for INI ----
$settingsJson = json_encode($settings, JSON_UNESCAPED_SLASHES);
$settingsJson = addcslashes($settingsJson, '"');

// Build INI block
$block  = "\n[$id]\n";
$block .= "TYPE=\"$type\"\n";
$block .= "CRON=\"$cron\"\n";
$block .= "ENABLED=\"yes\"\n";
$block .= "SETTINGS=\"$settingsJson\"\n";

// Append to schedules.cfg
file_put_contents($cfg, $block, FILE_APPEND);

// Rebuild cron file
rebuild_cron();

// Success response
echo json_encode([
    'success' => true,
    'id'      => $id
]);
