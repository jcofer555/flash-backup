<?php

define('SCHEDULES_CFG', '/boot/config/plugins/flash-backup/schedules.cfg');

// ------------------------------------------------------------------------------
// load_schedules() — guarded, realpath-normalized
// ------------------------------------------------------------------------------
function load_schedules(string $cfg): array {
    $real = realpath($cfg);
    if ($real === false || !file_exists($real)) {
        return [];
    }
    $schedules = parse_ini_file($real, true, INI_SCANNER_RAW);
    return is_array($schedules) ? $schedules : [];
}

// ------------------------------------------------------------------------------
// yes_no() — explicit boolean mapping to human-friendly label
// ------------------------------------------------------------------------------
function yes_no(string $value): string {
    $v = strtolower($value);
    return ($v === 'yes' || $v === '1' || $v === 'true') ? 'Yes' : 'No';
}

// ------------------------------------------------------------------------------
// human_cron() — explicit state machine, cron to human-readable string
// ------------------------------------------------------------------------------
function human_cron(string $cron): string {
    $cron  = trim($cron);
    $parts = preg_split('/\s+/', $cron);
    if (count($parts) !== 5) return $cron;

    [$min, $hour, $dom, $month, $dow] = $parts;

    if ($min === '*' && $hour === '*' && $dom === '*' && $month === '*' && $dow === '*') {
        return 'Runs every minute';
    }

    if (preg_match('/^\*\/(\d+)$/', $min, $m) && $hour === '*' && $dom === '*' && $month === '*' && $dow === '*') {
        $n = (int)$m[1];
        return "Runs every $n minute" . ($n !== 1 ? 's' : '');
    }

    if ($min === '0' && preg_match('/^\*\/(\d+)$/', $hour, $m) && $dom === '*' && $month === '*' && $dow === '*') {
        $n = (int)$m[1];
        return "Runs every $n hour" . ($n !== 1 ? 's' : '');
    }

    if (preg_match('/^\d+$/', $min) && preg_match('/^\d+$/', $hour) && $dom === '*' && $month === '*' && $dow === '*') {
        $t = date('g:i A', mktime((int)$hour, (int)$min));
        return "Runs daily at $t";
    }

    if (preg_match('/^\d+$/', $min) && preg_match('/^\d+$/', $hour) && $dom === '*' && $month === '*' && preg_match('/^\d+$/', $dow)) {
        $days = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
        $t    = date('g:i A', mktime((int)$hour, (int)$min));
        $d    = $days[(int)$dow] ?? $dow;
        return "Runs every $d at $t";
    }

    if (preg_match('/^\d+$/', $min) && preg_match('/^\d+$/', $hour) && preg_match('/^\d+$/', $dom) && $month === '*' && $dow === '*') {
        $t      = date('g:i A', mktime((int)$hour, (int)$min));
        $dom_i  = (int)$dom;
        $suffix = match($dom_i % 10) {
            1 => ($dom_i === 11) ? 'th' : 'st',
            2 => ($dom_i === 12) ? 'th' : 'nd',
            3 => ($dom_i === 13) ? 'th' : 'rd',
            default => 'th'
        };
        return "Runs monthly on the {$dom}{$suffix} at $t";
    }

    return $cron;
}

// ------------------------------------------------------------------------------
// decode_settings() — guarded JSON decode with INI strip
// ------------------------------------------------------------------------------
function decode_settings(string $raw): array {
    $decoded = json_decode(stripslashes($raw), true);
    return is_array($decoded) ? $decoded : [];
}

// ------------------------------------------------------------------------------
// backups_to_keep_label() — explicit human-friendly retention mapping
// ------------------------------------------------------------------------------
function backups_to_keep_label(array $settings): string {
    if (!isset($settings['BACKUPS_TO_KEEP'])) return '—';
    $btk = (int)$settings['BACKUPS_TO_KEEP'];
    if ($btk === 0) return 'Unlimited';
    if ($btk === 1) return 'Only Latest';
    return (string)$btk;
}

// ------------------------------------------------------------------------------
// render_row() — deterministic single-row output, all values escaped
// ------------------------------------------------------------------------------
function render_row(string $id, array $s): void {
    $enabled   = ($s['ENABLED'] ?? 'yes') === 'yes';
    $btn_text  = $enabled ? 'Disable' : 'Enable';
    $row_color = $enabled ? '#eaf7ea' : '#fdeaea';
    $txt_color = $enabled ? '#2e7d32' : '#b30000';

    $cron     = $s['CRON'] ?? '';
    $settings = decode_settings($s['SETTINGS'] ?? '');

    $dest           = !empty($settings['BACKUP_DESTINATION']) ? $settings['BACKUP_DESTINATION'] : '—';
    $backups_label  = backups_to_keep_label($settings);
    $backup_owner   = $settings['BACKUP_OWNER']   ?? '—';
    $dry_run        = isset($settings['DRY_RUN'])        ? yes_no($settings['DRY_RUN'])        : '—';
    $notify         = isset($settings['NOTIFICATIONS'])   ? yes_no($settings['NOTIFICATIONS'])  : '—';
    $minimal_backup = isset($settings['MINIMAL_BACKUP'])  ? yes_no($settings['MINIMAL_BACKUP']) : '—';

    $cron_human = human_cron($cron);
    $id_esc     = htmlspecialchars($id);
    $enabled_js = $enabled ? 'true' : 'false';
    ?>
    <tr style="border-bottom:1px solid #ccc; height:3px; background:<?php echo $row_color; ?>; color:<?php echo $txt_color; ?>;">

        <td style="padding:8px; text-align:center;">
            <span class="flash-backuptip" title="<?php echo htmlspecialchars($cron_human); ?> - <?php echo htmlspecialchars($cron); ?>">
                <?php echo htmlspecialchars($cron_human); ?>
            </span>
        </td>

        <td style="padding:8px; text-align:center;">
            <?php echo htmlspecialchars($minimal_backup); ?>
        </td>

        <td style="padding:8px; text-align:center; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"
            class="flash-backuptip"
            title="<?php echo htmlspecialchars($dest); ?>">
            <?php echo htmlspecialchars($dest); ?>
        </td>

        <td style="padding:8px; text-align:center;">
            <?php echo htmlspecialchars($backups_label); ?>
        </td>

        <td style="padding:8px; text-align:center;">
            <?php echo htmlspecialchars($backup_owner); ?>
        </td>

        <td style="padding:8px; text-align:center;">
            <?php echo htmlspecialchars($dry_run); ?>
        </td>

        <td style="padding:8px; text-align:center;">
            <?php echo htmlspecialchars($notify); ?>
        </td>

        <td style="padding:0px; text-align:center;">
            <button type="button"
                    class="flash-backuptip"
                    title="Edit schedule"
                    onclick="editSchedule('<?php echo $id_esc; ?>')">
                Edit
            </button>
            <button type="button"
                    class="flash-backuptip"
                    title="<?php echo $enabled ? 'Disable schedule' : 'Enable schedule'; ?>"
                    onclick="toggleSchedule('<?php echo $id_esc; ?>', <?php echo $enabled_js; ?>)">
                <?php echo $btn_text; ?>
            </button>
            <button type="button"
                    class="flash-backuptip"
                    title="Delete schedule"
                    onclick="deleteSchedule('<?php echo $id_esc; ?>')">
                Delete
            </button>
            <button type="button"
                    class="schedule-action-btn running-btn run-schedule-btn flash-backuptip"
                    title="Run schedule"
                    onclick="runScheduleBackup('<?php echo $id_esc; ?>', this)">
                Run
            </button>
        </td>

    </tr>
    <?php
}

// ------------------------------------------------------------------------------
// main() — explicit entrypoint
// ------------------------------------------------------------------------------
function main(): void {
    $schedules = load_schedules(SCHEDULES_CFG);
    if (empty($schedules)) return;
    ?>
    <h3>📅 Scheduled Local Backup Jobs</h3>

    <table class="flash-backup-schedules-table"
           style="width:100%; border-collapse:collapse; margin-top:20px; border:1px solid #ccc; table-layout:fixed;">
        <thead>
            <tr style="background:#f9f9f9; color:#b30000; text-align:center; border-bottom:2px solid #b30000;">
                <th style="padding:8px; width:18%;">Scheduling</th>
                <th style="padding:8px; width:9%;">Minimal Backup</th>
                <th style="padding:8px; width:20%;">Backup Destination</th>
                <th style="padding:8px; width:9%;">Backups To Keep</th>
                <th style="padding:8px; width:8%;">Backup Owner</th>
                <th style="padding:8px; width:6%;">Dry Run</th>
                <th style="padding:8px; width:8%;">Notifications</th>
                <th style="padding:8px; width:22%;">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($schedules as $id => $s): ?>
                <?php render_row($id, $s); ?>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php
}

main();