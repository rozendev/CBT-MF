#!/bin/sh
# Entrypoint deployment Railway.
#
# Aplikasi ini membaca konfigurasinya dari src/.env bergaya CodeIgniter
# (database.default.hostname, app.baseURL) -- nama berbintik yang tidak nyaman
# dipakai sebagai nama variabel di panel Railway. Jadi berkas itu dirender di
# sini dari variabel lingkungan biasa, cara yang sama dipakai 'cbt.sh install'.
set -e

APP_DIR=/var/www/html
ENV_FILE="$APP_DIR/.env"

# ── Sumber nilai: variabel eksplisit menang atas bawaan plugin Railway ──
DB_HOST="${DB_HOST:-${MYSQLHOST:-}}"
DB_PORT="${DB_PORT:-${MYSQLPORT:-3306}}"
DB_DATABASE="${DB_DATABASE:-${MYSQLDATABASE:-}}"
DB_USERNAME="${DB_USERNAME:-${MYSQLUSER:-}}"
DB_PASSWORD="${DB_PASSWORD:-${MYSQLPASSWORD:-}}"

REDIS_HOST="${REDIS_HOST:-${REDISHOST:-}}"
REDIS_PORT="${REDIS_PORT:-${REDISPORT:-6379}}"
REDIS_PASSWORD="${REDIS_PASSWORD:-${REDISPASSWORD:-}}"

PORT="${PORT:-8080}"

if [ -z "$DB_HOST" ] || [ -z "$DB_DATABASE" ] || [ -z "$DB_USERNAME" ]; then
    echo "[entrypoint] FATAL: konfigurasi database kosong." >&2
    echo "[entrypoint] Tambahkan service MySQL lalu rujuk variabelnya, contoh:" >&2
    echo "[entrypoint]   DB_HOST=\${{MySQL.MYSQLHOST}}  DB_DATABASE=\${{MySQL.MYSQLDATABASE}}" >&2
    exit 1
fi

# Session aplikasi ini memakai RedisHandler tanpa cadangan. Tanpa Redis, login
# gagal dengan gejala yang menyesatkan, jadi lebih baik berhenti di sini.
if [ -z "$REDIS_HOST" ]; then
    echo "[entrypoint] FATAL: Redis wajib -- driver session aplikasi ini RedisHandler." >&2
    echo "[entrypoint] Tambahkan service Redis lalu rujuk: REDIS_HOST=\${{Redis.REDISHOST}}" >&2
    exit 1
fi

BASE_URL="${APP_BASE_URL:-}"
if [ -z "$BASE_URL" ] && [ -n "$RAILWAY_PUBLIC_DOMAIN" ]; then
    BASE_URL="https://${RAILWAY_PUBLIC_DOMAIN}/"
fi
if [ -z "$BASE_URL" ]; then
    echo "[entrypoint] FATAL: app.baseURL tidak diketahui." >&2
    echo "[entrypoint] Setel APP_BASE_URL, atau pakai domain Railway (RAILWAY_PUBLIC_DOMAIN)." >&2
    exit 1
fi

# ── Render src/.env ──
# forceGlobalSecureRequests dimatikan: Railway menutup TLS di edge dan
# meneruskan HTTP ke container, sementara Config\App::$proxyIPs hanya
# mempercayai rentang bridge Docker. Dibiarkan menyala, CI4 tidak mengenali
# permintaan sebagai HTTPS dan mengalihkannya terus-menerus. Browser tetap
# bicara HTTPS ke edge, dan karena baseURL berskema https, flag secure pada
# cookie dan session tetap menyala.
umask 077
cat > "$ENV_FILE" <<ENV
# Dirender otomatis oleh docker/railway/entrypoint.sh saat container start.
# Suntingan manual di sini akan hilang pada deploy berikutnya.

CI_ENVIRONMENT = ${CI_ENVIRONMENT:-production}

app.baseURL = '${BASE_URL}'
app.forceGlobalSecureRequests = false

database.default.hostname = '${DB_HOST}'
database.default.port = ${DB_PORT}
database.default.database = '${DB_DATABASE}'
database.default.username = '${DB_USERNAME}'
database.default.password = '${DB_PASSWORD}'
database.default.DBDriver = MySQLi

REDIS_HOST = '${REDIS_HOST}'
REDIS_PORT = ${REDIS_PORT}
REDIS_PASSWORD = '${REDIS_PASSWORD}'
ENV

if [ -n "$ENCRYPTION_KEY" ]; then
    echo "encryption.key = '${ENCRYPTION_KEY}'" >> "$ENV_FILE"
fi

chown www-data:www-data "$ENV_FILE"
umask 022
echo "[entrypoint] .env dirender: db=${DB_HOST}:${DB_PORT}/${DB_DATABASE} redis=${REDIS_HOST}:${REDIS_PORT} base=${BASE_URL}"

# Volume Railway dipasang kosong dan dimiliki root; php-fpm berjalan sebagai
# www-data dan tidak akan bisa menulis gambar hasil impor Word tanpa ini.
mkdir -p "$APP_DIR/public/uploads/questions" "$APP_DIR/writable/cache" "$APP_DIR/writable/logs" "$APP_DIR/writable/session"
chown -R www-data:www-data "$APP_DIR/public/uploads" "$APP_DIR/writable"

# ── Migrasi ──
# Rangkap sebagai penantian database: service database kerap belum siap saat
# container aplikasi start.
if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
    attempt=1
    until php "$APP_DIR/spark" migrate --all; do
        if [ "$attempt" -ge "${MIGRATION_MAX_ATTEMPTS:-10}" ]; then
            echo "[entrypoint] FATAL: migrasi gagal setelah ${attempt} percobaan." >&2
            exit 1
        fi
        echo "[entrypoint] migrasi gagal (percobaan ${attempt}), database mungkin belum siap; ulangi dalam 5 detik..."
        attempt=$((attempt + 1))
        sleep 5
    done
    echo "[entrypoint] migrasi selesai."
fi

# Seeder membuat akun admin/admin123. Sengaja tidak otomatis: hidupkan sekali
# lewat RUN_SEEDER=true, lalu matikan lagi.
if [ "${RUN_SEEDER:-false}" = "true" ]; then
    php "$APP_DIR/spark" db:seed InitialSeeder
    echo "[entrypoint] seeder dijalankan."
fi

# ── nginx ──
sed "s/__PORT__/${PORT}/g" /etc/nginx/nginx.conf.template > /etc/nginx/nginx.conf
nginx -t

php-fpm -D
echo "[entrypoint] php-fpm jalan; nginx mendengarkan di port ${PORT}."

exec nginx -g 'daemon off;'
