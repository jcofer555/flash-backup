#!/bin/bash
export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

# ------------------------------------------------------------------------------
# Import environment variables from remote_backup.php (manual or scheduled)
# ------------------------------------------------------------------------------

if [[ -n "${RCLONE_CONFIG_REMOTE:-}" ]]; then
    DRY_RUN_REMOTE="${DRY_RUN_REMOTE:-no}"
    MINIMAL_BACKUP_REMOTE="${MINIMAL_BACKUP_REMOTE:-no}"
    BACKUPS_TO_KEEP_REMOTE="${BACKUPS_TO_KEEP_REMOTE:-0}"
    NOTIFICATIONS_REMOTE="${NOTIFICATIONS_REMOTE:-no}"
    REMOTE_PATH_IN_CONFIG="${REMOTE_PATH_IN_CONFIG:-}"
fi

# Remove accidental quotes
RCLONE_CONFIG_REMOTE="${RCLONE_CONFIG_REMOTE//\"/}"
DRY_RUN_REMOTE="${DRY_RUN_REMOTE//\"/}"
MINIMAL_BACKUP_REMOTE="${MINIMAL_BACKUP_REMOTE//\"/}"
BACKUPS_TO_KEEP_REMOTE="${BACKUPS_TO_KEEP_REMOTE//\"/}"
NOTIFICATIONS_REMOTE="${NOTIFICATIONS_REMOTE//\"/}"
REMOTE_PATH_IN_CONFIG="${REMOTE_PATH_IN_CONFIG//\"/}"

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

LOG_DIR="/tmp/flash-backup"
LAST_RUN_FILE="$LOG_DIR/flash-backup.log"
ROTATE_DIR="$LOG_DIR/archived_logs"
STATUS_FILE="$LOG_DIR/remote_backup_status.txt"

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
    mv "$LAST_RUN_FILE" "$ROTATE_DIR/remote-backup_$ts.log"
  fi
fi

# Keep only 10 rotated logs
mapfile -t rotated_logs < <(ls -1t "$ROTATE_DIR"/remote-backup_*.log 2>/dev/null)
if (( ${#rotated_logs[@]} > 10 )); then
  for (( i=10; i<${#rotated_logs[@]}; i++ )); do
    rm -f "${rotated_logs[$i]}"
  done
fi

exec > >(tee -a "$LAST_RUN_FILE") 2>&1

set_status() { echo "$1" > "$STATUS_FILE"; }

echo "--------------------------------------------------------------------------------------------------"
echo "Remote backup session started - $(date '+%Y-%m-%d %H:%M:%S')"
set_status "Starting remote backup"

# ----------------------------
# Notification helper
# ----------------------------
notify_unraid_remote() {
  local level="$1"
  local subject="$2"
  local message="$3"

  [[ "$NOTIFICATIONS_REMOTE" != "yes" ]] && return 0

  if [[ -x /usr/local/emhttp/webGui/scripts/notify ]]; then
    /usr/local/emhttp/webGui/scripts/notify \
      -e "Flash Backup (Remote)" \
      -s "$subject" \
      -d "$message" \
      -i "$level"
  fi
}

notify_unraid_remote "normal" "Flash Backup" "Remote backup started"

sleep 5

# ----------------------------
# Cleanup trap
# ----------------------------
cleanup() {
    LOCK_FILE="/tmp/flash-backup/lock.txt"
    rm -f "$LOCK_FILE"
    rm -f /tmp/flash_*.tar.gz /tmp/flash_*.tar.gz.tmp 2>/dev/null

    SCRIPT_END_EPOCH=$(date +%s)
    SCRIPT_DURATION=$(( SCRIPT_END_EPOCH - SCRIPT_START_EPOCH ))
    SCRIPT_DURATION_HUMAN="$(format_duration "$SCRIPT_DURATION")"

    set_status "Remote backup complete - Duration: $SCRIPT_DURATION_HUMAN"

    echo "Backup duration: $SCRIPT_DURATION_HUMAN"
    echo "Remote backup session finished - $(date '+%Y-%m-%d %H:%M:%S')"

    notify_unraid_remote "normal" "Flash Backup" \
      "Remote backup finished - Duration: $SCRIPT_DURATION_HUMAN"

    rm -f "$STATUS_FILE"
}

trap cleanup EXIT SIGTERM SIGINT SIGHUP SIGQUIT

# ----------------------------
# Validation
# ----------------------------
if [[ -z "$RCLONE_CONFIG_REMOTE" ]]; then
  echo "[ERROR] No rclone remotes selected"
  set_status "No rclone remotes selected"
  exit 1
fi

if ! [[ "$BACKUPS_TO_KEEP_REMOTE" =~ ^[0-9]+$ ]]; then
  echo "[ERROR] Backups to keep is not numeric: $BACKUPS_TO_KEEP_REMOTE"
  set_status "Backups to keep invalid"
  exit 1
fi

IFS=',' read -r -a REMOTE_ARRAY <<< "$RCLONE_CONFIG_REMOTE"

if (( ${#REMOTE_ARRAY[@]} == 0 )); then
  echo "[ERROR] No valid rclone remotes"
  exit 1
fi

# ----------------------------
# Normalize remote path
# ----------------------------
if [[ -z "$REMOTE_PATH_IN_CONFIG" ]]; then
    REMOTE_SUBPATH="Flash_Backups"
else
    REMOTE_SUBPATH="${REMOTE_PATH_IN_CONFIG#/}"
    REMOTE_SUBPATH="${REMOTE_SUBPATH%/}"
    [[ -z "$REMOTE_SUBPATH" ]] && REMOTE_SUBPATH="Flash_Backups"
fi

echo "Remote backup folder being used for backup -> $REMOTE_PATH_IN_CONFIG"

# ----------------------------
# Build file list (minimal vs full)
# ----------------------------
declare -a TAR_PATHS=()

if [[ "$MINIMAL_BACKUP_REMOTE" == "yes" ]]; then
  echo "Minimal remote backup mode only backing up /config, /extra, and /syslinux/syslinux.cfg"
  for path in "/boot/config" "/boot/extra" "/boot/syslinux/syslinux.cfg"; do
    [[ -e "$path" ]] && TAR_PATHS+=("${path#/}") || echo "Skipping missing path -> $path"
  done
  (( ${#TAR_PATHS[@]} == 0 )) && {
    echo "[ERROR] No valid paths found"
    set_status "No valid paths found"
    exit 1
  }
else
  echo "Full remote backup mode backing up entire /boot"
  TAR_PATHS=("boot")
fi

# ----------------------------
# Create remote backup archive
# ----------------------------
ts="$(date +"%Y-%m-%d_%H-%M-%S")"
backup_file="/tmp/flash_${ts}.tar.gz"
tmp_backup_file="${backup_file}.tmp"

set_status "Creating remote backup archive"

if [[ "$DRY_RUN_REMOTE" == "yes" ]]; then
  echo "[DRY RUN] Would create archive -> $backup_file"
else
  if [[ "${TAR_PATHS[0]}" == "boot" ]]; then
    tar czf "$tmp_backup_file" -C / boot || {
      echo "[ERROR] Failed to create remote backup tar archive"
      exit 1
    }
  else
    tar czf "$tmp_backup_file" -C / "${TAR_PATHS[@]}" || {
      echo "[ERROR] Failed to create remote backup tar archive"
      exit 1
    }
  fi

  tar -tf "$tmp_backup_file" >/dev/null 2>&1 || {
    echo "[ERROR] Remote backup integrity check failed"
    exit 1
  }

  mv "$tmp_backup_file" "$backup_file"
fi

# ----------------------------
# MAIN LOOP — upload to each remote
# ----------------------------
success_count=0
failure_count=0

for REMOTE in "${REMOTE_ARRAY[@]}"; do
    REMOTE="${REMOTE#"${REMOTE%%[![:space:]]*}"}"
    REMOTE="${REMOTE%"${REMOTE##*[![:space:]]}"}"

    [[ -z "$REMOTE" ]] && continue

    DEST="${REMOTE}:${REMOTE_SUBPATH}/"

    echo ""
    echo "Uploading remote backup to config -> $REMOTE using folder ${REMOTE_SUBPATH}"
    set_status "Uploading remote backup to $REMOTE"

    # Ensure remote folder exists
    if [[ "$DRY_RUN_REMOTE" == "yes" ]]; then
        echo "[DRY RUN] Would ensure remote folder exists -> $DEST"
    else
        if ! rclone mkdir "$DEST"; then
            echo "[ERROR] Failed to create folder $REMOTE_SUBPATH on config $REMOTE"
            set_status "Failed to create folder on $REMOTE"
            ((failure_count++))
            continue
        fi
    fi

    # Upload backup
    if [[ "$DRY_RUN_REMOTE" == "yes" ]]; then
        echo "[DRY RUN] Would upload $backup_file to $DEST"
        ((success_count++))
    else
        if ! rclone copy "$backup_file" "$DEST" --checksum --fast-list; then
            echo "[ERROR] Failed to upload remote backup to $REMOTE"
            set_status "Upload failed for $REMOTE"
            ((failure_count++))
            continue
        fi
        echo "Uploaded remote backup to -> $DEST"
        ((success_count++))
    fi

    # ----------------------------
    # Cleanup Old Backups (per remote)
    # ----------------------------
    set_status "Cleaning up old remote backups"

    if (( BACKUPS_TO_KEEP_REMOTE == 0 )); then
        :
    else
        if (( BACKUPS_TO_KEEP_REMOTE == 1 )); then
            keep_label="only latest"
            backup_word="backup"
        else
            keep_label="$BACKUPS_TO_KEEP_REMOTE"
            backup_word="backups"
        fi

        if [[ "$DRY_RUN_REMOTE" == "yes" ]]; then
            echo "[DRY RUN] Removing old remote backups keeping $keep_label $backup_word for $REMOTE"
        else
            echo "Removing old remote backups keeping $keep_label $backup_word for $REMOTE"
        fi

        mapfile -t files < <(rclone lsf "$DEST" --files-only --format "p" | sort -r)
        num_files=${#files[@]}

        if (( num_files > BACKUPS_TO_KEEP_REMOTE )); then
            for (( idx=BACKUPS_TO_KEEP_REMOTE; idx<num_files; idx++ )); do
                old="${files[$idx]}"
                if [[ "$DRY_RUN_REMOTE" == "yes" ]]; then
                    echo "[DRY RUN] Would remove $DEST/$old"
                else
                    if ! rclone delete "$DEST/$old"; then
                        echo "WARNING: Failed to remove remote file $old on $REMOTE"
                        set_status "Retention warning for $REMOTE"
                    fi
                fi
            done
        fi
    fi

done

exit 0
