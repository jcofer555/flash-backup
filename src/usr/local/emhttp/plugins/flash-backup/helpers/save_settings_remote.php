<?php
header('Content-Type: application/json');

$cmd = '/usr/local/emhttp/plugins/flash-backup/helpers/save_settings_remote.sh';

// --- Grab raw values ---
$minimal_backup_remote       = $_GET['MINIMAL_BACKUP_REMOTE'] ?? '';
$rclone_config_remote        = $_GET['RCLONE_CONFIG_REMOTE'] ?? '';
$b2_bucket_name              = $_GET['B2_BUCKET_NAME'] ?? '';
$remote_path_in_config       = $_GET['REMOTE_PATH_IN_CONFIG'] ?? '';
$backups_to_keep_remote      = $_GET['BACKUPS_TO_KEEP_REMOTE'] ?? '';
$dry_run_remote              = $_GET['DRY_RUN_REMOTE'] ?? '';
$notifications_remote        = $_GET['NOTIFICATIONS_REMOTE'] ?? '';
$discord_webhook_url_remote  = $_GET['DISCORD_WEBHOOK_URL_REMOTE'] ?? '';
$pushover_user_key_remote    = $_GET['PUSHOVER_USER_KEY_REMOTE'] ?? '';

// --- Normalize remote path in config ---
$remote_path_in_config = trim($remote_path_in_config);

// Default blank to /Flash_Backups/
if ($remote_path_in_config === '') {
    $remote_path_in_config = '/Flash_Backups/';
}

// Normalize only if not blank (now guaranteed non-blank)
if ($remote_path_in_config !== '') {

    // Ensure leading slash
    if ($remote_path_in_config[0] !== '/') {
        $remote_path_in_config = '/' . $remote_path_in_config;
    }

    // Ensure trailing slash
    if (substr($remote_path_in_config, -1) !== '/') {
        $remote_path_in_config .= '/';
    }
}

// --- Normalize B2 bucket name ---
$b2_bucket_name = trim($b2_bucket_name);

if ($b2_bucket_name !== '') {

    // Remove leading slash
    $b2_bucket_name = ltrim($b2_bucket_name, '/');

    // Ensure trailing slash
    if (substr($b2_bucket_name, -1) !== '/') {
        $b2_bucket_name .= '/';
    }
}

if (is_array($rclone_config_remote)) {
    $rclone_config_remote = array_map('trim', $rclone_config_remote);
    $rclone_config_remote = implode(',', $rclone_config_remote);
}

// --- Build args array ---
$args = [
    $minimal_backup_remote,
    $rclone_config_remote,
    $b2_bucket_name,
    $remote_path_in_config,
    $backups_to_keep_remote,
    $dry_run_remote,
    $notifications_remote,
    $discord_webhook_url_remote,
    $pushover_user_key_remote,
];

// Escape each argument for safety
$escapedArgs = array_map('escapeshellarg', $args);

// Build command string
$fullCmd = $cmd . ' ' . implode(' ', $escapedArgs);

// Execute
$process = proc_open($fullCmd, [
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w']
], $pipes);

if (is_resource($process)) {
    $output = stream_get_contents($pipes[1]);
    $error  = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    echo trim($output)
        ? $output
        : json_encode(['status' => 'error', 'message' => trim($error) ?: 'No response from shell script']);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Failed to start process']);
}
