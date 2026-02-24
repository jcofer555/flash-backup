<?php
require_once 'rebuild_cron_remote.php';

$cfg = '/boot/config/plugins/flash-backup/schedules-remote.cfg';

$id       = $_POST['id'] ?? '';
$cron     = trim($_POST['cron'] ?? '');
$settings = $_POST['settings'] ?? [];

if (!is_array($settings)) {
    $settings = [];
}

if (!$id) {
    http_response_code(400);
    exit("Missing schedule ID");
}

if (!preg_match('/^([\*\/0-9,-]+\s+){4}[\*\/0-9,-]+$/', $cron)) {
    http_response_code(400);
    exit("Invalid cron");
}

if (!file_exists($cfg)) {
    http_response_code(404);
    exit("Remote schedules file not found");
}

$schedules = parse_ini_file($cfg, true, INI_SCANNER_RAW);

if (!isset($schedules[$id])) {
    http_response_code(404);
    exit("Remote schedule not found");
}

$newFingerprint = [
    'BACKUP_DESTINATION' => $settings['BACKUP_DESTINATION'] ?? '',
];
ksort($newFingerprint);
$newHash = hash('sha256', json_encode($newFingerprint));

foreach ($schedules as $existingId => $s) {
    if ($existingId === $id) continue;
    if (empty($s['SETTINGS'])) continue;

    $existingSettings = json_decode(stripslashes($s['SETTINGS']), true);
    if (!is_array($existingSettings)) continue;

    $existingFingerprint = [
        'BACKUP_DESTINATION' => $existingSettings['BACKUP_DESTINATION'] ?? '',
    ];
    ksort($existingFingerprint);
    $existingHash = hash('sha256', json_encode($existingFingerprint));

    if ($existingHash === $newHash) {
        http_response_code(409);
        echo json_encode([
            'error'       => 'Duplicate remote schedule detected',
            'conflict_id' => $existingId
        ]);
        exit;
    }
}

$settingsJson = json_encode($settings, JSON_UNESCAPED_SLASHES);
$settingsJson = addcslashes($settingsJson, '"');

$schedules[$id]['CRON']     = $cron;
$schedules[$id]['SETTINGS'] = $settingsJson;

$out = '';
foreach ($schedules as $k => $s) {
    $out .= "[$k]\n";
    foreach ($s as $kk => $vv) {
        $out .= "$kk=\"$vv\"\n";
    }
    $out .= "\n";
}

file_put_contents($cfg, $out);

rebuild_cron_remote();

echo json_encode(['success' => true]);