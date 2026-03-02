<?php

define('LOCK_FILE', '/tmp/flash-backup/lock.txt');

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
    respond(200, ['running' => file_exists(LOCK_FILE)]);
}

main();