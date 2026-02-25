<?php
$cfg = '/boot/config/plugins/flash-backup/schedules-remote.cfg';

$schedules = [];
if (file_exists($cfg)) {
    $schedules = parse_ini_file($cfg, true, INI_SCANNER_RAW);
}

function yesNoremote($value) {
    $v = strtolower((string)$value);
    return ($v === 'yes' || $v === '1' || $v === 'true') ? 'Yes' : 'No';
}

function humanCronRemote($cron) {
    $cron = trim($cron);
    $parts = preg_split('/\s+/', $cron);
    if (count($parts) !== 5) return $cron;

    [$min, $hour, $dom, $month, $dow] = $parts;

    if (preg_match('/^\*\/(\d+)$/', $min, $m) && $hour === '*' && $dom === '*' && $month === '*' && $dow === '*') {
        $n = (int)$m[1];
        return "Runs every $n minute" . ($n !== 1 ? 's' : '');
    }

    if ($min === '*' && $hour === '*' && $dom === '*' && $month === '*' && $dow === '*') {
        return "Runs every minute";
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
        $t = date('g:i A', mktime((int)$hour, (int)$min));
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
?>

<?php if (!empty($schedules)): ?>

<h3>📅 Scheduled Remote Backup Jobs</h3>

<table class="flash-backup-schedules-table"
       style="width:100%; border-collapse: collapse; margin-top:20px; border:1px solid #ccc; table-layout:fixed;">

<thead>
<tr style="background:#f9f9f9; color:#b30000; text-align:center; border-bottom:2px solid #b30000;">

    <th style="padding:8px; width:18%;">Scheduling</th>
    <th style="padding:8px; width:10%;">Minimal Backup</th>
    <th style="padding:8px; width:16%;">Rclone Config</th>
    <th style="padding:8px; width:11%;">Path In Config</th>
    <th style="padding:8px; width:9%;">Backups To Keep</th>
    <th style="padding:8px; width:7%;">Dry Run</th>
    <th style="padding:8px; width:8%;">Notifications</th>
    <th style="padding:8px; width:21%;">Actions</th>

</tr>
</thead>

<tbody>

    <?php foreach ($schedules as $id => $s): ?>

        <?php
        $enabledBool = ($s['ENABLED'] ?? 'yes') === 'yes';
        $btnText     = $enabledBool ? 'Disable' : 'Enable';

        $rowColor  = $enabledBool ? '#eaf7ea' : '#fdeaea';
        $textColor = $enabledBool ? '#2e7d32' : '#b30000';

        $cron = $s['CRON'] ?? '';

        $settings = [];
        if (!empty($s['SETTINGS'])) {
            $settingsRaw = stripslashes($s['SETTINGS']);
            $settings    = json_decode($settingsRaw, true);
            if (!is_array($settings)) $settings = [];
        }

        $rcloneConfig = !empty($settings['RCLONE_CONFIG_REMOTE']) ? $settings['RCLONE_CONFIG_REMOTE'] : '—';
        $pathInConfig = !empty($settings['REMOTE_PATH_IN_CONFIG']) ? $settings['REMOTE_PATH_IN_CONFIG'] : '—';

        if (!isset($settings['BACKUPS_TO_KEEP_REMOTE'])) {
            $backupsToKeep = '—';
        } else {
            $btk = (int)$settings['BACKUPS_TO_KEEP_REMOTE'];
            if ($btk === 1)      $backupsToKeep = 'Only Latest';
            elseif ($btk === 0)  $backupsToKeep = 'Unlimited';
            else                 $backupsToKeep = $btk;
        }

        $dryRun        = !isset($settings['DRY_RUN_REMOTE'])        ? '—' : yesNoremote($settings['DRY_RUN_REMOTE']);
        $notify        = !isset($settings['NOTIFICATIONS_REMOTE'])   ? '—' : yesNoremote($settings['NOTIFICATIONS_REMOTE']);
        $minimalBackup = !isset($settings['MINIMAL_BACKUP_REMOTE'])  ? '—' : yesNoremote($settings['MINIMAL_BACKUP_REMOTE']);
        ?>

        <tr style="border-bottom:1px solid #ccc; height: 3px; background:<?php echo $rowColor; ?>; color:<?php echo $textColor; ?>;">

            <td style="padding:8px; text-align:center;">
                <span class="flash-backuptip" title="<?php echo htmlspecialchars(humanCronRemote($cron)); ?> - <?php echo htmlspecialchars($cron); ?>">
                    <?php echo htmlspecialchars(humanCronRemote($cron)); ?>
                </span>
            </td>

            <td style="padding:8px; text-align:center;">
                <?php echo htmlspecialchars($minimalBackup); ?>
            </td>

            <td style="
                padding:8px;
                text-align:center;
                white-space:nowrap;
                overflow:hidden;
                text-overflow:ellipsis;"
                class="flash-backuptip"
                title="<?php echo htmlspecialchars($rcloneConfig); ?>">
                <?php echo htmlspecialchars($rcloneConfig); ?>
            </td>

            <td style="
                padding:8px;
                text-align:center;
                white-space:nowrap;
                overflow:hidden;
                text-overflow:ellipsis;"
                class="flash-backuptip"
                title="<?php echo htmlspecialchars($pathInConfig); ?>">
                <?php echo htmlspecialchars($pathInConfig); ?>
            </td>

            <td style="padding:8px; text-align:center;">
                <?php echo htmlspecialchars($backupsToKeep); ?>
            </td>

            <td style="padding:8px; text-align:center;">
                <?php echo $dryRun; ?>
            </td>

            <td style="padding:8px; text-align:center;">
                <?php echo htmlspecialchars($notify); ?>
            </td>

            <td style="padding:0px; text-align:center;">

                <button type="button"
                        class="flash-backuptip"
                        title="Edit remote schedule"
                        onclick="editScheduleremote('<?php echo $id; ?>')">
                    Edit
                </button>

                <button type="button"
                        class="flash-backuptip"
                        title="<?php echo $enabledBool ? 'Disable remote schedule' : 'Enable remote schedule'; ?>"
                        onclick="toggleScheduleremote('<?php echo $id; ?>', <?php echo $enabledBool ? 'true' : 'false'; ?>)">
                    <?php echo $btnText; ?>
                </button>

                <button type="button"
                        class="flash-backuptip"
                        title="Delete remote schedule"
                        onclick="deleteScheduleremote('<?php echo $id; ?>')">
                    Delete
                </button>

                <button type="button"
                        class="schedule-action-btn-remote running-btn run-schedule-btn flash-backuptip"
                        title="Run remote schedule"
                        onclick="runScheduleBackupremote('<?php echo $id; ?>', this)">
                    Run
                </button>

            </td>

        </tr>

    <?php endforeach; ?>

</tbody>
</table>

<?php endif; ?>