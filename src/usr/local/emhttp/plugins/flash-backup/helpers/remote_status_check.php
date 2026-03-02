<?php

define('REMOTE_STATUS_FILE',    '/tmp/flash-backup/remote_backup_status.txt');
define('REMOTE_STATUS_DEFAULT', 'Remote Backup Not Running');

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
// main() — explicit entrypoint, all state explicit
// ------------------------------------------------------------------------------
function main(): void {
    $status = REMOTE_STATUS_DEFAULT;

    if (file_exists(REMOTE_STATUS_FILE)) {
        $raw = trim(file_get_contents(REMOTE_STATUS_FILE));
        if ($raw !== '') {
            $status = $raw;
        }
    }

    respond(200, ['status' => $status]);
}

main();