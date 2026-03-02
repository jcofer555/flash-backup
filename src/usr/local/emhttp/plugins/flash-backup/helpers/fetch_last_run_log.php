<?php

define('LOG_FILE',      '/tmp/flash-backup/flash-backup.log');
define('LOG_TAIL_LINES', 500);

// ------------------------------------------------------------------------------
// respond_text() — deterministic plain-text response with explicit HTTP code
// ------------------------------------------------------------------------------
function respond_text(int $code, string $body): void {
    http_response_code($code);
    header('Content-Type: text/plain');
    echo $body;
    exit;
}

// ------------------------------------------------------------------------------
// main() — explicit entrypoint, all state explicit
// ------------------------------------------------------------------------------
function main(): void {
    if (!file_exists(LOG_FILE)) {
        respond_text(404, 'Flash backup log not found');
    }

    $lines = file(LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        respond_text(500, 'Failed to read log file');
    }

    $tail     = array_slice($lines, -LOG_TAIL_LINES);
    $reversed = array_reverse($tail);

    respond_text(200, implode("\n", $reversed));
}

main();