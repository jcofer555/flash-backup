<?php
header('Content-Type: application/json');

// Path to the remote settings config and a temp file used during atomic write
$config = '/boot/config/plugins/flash-backup/settings_remote.cfg';
$tmp    = $config . '.tmp';

// Read all expected fields from POST with safe defaults
$minimal_backup_remote       = $_POST['MINIMAL_BACKUP_REMOTE']       ?? 'no';
$backups_to_keep_remote      = $_POST['BACKUPS_TO_KEEP_REMOTE']      ?? '0';
$dry_run_remote              = $_POST['DRY_RUN_REMOTE']              ?? 'no';
$notifications_remote        = $_POST['NOTIFICATIONS_REMOTE']        ?? 'no';
$notification_service_remote = $_POST['NOTIFICATION_SERVICE_REMOTE'] ?? '';
$pushover_user_key_remote    = $_POST['PUSHOVER_USER_KEY_REMOTE']    ?? '';

// Per-remote bucket names — received as a JSON object {"remoteName":"bucket/", ...}
// Stored base64-encoded so it survives parse_ini_file without quoting issues.
$bucket_names_stored = '';
$bucket_names_raw    = $_POST['BUCKET_NAMES'] ?? '';
if ($bucket_names_raw !== '') {
    $decoded = json_decode($bucket_names_raw, true);
    if (!is_array($decoded)) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid BUCKET_NAMES JSON']);
        exit;
    }
    // Sanitize keys and values
    $sanitized = [];
    foreach ($decoded as $remote => $bucket) {
        $remote = str_replace(['"', "'", "\n", "\r", '='], '', (string)$remote);
        $bucket = str_replace(['"', "'", "\n", "\r"],       '', (string)$bucket);
        if ($remote !== '') $sanitized[$remote] = $bucket;
    }
    if (!empty($sanitized)) {
        // base64 is all alphanumeric + / + = — safe inside double-quoted INI values
        $bucket_names_stored = base64_encode(json_encode($sanitized, JSON_UNESCAPED_SLASHES));
    }
}

// Accept either an array (multi-select) or a comma string
$rclone_raw = $_POST['RCLONE_CONFIG_REMOTE'] ?? '';
if (is_array($rclone_raw)) {
    $rclone_config_remote = implode(',', array_map('trim', $rclone_raw));
} else {
    $rclone_config_remote = trim((string)$rclone_raw);
}

// Leading and trailing slash enforced, default applied if empty
$remote_path_in_config = trim($_POST['REMOTE_PATH_IN_CONFIG'] ?? '');
if ($remote_path_in_config === '') {
    $remote_path_in_config = '/Flash_Backups/';
} else {
    if ($remote_path_in_config[0] !== '/') {
        $remote_path_in_config = '/' . $remote_path_in_config;
    }
    if (substr($remote_path_in_config, -1) !== '/') {
        $remote_path_in_config .= '/';
    }
}

// Collect webhook URLs for all supported notification services
$services    = ['DISCORD', 'GOTIFY', 'NTFY', 'PUSHOVER', 'SLACK'];
$webhookUrls = [];
foreach ($services as $svc) {
    $webhookUrls[$svc] = $_POST['WEBHOOK_' . $svc . '_REMOTE'] ?? '';
}

// Strip quotes and newlines to prevent config file injection
function sanitize(string $val): string
{
    return str_replace(['"', "'", "\n", "\r"], '', $val);
}

// Build the config key-value array in alphabetical order
$lines = [
    'B2_BUCKET_NAME'              => '',   // legacy — kept so old schedules that reference it still load cleanly
    'BACKUPS_TO_KEEP_REMOTE'      => $backups_to_keep_remote,
    'BUCKET_NAMES'                => $bucket_names_stored,  // base64-encoded JSON
    'DRY_RUN_REMOTE'              => $dry_run_remote,
    'MINIMAL_BACKUP_REMOTE'       => $minimal_backup_remote,
    'NOTIFICATION_SERVICE_REMOTE' => $notification_service_remote,
    'NOTIFICATIONS_REMOTE'        => $notifications_remote,
    'PUSHOVER_USER_KEY_REMOTE'    => $pushover_user_key_remote,
    'RCLONE_CONFIG_REMOTE'        => $rclone_config_remote,
    'REMOTE_PATH_IN_CONFIG'       => $remote_path_in_config,
    'WEBHOOK_DISCORD_REMOTE'      => $webhookUrls['DISCORD'],
    'WEBHOOK_GOTIFY_REMOTE'       => $webhookUrls['GOTIFY'],
    'WEBHOOK_NTFY_REMOTE'         => $webhookUrls['NTFY'],
    'WEBHOOK_PUSHOVER_REMOTE'     => $webhookUrls['PUSHOVER'],
    'WEBHOOK_SLACK_REMOTE'        => $webhookUrls['SLACK'],
];

$content = '';
foreach ($lines as $key => $val) {
    $content .= $key . '="' . sanitize($val) . '"' . "\n";
}

// Write to a temp file then rename over the real config
@mkdir(dirname($config), 0755, true);

if (file_put_contents($tmp, $content) === false) {
    echo json_encode(['status' => 'error', 'message' => 'Failed to write temp config']);
    exit;
}

if (!rename($tmp, $config)) {
    @unlink($tmp);
    echo json_encode(['status' => 'error', 'message' => 'Failed to move config into place']);
    exit;
}

echo json_encode(['status' => 'ok']);
