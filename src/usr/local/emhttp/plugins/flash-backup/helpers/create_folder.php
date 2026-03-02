<?php

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
// validate_csrf() — explicit CSRF check, must be POST with matching tokens
// ------------------------------------------------------------------------------
function validate_csrf(): void {
    $cookie = $_COOKIE['csrf_token'] ?? '';
    $posted = $_POST['csrf_token']   ?? '';

    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !hash_equals($cookie, $posted)) {
        respond(403, ['ok' => false, 'error' => 'Invalid CSRF token']);
    }
}

// ------------------------------------------------------------------------------
// main() — explicit entrypoint, all state explicit
// ------------------------------------------------------------------------------
function main(): void {
    validate_csrf();

    $parent = realpath($_POST['path'] ?? '');
    $name   = basename($_POST['name'] ?? '');

    if ($parent === false || $name === '' || !is_dir($parent)) {
        respond(400, ['success' => false, 'error' => 'Invalid path']);
    }

    $new = $parent . '/' . $name;

    if (file_exists($new)) {
        respond(409, ['success' => false, 'error' => 'Already exists']);
    }

    $stat = stat($parent);
    if ($stat === false) {
        respond(500, ['success' => false, 'error' => 'Failed to stat parent directory']);
    }

    $uid  = $stat['uid'];
    $gid  = $stat['gid'];
    $mode = $stat['mode'] & 0777;

    if (!mkdir($new, $mode, false)) {
        respond(500, ['success' => false, 'error' => 'Failed to create directory']);
    }

    chown($new, $uid);
    chgrp($new, $gid);

    respond(200, ['success' => true]);
}

main();