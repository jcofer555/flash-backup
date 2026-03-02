<?php

define('REMOTE_SETTINGS_FILE', '/boot/config/plugins/flash-backup/settings_remote.cfg');
define('LOCK_DIR',             '/tmp/flash-backup');
define('LOCK_FILE',            LOCK_DIR . '/lock.txt');
define('REMOTE_BACKUP_SCRIPT', '/usr/local/emhttp/plugins/flash-backup/helpers/remote_backup.sh');

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
// load_and_export_settings() — guarded settings parse, explicit env export
// ------------------------------------------------------------------------------
function load_and_export_settings(string $settings_file): void {
    if (!is_file($settings_file)) {
        return;
    }

    $lines = file($settings_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return;
    }

    foreach ($lines as $line) {
        if (strpos($line, '=') === false) continue;
        [$key, $val] = explode('=', $line, 2);
        putenv("{$key}={$val}");
    }
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
        respond(500, ['status' => 'error', 'message' => 'Unable to open remote lock file']);
    }

    if (!flock($fp, LOCK_EX | LOCK_NB)) {
        respond(409, ['status' => 'error', 'message' => 'Remote backup already running']);
    }

    return $fp;
}

// ------------------------------------------------------------------------------
// write_lock_meta() — atomic metadata write into open lock file handle
// ------------------------------------------------------------------------------
function write_lock_meta(mixed $fp, string $pid): void {
    $meta = implode("\n", [
        "PID={$pid}",
        "MODE=remote",
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
    // --- Verify backup script before acquiring lock ---
    if (!is_file(REMOTE_BACKUP_SCRIPT) || !is_executable(REMOTE_BACKUP_SCRIPT)) {
        respond(500, ['status' => 'error', 'message' => 'Remote backup script missing or not executable']);
    }

    // --- Load and export settings ---
    load_and_export_settings(REMOTE_SETTINGS_FILE);

    // --- Acquire exclusive lock ---
    $fp = acquire_lock();

    // --- Launch script ---
    $cmd = 'nohup /bin/bash ' . REMOTE_BACKUP_SCRIPT . ' >/dev/null 2>&1 & echo $!';
    $pid = trim((string)shell_exec($cmd));

    if ($pid === '' || !is_numeric($pid)) {
        respond(500, ['status' => 'error', 'message' => 'Failed to start remote backup']);
    }

    // --- Write lock metadata, keep handle open to hold lock ---
    write_lock_meta($fp, $pid);

    respond(200, [
        'status' => 'ok',
        'pid'    => $pid,
    ]);
}

main();