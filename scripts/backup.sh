#!/bin/bash
# ─── Automated Database Backup ──────────────────────────────────
# Run via cron: 0 2 * * * /path/to/scripts/backup.sh
# Or manually: ./scripts/backup.sh

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"

source "$PROJECT_DIR/.env" 2>/dev/null || {
    echo "Error: .env file not found at $PROJECT_DIR/.env"
    exit 1
}

BACKUP_DIR="$PROJECT_DIR/backups"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)
DB_BACKUP="$BACKUP_DIR/db_${DB_DATABASE}_${TIMESTAMP}.sql.gz"
KEEP_DAYS=7

mkdir -p "$BACKUP_DIR"

echo "[$(date '+%Y-%m-%d %H:%M:%S')] Starting backup..."

docker exec -e MYSQL_PWD="$DB_PASSWORD" "$CONTAINER_DB" \
    mariadb-dump \
        -u"$DB_USERNAME" \
        --single-transaction \
        --routines \
        --triggers \
        --databases "$DB_DATABASE" \
    | gzip > "$DB_BACKUP"

if [ $? -eq 0 ] && [ -s "$DB_BACKUP" ]; then
    size=$(du -h "$DB_BACKUP" | cut -f1)
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] Database backup complete: $DB_BACKUP ($size)"
else
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] ERROR: Database backup failed!"
    rm -f "$DB_BACKUP"
    exit 1
fi

REDIS_BACKUP="$BACKUP_DIR/redis_${TIMESTAMP}.rdb"
docker exec "$CONTAINER_REDIS" redis-cli -a "$REDIS_PASSWORD" BGSAVE 2>/dev/null || true
sleep 2
docker cp "$CONTAINER_REDIS:/data/dump.rdb" "$REDIS_BACKUP" 2>/dev/null && \
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] Redis backup complete: $REDIS_BACKUP" || \
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] Redis backup skipped (no dump.rdb)"

deleted=$(find "$BACKUP_DIR" -name "db_*.sql.gz" -mtime +$KEEP_DAYS -print -delete 2>/dev/null | wc -l)
find "$BACKUP_DIR" -name "redis_*.rdb" -mtime +$KEEP_DAYS -delete 2>/dev/null || true

if [ "$deleted" -gt 0 ]; then
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] Cleaned $deleted backup(s) older than ${KEEP_DAYS} days"
fi

echo "[$(date '+%Y-%m-%d %H:%M:%S')] Backup finished."
