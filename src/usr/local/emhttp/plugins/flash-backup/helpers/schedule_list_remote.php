<?php

define('REMOTE_SCHEDULES_CFG', '/boot/config/plugins/flash-backup/schedules-remote.cfg');

function yes_no(string $value): string {
    $v = strtolower($value);
    return ($v === 'yes' || $v === '1' || $v === 'true') ? 'Yes' : 'No';
}

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
        $t = date('g:i A', mktime((int)$hour, (int)$min));
        $d = $days[(int)$dow] ?? $dow;
        return "Runs every $d at $t";
    }

    if (preg_match('/^\d+$/', $min) && preg_match('/^\d+$/', $hour) && preg_match('/^\d+$/', $dom) && $month === '*' && $dow === '*') {
        $t      = date('g:i A', mktime((int)$hour, (int)$min));
        $suffix = match((int)$dom % 10) {
            1 => ((int)$dom === 11) ? 'th' : 'st',
            2 => ((int)$dom === 12) ? 'th' : 'nd',
            3 => ((int)$dom === 13) ? 'th' : 'rd',
            default => 'th'
        };
        return "Runs monthly on the {$dom}{$suffix} at $t";
    }

    return $cron;
}

$schedules = [];
if (file_exists(REMOTE_SCHEDULES_CFG)) {
    $schedules = parse_ini_file(REMOTE_SCHEDULES_CFG, true, INI_SCANNER_RAW);
}

?>

<?php if (!empty($schedules)): ?>

<h3 style="color:#d4f5d4;">📅 Scheduled Remote Backup Jobs</h3>

<table class="flash-backup-schedules-table flash-backup-schedule-responsive"
       style="
           width:100%;
           table-layout:fixed;
           border-collapse: collapse;
           margin-top:20px;
           border:3px solid #2ECC40;
           background:#000;
       ">

<thead>
<tr style="
    background:#000;
    color:#2ECC40;
    text-align:center;
    border-bottom:3px solid #2ECC40;
">
    <th style="padding:8px; width:24%;">Scheduling</th>
    <th style="padding:8px; width:6%;">Minimal Backup</th>
    <th style="padding:8px; width:13%;">Rclone Config</th>
    <th style="padding:8px; width:16%;">Path In Config</th>
    <th style="padding:8px; width:7%;">Backups To Keep</th>
    <th style="padding:8px; width:5%;">Dry Run</th>
    <th style="padding:8px; width:5%;">Notifications</th>
    <th style="padding:8px; width:22%;">Actions</th>
</tr>
</thead>

<tbody>

<?php foreach ($schedules as $id => $s): ?>

    <?php
    $enabledBool = ($s['ENABLED'] ?? 'yes') === 'yes';
    $btnText     = $enabledBool ? 'Disable' : 'Enable';

    $sideBorder = $enabledBool ? '#2ECC40' : '#b30000'; // green or red
    $statusDot  = $enabledBool ? '🟢' : '🔴';

    $cron = $s['CRON'] ?? '';

    $settings = [];
    if (!empty($s['SETTINGS'])) {
        $settingsRaw = stripslashes($s['SETTINGS']);
        $settings    = json_decode($settingsRaw, true);
        if (!is_array($settings)) $settings = [];
    }

    $rcloneConfig  = $settings['RCLONE_CONFIG_REMOTE'] ?? '—';
    $pathInConfig  = $settings['REMOTE_PATH_IN_CONFIG'] ?? '—';

    if (!isset($settings['BACKUPS_TO_KEEP_REMOTE'])) {
        $backupsToKeep = '—';
    } else {
        $btk = (int)$settings['BACKUPS_TO_KEEP_REMOTE'];
        if ($btk === 1)      $backupsToKeep = 'Only Latest';
        elseif ($btk === 0)  $backupsToKeep = 'Unlimited';
        else                 $backupsToKeep = $btk;
    }

    $minimalBackup = isset($settings['MINIMAL_BACKUP_REMOTE']) ? yes_no($settings['MINIMAL_BACKUP_REMOTE']) : '—';
    $dryRun        = isset($settings['DRY_RUN_REMOTE'])        ? yes_no($settings['DRY_RUN_REMOTE']) : '—';
    $notify        = isset($settings['NOTIFICATIONS_REMOTE']) ? yes_no($settings['NOTIFICATIONS_REMOTE']) : '—';

    $id_esc = htmlspecialchars($id);
    ?>

    <tr style="
        background:#000;
        color:#d4f5d4;
        border-left:3px solid <?php echo $sideBorder; ?>;
        border-right:3px solid <?php echo $sideBorder; ?>;
        border-bottom:3px solid #2ECC40;
        vertical-align:middle;
    ">

        <td style="padding:8px; text-align:center;">
            <span style="margin-right:6px;"><?php echo $statusDot; ?></span>
            <span class="flash-backuptip" title="<?php echo htmlspecialchars(human_cron($cron) . ' - ' . $cron); ?>"><?php echo htmlspecialchars(human_cron($cron)); ?></span>
        </td>

        <td style="padding:8px; text-align:center;"><?php echo htmlspecialchars($minimalBackup); ?></td>
        <td style="padding:8px; text-align:center; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:0;"><span class="flash-backuptip" title="<?php echo htmlspecialchars($rcloneConfig); ?>"><?php echo htmlspecialchars($rcloneConfig); ?></span></td>
        <td style="padding:8px; text-align:center; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:0;"><span class="flash-backuptip" title="<?php echo htmlspecialchars($pathInConfig); ?>"><?php echo htmlspecialchars($pathInConfig); ?></span></td>
        <td style="padding:8px; text-align:center;"><?php echo htmlspecialchars($backupsToKeep); ?></td>
        <td style="padding:8px; text-align:center;"><?php echo htmlspecialchars($dryRun); ?></td>
        <td style="padding:8px; text-align:center;"><?php echo htmlspecialchars($notify); ?></td>

        <td class="schedule-actions-cell" style="padding:8px; text-align:center; white-space:normal;">
            <button type="button" onclick="editScheduleremote('<?php echo $id_esc; ?>')">Edit</button>
            <button type="button" onclick="toggleScheduleremote('<?php echo $id_esc; ?>', <?php echo $enabledBool ? 'true' : 'false'; ?>)"><?php echo $btnText; ?></button>
            <button type="button" onclick="deleteScheduleremote('<?php echo $id_esc; ?>')">Delete</button>
            <button type="button" class="schedule-run-btn" onclick="runScheduleBackupremote('<?php echo $id_esc; ?>', this)">Run</button>
        </td>

    </tr>

<?php endforeach; ?>

</tbody>
</table>

<?php endif; ?>