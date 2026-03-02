<?php

define('SAVE_SETTINGS_REMOTE_SCRIPT', '/usr/local/emhttp/plugins/flash-backup/helpers/save_settings_remote.sh');

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
// normalize_remote_path() — deterministic leading+trailing slash enforcement
// ------------------------------------------------------------------------------
function normalize_remote_path(string $path): string {
    $path = trim($path);
    if ($path === '') {
        return '/Flash_Backups/';
    }
    if ($path[0] !== '/') {
        $path = '/' . $path;
    }
    if (substr($path, -1) !== '/') {
        $path .= '/';
    }
    return $path;
}

// ------------------------------------------------------------------------------
// normalize_b2_bucket() — deterministic no-leading, trailing slash enforcement
// ------------------------------------------------------------------------------
function normalize_b2_bucket(string $bucket): string {
    $bucket = trim($bucket);
    if ($bucket === '') {
        return '';
    }
    $bucket = ltrim($bucket, '/');
    if (substr($bucket, -1) !== '/') {
        $bucket .= '/';
    }
    return $bucket;
}

// ------------------------------------------------------------------------------
// normalize_rclone_config() — array-to-csv or passthrough string
// ------------------------------------------------------------------------------
function normalize_rclone_config(mixed $value): string {
    if (is_array($value)) {
        return implode(',', array_map('trim', $value));
    }
    return (string)$value;
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
    if (!is_file(SAVE_SETTINGS_REMOTE_SCRIPT) || !is_executable(SAVE_SETTINGS_REMOTE_SCRIPT)) {
        respond(500, ['status' => 'error', 'message' => 'Save remote settings script missing or not executable']);
    }

    $args = [
        $_GET['MINIMAL_BACKUP_REMOTE']       ?? '',
        normalize_rclone_config($_GET['RCLONE_CONFIG_REMOTE'] ?? ''),
        normalize_b2_bucket($_GET['B2_BUCKET_NAME']           ?? ''),
        normalize_remote_path($_GET['REMOTE_PATH_IN_CONFIG']  ?? ''),
        $_GET['BACKUPS_TO_KEEP_REMOTE']      ?? '',
        $_GET['DRY_RUN_REMOTE']              ?? '',
        $_GET['NOTIFICATIONS_REMOTE']        ?? '',
        $_GET['NOTIFICATION_SERVICE_REMOTE'] ?? '',
        $_GET['WEBHOOK_DISCORD_REMOTE']      ?? '',
        $_GET['WEBHOOK_GOTIFY_REMOTE']       ?? '',
        $_GET['WEBHOOK_NTFY_REMOTE']         ?? '',
        $_GET['WEBHOOK_PUSHOVER_REMOTE']     ?? '',
        $_GET['WEBHOOK_SLACK_REMOTE']        ?? '',
        $_GET['PUSHOVER_USER_KEY_REMOTE']    ?? '',
    ];

    $cmd = SAVE_SETTINGS_REMOTE_SCRIPT . ' ' . implode(' ', array_map('escapeshellarg', $args));

    run_script($cmd);
}

main();