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
    B2_BUCKET_NAME="${B2_BUCKET_NAME:-}"
fi

# Remove accidental quotes
RCLONE_CONFIG_REMOTE="${RCLONE_CONFIG_REMOTE//\"/}"
DRY_RUN_REMOTE="${DRY_RUN_REMOTE//\"/}"
MINIMAL_BACKUP_REMOTE="${MINIMAL_BACKUP_REMOTE//\"/}"
BACKUPS_TO_KEEP_REMOTE="${BACKUPS_TO_KEEP_REMOTE//\"/}"
NOTIFICATIONS_REMOTE="${NOTIFICATIONS_REMOTE//\"/}"
REMOTE_PATH_IN_CONFIG="${REMOTE_PATH_IN_CONFIG//\"/}"
B2_BUCKET_NAME="${B2_BUCKET_NAME//\"/}"
DISCORD_WEBHOOK_URL_REMOTE="${DISCORD_WEBHOOK_URL_REMOTE//\"/}"
PUSHOVER_USER_KEY_REMOTE="${PUSHOVER_USER_KEY_REMOTE//\"/}"

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

format_bytes() {
    local bytes=$1
    local units=(B KB MB GB TB)
    local i=0

    while (( bytes >= 1024 && i < ${#units[@]}-1 )); do
        bytes=$(( bytes / 1024 ))
        ((i++))
    done

    echo "${bytes}${units[$i]}"
}

get_rclone_flags() {
    local remote_type="$1"
    local underlying_type="$2"

    # For crypt remotes, use the underlying remote's flags + --fast-list if supported
    if [[ "$remote_type" == "crypt" ]]; then
        remote_type="$underlying_type"
    fi

    case "$remote_type" in
        b2)
            echo "--fast-list --transfers=32 --checkers=32 --b2-chunk-size=100M --retries=5 --low-level-retries=10"
            ;;
        drive)
            echo "--fast-list --transfers=8 --checkers=8 --drive-chunk-size=64M --drive-skip-gdocs --retries=5 --low-level-retries=10"
            ;;
        s3)
            echo "--fast-list --transfers=32 --checkers=32 --s3-upload-cutoff=100M --s3-chunk-size=100M --retries=5 --low-level-retries=10"
            ;;
        onedrive)
            echo "--fast-list --transfers=10 --checkers=10 --onedrive-chunk-size=100M --retries=5 --low-level-retries=10"
            ;;
        dropbox)
            echo "--transfers=8 --checkers=8 --dropbox-chunk-size=48M --retries=5 --low-level-retries=10"
            ;;
        sftp|ftp|ssh)
            echo "--transfers=4 --checkers=4 --timeout=1m --contimeout=1m --retries=3 --low-level-retries=5"
            ;;
        s3idrive)
            echo "--fast-list --transfers=16 --checkers=16 --s3-upload-cutoff=64M --s3-chunk-size=64M --retries=5 --low-level-retries=10"
            ;;
        box)
            echo "--fast-list --transfers=6 --checkers=6 --retries=5 --low-level-retries=10"
            ;;
        gcs)
            echo "--fast-list --transfers=32 --checkers=32 --gcs-chunk-size=100M --retries=5 --low-level-retries=10"
            ;;
        googlephotos)
            echo "--transfers=4 --checkers=4 --retries=5 --low-level-retries=10"
            ;;
        koofr)
            echo "--fast-list --transfers=8 --checkers=8 --retries=5 --low-level-retries=10"
            ;;
        mega)
            echo "--transfers=6 --checkers=6 --mega-chunk-size=32M --retries=5 --low-level-retries=10"
            ;;
        azureblob)
            echo "--fast-list --transfers=32 --checkers=32 --azureblob-chunk-size=100M --retries=5 --low-level-retries=10"
            ;;
        azurefiles)
            echo "--transfers=16 --checkers=16 --azurefiles-chunk-size=64M --retries=5 --low-level-retries=10"
            ;;
        protondrive)
            echo "--transfers=4 --checkers=4 --retries=5 --low-level-retries=10"
            ;;
        putio)
            echo "--transfers=6 --checkers=6 --retries=5 --low-level-retries=10"
            ;;
        smb)
            echo "--transfers=8 --checkers=8 --retries=3 --low-level-retries=5"
            ;;
        seafile)
            echo "--fast-list --transfers=8 --checkers=8 --retries=5 --low-level-retries=10"
            ;;
        *)
            echo "--transfers=4 --checkers=4 --retries=5 --low-level-retries=10"
            ;;
    esac
}

# ----------------------------
# Config / Paths
# ----------------------------
PLUGIN_NAME="flash-backup"

LOG_DIR="/tmp/flash-backup"
LAST_RUN_FILE="$LOG_DIR/flash-backup.log"
ROTATE_DIR="$LOG_DIR/archived_logs"
STATUS_FILE="$LOG_DIR/remote_backup_status.txt"
DEBUG_LOG="$LOG_DIR/flash-backup-remote-debug.log"

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
    mv "$LAST_RUN_FILE" "$ROTATE_DIR/remote-backup_$ts.log"
    debug_log "Rotated main log to $ROTATE_DIR/remote-backup_$ts.log (was >= 10MB)"
  fi
fi

# Keep only 10 rotated main logs
mapfile -t rotated_logs < <(ls -1t "$ROTATE_DIR"/remote-backup_*.log 2>/dev/null)
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
    mv "$DEBUG_LOG" "$ROTATE_DIR/flash-backup-remote-debug_$ts.log"
    debug_log "Rotated debug log to $ROTATE_DIR/flash-backup-remote-debug_$ts.log (was >= 10MB)"
  fi
fi

# Keep only 10 rotated debug logs
mapfile -t rotated_debug_logs < <(ls -1t "$ROTATE_DIR"/flash-backup-remote-debug_*.log 2>/dev/null)
if (( ${#rotated_debug_logs[@]} > 10 )); then
  for (( i=10; i<${#rotated_debug_logs[@]}; i++ )); do
    rm -f "${rotated_debug_logs[$i]}"
    debug_log "Purged old rotated debug log: ${rotated_debug_logs[$i]}"
  done
fi

exec > >(tee -a "$LAST_RUN_FILE") 2>&1

set_status() { echo "$1" > "$STATUS_FILE"; }

echo "--------------------------------------------------------------------------------------------------"
echo "Remote backup session started - $(date '+%Y-%m-%d %H:%M:%S')"
set_status "Starting remote backup"

debug_log "===== Session started ====="
debug_log "RCLONE_CONFIG_REMOTE=$RCLONE_CONFIG_REMOTE"
debug_log "BACKUPS_TO_KEEP_REMOTE=$BACKUPS_TO_KEEP_REMOTE"
debug_log "DRY_RUN_REMOTE=$DRY_RUN_REMOTE"
debug_log "MINIMAL_BACKUP_REMOTE=$MINIMAL_BACKUP_REMOTE"
debug_log "NOTIFICATIONS_REMOTE=$NOTIFICATIONS_REMOTE"
debug_log "REMOTE_PATH_IN_CONFIG=$REMOTE_PATH_IN_CONFIG"
debug_log "B2_BUCKET_NAME=${B2_BUCKET_NAME:+(set)}"
debug_log "DISCORD_WEBHOOK_URL_REMOTE=${DISCORD_WEBHOOK_URL_REMOTE:+(set)}"
debug_log "PUSHOVER_USER_KEY_REMOTE=${PUSHOVER_USER_KEY_REMOTE:+(set)}"
debug_log "SCRIPT_START_EPOCH=$SCRIPT_START_EPOCH"

# ----------------------------
# Notification helper
# ----------------------------
notify_remote() {
  local level="$1"
  local subject="$2"
  local message="$3"

  debug_log "notify_remote called: level=$level subject=$subject message=$message"

  [[ "$NOTIFICATIONS_REMOTE" != "yes" ]] && { debug_log "Notifications disabled, skipping"; return 0; }

  if [[ -n "$DISCORD_WEBHOOK_URL_REMOTE" ]]; then
    local color
    case "$level" in
      alert)   color=15158332 ;;
      warning) color=16776960 ;;
      *)       color=3066993  ;;
    esac

    if [[ "$DISCORD_WEBHOOK_URL_REMOTE" == *"discord.com/api/webhooks"* ]]; then
      debug_log "Sending Discord webhook notification"
      curl -sf -X POST "$DISCORD_WEBHOOK_URL_REMOTE" \
        -H "Content-Type: application/json" \
        -d "{\"embeds\":[{\"title\":\"$subject\",\"description\":\"$message\",\"color\":$color}]}" || true

    elif [[ "$DISCORD_WEBHOOK_URL_REMOTE" == *"hooks.slack.com"* ]]; then
      debug_log "Sending Slack webhook notification"
      curl -sf -X POST "$DISCORD_WEBHOOK_URL_REMOTE" \
        -H "Content-Type: application/json" \
        -d "{\"text\":\"*$subject*\n$message\"}" || true

    elif [[ "$DISCORD_WEBHOOK_URL_REMOTE" == *"outlook.office.com/webhook"* ]]; then
      debug_log "Sending Teams webhook notification"
      curl -sf -X POST "$DISCORD_WEBHOOK_URL_REMOTE" \
        -H "Content-Type: application/json" \
        -d "{\"title\":\"$subject\",\"text\":\"$message\"}" || true

    elif [[ "$DISCORD_WEBHOOK_URL_REMOTE" == *"/message"* ]]; then
      debug_log "Sending Gotify notification"
      curl -sf -X POST "$DISCORD_WEBHOOK_URL_REMOTE" \
        -H "Content-Type: application/json" \
        -d "{\"title\":\"$subject\",\"message\":\"$message\",\"priority\":5}" > /dev/null || true

    elif [[ "$DISCORD_WEBHOOK_URL_REMOTE" == *"ntfy.sh"* || "$DISCORD_WEBHOOK_URL_REMOTE" == *"/ntfy/"* ]]; then
      debug_log "Sending ntfy notification"
      curl -sf -X POST "$DISCORD_WEBHOOK_URL_REMOTE" \
        -H "Title: $subject" \
        -d "$message" > /dev/null || true

    elif [[ "$DISCORD_WEBHOOK_URL_REMOTE" == *"api.pushover.net"* ]]; then
      debug_log "Sending Pushover notification"
      local token="${DISCORD_WEBHOOK_URL_REMOTE##*/}"
      curl -sf -X POST "https://api.pushover.net/1/messages.json" \
        -d "token=${token}" \
        -d "user=${PUSHOVER_USER_KEY_REMOTE}" \
        -d "title=${title}" \
        -d "message=${message}" > /dev/null || true

    fi
  else
    if [[ -x /usr/local/emhttp/webGui/scripts/notify ]]; then
      debug_log "Sending Unraid native notification"
      /usr/local/emhttp/webGui/scripts/notify \
        -e "Flash Backup (Remote)" \
        -s "$subject" \
        -d "$message" \
        -i "$level"
    else
      debug_log "No notification method available (notify script not found)"
    fi
  fi
}

notify_remote "normal" "Flash Backup" "Remote backup started"

sleep 5

# ----------------------------
# Cleanup trap
# ----------------------------
cleanup() {
    LOCK_FILE="/tmp/flash-backup/lock.txt"
    rm -f "$LOCK_FILE"
    rm -f /tmp/flash_*.tar.gz /tmp/flash_*.tar.gz.tmp 2>/dev/null
    debug_log "Lock file and temp files removed"

    SCRIPT_END_EPOCH=$(date +%s)
    SCRIPT_DURATION=$(( SCRIPT_END_EPOCH - SCRIPT_START_EPOCH ))
    SCRIPT_DURATION_HUMAN="$(format_duration "$SCRIPT_DURATION")"

    set_status "Remote backup complete - Duration: $SCRIPT_DURATION_HUMAN"

    echo "Backup duration: $SCRIPT_DURATION_HUMAN"
    echo "Remote backup session finished - $(date '+%Y-%m-%d %H:%M:%S')"

    debug_log "Session finished - duration=$SCRIPT_DURATION_HUMAN failure_count=$failure_count success_count=$success_count"

    if (( failure_count > 0 )); then
      notify_remote "warning" "Flash Backup" \
        "Remote backup finished with errors - Duration: $SCRIPT_DURATION_HUMAN - Check logs for details"
    else
      notify_remote "normal" "Flash Backup" \
        "Remote backup finished - Duration: $SCRIPT_DURATION_HUMAN"
    fi

    rm -f "$STATUS_FILE"
    debug_log "===== Session ended ====="
}

trap cleanup EXIT SIGTERM SIGINT SIGHUP SIGQUIT

# ----------------------------
# Validation
# ----------------------------
if [[ -z "$RCLONE_CONFIG_REMOTE" ]]; then
  debug_log "ERROR: RCLONE_CONFIG_REMOTE is empty"
  echo "[ERROR] No rclone remotes selected"
  set_status "No rclone remotes selected"
  notify_remote "alert" "Flash Backup Error" "No rclone remotes selected"
  exit 1
fi

if ! [[ "$BACKUPS_TO_KEEP_REMOTE" =~ ^[0-9]+$ ]]; then
  debug_log "ERROR: BACKUPS_TO_KEEP_REMOTE is not numeric: $BACKUPS_TO_KEEP_REMOTE"
  echo "[ERROR] Backups to keep is not numeric: $BACKUPS_TO_KEEP_REMOTE"
  set_status "Backups to keep invalid"
  notify_remote "alert" "Flash Backup Error" "Backups to keep is not numeric: $BACKUPS_TO_KEEP_REMOTE"
  exit 1
fi

IFS=',' read -r -a REMOTE_ARRAY <<< "$RCLONE_CONFIG_REMOTE"

if (( ${#REMOTE_ARRAY[@]} == 0 )); then
  debug_log "ERROR: No valid rclone remotes after split"
  echo "[ERROR] No valid rclone remotes"
  notify_remote "alert" "Flash Backup Error" "No valid rclone remotes"
  exit 1
fi

debug_log "Remote count: ${#REMOTE_ARRAY[@]}"

# Validate allowed characters for each folder segment
if [[ -n "$REMOTE_PATH_IN_CONFIG" ]]; then
    inner="${REMOTE_PATH_IN_CONFIG#/}"
    inner="${inner%/}"
    IFS='/' read -r -a parts <<< "$inner"

    for p in "${parts[@]}"; do
        if ! [[ "$p" =~ ^[A-Za-z0-9._+\-@[:space:]]+$ ]]; then
            debug_log "ERROR: Invalid folder name segment: $p"
            echo "[ERROR] Invalid folder name $p"
            set_status "Invalid folder name"
            notify_remote "alert" "Flash Backup Error" "Invalid folder name: $p"
            exit 1
        fi
    done
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

debug_log "REMOTE_SUBPATH=$REMOTE_SUBPATH"

# ----------------------------
# Build file list (minimal vs full)
# ----------------------------
declare -a TAR_PATHS=()

if [[ "$MINIMAL_BACKUP_REMOTE" == "yes" ]]; then
  echo "Minimal remote backup mode only backing up /config, /extra, and /syslinux/syslinux.cfg"
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
    notify_remote "alert" "Flash Backup Error" "No valid paths found for minimal backup"
    exit 1
  }
else
  echo "Full remote backup mode backing up entire /boot"
  debug_log "Full backup mode enabled, TAR_PATHS=(boot)"
  TAR_PATHS=("boot")
fi

debug_log "TAR_PATHS: ${TAR_PATHS[*]}"

# ----------------------------
# Create remote backup archive
# ----------------------------
ts="$(date +"%Y-%m-%d_%H-%M-%S")"
backup_file="/tmp/flash_${ts}.tar.gz"
tmp_backup_file="${backup_file}.tmp"

debug_log "backup_file=$backup_file"
debug_log "tmp_backup_file=$tmp_backup_file"

if [[ "$DRY_RUN_REMOTE" == "yes" ]]; then
  echo "[DRY RUN] Would create archive -> $backup_file"
  debug_log "[DRY RUN] Would create archive: $backup_file"
else
  debug_log "Starting tar archive creation"
  if [[ "${TAR_PATHS[0]}" == "boot" ]]; then
    tar czf "$tmp_backup_file" -C / boot || {
      debug_log "ERROR: tar failed for full boot backup"
      echo "[ERROR] Failed to create remote backup tar archive"
      notify_remote "alert" "Flash Backup Error" "Failed to create backup tar archive"
      exit 1
    }
  else
    tar czf "$tmp_backup_file" -C / "${TAR_PATHS[@]}" || {
      debug_log "ERROR: tar failed for minimal backup paths: ${TAR_PATHS[*]}"
      echo "[ERROR] Failed to create remote backup tar archive"
      notify_remote "alert" "Flash Backup Error" "Failed to create backup tar archive"
      exit 1
    }
  fi
  debug_log "tar archive created successfully: $tmp_backup_file"

  debug_log "Verifying integrity of: $tmp_backup_file"
  tar -tf "$tmp_backup_file" >/dev/null 2>&1 || {
    debug_log "ERROR: Integrity check failed for: $tmp_backup_file"
    echo "[ERROR] Remote backup integrity check failed"
    notify_remote "alert" "Flash Backup Error" "Backup integrity check failed"
    exit 1
  }
  debug_log "Integrity check passed"

  mv "$tmp_backup_file" "$backup_file"
  debug_log "Renamed tmp to final: $backup_file"
fi

# ----------------------------
# Backup size logging
# ----------------------------
backup_size_bytes=$(stat -c%s "$backup_file" 2>/dev/null || echo 0)
backup_size_human=$(format_bytes "$backup_size_bytes")

echo "Backup size is $backup_size_human"
debug_log "Backup size: $backup_size_human ($backup_size_bytes bytes)"

# ----------------------------
# MAIN LOOP — upload to each remote
# ----------------------------
success_count=0
failure_count=0

for REMOTE in "${REMOTE_ARRAY[@]}"; do
    REMOTE="${REMOTE#"${REMOTE%%[![:space:]]*}"}"
    REMOTE="${REMOTE%"${REMOTE##*[![:space:]]}"}"

    [[ -z "$REMOTE" ]] && { debug_log "Skipping empty remote entry"; continue; }

    debug_log "Processing remote: $REMOTE"

    REMOTE_TYPE=$(rclone config show "$REMOTE" 2>/dev/null | awk -F' *= *' '/^[[:space:]]*type/{print $2; exit}')
    debug_log "Remote type: $REMOTE_TYPE"

    UNDERLYING_TYPE=""
    if [[ "$REMOTE_TYPE" == "crypt" ]]; then
        CRYPT_REMOTE=$(rclone config show "$REMOTE" 2>/dev/null | awk -F' *= *' '/^[[:space:]]*remote/{print $2; exit}')
        CRYPT_REMOTE="${CRYPT_REMOTE%%:*}"
        UNDERLYING_TYPE=$(rclone config show "$CRYPT_REMOTE" 2>/dev/null | awk -F' *= *' '/^[[:space:]]*type/{print $2; exit}')
        debug_log "Crypt remote detected, underlying remote: $CRYPT_REMOTE type: $UNDERLYING_TYPE"
    fi

    RCLONE_FLAGS=$(get_rclone_flags "$REMOTE_TYPE" "$UNDERLYING_TYPE")
    debug_log "rclone flags: $RCLONE_FLAGS"

    if [[ "$REMOTE_TYPE" == "b2" || "$REMOTE_TYPE" == "crypt" ]]; then
        DEST="${REMOTE}:${B2_BUCKET_NAME}${REMOTE_SUBPATH}"
    else
        DEST="${REMOTE}:${REMOTE_SUBPATH}"
    fi

    debug_log "Upload destination: $DEST"

    set_status "Uploading flash backup to config $REMOTE"

    # Ensure remote folder exists (skip for b2 and crypt, they don't support empty dirs)
    if [[ "$REMOTE_TYPE" == "b2" || "$REMOTE_TYPE" == "crypt" ]]; then
        debug_log "Skipping mkdir for b2/crypt remote"
    elif [[ "$DRY_RUN_REMOTE" == "yes" ]]; then
        echo "[DRY RUN] Would ensure remote folder exists -> $DEST"
        debug_log "[DRY RUN] Would mkdir: $DEST"
    else
        debug_log "Creating remote folder: $DEST"
        if ! rclone mkdir "$DEST"; then
            debug_log "ERROR: Failed to create remote folder: $DEST"
            echo "[ERROR] Failed to create folder $REMOTE_SUBPATH on config $REMOTE"
            set_status "Failed to create folder on $REMOTE"
            ((failure_count++))
            continue
        fi
        debug_log "Remote folder ready: $DEST"
    fi

    # Upload backup
    if [[ "$DRY_RUN_REMOTE" == "yes" ]]; then
        echo "[DRY RUN] Would upload $backup_file to $DEST"
        debug_log "[DRY RUN] Would upload $backup_file to $DEST"
        ((success_count++))
    else
        debug_log "Starting upload of $backup_file to $DEST"
        if ! rclone copy "$backup_file" "$DEST" $RCLONE_FLAGS; then
            debug_log "ERROR: Upload failed for remote: $REMOTE"
            echo "[ERROR] Failed to upload remote backup to $REMOTE"
            set_status "Upload failed for $REMOTE"
            ((failure_count++))
            continue
        fi
        echo "Uploaded remote backup to -> $DEST"
        debug_log "Upload successful to $DEST"
        ((success_count++))
    fi

    # ----------------------------
    # Cleanup Old Backups (per remote)
    # ----------------------------
    set_status "Cleaning up old remote backups"

    if (( BACKUPS_TO_KEEP_REMOTE == 0 )); then
        debug_log "BACKUPS_TO_KEEP_REMOTE=0, skipping old backup cleanup for $REMOTE"
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
            debug_log "[DRY RUN] Would remove old backups keeping $keep_label $backup_word for $REMOTE"
        else
            echo "Removing old remote backups keeping $keep_label $backup_word for $REMOTE"
            debug_log "Removing old backups keeping $keep_label $backup_word for $REMOTE"
        fi

        mapfile -t files < <(rclone lsf "$DEST" --files-only --format "p" 2>/dev/null | sort -r)
        num_files=${#files[@]}
        debug_log "Found $num_files existing remote backup(s) in $DEST"

        if (( num_files > BACKUPS_TO_KEEP_REMOTE )); then
            remove_count=$(( num_files - BACKUPS_TO_KEEP_REMOTE ))
            debug_log "Need to remove $remove_count old remote backup(s)"
            for (( idx=BACKUPS_TO_KEEP_REMOTE; idx<num_files; idx++ )); do
                old="${files[$idx]}"
                if [[ "$DRY_RUN_REMOTE" == "yes" ]]; then
                    echo "[DRY RUN] Would remove ${DEST}/${old}"
                    debug_log "[DRY RUN] Would remove: ${DEST}/${old}"
                else
                    debug_log "Removing old remote backup: ${DEST}/${old}"
                    if ! rclone delete "${DEST}/${old}"; then
                        echo "WARNING: Failed to remove remote file $old on $REMOTE"
                        debug_log "WARNING: Failed to remove remote file: ${DEST}/${old}"
                        set_status "Retention warning for $REMOTE"
                    else
                        debug_log "Removed: ${DEST}/${old}"
                    fi
                fi
            done
        else
            debug_log "No old remote backups to remove ($num_files files, keeping $BACKUPS_TO_KEEP_REMOTE)"
        fi
    fi

    debug_log "Finished processing remote: $REMOTE"

done

debug_log "All remotes processed - success_count=$success_count failure_count=$failure_count"
exit 0