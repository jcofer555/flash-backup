<?php
$cfg = '/boot/config/plugins/flash-backup/schedules-remote.cfg';
$id  = $_GET['id'] ?? null;

if (!$id) {
    http_response_code(400);
    exit("Missing schedule ID");
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

$entry = $schedules[$id];

$settingsRaw = $entry['SETTINGS'] ?? '{}';
$settings    = json_decode(stripslashes($settingsRaw), true);

if (!is_array($settings)) {
    $settings = [];
}

$entry['SETTINGS'] = $settings;

header('Content-Type: application/json');
echo json_encode($entry);