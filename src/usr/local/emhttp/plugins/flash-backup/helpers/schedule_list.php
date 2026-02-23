<?php
$cfg = '/boot/config/plugins/flash-backup/schedules.cfg';

$schedules = [];
if (file_exists($cfg)) {
    $schedules = parse_ini_file($cfg, true, INI_SCANNER_RAW);
}
?>

<table class="flash-backup-schedules-table"
       style="width:100%; border-collapse: collapse; margin-top:20px; border:1px solid #ccc; table-layout:fixed;">

<thead>
<tr style="background:#f9f9f9; color:#b30000; text-align:center; border-bottom:2px solid #b30000;">

    <th style="padding:8px; width:4%;">Scheduling</th>
    <th style="padding:8px; width:4%;">Type</th>
    <th style="padding:8px; width:4%;">Minimal Backup</th>
    <th style="padding:8px; width:12%;">Backup Destination</th>
    <th style="padding:8px; width:4%;">Backups To Keep</th>
    <th style="padding:8px; width:6%;">Backup Owner</th>
    <th style="padding:8px; width:4%;">Dry Run</th>
    <th style="padding:8px; width:4%;">Notifications</th>
    <th style="padding:8px; width:12%;">Actions</th>

</tr>
</thead>

<tbody>

<?php if (empty($schedules)): ?>

    <tr style="border-bottom:1px solid #ccc;">
        <td style="padding:12px; text-align:center; vertical-align:middle;" colspan="9">
            No schedules found
        </td>
    </tr>

<?php else: ?>

    <?php foreach ($schedules as $id => $s): ?>

        <?php
        // Enabled state
        $enabledBool = ($s['ENABLED'] ?? 'yes') === 'yes';
        $btnText     = $enabledBool ? 'Disable' : 'Enable';

        // Row color
        $rowColor  = $enabledBool ? '#eaf7ea' : '#fdeaea';
        $textColor = $enabledBool ? '#2e7d32' : '#b30000';

        // Cron
        $cron = $s['CRON'] ?? '';

        // Decode SETTINGS JSON
        $settings = [];
        if (!empty($s['SETTINGS'])) {
            $settingsRaw = stripslashes($s['SETTINGS']);
            $settings    = json_decode($settingsRaw, true);
            if (!is_array($settings)) $settings = [];
        }

        /* -------------------------
           Destination
           ------------------------- */
        $dest = '—';

        if (!empty($settings)) {
            if (!empty($settings['BACKUP_DESTINATION'])) {
                $dest = $settings['BACKUP_DESTINATION'];
            }
        }

        /* -------------------------
           HUMAN-FRIENDLY VALUES
           ------------------------- */

        // Backups To Keep
        if (!isset($settings['BACKUPS_TO_KEEP'])) {
            $backupsToKeep = '—';
        } else {
            $btk = (int)$settings['BACKUPS_TO_KEEP'];
            if ($btk === 1)      $backupsToKeep = 'Only Latest';
            elseif ($btk === 0)  $backupsToKeep = 'Unlimited';
            else                 $backupsToKeep = $btk;
        }

        // Backup Owner
        $backupOwner = $settings['BACKUP_OWNER'] ?? '—';

        // Normalize Yes/No fields
        function yesNo($value) {
            $v = strtolower((string)$value);
            return ($v === 'yes' || $v === '1' || $v === 'true') ? 'Yes' : 'No';
        }

        // Dry Run
        if (!isset($settings['DRY_RUN'])) {
            $dryRun = '—';
        } else {
            $dryRun = yesNo($settings['DRY_RUN']);
        }

        // Notifications
        if (!isset($settings['NOTIFICATIONS'])) {
            $notify = '—';
        } else {
            $notify = yesNo($settings['NOTIFICATIONS']);
        }

        // Minimal Backup
        if (!isset($settings['MINIMAL_BACKUP'])) {
            $minimalBackup = '—';
        } else {
            $minimalBackup = yesNo($settings['MINIMAL_BACKUP']);
        }

        // Backup Type
        $backupType = $s['TYPE'] ?? '—';
        ?>

        <tr style="border-bottom:1px solid #ccc; height: 3px; background:<?php echo $rowColor; ?>; color:<?php echo $textColor; ?>;">

            <!-- Scheduling -->
            <td style="padding:8px; text-align:center;">
                <?php echo htmlspecialchars($cron); ?>
            </td>

            <!-- Backup Type -->
            <td style="padding:8px; text-align:center;">
                <?php echo htmlspecialchars($backupType); ?>
            </td>

            <!-- Minimal Backup -->
            <td style="padding:8px; text-align:center;">
                <?php echo htmlspecialchars($minimalBackup); ?>
            </td>

            <!-- Backup Destination -->
            <td style="
                padding:8px;
                text-align:center;
                white-space:nowrap;
                overflow:hidden;
                text-overflow:ellipsis;"
                class="flash-backuptip"
                title="<?php echo htmlspecialchars($dest); ?>">
                <?php echo htmlspecialchars($dest); ?>
            </td>

            <!-- Backups To Keep -->
            <td style="padding:8px; text-align:center;">
                <?php echo htmlspecialchars($backupsToKeep); ?>
            </td>

            <!-- Backup Owner -->
            <td style="padding:8px; text-align:center;">
                <?php echo htmlspecialchars($backupOwner); ?>
            </td>

            <!-- Dry Run -->
            <td style="padding:8px; text-align:center;">
                <?php echo $dryRun; ?>
            </td>

            <!-- Notifications -->
            <td style="padding:8px; text-align:center;">
                <?php echo htmlspecialchars($notify); ?>
            </td>

            <!-- Actions -->
            <td style="padding:0px; text-align:center;">

                <button type="button"
                        class="flash-backuptip"
                        title="Edit schedule"
                        onclick="editSchedule('<?php echo $id; ?>')">
                    Edit
                </button>

                <button type="button"
                        class="flash-backuptip"
                        title="<?php echo $enabledBool ? 'Disable schedule' : 'Enable schedule'; ?>"
                        onclick="toggleSchedule('<?php echo $id; ?>', <?php echo $enabledBool ? 'true' : 'false'; ?>)">
                    <?php echo $btnText; ?>
                </button>

                <button type="button"
                        class="flash-backuptip"
                        title="Delete schedule"
                        onclick="deleteSchedule('<?php echo $id; ?>')">
                    Delete
                </button>

                <button type="button"
                        class="schedule-action-btn running-btn run-schedule-btn flash-backuptip"
                        title="Run schedule"
                        onclick="runScheduleBackup('<?php echo $id; ?>', this)">
                    Run
                </button>

            </td>

        </tr>

    <?php endforeach; ?>

<?php endif; ?>

</tbody>
</table>
