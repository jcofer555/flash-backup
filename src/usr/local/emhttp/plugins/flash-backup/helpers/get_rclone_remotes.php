<?php

declare(strict_types=1);
// Returns the current list of rclone remotes from all known config file locations.
// Used by the 1-second JS poller to keep the Rclone Config multiselect up to date.

header('Content-Type: application/json');

$rcloneConfigs = [
    '/boot/config/plugins/rclone/.rclone.conf',
    '/mnt/user/appdata/rclone/rclone.conf',
    '/mnt/user/appdata/Rclone/.rclone.conf',
    '/mnt/user/appdata/binhex-rclone/rclone/config/rclone.conf',
    '/mnt/user/appdata/Rclone-mount/.rclone.conf',
    '/boot/config/rclone/rclone.conf',
];

$rcloneIni    = [];
$remoteTypes  = [];

foreach ($rcloneConfigs as $config) {
    if (!file_exists($config)) continue;
    $parsed = parse_ini_file($config, true, INI_SCANNER_RAW) ?: [];
    foreach ($parsed as $remoteName => $data) {
        if (isset($rcloneIni[$remoteName])) continue; // first definition wins
        $rcloneIni[$remoteName] = $data;
    }
}

$remotes = array_keys($rcloneIni);
sort($remotes, SORT_NATURAL | SORT_FLAG_CASE);

foreach ($remotes as $r) {
    $type = $rcloneIni[$r]['type'] ?? 'unknown';
    $remoteTypes[$r] = $type;
    if ($type === 'crypt') {
        $underlying = explode(':', $rcloneIni[$r]['remote'] ?? '')[0];
        if (isset($rcloneIni[$underlying])) {
            $ut = $rcloneIni[$underlying]['type'] ?? '';
            if ($ut === 'b2') $remoteTypes[$r] = 'crypt-b2';
            elseif ($ut === 's3') $remoteTypes[$r] = 'crypt-s3';
        }
    }
}

echo json_encode([
    'remotes' => $remotes,
    'types'   => $remoteTypes,
], JSON_UNESCAPED_SLASHES);
