<?php
header('Content-Type: application/json');

$id = $_POST['id'] ?? '';
if (!$id) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing schedule ID']);
    exit;
}

$cfg = '/boot/config/plugins/flash-backup/schedules-remote.cfg';
if (!file_exists($cfg)) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Remote schedules file not found']);
    exit;
}

$schedules = parse_ini_file($cfg, true, INI_SCANNER_RAW);
if (!isset($schedules[$id])) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Remote schedule not found']);
    exit;
}

$s        = $schedules[$id];
$settings = json_decode(stripslashes($s['SETTINGS']), true);
if (!is_array($settings)) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Invalid remote schedule settings']);
    exit;
}

$env = '';
foreach ($settings as $k => $v) {
    // If value is an array (e.g. RCLONE_CONFIG_REMOTE[]), join to comma-separated string
    if (is_array($v)) {
        $v = implode(',', $v);
    }
    $env .= $k . '="' . addslashes($v) . '" ';
}
$env .= 'SCHEDULE_ID="' . addslashes($id) . '" ';

$lockDir = '/tmp/flash-backup';
$lock    = "$lockDir/lock.txt";

if (!is_dir($lockDir)) {
    mkdir($lockDir, 0777, true);
}

$fp = fopen($lock, 'c');
if (!$fp) {
    echo json_encode(['status' => 'error', 'message' => 'Unable to open lock file']);
    exit;
}

if (!flock($fp, LOCK_EX | LOCK_NB)) {
    echo json_encode(['status' => 'error', 'message' => 'Backup already running']);
    exit;
}

$script = '/usr/local/emhttp/plugins/flash-backup/helpers/backup_remote.sh';

if (!is_file($script) || !is_executable($script)) {
    echo json_encode(['status' => 'error', 'message' => 'Remote backup script missing or not executable']);
    exit;
}

$cmd = "nohup /usr/bin/env $env /bin/bash $script >/dev/null 2>&1 & echo $!";
$pid = trim(shell_exec($cmd));

if (!$pid || !is_numeric($pid)) {
    echo json_encode(['status' => 'error', 'message' => 'Failed to start remote scheduled backup']);
    exit;
}

$meta = [
    "PID=$pid",
    "MODE=schedule-remote-manual",
    "SCHEDULE_ID=$id",
    "START=" . time()
];

ftruncate($fp, 0);
fwrite($fp, implode("\n", $meta) . "\n");
fflush($fp);

echo json_encode([
    'status'  => 'ok',
    'started' => true,
    'id'      => $id,
    'pid'     => $pid
]);