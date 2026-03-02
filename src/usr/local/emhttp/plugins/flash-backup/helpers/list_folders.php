<?php

define('PICKER_BASE', '/mnt');
define('MIN_SELECTABLE_DEPTH',            3);
define('RESTORE_DESTINATION_MIN_DEPTH',   2);

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
// resolve_path() — realpath-normalized, constrained to base
// ------------------------------------------------------------------------------
function resolve_path(string $path): string {
    $resolved = realpath($path);
    if ($resolved === false || strpos($resolved, PICKER_BASE) !== 0) {
        return PICKER_BASE;
    }
    return $resolved;
}

// ------------------------------------------------------------------------------
// get_depth() — deterministic depth relative to base
// ------------------------------------------------------------------------------
function get_depth(string $full_path): int {
    $relative = trim(str_replace(PICKER_BASE, '', $full_path), '/');
    if ($relative === '') return 0;
    return count(explode('/', $relative));
}

// ------------------------------------------------------------------------------
// is_selectable() — explicit state machine for selectability by field and depth
// ------------------------------------------------------------------------------
function is_selectable(int $depth, string $field): bool {
    if ($field === 'restore_destination' && $depth === RESTORE_DESTINATION_MIN_DEPTH) {
        return true;
    }
    return $depth >= MIN_SELECTABLE_DEPTH;
}

// ------------------------------------------------------------------------------
// scan_folders() — guarded scandir, returns deterministic folder list
// ------------------------------------------------------------------------------
function scan_folders(string $path, string $field): array {
    if (!is_dir($path)) {
        return [];
    }

    $items = scandir($path);
    if (!is_array($items)) {
        return [];
    }

    $folders = [];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;

        $full = $path . '/' . $item;
        if (!is_dir($full)) continue;

        $depth = get_depth($full);

        $folders[] = [
            'name'       => $item,
            'path'       => $full,
            'selectable' => is_selectable($depth, $field),
        ];
    }

    return $folders;
}

// ------------------------------------------------------------------------------
// main() — explicit entrypoint, all state explicit
// ------------------------------------------------------------------------------
function main(): void {
    $path  = resolve_path($_GET['path'] ?? PICKER_BASE);
    $field = trim($_GET['field'] ?? '');

    $folders = scan_folders($path, $field);
    $parent  = ($path !== PICKER_BASE) ? dirname($path) : null;

    respond(200, [
        'current' => $path,
        'parent'  => $parent,
        'folders' => $folders,
    ]);
}

main();