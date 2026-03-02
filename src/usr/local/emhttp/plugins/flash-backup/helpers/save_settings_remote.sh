#!/bin/bash

readonly CONFIG="/boot/config/plugins/flash-backup/settings_remote.cfg"
readonly TMP="${CONFIG}.tmp"

# ------------------------------------------------------------------------------
# main() — explicit entrypoint, all state explicit
# ------------------------------------------------------------------------------
main() {
    local minimal_backup_remote="${1:-no}"
    local rclone_config_remote="${2:-/Flash_Backups}"
    local b2_bucket_name="${3:-}"
    local remote_path_in_config="${4:-0}"
    local backups_to_keep_remote="${5:-0}"
    local dry_run_remote="${6:-no}"
    local notifications_remote="${7:-no}"
    local notification_service_remote="${8:-}"
    local webhook_discord_remote="${9:-}"
    local webhook_gotify_remote="${10:-}"
    local webhook_ntfy_remote="${11:-}"
    local webhook_pushover_remote="${12:-}"
    local webhook_slack_remote="${13:-}"
    local pushover_user_key_remote="${14:-}"

    mkdir -p "$(dirname "$CONFIG")" || {
        echo '{"status":"error","message":"Failed to create config directory"}'
        exit 1
    }

    # Write all settings atomically via tmp-then-rename
    {
        echo "B2_BUCKET_NAME=\"$b2_bucket_name\""
        echo "BACKUPS_TO_KEEP_REMOTE=\"$backups_to_keep_remote\""
        echo "DRY_RUN_REMOTE=\"$dry_run_remote\""
        echo "MINIMAL_BACKUP_REMOTE=\"$minimal_backup_remote\""
        echo "NOTIFICATION_SERVICE_REMOTE=\"$notification_service_remote\""
        echo "NOTIFICATIONS_REMOTE=\"$notifications_remote\""
        echo "PUSHOVER_USER_KEY_REMOTE=\"$pushover_user_key_remote\""
        echo "RCLONE_CONFIG_REMOTE=\"$rclone_config_remote\""
        echo "REMOTE_PATH_IN_CONFIG=\"$remote_path_in_config\""
        echo "WEBHOOK_DISCORD_REMOTE=\"$webhook_discord_remote\""
        echo "WEBHOOK_GOTIFY_REMOTE=\"$webhook_gotify_remote\""
        echo "WEBHOOK_NTFY_REMOTE=\"$webhook_ntfy_remote\""
        echo "WEBHOOK_PUSHOVER_REMOTE=\"$webhook_pushover_remote\""
        echo "WEBHOOK_SLACK_REMOTE=\"$webhook_slack_remote\""
    } > "$TMP" || {
        echo '{"status":"error","message":"Failed to write temporary settings file"}'
        exit 1
    }

    mv "$TMP" "$CONFIG" || {
        rm -f "$TMP"
        echo '{"status":"error","message":"Failed to commit settings file"}'
        exit 1
    }

    echo '{"status":"ok"}'
    exit 0
}

main "$@"