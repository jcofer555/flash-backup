<?php
header('Content-Type: application/json');

$status_file = '/tmp/flash-backup/local_backup_status.txt';

$status = 'Local Backup Not Running';

if (file_exists($status_file)) {
    $raw = trim(file_get_contents($status_file));
    if ($raw !== '') {
        $status = $raw;
    }
}

$running = ($status !== 'Local Backup Not Running');

echo json_encode([
    'status' => $status,
    'running' => $running
]);
