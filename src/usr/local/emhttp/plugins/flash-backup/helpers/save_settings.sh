#!/bin/bash

CONFIG="/boot/config/plugins/flash-backup/settings.cfg"
TMP="${CONFIG}.tmp"

mkdir -p "$(dirname "$CONFIG")"

# Safely assign defaults if missing
MINIMAL_BACKUP="${1:-no}"
BACKUP_DESTINATION="${2:-}"
BACKUPS_TO_KEEP="${3:-0}"
BACKUP_OWNER="${4:-nobody}"
DRY_RUN="${5:-no}"
NOTIFICATIONS="${6:-no}"

# ==========================================================
#  Write all settings
# ==========================================================
{
  echo "MINIMAL_BACKUP=\"$MINIMAL_BACKUP\""
  echo "BACKUP_DESTINATION=\"$BACKUP_DESTINATION\""
  echo "BACKUPS_TO_KEEP=\"$BACKUPS_TO_KEEP\""
  echo "BACKUP_OWNER=\"$BACKUP_OWNER\""
  echo "DRY_RUN=\"$DRY_RUN\""
  echo "NOTIFICATIONS=\"$NOTIFICATIONS\""
} > "$TMP"

mv "$TMP" "$CONFIG"
echo '{"status":"ok"}'
exit 0
