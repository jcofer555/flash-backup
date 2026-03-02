#!/bin/bash

readonly CONFIG="/boot/config/plugins/flash-backup/settings.cfg"
readonly TMP="${CONFIG}.tmp"

# ------------------------------------------------------------------------------
# main() — explicit entrypoint, all state explicit
# ------------------------------------------------------------------------------
main() {
    local minimal_backup="${1:-no}"
    local backup_destination="${2:-}"
    local backups_to_keep="${3:-0}"
    local backup_owner="${4:-nobody}"
    local dry_run="${5:-no}"
    local notifications="${6:-no}"
    local notification_service="${7:-}"
    local webhook_discord="${8:-}"
    local webhook_gotify="${9:-}"
    local webhook_ntfy="${10:-}"
    local webhook_pushover="${11:-}"
    local webhook_slack="${12:-}"
    local pushover_user_key="${13:-}"

    mkdir -p "$(dirname "$CONFIG")" || {
        echo '{"status":"error","message":"Failed to create config directory"}'
        exit 1
    }

    # Write all settings atomically via tmp-then-rename
    {
        echo "BACKUP_DESTINATION=\"$backup_destination\""
        echo "BACKUP_OWNER=\"$backup_owner\""
        echo "BACKUPS_TO_KEEP=\"$backups_to_keep\""
        echo "DRY_RUN=\"$dry_run\""
        echo "MINIMAL_BACKUP=\"$minimal_backup\""
        echo "NOTIFICATION_SERVICE=\"$notification_service\""
        echo "NOTIFICATIONS=\"$notifications\""
        echo "PUSHOVER_USER_KEY=\"$pushover_user_key\""
        echo "WEBHOOK_DISCORD=\"$webhook_discord\""
        echo "WEBHOOK_GOTIFY=\"$webhook_gotify\""
        echo "WEBHOOK_NTFY=\"$webhook_ntfy\""
        echo "WEBHOOK_PUSHOVER=\"$webhook_pushover\""
        echo "WEBHOOK_SLACK=\"$webhook_slack\""
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