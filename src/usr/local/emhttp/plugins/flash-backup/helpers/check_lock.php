<?php
$lock = '/tmp/flash-backup/lock.txt';

header('Content-Type: application/json');
echo json_encode([
    'locked' => file_exists($lock)
]);
