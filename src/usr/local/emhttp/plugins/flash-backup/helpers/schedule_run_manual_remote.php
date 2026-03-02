<?php

define('REMOTE_SCHEDULES_CFG', '/boot/config/plugins/flash-backup/schedules-remote.cfg');
define('LOCK_DIR',             '/tmp/flash-backup');
define('LOCK_FILE',            LOCK_DIR . '/lock.txt');
define('REMOTE_BACKUP_SCRIPT', '/usr/local/emhttp/plugins/flash-backup/helpers/backup_remote.sh');

// ------------------------------------------------------------------------------
// respond() — deterministic JSON response with explicit HTTP code, then exit
// ------------------------------------------------------------------------------
function respond(int $code, array $payload): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

// ------------------------------------------------------------------------------
// load_schedules() — guarded, realpath-normalized
// ------------------------------------------------------------------------------
function load_schedules(string $cfg): array {
    $real = realpath($cfg);
    if ($real === false || !file_exists($real)) {
        respond(404, ['status' => 'error', 'message' => 'Remote schedules file not found']);
    }
    $schedules = parse_ini_file($real, true, INI_SCANNER_RAW);
    if (!is_array($schedules)) {
        respond(500, ['status' => 'error', 'message' => 'Failed to parse remote schedules file']);
    }
    return $schedules;
}

// ------------------------------------------------------------------------------
// acquire_lock() — guarded lock dir creation, non-blocking exclusive lock
// ------------------------------------------------------------------------------
function acquire_lock(): mixed {
    if (!is_dir(LOCK_DIR)) {
        if (!mkdir(LOCK_DIR, 0777, true)) {
            respond(500, ['status' => 'error', 'message' => 'Unable to create lock directory']);
        }
    }

    $fp = fopen(LOCK_FILE, 'c');
    if (!$fp) {
        respond(500, ['status' => 'error', 'message' => 'Unable to open lock file']);
    }

    if (!flock($fp, LOCK_EX | LOCK_NB)) {
        respond(409, ['status' => 'error', 'message' => 'Backup already running']);
    }

    return $fp;
}

// ------------------------------------------------------------------------------
// build_env() — deterministic environment string, arrays joined, all values escaped
// ------------------------------------------------------------------------------
function build_env(array $settings, string $id): string {
    $env = '';
    foreach ($settings as $key => $val) {
        if (is_array($val)) {
            $val = implode(',', $val);
        }
        $env .= $key . '="' . addslashes((string)$val) . '" ';
    }
    $env .= 'SCHEDULE_ID="' . addslashes($id) . '" ';
    return $env;
}

// ------------------------------------------------------------------------------
// write_lock_meta() — atomic metadata write into open lock file handle
// ------------------------------------------------------------------------------
function write_lock_meta(mixed $fp, string $pid, string $id): void {
    $meta = implode("\n", [
        "PID={$pid}",
        "MODE=schedule-remote-manual",
        "SCHEDULE_ID={$id}",
        "START=" . time(),
    ]) . "\n";

    ftruncate($fp, 0);
    fwrite($fp, $meta);
    fflush($fp);
}

// ------------------------------------------------------------------------------
// main() — explicit entrypoint, all state explicit
// ------------------------------------------------------------------------------
function main(): void {
    $id = trim($_POST['id'] ?? '');

    if ($id === '') {
        respond(400, ['status' => 'error', 'message' => 'Missing schedule ID']);
    }

    // --- Load and verify schedule exists ---
    $schedules = load_schedules(REMOTE_SCHEDULES_CFG);

    if (!isset($schedules[$id])) {
        respond(404, ['status' => 'error', 'message' => 'Remote schedule not found']);
    }

    // --- Decode settings ---
    $settings = json_decode(stripslashes($schedules[$id]['SETTINGS'] ?? ''), true);
    if (!is_array($settings)) {
        respond(500, ['status' => 'error', 'message' => 'Invalid remote schedule settings']);
    }

    // --- Verify backup script ---
    if (!is_file(REMOTE_BACKUP_SCRIPT) || !is_executable(REMOTE_BACKUP_SCRIPT)) {
        respond(500, ['status' => 'error', 'message' => 'Remote backup script missing or not executable']);
    }

    // --- Acquire exclusive lock ---
    $fp = acquire_lock();

    // --- Build and launch command ---
    $env = build_env($settings, $id);
    $cmd = "nohup /usr/bin/env {$env} /bin/bash " . REMOTE_BACKUP_SCRIPT . " >/dev/null 2>&1 & echo $!";
    $pid = trim((string)shell_exec($cmd));

    if ($pid === '' || !is_numeric($pid)) {
        respond(500, ['status' => 'error', 'message' => 'Failed to start remote scheduled backup']);
    }

    // --- Write lock metadata, keep handle open to hold lock ---
    write_lock_meta($fp, $pid, $id);

    respond(200, [
        'status'  => 'ok',
        'started' => true,
        'id'      => $id,
        'pid'     => $pid,
    ]);
}

main();