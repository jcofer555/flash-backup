<?php

// Root path that all browsing is constrained to
define('PICKER_BASE', '/mnt');
// Minimum depth for a folder to be selectable in the standard backup destination picker
define('MIN_SELECTABLE_DEPTH',            3);
// Minimum depth for a folder to be selectable in the restore destination picker
define('RESTORE_DESTINATION_MIN_DEPTH',   2);

// ------------------------------------------------------------------------------
// respond() — JSON response with explicit HTTP code, then exit
// ------------------------------------------------------------------------------
function respond(int $code, array $payload): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

// ------------------------------------------------------------------------------
// resolve_path()
// ------------------------------------------------------------------------------
function resolve_path(string $path): string {
    $resolved = realpath($path);
    // Fall back to the base if the path resolves outside it
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
// is_selectable() — selectability by field and depth
// ------------------------------------------------------------------------------
function is_selectable(int $depth, string $field): bool {
    // The restore destination picker allows selection one level shallower
    if ($field === 'restore_destination' && $depth === RESTORE_DESTINATION_MIN_DEPTH) {
        return true;
    }
    return $depth >= MIN_SELECTABLE_DEPTH;
}

// ------------------------------------------------------------------------------
// scan_folders() — returns folder list
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
        // Skip dot entries
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
// main()
// ------------------------------------------------------------------------------
function main(): void {
    $path  = resolve_path($_GET['path'] ?? PICKER_BASE);
    $field = trim($_GET['field'] ?? '');

    $folders = scan_folders($path, $field);
    // Parent is null when already at the root of the picker
    $parent  = ($path !== PICKER_BASE) ? dirname($path) : null;

    respond(200, [
        'current' => $path,
        'parent'  => $parent,
        'folders' => $folders,
    ]);
}

main();
