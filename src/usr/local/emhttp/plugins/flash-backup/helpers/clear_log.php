<?php

define('LOG_FILES', [
    'last' => '/tmp/flash-backup/flash-backup.log',
]);

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
// validate_csrf() — accepts header or POST token, validates against cookie
// ------------------------------------------------------------------------------
function validate_csrf(): void {
    $cookie = $_COOKIE['csrf_token']              ?? '';
    $header = $_SERVER['HTTP_X_CSRF_TOKEN']       ?? '';
    $posted = $_POST['csrf_token']                ?? '';

    if ($header === '' && $posted === '') {
        respond(403, ['ok' => false, 'message' => 'Missing CSRF token']);
    }

    if (!hash_equals($cookie, $header) && !hash_equals($cookie, $posted)) {
        respond(403, ['ok' => false, 'message' => 'Invalid CSRF token']);
    }
}

// ------------------------------------------------------------------------------
// main() — explicit entrypoint, all state explicit
// ------------------------------------------------------------------------------
function main(): void {
    validate_csrf();

    $log = $_POST['log'] ?? '';

    $log_files = LOG_FILES;
    if (!isset($log_files[$log])) {
        respond(400, ['ok' => false, 'message' => 'Invalid log target']);
    }

    $file = $log_files[$log];

    if (!file_exists($file)) {
        respond(404, ['ok' => false, 'message' => 'Log file not found.']);
    }

    if (file_put_contents($file, '') === false) {
        respond(500, ['ok' => false, 'message' => 'Failed to clear log file.']);
    }

    respond(200, [
        'ok'      => true,
        'message' => '✅ ' . ucfirst($log) . ' log cleared successfully.',
    ]);
}

main();