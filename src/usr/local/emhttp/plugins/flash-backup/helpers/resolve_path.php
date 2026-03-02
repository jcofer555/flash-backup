<?php

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
    $path = $_POST['path'] ?? '';

    if ($path === '') {
        respond_text(200, '');
    }

    $resolved = realpath($path);

    respond_text(200, $resolved !== false ? $resolved : $path);
}

main();