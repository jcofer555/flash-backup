<?php

define('SAVE_SETTINGS_SCRIPT', '/usr/local/emhttp/plugins/flash-backup/helpers/save_settings.sh');

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
// run_script() — guarded proc_open execution, explicit stdout/stderr handling
// ------------------------------------------------------------------------------
function run_script(string $cmd): void {
    $process = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

    if (!is_resource($process)) {
        respond(500, ['status' => 'error', 'message' => 'Failed to start process']);
    }

    $output = stream_get_contents($pipes[1]);
    $error  = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    $output = trim($output);
    $error  = trim($error);

    if ($output !== '') {
        header('Content-Type: application/json');
        echo $output;
        exit;
    }

    respond(500, ['status' => 'error', 'message' => $error !== '' ? $error : 'No response from shell script']);
}

// ------------------------------------------------------------------------------
// main() — explicit entrypoint, all state explicit
// ------------------------------------------------------------------------------
function main(): void {
    if (!is_file(SAVE_SETTINGS_SCRIPT) || !is_executable(SAVE_SETTINGS_SCRIPT)) {
        respond(500, ['status' => 'error', 'message' => 'Save settings script missing or not executable']);
    }

    $args = [
        $_GET['MINIMAL_BACKUP']       ?? '',
        $_GET['BACKUP_DESTINATION']   ?? '',
        $_GET['BACKUPS_TO_KEEP']      ?? '',
        $_GET['BACKUP_OWNER']         ?? '',
        $_GET['DRY_RUN']              ?? '',
        $_GET['NOTIFICATIONS']        ?? '',
        $_GET['NOTIFICATION_SERVICE'] ?? '',
        $_GET['WEBHOOK_DISCORD']      ?? '',
        $_GET['WEBHOOK_GOTIFY']       ?? '',
        $_GET['WEBHOOK_NTFY']         ?? '',
        $_GET['WEBHOOK_PUSHOVER']     ?? '',
        $_GET['WEBHOOK_SLACK']        ?? '',
        $_GET['PUSHOVER_USER_KEY']    ?? '',
    ];

    $cmd = SAVE_SETTINGS_SCRIPT . ' ' . implode(' ', array_map('escapeshellarg', $args));

    run_script($cmd);
}

main();