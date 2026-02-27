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

# ----------------------------
# Helpers: Status + Logging
# ----------------------------
mkdir -p "$LOG_DIR" "$ROTATE_DIR"

# Rotate log if >= 10MB
if [[ -f "$LAST_RUN_FILE" ]]; then
  size_bytes=$(stat -c%s "$LAST_RUN_FILE")
  max_bytes=$((10 * 1024 * 1024))
  if (( size_bytes >= max_bytes )); then
    ts="$(date +%Y%m%d_%H%M%S)"
    mv "$LAST_RUN_FILE" "$ROTATE_DIR/flash-backup_$ts.log"
  fi
fi

# Keep only 10 rotated logs
mapfile -t rotated_logs < <(ls -1t "$ROTATE_DIR"/flash-backup_*.log 2>/dev/null)
if (( ${#rotated_logs[@]} > 10 )); then
  for (( i=10; i<${#rotated_logs[@]}; i++ )); do
    rm -f "${rotated_logs[$i]}"
  done
fi

exec > >(tee -a "$LAST_RUN_FILE") 2>&1

set_status() { echo "$1" > "$STATUS_FILE"; }

echo "--------------------------------------------------------------------------------------------------"
echo "Local backup session started - $(date '+%Y-%m-%d %H:%M:%S')"
set_status "Starting local backup"

# ----------------------------
# Notification helper
# ----------------------------
notify_local() {
  local level="$1"
  local subject="$2"
  local message="$3"

  [[ "$NOTIFICATIONS" != "yes" ]] && return 0

  if [[ -n "$DISCORD_WEBHOOK_URL" ]]; then
    local color
    case "$level" in
      alert)   color=15158332 ;;
      warning) color=16776960 ;;
      *)       color=3066993  ;;
    esac

    if [[ "$DISCORD_WEBHOOK_URL" == *"discord.com/api/webhooks"* ]]; then
      curl -sf -X POST "$DISCORD_WEBHOOK_URL" \
        -H "Content-Type: application/json" \
        -d "{\"embeds\":[{\"title\":\"$subject\",\"description\":\"$message\",\"color\":$color}]}" || true

    elif [[ "$DISCORD_WEBHOOK_URL" == *"hooks.slack.com"* ]]; then
      curl -sf -X POST "$DISCORD_WEBHOOK_URL" \
        -H "Content-Type: application/json" \
        -d "{\"text\":\"*$subject*\n$message\"}" || true

    elif [[ "$DISCORD_WEBHOOK_URL" == *"outlook.office.com/webhook"* ]]; then
      curl -sf -X POST "$DISCORD_WEBHOOK_URL" \
        -H "Content-Type: application/json" \
        -d "{\"title\":\"$subject\",\"text\":\"$message\"}" || true

    elif [[ "$DISCORD_WEBHOOK_URL" == *"/message"* ]]; then
      curl -sf -X POST "$DISCORD_WEBHOOK_URL" \
        -H "Content-Type: application/json" \
        -d "{\"title\":\"$subject\",\"message\":\"$message\",\"priority\":5}" || true

    elif [[ "$DISCORD_WEBHOOK_URL" == *"ntfy.sh"* || "$DISCORD_WEBHOOK_URL" == *"/ntfy/"* ]]; then
      curl -sf -X POST "$DISCORD_WEBHOOK_URL" \
        -H "Title: $subject" \
        -d "$message" > /dev/null || true

    elif [[ "$DISCORD_WEBHOOK_URL" == *"api.pushover.net"* ]]; then
    local token="${DISCORD_WEBHOOK_URL##*/}"
    curl -sf -X POST "https://api.pushover.net/1/messages.json" \
        -d "token=${token}" \
        -d "user=${PUSHOVER_USER_KEY}" \
        -d "title=${title}" \
        -d "message=${message}" > /dev/null || true
        
    fi
  else
    if [[ -x /usr/local/emhttp/webGui/scripts/notify ]]; then
      /usr/local/emhttp/webGui/scripts/notify \
        -e "Flash Backup" \
        -s "$subject" \
        -d "$message" \
        -i "$level"
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

    SCRIPT_END_EPOCH=$(date +%s)
    SCRIPT_DURATION=$(( SCRIPT_END_EPOCH - SCRIPT_START_EPOCH ))
    SCRIPT_DURATION_HUMAN="$(format_duration "$SCRIPT_DURATION")"

    set_status "Local backup complete - Duration: $SCRIPT_DURATION_HUMAN"

    echo "Backup duration: $SCRIPT_DURATION_HUMAN"
    echo "Local backup session finished - $(date '+%Y-%m-%d %H:%M:%S')"

    if (( error_count > 0 )); then
      notify_local "warning" "Flash Backup" \
        "Local backup finished with errors - Duration: $SCRIPT_DURATION_HUMAN - Check logs for details"
    else
      notify_local "normal" "Flash Backup" \
        "Local backup finished - Duration: $SCRIPT_DURATION_HUMAN"
    fi

    rm -f "$STATUS_FILE"
}

trap cleanup EXIT SIGTERM SIGINT SIGHUP SIGQUIT

# ----------------------------
# Validation
# ----------------------------
error_count=0

if [[ -z "$BACKUP_DESTINATION" ]]; then
  echo "[ERROR] Backup destination is empty"
  set_status "Backup destination empty"
  notify_local "alert" "Flash Backup Error" "Backup destination is empty"
  exit 1
fi

if ! [[ "$BACKUPS_TO_KEEP" =~ ^[0-9]+$ ]]; then
  echo "[ERROR] Backups to keep is not numeric: $BACKUPS_TO_KEEP"
  set_status "Backups to keep invalid"
  notify_local "alert" "Flash Backup Error" "Backups to keep is not numeric: $BACKUPS_TO_KEEP"
  exit 1
fi

### [MULTI] Split comma-separated destinations
IFS=',' read -r -a DEST_ARRAY <<< "$BACKUP_DESTINATION"

if (( ${#DEST_ARRAY[@]} == 0 )); then
  echo "[ERROR] No valid backup destinations"
  notify_local "alert" "Flash Backup Error" "No valid backup destinations"
  exit 1
fi

# ----------------------------
# Build file list (minimal vs full)
# ----------------------------
declare -a TAR_PATHS=()

if [[ "$MINIMAL_BACKUP" == "yes" ]]; then
  echo "Minimal backup mode only backing up /config, /extra, and /syslinux/syslinux.cfg"
  for path in "/boot/config" "/boot/extra" "/boot/syslinux/syslinux.cfg"; do
    [[ -e "$path" ]] && TAR_PATHS+=("${path#/}") || echo "Skipping missing path -> $path"
  done
  (( ${#TAR_PATHS[@]} == 0 )) && {
    echo "[ERROR] No valid paths found"
    set_status "No valid paths found"
    notify_local "alert" "Flash Backup Error" "No valid paths found for minimal backup"
    exit 1
  }
else
  echo "Full backup mode backing up entire /boot"
  TAR_PATHS=("boot")
fi

# ----------------------------
# MAIN LOOP — backup each destination
# ----------------------------
IFS=',' read -r -a DEST_ARRAY <<< "$BACKUP_DESTINATION"
dest_count=${#DEST_ARRAY[@]}

for DEST in "${DEST_ARRAY[@]}"; do
    # Trim whitespace
    DEST="${DEST#"${DEST%%[![:space:]]*}"}"
    DEST="${DEST%"${DEST##*[![:space:]]}"}"

    [[ -z "$DEST" ]] && continue

    # Only show header when more than one destination
    if (( dest_count > 1 )); then
        echo ""
        echo "Processing destination -> $DEST"
    fi

    if [[ ! -d "$DEST" ]]; then
      if [[ "$DRY_RUN" == "yes" ]]; then
        echo "[DRY RUN] Would create directory -> $DEST"
      else
        mkdir -p "$DEST" || {
          echo "[ERROR] Failed to create backup destination -> $DEST"
          notify_local "alert" "Flash Backup Error" "Failed to create backup destination: $DEST"
          exit 1
        }
      fi
    fi

    [[ "$DEST" != */ ]] && DEST="${DEST}/"

    ts="$(date +"%Y-%m-%d_%H-%M-%S")"
    backup_file="${DEST}flash_${ts}.tar.gz"
    tmp_backup_file="${backup_file}.tmp"

    set_status "Creating backup archive"

    # Create archive
    if [[ "$DRY_RUN" == "yes" ]]; then
      echo "[DRY RUN] Would create archive at -> $backup_file"
    else
      if [[ "${TAR_PATHS[0]}" == "boot" ]]; then
        tar czf "$tmp_backup_file" -C / boot || {
          echo "[ERROR] Failed to create backup"
          notify_local "alert" "Flash Backup Error" "Failed to create backup tar archive"
          exit 1
        }
      else
        tar czf "$tmp_backup_file" -C / "${TAR_PATHS[@]}" || {
          echo "[ERROR] Failed to create backup"
          notify_local "alert" "Flash Backup Error" "Failed to create backup tar archive"
          exit 1
        }
      fi
    fi

    # Verify integrity
    if [[ "$DRY_RUN" == "yes" ]]; then
      echo "[DRY RUN] Would verify backup integrity"
    else
      tar -tf "$tmp_backup_file" >/dev/null 2>&1 || {
        echo "[ERROR] Backup integrity check failed"
        notify_local "alert" "Flash Backup Error" "Backup integrity check failed"
        exit 1
      }
      mv "$tmp_backup_file" "$backup_file"
      echo "Created backup at -> $backup_file"
    fi

    # Ownership
    set_status "Changing ownership"
    if [[ "$DRY_RUN" == "yes" ]]; then
      echo "[DRY RUN] Would change owner to $BACKUP_OWNER:users"
    else
      chown "$BACKUP_OWNER:users" "$backup_file" || echo "[WARNING] Failed to change owner"
      echo "Changed owner to $BACKUP_OWNER:users"
    fi

# ----------------------------
# Cleanup Old Backups (per destination)
# ----------------------------
set_status "Cleaning up old backups"

if (( BACKUPS_TO_KEEP == 0 )); then
    :
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
    else
        echo "Removing old backups keeping $keep_label $backup_word for destination $DEST"
    fi

    # Collect backups for this destination
    mapfile -t backup_files < <(ls -1t "${DEST}"/flash_*.tar.gz 2>/dev/null)
    num_backups=${#backup_files[@]}

    if (( num_backups > BACKUPS_TO_KEEP )); then
        remove_count=$(( num_backups - BACKUPS_TO_KEEP ))

        if [[ "$DRY_RUN" == "yes" ]]; then
            for (( idx=BACKUPS_TO_KEEP; idx<num_backups; idx++ )); do
                echo "[DRY RUN] Would remove ${backup_files[$idx]}"
            done
        else
            for (( idx=BACKUPS_TO_KEEP; idx<num_backups; idx++ )); do
                file="${backup_files[$idx]}"
                rm -f "$file" || echo "WARNING: Failed to remove file $file"
            done
        fi
    else
        :
    fi
fi

done

echo "Local backup completed successfully"
exit 0