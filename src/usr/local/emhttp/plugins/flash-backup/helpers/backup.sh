#!/bin/bash
export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

# ------------------------------------------------------------------------------
# Import environment variables from backup.php (manual or scheduled)
# ------------------------------------------------------------------------------

if [[ -n "${BACKUP_DESTINATION:-}" ]]; then
    DRY_RUN="${DRY_RUN:-no}"
    MINIMAL_BACKUP="${MINIMAL_BACKUP:-no}"
    BACKUPS_TO_KEEP="${BACKUPS_TO_KEEP:-0}"
    BACKUP_OWNER="${BACKUP_OWNER:-nobody}"
    NOTIFICATIONS="${NOTIFICATIONS:-no}"
fi

# Remove accidental quotes
BACKUPS_TO_KEEP="${BACKUPS_TO_KEEP//\"/}"
BACKUP_DESTINATION="${BACKUP_DESTINATION//\"/}"
BACKUP_OWNER="${BACKUP_OWNER//\"/}"
DRY_RUN="${DRY_RUN//\"/}"
MINIMAL_BACKUP="${MINIMAL_BACKUP//\"/}"
NOTIFICATIONS="${NOTIFICATIONS//\"/}"
DISCORD_WEBHOOK_URL="${DISCORD_WEBHOOK_URL//\"/}"
PUSHOVER_USER_KEY="${PUSHOVER_USER_KEY//\"/}"

SCRIPT_START_EPOCH=$(date +%s)

format_duration() {
    local total=$1
    local h=$(( total / 3600 ))
    local m=$(( (total % 3600) / 60 ))
    local s=$(( total % 60 ))
    local out=""
    (( h > 0 )) && out+="${h}h "
    (( m > 0 )) && out+="${m}m "
    out+="${s}s"
    echo "$out"
}

# ----------------------------
# Config / Paths
# ----------------------------
PLUGIN_NAME="flash-backup"
SETTINGS_FILE="/boot/config/plugins/${PLUGIN_NAME}/settings.cfg"

LOG_DIR="/tmp/flash-backup"
LAST_RUN_FILE="$LOG_DIR/flash-backup.log"
ROTATE_DIR="$LOG_DIR/archived_logs"
STATUS_FILE="$LOG_DIR/local_backup_status.txt"
DEBUG_LOG="$LOG_DIR/flash-backup-debug.log"

# ----------------------------
# Helpers: Status + Logging
# ----------------------------
mkdir -p "$LOG_DIR" "$ROTATE_DIR"

debug_log() {
    echo "[DEBUG $(date '+%Y-%m-%d %H:%M:%S')] $*" >> "$DEBUG_LOG"
}

# Rotate main log if >= 10MB
if [[ -f "$LAST_RUN_FILE" ]]; then
  size_bytes=$(stat -c%s "$LAST_RUN_FILE")
  max_bytes=$((10 * 1024 * 1024))
  if (( size_bytes >= max_bytes )); then
    ts="$(date +%Y%m%d_%H%M%S)"
    mv "$LAST_RUN_FILE" "$ROTATE_DIR/flash-backup_$ts.log"
    debug_log "Rotated main log to $ROTATE_DIR/flash-backup_$ts.log (was >= 10MB)"
  fi
fi

# Keep only 10 rotated main logs
mapfile -t rotated_logs < <(ls -1t "$ROTATE_DIR"/flash-backup_*.log 2>/dev/null)
if (( ${#rotated_logs[@]} > 10 )); then
  for (( i=10; i<${#rotated_logs[@]}; i++ )); do
    rm -f "${rotated_logs[$i]}"
    debug_log "Purged old rotated log: ${rotated_logs[$i]}"
  done
fi

# Rotate debug log if >= 10MB
if [[ -f "$DEBUG_LOG" ]]; then
  size_bytes=$(stat -c%s "$DEBUG_LOG")
  max_bytes=$((10 * 1024 * 1024))
  if (( size_bytes >= max_bytes )); then
    ts="$(date +%Y%m%d_%H%M%S)"
    mv "$DEBUG_LOG" "$ROTATE_DIR/flash-backup-debug_$ts.log"
    debug_log "Rotated debug log to $ROTATE_DIR/flash-backup-debug_$ts.log (was >= 10MB)"
  fi
fi

# Keep only 10 rotated debug logs
mapfile -t rotated_debug_logs < <(ls -1t "$ROTATE_DIR"/flash-backup-debug_*.log 2>/dev/null)
if (( ${#rotated_debug_logs[@]} > 10 )); then
  for (( i=10; i<${#rotated_debug_logs[@]}; i++ )); do
    rm -f "${rotated_debug_logs[$i]}"
    debug_log "Purged old rotated debug log: ${rotated_debug_logs[$i]}"
  done
fi

exec > >(tee -a "$LAST_RUN_FILE") 2>&1

set_status() { echo "$1" > "$STATUS_FILE"; }

echo "--------------------------------------------------------------------------------------------------"
echo "Local backup session started - $(date '+%Y-%m-%d %H:%M:%S')"
set_status "Starting local backup"

debug_log "===== Session started ====="
debug_log "BACKUP_DESTINATION=$BACKUP_DESTINATION"
debug_log "BACKUPS_TO_KEEP=$BACKUPS_TO_KEEP"
debug_log "BACKUP_OWNER=$BACKUP_OWNER"
debug_log "DRY_RUN=$DRY_RUN"
debug_log "MINIMAL_BACKUP=$MINIMAL_BACKUP"
debug_log "NOTIFICATIONS=$NOTIFICATIONS"
debug_log "DISCORD_WEBHOOK_URL=${DISCORD_WEBHOOK_URL:+(set)}"
debug_log "PUSHOVER_USER_KEY=${PUSHOVER_USER_KEY:+(set)}"
debug_log "SCRIPT_START_EPOCH=$SCRIPT_START_EPOCH"

# ----------------------------
# Notification helper
# ----------------------------
notify_local() {
  local level="$1"
  local subject="$2"
  local message="$3"

  debug_log "notify_local called: level=$level subject=$subject message=$message"

  [[ "$NOTIFICATIONS" != "yes" ]] && { debug_log "Notifications disabled, skipping"; return 0; }

  if [[ -n "$DISCORD_WEBHOOK_URL" ]]; then
    local color
    case "$level" in
      alert)   color=15158332 ;;
      warning) color=16776960 ;;
      *)       color=3066993  ;;
    esac

    if [[ "$DISCORD_WEBHOOK_URL" == *"discord.com/api/webhooks"* ]]; then
      debug_log "Sending Discord webhook notification"
      curl -sf -X POST "$DISCORD_WEBHOOK_URL" \
        -H "Content-Type: application/json" \
        -d "{\"embeds\":[{\"title\":\"$subject\",\"description\":\"$message\",\"color\":$color}]}" || true

    elif [[ "$DISCORD_WEBHOOK_URL" == *"hooks.slack.com"* ]]; then
      debug_log "Sending Slack webhook notification"
      curl -sf -X POST "$DISCORD_WEBHOOK_URL" \
        -H "Content-Type: application/json" \
        -d "{\"text\":\"*$subject*\n$message\"}" || true

    elif [[ "$DISCORD_WEBHOOK_URL" == *"outlook.office.com/webhook"* ]]; then
      debug_log "Sending Teams webhook notification"
      curl -sf -X POST "$DISCORD_WEBHOOK_URL" \
        -H "Content-Type: application/json" \
        -d "{\"title\":\"$subject\",\"text\":\"$message\"}" || true

    elif [[ "$DISCORD_WEBHOOK_URL" == *"/message"* ]]; then
      debug_log "Sending Gotify notification"
      curl -sf -X POST "$DISCORD_WEBHOOK_URL" \
        -H "Content-Type: application/json" \
        -d "{\"title\":\"$subject\",\"message\":\"$message\",\"priority\":5}" || true

    elif [[ "$DISCORD_WEBHOOK_URL" == *"ntfy.sh"* || "$DISCORD_WEBHOOK_URL" == *"/ntfy/"* ]]; then
      debug_log "Sending ntfy notification"
      curl -sf -X POST "$DISCORD_WEBHOOK_URL" \
        -H "Title: $subject" \
        -d "$message" > /dev/null || true

    elif [[ "$DISCORD_WEBHOOK_URL" == *"api.pushover.net"* ]]; then
      debug_log "Sending Pushover notification"
      local token="${DISCORD_WEBHOOK_URL##*/}"
      curl -sf -X POST "https://api.pushover.net/1/messages.json" \
        -d "token=${token}" \
        -d "user=${PUSHOVER_USER_KEY}" \
        -d "title=${title}" \
        -d "message=${message}" > /dev/null || true
        
    fi
  else
    if [[ -x /usr/local/emhttp/webGui/scripts/notify ]]; then
      debug_log "Sending Unraid native notification"
      /usr/local/emhttp/webGui/scripts/notify \
        -e "Flash Backup" \
        -s "$subject" \
        -d "$message" \
        -i "$level"
    else
      debug_log "No notification method available (notify script not found)"
    fi
  fi
}

notify_local "normal" "Flash Backup" "Local backup started"

sleep 5

# ----------------------------
# Cleanup trap
# ----------------------------
cleanup() {
    LOCK_FILE="/tmp/flash-backup/lock.txt"
    rm -f "$LOCK_FILE"
    debug_log "Lock file removed"

    SCRIPT_END_EPOCH=$(date +%s)
    SCRIPT_DURATION=$(( SCRIPT_END_EPOCH - SCRIPT_START_EPOCH ))
    SCRIPT_DURATION_HUMAN="$(format_duration "$SCRIPT_DURATION")"

    set_status "Local backup complete - Duration: $SCRIPT_DURATION_HUMAN"

    echo "Backup duration: $SCRIPT_DURATION_HUMAN"
    echo "Local backup session finished - $(date '+%Y-%m-%d %H:%M:%S')"

    debug_log "Session finished - duration=$SCRIPT_DURATION_HUMAN error_count=$error_count"

    if (( error_count > 0 )); then
      notify_local "warning" "Flash Backup" \
        "Local backup finished with errors - Duration: $SCRIPT_DURATION_HUMAN - Check logs for details"
    else
      notify_local "normal" "Flash Backup" \
        "Local backup finished - Duration: $SCRIPT_DURATION_HUMAN"
    fi

    rm -f "$STATUS_FILE"
    debug_log "===== Session ended ====="
}

trap cleanup EXIT SIGTERM SIGINT SIGHUP SIGQUIT

# ----------------------------
# Validation
# ----------------------------
error_count=0

if [[ -z "$BACKUP_DESTINATION" ]]; then
  debug_log "ERROR: BACKUP_DESTINATION is empty"
  echo "[ERROR] Backup destination is empty"
  set_status "Backup destination empty"
  notify_local "alert" "Flash Backup Error" "Backup destination is empty"
  exit 1
fi

if ! [[ "$BACKUPS_TO_KEEP" =~ ^[0-9]+$ ]]; then
  debug_log "ERROR: BACKUPS_TO_KEEP is not numeric: $BACKUPS_TO_KEEP"
  echo "[ERROR] Backups to keep is not numeric: $BACKUPS_TO_KEEP"
  set_status "Backups to keep invalid"
  notify_local "alert" "Flash Backup Error" "Backups to keep is not numeric: $BACKUPS_TO_KEEP"
  exit 1
fi

### [MULTI] Split comma-separated destinations
IFS=',' read -r -a DEST_ARRAY <<< "$BACKUP_DESTINATION"

if (( ${#DEST_ARRAY[@]} == 0 )); then
  debug_log "ERROR: No valid backup destinations after split"
  echo "[ERROR] No valid backup destinations"
  notify_local "alert" "Flash Backup Error" "No valid backup destinations"
  exit 1
fi

debug_log "Destination count: ${#DEST_ARRAY[@]}"

# ----------------------------
# Build file list (minimal vs full)
# ----------------------------
declare -a TAR_PATHS=()

if [[ "$MINIMAL_BACKUP" == "yes" ]]; then
  echo "Minimal backup mode only backing up /config, /extra, and /syslinux/syslinux.cfg"
  debug_log "Minimal backup mode enabled"
  for path in "/boot/config" "/boot/extra" "/boot/syslinux/syslinux.cfg"; do
    if [[ -e "$path" ]]; then
      TAR_PATHS+=("${path#/}")
      debug_log "Including path: $path"
    else
      echo "Skipping missing path -> $path"
      debug_log "Skipping missing path: $path"
    fi
  done
  (( ${#TAR_PATHS[@]} == 0 )) && {
    debug_log "ERROR: No valid paths found for minimal backup"
    echo "[ERROR] No valid paths found"
    set_status "No valid paths found"
    notify_local "alert" "Flash Backup Error" "No valid paths found for minimal backup"
    exit 1
  }
else
  echo "Full backup mode backing up entire /boot"
  debug_log "Full backup mode enabled, TAR_PATHS=(boot)"
  TAR_PATHS=("boot")
fi

debug_log "TAR_PATHS: ${TAR_PATHS[*]}"

# ----------------------------
# MAIN LOOP — backup each destination
# ----------------------------
IFS=',' read -r -a DEST_ARRAY <<< "$BACKUP_DESTINATION"
dest_count=${#DEST_ARRAY[@]}

for DEST in "${DEST_ARRAY[@]}"; do
    # Trim whitespace
    DEST="${DEST#"${DEST%%[![:space:]]*}"}"
    DEST="${DEST%"${DEST##*[![:space:]]}"}"

    [[ -z "$DEST" ]] && { debug_log "Skipping empty destination entry"; continue; }

    debug_log "Processing destination: $DEST"

    # Only show header when more than one destination
    if (( dest_count > 1 )); then
        echo ""
        echo "Processing destination -> $DEST"
    fi

    if [[ ! -d "$DEST" ]]; then
      if [[ "$DRY_RUN" == "yes" ]]; then
        echo "[DRY RUN] Would create directory -> $DEST"
        debug_log "[DRY RUN] Would create directory: $DEST"
      else
        debug_log "Directory does not exist, attempting to create: $DEST"
        mkdir -p "$DEST" || {
          debug_log "ERROR: Failed to create directory: $DEST"
          echo "[ERROR] Failed to create backup destination -> $DEST"
          notify_local "alert" "Flash Backup Error" "Failed to create backup destination: $DEST"
          exit 1
        }
        debug_log "Directory created: $DEST"
      fi
    else
      debug_log "Destination directory exists: $DEST"
    fi

    [[ "$DEST" != */ ]] && DEST="${DEST}/"

    ts="$(date +"%Y-%m-%d_%H-%M-%S")"
    backup_file="${DEST}flash_${ts}.tar.gz"
    tmp_backup_file="${backup_file}.tmp"

    debug_log "backup_file=$backup_file"
    debug_log "tmp_backup_file=$tmp_backup_file"

    set_status "Creating backup archive"

    # Create archive
    if [[ "$DRY_RUN" == "yes" ]]; then
      echo "[DRY RUN] Would create archive at -> $backup_file"
      debug_log "[DRY RUN] Would create archive: $backup_file"
    else
      debug_log "Starting tar archive creation"
      if [[ "${TAR_PATHS[0]}" == "boot" ]]; then
        tar czf "$tmp_backup_file" -C / boot || {
          debug_log "ERROR: tar failed for full boot backup"
          echo "[ERROR] Failed to create backup"
          notify_local "alert" "Flash Backup Error" "Failed to create backup tar archive"
          exit 1
        }
      else
        tar czf "$tmp_backup_file" -C / "${TAR_PATHS[@]}" || {
          debug_log "ERROR: tar failed for minimal backup paths: ${TAR_PATHS[*]}"
          echo "[ERROR] Failed to create backup"
          notify_local "alert" "Flash Backup Error" "Failed to create backup tar archive"
          exit 1
        }
      fi
      debug_log "tar archive created successfully: $tmp_backup_file"
    fi

    # Verify integrity
    if [[ "$DRY_RUN" == "yes" ]]; then
      echo "[DRY RUN] Would verify backup integrity"
      debug_log "[DRY RUN] Would verify integrity of: $tmp_backup_file"
    else
      debug_log "Verifying integrity of: $tmp_backup_file"
      tar -tf "$tmp_backup_file" >/dev/null 2>&1 || {
        debug_log "ERROR: Integrity check failed for: $tmp_backup_file"
        echo "[ERROR] Backup integrity check failed"
        notify_local "alert" "Flash Backup Error" "Backup integrity check failed"
        exit 1
      }
      debug_log "Integrity check passed"
      mv "$tmp_backup_file" "$backup_file"
      debug_log "Renamed tmp to final: $backup_file"
      echo "Created backup at -> $backup_file"

      # Log final file size
      if [[ -f "$backup_file" ]]; then
        backup_size=$(du -sh "$backup_file" 2>/dev/null | cut -f1)
        debug_log "Final backup size: $backup_size"
      fi
    fi

    # Ownership
    set_status "Changing ownership"
    if [[ "$DRY_RUN" == "yes" ]]; then
      echo "[DRY RUN] Would change owner to $BACKUP_OWNER:users"
      debug_log "[DRY RUN] Would chown $BACKUP_OWNER:users $backup_file"
    else
      debug_log "Changing ownership to $BACKUP_OWNER:users on $backup_file"
      chown "$BACKUP_OWNER:users" "$backup_file" || echo "[WARNING] Failed to change owner"
      echo "Changed owner to $BACKUP_OWNER:users"
      debug_log "Ownership change complete"
    fi

# ----------------------------
# Cleanup Old Backups (per destination)
# ----------------------------
set_status "Cleaning up old backups"

if (( BACKUPS_TO_KEEP == 0 )); then
    debug_log "BACKUPS_TO_KEEP=0, skipping old backup cleanup"
else

    # Human‑friendly label
    if (( BACKUPS_TO_KEEP == 1 )); then
        keep_label="only latest"
        backup_word="backup"
    else
        keep_label="$BACKUPS_TO_KEEP"
        backup_word="backups"
    fi

    # Print the same messages you had before
    if [[ "$DRY_RUN" == "yes" ]]; then
        echo "[DRY RUN] Removing old backups keeping $keep_label $backup_word for destination $DEST"
        debug_log "[DRY RUN] Would remove old backups keeping $keep_label $backup_word for: $DEST"
    else
        echo "Removing old backups keeping $keep_label $backup_word for destination $DEST"
        debug_log "Removing old backups keeping $keep_label $backup_word for: $DEST"
    fi

    # Collect backups for this destination
    mapfile -t backup_files < <(ls -1t "${DEST}"/flash_*.tar.gz 2>/dev/null)
    num_backups=${#backup_files[@]}

    debug_log "Found $num_backups existing backup(s) in $DEST"

    if (( num_backups > BACKUPS_TO_KEEP )); then
        remove_count=$(( num_backups - BACKUPS_TO_KEEP ))
        debug_log "Need to remove $remove_count old backup(s)"

        if [[ "$DRY_RUN" == "yes" ]]; then
            for (( idx=BACKUPS_TO_KEEP; idx<num_backups; idx++ )); do
                echo "[DRY RUN] Would remove ${backup_files[$idx]}"
                debug_log "[DRY RUN] Would remove: ${backup_files[$idx]}"
            done
        else
            for (( idx=BACKUPS_TO_KEEP; idx<num_backups; idx++ )); do
                file="${backup_files[$idx]}"
                debug_log "Removing old backup: $file"
                rm -f "$file" || echo "WARNING: Failed to remove file $file"
                debug_log "Removed: $file"
            done
        fi
    else
        debug_log "No old backups to remove ($num_backups backups, keeping $BACKUPS_TO_KEEP)"
    fi
fi

done

debug_log "All destinations processed"
echo "Local backup completed successfully"
exit 0