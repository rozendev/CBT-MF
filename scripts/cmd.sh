#!/bin/bash
# ============================================================
# Sistem Ujian - Helper Commands
# ============================================================
# Usage: ./cmd.sh <command> [args]

set -e

COMPOSE="docker compose"
PHP_CONTAINER="ujian_php"
DB_CONTAINER="ujian_mariadb"
REDIS_CONTAINER="ujian_redis"

case "$1" in

  # ─── Docker ──────────────────────────────────────────────
  up)
    echo "🚀 Starting all services..."
    $COMPOSE up -d --build
    echo ""
    echo "✅ Services ready:"
    echo "   App:        http://localhost:8080"
    echo "   phpMyAdmin: http://localhost:8081"
    echo "   MariaDB:    localhost:3306"
    echo "   Redis:      localhost:6379"
    ;;

  down)
    echo "🛑 Stopping all services..."
    $COMPOSE down
    ;;

  restart)
    echo "🔄 Restarting all services..."
    $COMPOSE restart
    ;;

  logs)
    $COMPOSE logs -f ${2:-}
    ;;

  status)
    $COMPOSE ps
    ;;

  # ─── PHP Commands ────────────────────────────────────────
  php)
    shift
    docker exec -it $PHP_CONTAINER php "$@"
    ;;

  composer)
    shift
    docker exec -it $PHP_CONTAINER composer "$@"
    ;;

  # ─── Database Commands ───────────────────────────────────
  db)
    echo "🗄️  Connecting to MariaDB..."
    docker exec -it $DB_CONTAINER mariadb -u ujian_user -pujian_secret sistem_ujian
    ;;

  db-root)
    echo "🗄️  Connecting to MariaDB as root..."
    docker exec -it $DB_CONTAINER mariadb -u root -proot_secret
    ;;

  db-export)
    FILENAME=${2:-"backup_$(date +%Y%m%d_%H%M%S).sql"}
    echo "📦 Exporting database to $FILENAME..."
    docker exec $DB_CONTAINER mariadb-dump -u root -proot_secret sistem_ujian > "$FILENAME"
    echo "✅ Exported to $FILENAME"
    ;;

  db-import)
    if [ -z "$2" ]; then
      echo "❌ Usage: ./cmd.sh db-import <file.sql>"
      exit 1
    fi
    echo "📥 Importing $2..."
    docker exec -i $DB_CONTAINER mariadb -u root -proot_secret sistem_ujian < "$2"
    echo "✅ Import complete"
    ;;

  # ─── Redis Commands ──────────────────────────────────────
  redis)
    echo "📮 Connecting to Redis CLI..."
    docker exec -it $REDIS_CONTAINER redis-cli
    ;;

  redis-flush)
    echo "🧹 Flushing Redis..."
    docker exec -it $REDIS_CONTAINER redis-cli FLUSHALL
    echo "✅ Redis flushed"
    ;;

  # ─── Shell Access ────────────────────────────────────────
  shell)
    echo "🐚 Opening shell in PHP container..."
    docker exec -it $PHP_CONTAINER bash
    ;;

  # ─── Help ────────────────────────────────────────────────
  *)
    echo "============================================================"
    echo "  Sistem Ujian - Command Helper"
    echo "============================================================"
    echo ""
    echo "  Docker:"
    echo "    up              Start all services"
    echo "    down            Stop all services"
    echo "    restart         Restart all services"
    echo "    logs [service]  View logs (optional: php, mariadb, redis)"
    echo "    status          Show container status"
    echo ""
    echo "  PHP:"
    echo "    php <args>      Run PHP command"
    echo "    composer <args> Run Composer command"
    echo ""
    echo "  Database:"
    echo "    db              Open MariaDB CLI"
    echo "    db-root         Open MariaDB CLI as root"
    echo "    db-export [f]   Export database to SQL file"
    echo "    db-import <f>   Import SQL file to database"
    echo ""
    echo "  Redis:"
    echo "    redis           Open Redis CLI"
    echo "    redis-flush     Flush all Redis data"
    echo ""
    echo "  Other:"
    echo "    shell           Open bash shell in PHP container"
    echo ""
    ;;
esac
