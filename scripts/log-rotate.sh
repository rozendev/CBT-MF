#!/bin/bash
# ─── Log Rotation for CBT Application ───────────────────────────
# Run via cron: 0 0 * * * /path/to/scripts/log-rotate.sh
# Or manually: ./scripts/log-rotate.sh

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"
LOG_DIR="$PROJECT_DIR/src/writable/logs"
BACKUP_DIR="$LOG_DIR/archive"
MAX_DAYS=30
MAX_SIZE_MB=50

mkdir -p "$BACKUP_DIR"

rotated=0
skipped=0

for logfile in "$LOG_DIR"/*.log; do
    [ -f "$logfile" ] || continue

    size_mb=$(du -m "$logfile" 2>/dev/null | cut -f1)

    if [ "$size_mb" -lt "$MAX_SIZE_MB" ]; then
        skipped=$((skipped + 1))
        continue
    fi

    basename=$(basename "$logfile" .log)
    timestamp=$(date +%Y%m%d_%H%M%S)
    archive_name="${basename}_${timestamp}.log.gz"

    # Copy first, then truncate — avoids data loss if app is mid-write
    cp "$logfile" "$BACKUP_DIR/$archive_name.tmp"
    : > "$logfile"
    gzip "$BACKUP_DIR/$archive_name.tmp"

    rotated=$((rotated + 1))
done

find "$BACKUP_DIR" -name "*.log.gz" -mtime +$MAX_DAYS -delete 2>/dev/null || true

echo "[$(date '+%Y-%m-%d %H:%M:%S')] Rotated: $rotated, Skipped: $skipped, Cleaned archives older than ${MAX_DAYS}d"
