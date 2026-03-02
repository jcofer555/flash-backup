<?php

define('LOCAL_STATUS_FILE',    '/tmp/flash-backup/local_backup_status.txt');
define('LOCAL_STATUS_DEFAULT', 'Local Backup Not Running');

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
    $status = LOCAL_STATUS_DEFAULT;

    if (file_exists(LOCAL_STATUS_FILE)) {
        $raw = trim(file_get_contents(LOCAL_STATUS_FILE));
        if ($raw !== '') {
            $status = $raw;
        }
    }

    respond(200, [
        'status'  => $status,
        'running' => ($status !== LOCAL_STATUS_DEFAULT),
    ]);
}

main();