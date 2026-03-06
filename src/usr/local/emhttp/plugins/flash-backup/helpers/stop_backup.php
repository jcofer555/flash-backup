<?php
header('Content-Type: application/json');
$lock = '/tmp/flash-backup/lock.txt';
if (!file_exists($lock)) { http_response_code(400); echo json_encode(['error' => 'No backup running']); exit; }
$lockContent = @file_get_contents($lock);
if ($lockContent && preg_match('/MODE=(\S+)/', $lockContent, $m) && $m[1] === 'remote') {
    http_response_code(400); echo json_encode(['error' => 'Remote backup is running, not local']); exit;
}
$tarPid = trim(@file_get_contents('/tmp/flash-backup/tar.pid'));
if ($tarPid && is_numeric($tarPid)) { shell_exec("kill -15 " . $tarPid . " 2>&1"); }
touch('/tmp/flash-backup/stop_requested.txt');
echo json_encode(['ok' => true]);
