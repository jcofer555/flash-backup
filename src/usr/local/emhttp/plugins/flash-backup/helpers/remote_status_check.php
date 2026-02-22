<?php
header('Content-Type: application/json');

$status_file = '/tmp/flash-backup/remote_backup_status.txt';

$status = 'Remote Backup Not Running';

if (file_exists($status_file)) {
    $raw = trim(file_get_contents($status_file));
    if ($raw !== '') {
        $status = $raw;
    }
}

echo json_encode([
    'status' => $status
]);
