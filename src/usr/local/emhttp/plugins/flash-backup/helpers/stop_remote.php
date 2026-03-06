<?php
header('Content-Type: application/json');
$lock = '/tmp/flash-backup/lock.txt';
if (!file_exists($lock)) { http_response_code(400); echo json_encode(['error' => 'No remote backup running']); exit; }
$lockContent = @file_get_contents($lock);
if ($lockContent && preg_match('/MODE=(\S+)/', $lockContent, $m) && $m[1] !== 'remote' && $m[1] !== 'schedule-remote') {
    http_response_code(400); echo json_encode(['error' => 'Local backup is running, not remote']); exit;
}
// Read script PID from lock file and send SIGTERM so the watcher loop picks it up
if ($lockContent && preg_match('/PID=(\d+)/', $lockContent, $m)) {
    shell_exec("kill -15 " . $m[1] . " 2>&1");
}
// Also touch the stop flag as the watcher watches for it
touch('/tmp/flash-backup/stop_requested_remote.txt');
echo json_encode(['ok' => true]);
