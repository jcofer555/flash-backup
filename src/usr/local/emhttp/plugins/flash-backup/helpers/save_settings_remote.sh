#!/bin/bash

CONFIG="/boot/config/plugins/flash-backup/settings_remote.cfg"
TMP="${CONFIG}.tmp"

mkdir -p "$(dirname "$CONFIG")"

# Safely assign defaults if missing
MINIMAL_BACKUP_REMOTE="${1:-no}"
RCLONE_CONFIG_REMOTE="${2:-/Flash_Backups}"
B2_BUCKET_NAME="${3:-}"
REMOTE_PATH_IN_CONFIG="${4:-0}"
BACKUPS_TO_KEEP_REMOTE="${5:-0}"
DRY_RUN_REMOTE="${6:-no}"
NOTIFICATIONS_REMOTE="${7:-no}"
DISCORD_WEBHOOK_URL_REMOTE="${8:-}"
PUSHOVER_USER_KEY_REMOTE="${9:-}"

# ==========================================================
#  Write all settings
# ==========================================================
{
  echo "MINIMAL_BACKUP_REMOTE=\"$MINIMAL_BACKUP_REMOTE\""
  echo "RCLONE_CONFIG_REMOTE=\"$RCLONE_CONFIG_REMOTE\""
  echo "B2_BUCKET_NAME=\"$B2_BUCKET_NAME\""
  echo "REMOTE_PATH_IN_CONFIG=\"$REMOTE_PATH_IN_CONFIG\""
  echo "BACKUPS_TO_KEEP_REMOTE=\"$BACKUPS_TO_KEEP_REMOTE\""
  echo "DRY_RUN_REMOTE=\"$DRY_RUN_REMOTE\""
  echo "NOTIFICATIONS_REMOTE=\"$NOTIFICATIONS_REMOTE\""
  echo "DISCORD_WEBHOOK_URL_REMOTE=\"$DISCORD_WEBHOOK_URL_REMOTE\""
  echo "PUSHOVER_USER_KEY_REMOTE=\"$PUSHOVER_USER_KEY_REMOTE\""
} > "$TMP"

mv "$TMP" "$CONFIG"
echo '{"status":"ok"}'
exit 0
