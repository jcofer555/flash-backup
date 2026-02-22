<?php
header('Content-Type: application/json');

$lockDir = '/tmp/flash-backup';
$lock = "$lockDir/lock.txt";
$script = '/usr/local/emhttp/plugins/flash-backup/helpers/remote_backup.sh';

if (!is_dir($lockDir)) {
    mkdir($lockDir, 0777, true);
}

// Load settings.cfg
$settingsFile = "/boot/config/plugins/flash-backup/settings_remote.cfg";
$settings = [];

if (is_file($settingsFile)) {
    $lines = file($settingsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '=') !== false) {
            list($k, $v) = explode('=', $line, 2);
            $settings[$k] = $v;
        }
    }
}

// Export settings as environment variables
foreach ($settings as $k => $v) {
    putenv("$k=$v");
}

// Open lock file
$fp = fopen($lock, 'c');
if (!$fp) {
    echo json_encode(['status' => 'error', 'message' => 'Unable to open remote lock file']);
    exit;
}

// Try to acquire exclusive lock
if (!flock($fp, LOCK_EX | LOCK_NB)) {
    echo json_encode(['status' => 'error', 'message' => 'Remote backup already running']);
    exit;
}

// Validate script
if (!is_file($script) || !is_executable($script)) {
    echo json_encode(['status' => 'error', 'message' => 'Remote backup script missing or not executable']);
    exit;
}

// Launch remote backup script asynchronously
$cmd = "nohup /bin/bash $script >/dev/null 2>&1 & echo $!";
$pid = trim(shell_exec($cmd));

if (!$pid || !is_numeric($pid)) {
    echo json_encode(['status' => 'error', 'message' => 'Failed to start remote backup']);
    exit;
}

// Write metadata
$meta = [
    "PID=$pid",
    "MODE=manual",
    "START=" . time()
];

ftruncate($fp, 0);
fwrite($fp, implode("\n", $meta) . "\n");
fflush($fp);

echo json_encode([
    'status' => 'ok',
    'pid' => $pid
]);
