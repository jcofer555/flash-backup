#!/bin/bash

CONFIG="/boot/config/plugins/flash-backup/settings_remote.cfg"
TMP="${CONFIG}.tmp"

mkdir -p "$(dirname "$CONFIG")"

# Safely assign defaults if missing
MINIMAL_BACKUP_REMOTE="${1:-no}"
RCLONE_MODE="${2:-mount}"
BACKUP_DESTINATION="${3:-}"
RCLONE_CONFIG="${4:-}"
BACKUPS_TO_KEEP_REMOTE="${5:-0}"
DRY_RUN_REMOTE="${6:-no}"
NOTIFICATIONS_REMOTE="${7:-no}"

# ==========================================================
#  Write all settings
# ==========================================================
{
  echo "MINIMAL_BACKUP_REMOTE=\"$MINIMAL_BACKUP_REMOTE\""
  echo "RCLONE_MODE=\"$RCLONE_MODE\""
  echo "BACKUP_DESTINATION=\"$BACKUP_DESTINATION\""
  echo "RCLONE_CONFIG=\"$RCLONE_CONFIG\""
  echo "BACKUPS_TO_KEEP_REMOTE=\"$BACKUPS_TO_KEEP_REMOTE\""
  echo "DRY_RUN_REMOTE=\"$DRY_RUN_REMOTE\""
  echo "NOTIFICATIONS_REMOTE=\"$NOTIFICATIONS_REMOTE\""
} > "$TMP"

mv "$TMP" "$CONFIG"
echo '{"status":"ok"}'
exit 0
