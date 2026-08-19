#!/bin/bash
# ============================================================
# CBT-MF CLI Helper
# Satu perintah ditulis sekali di senarai CMD; menu dan CLI
# sama-sama dirender dari sana.
# ============================================================

set -euo pipefail

CYAN='\033[0;36m'; GREEN='\033[0;32m'; YELLOW='\033[0;33m'
RED='\033[0;31m'; BLUE='\033[0;34m'; BOLD='\033[1m'; NC='\033[0m'

# die() sering dipanggil dari dalam $( ), dan 'exit' di sana hanya
# mematikan subshell-nya: skrip induk jalan terus dengan nilai kosong.
# Sinyal ke $$ (PID skrip, tetap sama di dalam subshell) yang membuat
# induknya benar-benar berhenti; trap-nya menjaga agar berhenti rapi
# dengan status 1, bukan 143 plus "Terminated".
trap 'exit 1' TERM
die() {
    printf '%b\n' "${RED}Error: $*${NC}" >&2
    kill -TERM $$ 2>/dev/null
    exit 1
}
warn() { printf '%b\n' "${YELLOW}$*${NC}" >&2; }
info() { printf '%b\n' "${CYAN}$*${NC}"; }
ok()   { printf '%b\n' "${GREEN}$*${NC}"; }

[ "$EUID" -eq 0 ] || die "Script ini harus dijalankan sebagai root (gunakan sudo).
Contoh: sudo bash scripts/cbt.sh"

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"
COMPOSE="docker compose"

# Pembaca .env yang tidak mengeksekusi isinya. 'source' akan menjalankan
# backtick di dalam .env sebagai perintah; ini hanya membaca pasangan
# key=value, melepas kutip pembungkus, dan melewati kunci yang bukan
# identifier shell (mis. 'app.baseURL' di src/.env).
load_env() {
    local file="$1" line key value
    [ -f "$file" ] || return 1
    while IFS= read -r line || [ -n "$line" ]; do
        case "$line" in ''|'#'*) continue ;; esac
        case "$line" in *=*) ;; *) continue ;; esac
        key=${line%%=*}
        value=${line#*=}
        key=$(printf '%s' "$key" | tr -d '[:space:]')
        case "$key" in
            ''|*[!A-Za-z0-9_]*) continue ;;
        esac
        case "$value" in
            \"*\") value=${value#\"}; value=${value%\"} ;;
            \'*\') value=${value#\'}; value=${value%\'} ;;
        esac
        export "$key=$value"
    done < "$file"
    return 0
}

# Instalasi baru yang bersih belum punya .env sama sekali; itu tugas
# installer. Jadi ketiadaannya memperingatkan, bukan menghentikan.
if ! load_env "$PROJECT_DIR/.env"; then
    if ! load_env "$PROJECT_DIR/.env.example"; then
        warn "Peringatan: .env maupun .env.example tidak ditemukan di $PROJECT_DIR."
        warn "Sebagian besar perintah akan menolak jalan sampai installer dijalankan."
    fi
fi

# Sementara: dua turunan ini masih dipakai run_reset. Task 9 mengganti
# pemakainya dengan MYSQL_PWD langsung dari .env; hapus blok ini begitu
# tidak ada lagi yang merujuknya.
DB_NAME="${DB_DATABASE:-}"
ROOT_PASS="${MYSQL_ROOT_PASSWORD:-}"

# Resolusi container sengaja MALAS. Kalau ini gagal-keras saat berkas
# dimuat, 'install' ikut terkunci justru pada saat paling dibutuhkan.
need_env() {
    local name="$1" value="${!1:-}"
    [ -n "$value" ] || die "$name belum ada di .env.
Jalankan installer dulu:  sudo ./scripts/cbt.sh install"
    printf '%s' "$value"
}

php_container()   { need_env CONTAINER_PHP; }
db_container()    { need_env CONTAINER_DB; }
redis_container() { need_env CONTAINER_REDIS; }

require_container() {
    local name="$1"
    docker ps --format '{{.Names}}' | grep -qx "$name" \
        || die "Container '$name' tidak berjalan.
Nyalakan dulu:  sudo ./scripts/cbt.sh docker up"
}

# --- Helper Functions ---
print_header() {
    clear
    printf '%b\n' "${CYAN}${BOLD}"
    echo "============================================================"
    echo "                 CBT-MF CLI HELPER                          "
    echo "============================================================"
    printf '%b\n' "${NC}"
}

pause() {
    echo ""
    read -r -p "Press [Enter] to continue..."
}

# ── Senarai perintah ────────────────────────────────────────
# Format: grup|nama|fungsi|bahaya|deskripsi
# grup kosong = perintah tingkat atas (tanpa subperintah).
# bahaya=1 memaksa ketik ulang nama perintah sebelum jalan.
declare -a CMD=()
reg() { CMD+=("$1|$2|$3|$4|$5"); }

reg docker  up          do_docker_up        0 "Nyalakan semua layanan"
reg docker  down        do_docker_down      0 "Matikan semua layanan"
reg docker  restart     do_docker_restart   0 "Nyalakan ulang semua layanan"
reg docker  logs        do_docker_logs      0 "Ikuti log semua layanan"
reg docker  status      do_docker_status    0 "Status container"

reg app     shell       do_app_shell        0 "Buka bash di container PHP"
reg app     php         do_app_php          0 "Jalankan perintah php di container"
reg app     composer    do_app_composer     0 "Jalankan composer di container"

reg db      shell       do_db_shell         0 "Buka MariaDB sebagai user aplikasi"
reg db      root        do_db_root          0 "Buka MariaDB sebagai root"
reg db      export      do_db_export        0 "Ekspor database ke berkas .sql"
reg db      import      do_db_import        1 "Impor berkas .sql (menimpa data)"
reg db      reset-password do_db_reset_pw   0 "Setel ulang password admin"

reg redis   shell       do_redis_shell      0 "Buka redis-cli"
reg redis   flush       do_redis_flush      1 "Hapus seluruh isi Redis"

reg bundle  build       do_bundle_build     0 "Bangun ulang bundle UI kiosk"
reg bundle  status      do_bundle_status    0 "Bandingkan versi bundle lokal, server, dan zip publik"

reg data    images      do_data_images      0 "Keluarkan gambar base64 dari teks soal"
reg data    optimize    do_data_optimize    1 "OPTIMIZE TABLE (mengunci tabel)"
reg data    cache-clear do_data_cache_clear 0 "Bersihkan cache aplikasi"
reg data    finalize    do_data_finalize    0 "Tutup attempt yang lewat batas waktu"
reg data    prune-kiosk do_data_prune_kiosk 0 "Bersihkan kunci kiosk_live basi"

reg ""      backup      run_backup          0 "Backup database dan Redis"
reg ""      log-rotate  run_log_rotate      0 "Rotasi log aplikasi"
reg ""      reset-install run_reset         1 "Reset instalasi (hapus semua data)"
reg ""      test-k6     do_test_k6          0 "Uji beban k6"
reg ""      install     run_install         0 "Installer interaktif"
reg ""      help        do_help             0 "Tampilkan bantuan"

find_cmd() {
    local entry g n fn danger desc
    for entry in "${CMD[@]}"; do
        IFS='|' read -r g n fn danger desc <<< "$entry"
        if [ "$g" = "$1" ] && [ "$n" = "$2" ]; then
            printf '%s|%s' "$fn" "$danger"
            return 0
        fi
    done
    return 1
}

groups() {
    local entry g rest
    for entry in "${CMD[@]}"; do
        g=${entry%%|*}
        [ -n "$g" ] && printf '%s\n' "$g"
    done | awk '!seen[$0]++'
}

confirm_typed() {
    local label="$1" answer
    printf '%b\n' "${RED}${BOLD}BAHAYA:${NC} ${RED}perintah ini merusak data.${NC}"
    printf 'Ketik ulang persis "%s" untuk melanjutkan: ' "$label"
    read -r answer
    [ "$answer" = "$label" ]
}

run_entry() {
    local fn="$1" danger="$2" label="$3"; shift 3
    if [ "$danger" = "1" ]; then
        confirm_typed "$label" || { warn "Dibatalkan."; return 0; }
    fi
    "$fn" "$@"
}

# --- Command Functions ---

# 1. Docker
do_docker_up()      { cd "$PROJECT_DIR" && $COMPOSE up -d --build; ok "Layanan siap: http://localhost:8080"; }
do_docker_down()    { cd "$PROJECT_DIR" && $COMPOSE down; }
do_docker_restart() { cd "$PROJECT_DIR" && $COMPOSE restart; }
do_docker_logs()    { cd "$PROJECT_DIR" && $COMPOSE logs -f; }
do_docker_status()  { cd "$PROJECT_DIR" && $COMPOSE ps; }

# 2. App Services (PHP/Composer)
do_app_shell()    { local c; c=$(php_container); require_container "$c"; docker exec -it "$c" bash; }
do_app_php()      { local c; c=$(php_container); require_container "$c"; docker exec -it "$c" php "$@"; }
do_app_composer() { local c; c=$(php_container); require_container "$c"; docker exec -it "$c" composer "$@"; }

# 3. Database Operations
# Password TIDAK PERNAH lewat argumen: -p"$pass" terbaca siapa pun di
# 'ps'. MYSQL_PWD adalah pola yang sudah dipakai run_backup di berkas ini.
# Helper umum: "$@" sengaja disiapkan untuk pemanggil mendatang meski
# do_db_reset_pw belum memakainya.
# shellcheck disable=SC2119,SC2120
db_exec() {
    local c; c=$(db_container); require_container "$c"
    docker exec -i -e MYSQL_PWD="${DB_PASSWORD:-}" "$c" \
        mariadb -u"${DB_USERNAME:-}" "${DB_DATABASE:-}" "$@"
}

db_exec_root() {
    local c; c=$(db_container); require_container "$c"
    docker exec -i -e MYSQL_PWD="${MYSQL_ROOT_PASSWORD:-}" "$c" mariadb -uroot "$@"
}

do_db_shell() {
    local c; c=$(db_container); require_container "$c"
    docker exec -it -e MYSQL_PWD="${DB_PASSWORD:-}" "$c" \
        mariadb -u"${DB_USERNAME:-}" "${DB_DATABASE:-}"
}

do_db_root() {
    local c; c=$(db_container); require_container "$c"
    docker exec -it -e MYSQL_PWD="${MYSQL_ROOT_PASSWORD:-}" "$c" mariadb -uroot
}

do_db_export() {
    local c file
    c=$(db_container); require_container "$c"
    file="${1:-backup_$(date +%Y%m%d_%H%M%S).sql}"
    docker exec -e MYSQL_PWD="${MYSQL_ROOT_PASSWORD:-}" "$c" \
        mariadb-dump -uroot --single-transaction "${DB_DATABASE:-}" > "$PROJECT_DIR/$file"
    ok "Diekspor ke $PROJECT_DIR/$file"
}

do_db_import() {
    local c file="${1:-}"
    [ -n "$file" ] || die "Berkas SQL belum disebut. Contoh: ./scripts/cbt.sh db import dump.sql"
    [ -f "$PROJECT_DIR/$file" ] || die "Berkas tidak ditemukan: $PROJECT_DIR/$file"
    c=$(db_container); require_container "$c"
    docker exec -i -e MYSQL_PWD="${MYSQL_ROOT_PASSWORD:-}" "$c" \
        mariadb -uroot "${DB_DATABASE:-}" < "$PROJECT_DIR/$file"
    ok "Impor $file selesai."
}

# Kutip nilai untuk MariaDB: gandakan kutip tunggal, bungkus.
# MariaDB memperlakukan backslash sebagai karakter escape di dalam string,
# jadi menggandakan kutip saja belum cukup: nama berakhiran "\\" membuat
# kutip penutupnya ikut ter-escape dan kuerinya rusak.
sql_quote() { printf "'%s'" "$(printf '%s' "$1" | sed -e 's/\\/\\\\/g' -e "s/'/''/g")"; }

do_db_reset_pw() {
    local user pass hash c
    read -r -p "Username admin [admin]: " user
    user=${user:-admin}
    read -r -s -p "Password baru: " pass; echo ""
    [ -n "$pass" ] || die "Password tidak boleh kosong."

    c=$(php_container); require_container "$c"
    hash=$(printf '%s' "$pass" | docker exec -i "$c" \
        php -r 'echo password_hash(stream_get_contents(STDIN), PASSWORD_BCRYPT);')
    [ -n "$hash" ] || die "Gagal membuat hash password."

    # Nilai dikirim lewat variabel sesi MariaDB, bukan disisipkan ke teks
    # SQL: username atau hash yang mengandung kutip tidak lagi bisa
    # merusak kuerinya.
    printf "SET @u := %s; SET @h := %s;\nUPDATE users SET password = @h WHERE username = @u AND role = 'admin';\nSELECT ROW_COUNT() AS diubah;\n" \
        "$(sql_quote "$user")" "$(sql_quote "$hash")" | db_exec
}

# 4. Redis Operations
do_redis_shell() {
    local c; c=$(redis_container); require_container "$c"
    if [ -n "${REDIS_PASSWORD:-}" ]; then
        docker exec -it "$c" redis-cli -a "$REDIS_PASSWORD" --no-auth-warning
    else
        docker exec -it "$c" redis-cli
    fi
}

do_redis_flush() {
    local c; c=$(redis_container); require_container "$c"
    if [ -n "${REDIS_PASSWORD:-}" ]; then
        docker exec -i "$c" redis-cli -a "$REDIS_PASSWORD" --no-auth-warning FLUSHALL
    else
        docker exec -i "$c" redis-cli FLUSHALL
    fi
    ok "Redis dikosongkan."
    warn "Sesi login ikut terhapus — semua orang harus login ulang."
}

# 4b. Bundle UI Kiosk
app_base_url() {
    local f="$PROJECT_DIR/src/.env" line
    [ -f "$f" ] || return 1
    line=$(grep -E "^[# ]*app\.baseURL" "$f" | grep -v '^#' | head -1) || return 1
    printf '%s' "$line" | sed -E "s/^[^=]*=[[:space:]]*['\"]?([^'\"]*)['\"]?.*/\1/" | sed 's:/*$::'
}

do_bundle_build() {
    local logo="" c
    while [ $# -gt 0 ]; do
        case "$1" in
            --logo) logo="${2:-}"; [ -n "$logo" ] || die "--logo butuh path berkas."; shift 2 ;;
            *) die "Opsi tidak dikenal: $1" ;;
        esac
    done

    c=$(php_container); require_container "$c"

    if [ -z "$logo" ]; then
        docker exec "$c" php spark cbt:build-ui-bundle
        return
    fi

    # Path yang diketik ada di host; spark jalan di container. Berkasnya
    # harus masuk repo dulu supaya terlihat dari dalam.
    [ -f "$logo" ] || die "Berkas logo tidak ditemukan: $logo"
    local mime ext hash dest bytes
    mime=$(file -b --mime-type "$logo")
    case "$mime" in
        image/png)  ext=png ;;
        image/jpeg) ext=jpg ;;
        image/webp) ext=webp ;;
        image/gif)  ext=gif ;;
        *) die "Bukan gambar yang didukung (terbaca: $mime). Pakai PNG, JPG, WebP, atau GIF." ;;
    esac
    bytes=$(stat -c%s "$logo")
    [ "$bytes" -le 5242880 ] || die "Logo lebih dari 5 MB ($bytes byte)."

    hash=$(sha256sum "$logo" | cut -c1-32)
    dest="uploads/kiosk/logo_${hash}.${ext}"
    mkdir -p "$PROJECT_DIR/src/public/uploads/kiosk"
    cp "$logo" "$PROJECT_DIR/src/public/$dest"
    chmod 644 "$PROJECT_DIR/src/public/$dest"
    ok "Logo disalin ke src/public/$dest"

    docker exec "$c" php spark cbt:build-ui-bundle --logo "$dest"
}

do_bundle_status() {
    local c base localVer serverVer zipVer zipUrl tmp
    c=$(php_container); require_container "$c"

    localVer=$(docker exec "$c" php -r \
        '$m = @json_decode(@file_get_contents("public/ui-bundle/manifest.json"), true); echo $m["version"] ?? "";')
    printf 'Bundle lokal di server : %s\n' "${localVer:-(tidak ada)}"

    base=$(app_base_url || true)
    if [ -z "$base" ]; then
        warn "app.baseURL tidak terbaca dari src/.env — pemeriksaan publik dilewati."
        return 0
    fi

    local cfg
    if ! cfg=$(curl -fsS --max-time 15 "$base/api/kiosk/config" 2>/dev/null); then
        warn "Server publik tidak terjangkau ($base) — dua pemeriksaan berikutnya dilewati."
        return 0
    fi
    serverVer=$(printf '%s' "$cfg" | grep -o '"version":"[a-f0-9]*"' | head -1 | cut -d'"' -f4)
    zipUrl=$(printf '%s' "$cfg" | grep -o '"url":"[^"]*"' | head -1 | cut -d'"' -f4)
    printf 'Dilaporkan config      : %s\n' "${serverVer:-(kosong)}"

    if ! command -v unzip >/dev/null 2>&1; then
        warn "unzip tidak terpasang — isi zip publik tidak diperiksa."
        return 0
    fi
    tmp=$(mktemp -d)
    if curl -fsS --max-time 60 "$zipUrl" -o "$tmp/b.zip" 2>/dev/null; then
        zipVer=$(unzip -p "$tmp/b.zip" manifest.json 2>/dev/null \
            | grep -o '"version": *"[a-f0-9]*"' | head -1 | cut -d'"' -f4)
        printf 'Isi zip yang diunduh   : %s\n' "${zipVer:-(gagal dibaca)}"
    else
        warn "Zip publik tidak dapat diunduh."
    fi
    rm -rf "$tmp"

    if [ -n "${zipVer:-}" ] && [ "$zipVer" != "$serverVer" ]; then
        warn "Zip publik BEDA dengan versi yang dilaporkan config."
        warn "Cache CDN kemungkinan masih menahan berkas lama."
    elif [ -n "$localVer" ] && [ "$localVer" = "$serverVer" ]; then
        ok "Ketiganya cocok."
    fi
}

# 4c. Data Maintenance
do_data_images() {
    local c; c=$(php_container); require_container "$c"
    if [ "${1:-}" = "--commit" ]; then
        confirm_typed "data images --commit" || { warn "Dibatalkan."; return 0; }
        docker exec "$c" php spark cbt:extract-inline-images --commit
        docker exec "$c" php spark cache:clear
        warn "Jalankan 'data optimize' untuk mengembalikan ruang disk."
    else
        docker exec "$c" php spark cbt:extract-inline-images
        info "Ini modus laporan. Tambahkan --commit untuk menerapkan."
    fi
}

do_data_optimize() {
    warn "OPTIMIZE mengunci tabel selama berjalan. Jangan lakukan saat ujian berlangsung."
    db_exec_root "${DB_DATABASE:-}" -e \
        "OPTIMIZE TABLE test_logs, test_log_answers, questions, answers"
}

do_data_cache_clear() {
    local c; c=$(php_container); require_container "$c"
    docker exec "$c" php spark cache:clear
}

do_data_finalize() {
    local c; c=$(php_container); require_container "$c"
    docker exec "$c" php spark finalize:expired
}

do_data_prune_kiosk() {
    local c; c=$(php_container); require_container "$c"
    docker exec "$c" php spark kiosk:prune
}

# 5. Maintenance (Backup, Log rotate, Reset)
run_backup() {
    BACKUP_DIR="$PROJECT_DIR/backups"
    TIMESTAMP=$(date +%Y%m%d_%H%M%S)
    DB_BACKUP="$BACKUP_DIR/db_${DB_DATABASE}_${TIMESTAMP}.sql.gz"
    KEEP_DAYS=7

    mkdir -p "$BACKUP_DIR"
    echo -e "${YELLOW}[$(date '+%Y-%m-%d %H:%M:%S')] Starting automated backup...${NC}"

    if docker exec -e MYSQL_PWD="$DB_PASSWORD" "$CONTAINER_DB" \
        mariadb-dump -u"$DB_USERNAME" --single-transaction --routines --triggers --databases "$DB_DATABASE" \
        | gzip > "$DB_BACKUP" && [ -s "$DB_BACKUP" ]; then
        size=$(du -h "$DB_BACKUP" | cut -f1)
        echo -e "${GREEN}[$(date '+%Y-%m-%d %H:%M:%S')] Database backup complete: $DB_BACKUP ($size)${NC}"
    else
        echo -e "${RED}[$(date '+%Y-%m-%d %H:%M:%S')] ERROR: Database backup failed!${NC}"
        rm -f "$DB_BACKUP"
    fi

    REDIS_BACKUP="$BACKUP_DIR/redis_${TIMESTAMP}.rdb"
    docker exec "$CONTAINER_REDIS" redis-cli -a "$REDIS_PASSWORD" BGSAVE 2>/dev/null || true
    sleep 2
    if docker cp "$CONTAINER_REDIS:/data/dump.rdb" "$REDIS_BACKUP" 2>/dev/null; then
        echo -e "${GREEN}[$(date '+%Y-%m-%d %H:%M:%S')] Redis backup complete: $REDIS_BACKUP${NC}"
    else
        echo -e "${YELLOW}[$(date '+%Y-%m-%d %H:%M:%S')] Redis backup skipped (no dump.rdb found/accessible)${NC}"
    fi

    deleted=$(find "$BACKUP_DIR" -name "db_*.sql.gz" -mtime +$KEEP_DAYS -print -delete 2>/dev/null | wc -l)
    find "$BACKUP_DIR" -name "redis_*.rdb" -mtime +$KEEP_DAYS -delete 2>/dev/null || true

    if [ -n "$deleted" ] && [ "$deleted" -gt 0 ]; then
        echo -e "${CYAN}[$(date '+%Y-%m-%d %H:%M:%S')] Cleaned $deleted backup(s) older than ${KEEP_DAYS} days${NC}"
    fi
}

run_log_rotate() {
    LOG_DIR="$PROJECT_DIR/src/writable/logs"
    ARC_DIR="$LOG_DIR/archive"
    MAX_DAYS=30
    MAX_SIZE_MB=50

    mkdir -p "$ARC_DIR"
    rotated=0
    skipped=0

    echo -e "${YELLOW}[$(date '+%Y-%m-%d %H:%M:%S')] Starting log rotation...${NC}"

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

        cp "$logfile" "$ARC_DIR/$archive_name.tmp"
        : > "$logfile"
        gzip "$ARC_DIR/$archive_name.tmp"
        rotated=$((rotated + 1))
    done

    find "$ARC_DIR" -name "*.log.gz" -mtime +$MAX_DAYS -delete 2>/dev/null || true
    echo -e "${GREEN}[$(date '+%Y-%m-%d %H:%M:%S')] Rotated: $rotated, Skipped: $skipped, Cleaned archives > ${MAX_DAYS}d${NC}"
}

run_reset() {
    echo -e "${RED}🛑 PERINGATAN: Semua data akan dihapus!${NC}"
    read -r -p "Ketik 'YES' untuk melanjutkan reset instalasi: " CONFIRM

    if [ "$CONFIRM" != "YES" ]; then
        echo -e "${YELLOW}❌ Reset dibatalkan.${NC}"
        return
    fi

    echo -e "${CYAN}🔄 Memulai proses reset...${NC}"
    if [ -f "$PROJECT_DIR/src/.env" ]; then
        echo "🗑️ Menghapus file src/.env..."
        rm -f "$PROJECT_DIR/src/.env"
    fi

    echo "🗄️ Menghapus dan membuat ulang database..."
    docker exec "$(db_container)" mariadb -u root -p"$ROOT_PASS" -e "DROP DATABASE IF EXISTS $DB_NAME; CREATE DATABASE $DB_NAME;"

    echo "📮 Membersihkan memori Redis..."
    docker exec "$(redis_container)" redis-cli FLUSHALL > /dev/null

    echo "🧹 Membersihkan file unggahan dan cache..."
    rm -rf "$PROJECT_DIR/src/public/uploads/questions/"* 2>/dev/null || true
    rm -rf "$PROJECT_DIR/src/writable/session/"* 2>/dev/null || true
    rm -rf "$PROJECT_DIR/src/writable/cache/"* 2>/dev/null || true

    echo -e "\n${GREEN}✅ RESET SELESAI!${NC}"
    echo "Sistem sekarang dalam kondisi awal (belum diinstall)."
}

run_install() {
    # Installer berjalan dengan aturan lama: isinya belum diaudit untuk
    # mode ketat, dan satu-satunya cara mengujinya adalah instalasi dari
    # nol. Hapus dua baris ini bila nanti sudah diaudit.
    set +e +u +o pipefail

    print_header
    echo -e "${CYAN}=== 🛠️ CBT-MF Interactive Installer ===${NC}"
    echo "Installer ini akan memandu Anda untuk mengatur kredensial database,"
    echo "Cloudflare Tunnel, dan membuat akun Admin awal."
    echo "------------------------------------------------------------"
    
    read -p "Masukkan nama database [cbt-mf]: " input_dbname
    input_dbname=${input_dbname:-cbt-mf}
    
    read -p "Masukkan username database [sayasukakamu]: " input_dbuser
    input_dbuser=${input_dbuser:-sayasukakamu}
    
    read -p "Masukkan Prefix Nama Container [ujian]: " input_prefix
    input_prefix=${input_prefix:-ujian}

    read -p "Masukkan Cloudflare Tunnel Token (Kosongkan jika tidak pakai): " input_cf_token
    
    read -p "Masukkan Base URL Aplikasi [http://localhost:8080/]: " input_baseurl
    input_baseurl=${input_baseurl:-"http://localhost:8080/"}
    
    read -p "Masukkan Username Admin Baru [admin]: " input_admin_user
    input_admin_user=${input_admin_user:-admin}
    
    echo "---"
    read -sp "Masukkan password Redis (Kosongkan jika tidak butuh password): " input_redispass
    echo ""
    
    # Looping until password is provided and matches verification
    while true; do
        read -sp "Masukkan password database: " input_dbpass
        echo ""
        read -sp "Verifikasi password database: " input_dbpass_verify
        echo ""
        
        if [ -z "$input_dbpass" ]; then
            echo -e "${RED}Error: Password tidak boleh kosong! Silakan ulangi.${NC}"
        elif [ "$input_dbpass" != "$input_dbpass_verify" ]; then
            echo -e "${RED}Error: Password tidak cocok! Silakan ulangi.${NC}"
        else
            break
        fi
    done

    while true; do
        read -sp "Masukkan password untuk Admin: " input_admin_pass
        echo ""
        read -sp "Verifikasi password Admin: " input_admin_pass_verify
        echo ""
        
        if [ -z "$input_admin_pass" ]; then
            echo -e "${RED}Error: Password Admin tidak boleh kosong! Silakan ulangi.${NC}"
        elif [ "$input_admin_pass" != "$input_admin_pass_verify" ]; then
            echo -e "${RED}Error: Password Admin tidak cocok! Silakan ulangi.${NC}"
        else
            break
        fi
    done
    
    echo -e "\n${YELLOW}Menyimpan konfigurasi...${NC}"
    
    # Restore Root .env if missing
    if [ ! -f "$PROJECT_DIR/.env" ]; then
        if [ -f "$PROJECT_DIR/.env.example" ]; then
            cp "$PROJECT_DIR/.env.example" "$PROJECT_DIR/.env"
        fi
    fi
    
    # Setup Root .env
    if [ -f "$PROJECT_DIR/.env" ]; then
        sed -i "s|^[# ]*DB_HOST=.*|DB_HOST=${input_prefix}_mariadb|" "$PROJECT_DIR/.env"
        sed -i "s|^[# ]*DB_DATABASE=.*|DB_DATABASE=$input_dbname|" "$PROJECT_DIR/.env"
        sed -i "s|^[# ]*DB_USERNAME=.*|DB_USERNAME=$input_dbuser|" "$PROJECT_DIR/.env"
        sed -i "s|^[# ]*DB_PASSWORD=.*|DB_PASSWORD=$input_dbpass|" "$PROJECT_DIR/.env"
        sed -i "s|^[# ]*MYSQL_ROOT_PASSWORD=.*|MYSQL_ROOT_PASSWORD=$input_dbpass|" "$PROJECT_DIR/.env"
        
        sed -i "s|^CONTAINER_NGINX=.*|CONTAINER_NGINX=${input_prefix}_nginx|" "$PROJECT_DIR/.env"
        sed -i "s|^CONTAINER_PHP=.*|CONTAINER_PHP=${input_prefix}_php|" "$PROJECT_DIR/.env"
        sed -i "s|^CONTAINER_WEBSOCKET=.*|CONTAINER_WEBSOCKET=${input_prefix}_websocket|" "$PROJECT_DIR/.env"
        sed -i "s|^CONTAINER_CLOUDFLARED=.*|CONTAINER_CLOUDFLARED=${input_prefix}_cloudflared|" "$PROJECT_DIR/.env"
        sed -i "s|^CONTAINER_DB=.*|CONTAINER_DB=${input_prefix}_mariadb|" "$PROJECT_DIR/.env"
        sed -i "s|^CONTAINER_PHPMYADMIN=.*|CONTAINER_PHPMYADMIN=${input_prefix}_phpmyadmin|" "$PROJECT_DIR/.env"
        sed -i "s|^CONTAINER_REDIS=.*|CONTAINER_REDIS=${input_prefix}_redis|" "$PROJECT_DIR/.env"
        
        if ! grep -q "^[# ]*REDIS_PASSWORD[ ]*=" "$PROJECT_DIR/.env"; then
            echo "REDIS_PASSWORD=$input_redispass" >> "$PROJECT_DIR/.env"
        else
            sed -i "s|^[# ]*REDIS_PASSWORD[ ]*=.*|REDIS_PASSWORD=$input_redispass|" "$PROJECT_DIR/.env"
        fi

        if ! grep -q "^[# ]*CF_TUNNEL_TOKEN[ ]*=" "$PROJECT_DIR/.env"; then
            echo "CF_TUNNEL_TOKEN=$input_cf_token" >> "$PROJECT_DIR/.env"
        else
            sed -i "s|^[# ]*CF_TUNNEL_TOKEN[ ]*=.*|CF_TUNNEL_TOKEN=$input_cf_token|" "$PROJECT_DIR/.env"
        fi
    fi

    # Setup App .env
    if [ ! -f "$PROJECT_DIR/src/.env" ]; then
        if [ -f "$PROJECT_DIR/src/env" ]; then
            cp "$PROJECT_DIR/src/env" "$PROJECT_DIR/src/.env"
        fi
    fi
    
    if [ -f "$PROJECT_DIR/src/.env" ]; then
        # Set Base URL
        sed -i "s|^[# ]*app.baseURL =.*|app.baseURL = '$input_baseurl'|" "$PROJECT_DIR/src/.env"
        
        # Set Database Credentials
        sed -i "s|^[# ]*database.default.hostname.*|database.default.hostname = '${input_prefix}_mariadb'|" "$PROJECT_DIR/src/.env"
        sed -i "s|^[# ]*database.default.database.*|database.default.database = '$input_dbname'|" "$PROJECT_DIR/src/.env"
        sed -i "s|^[# ]*database.default.username.*|database.default.username = '$input_dbuser'|" "$PROJECT_DIR/src/.env"
        sed -i "s|^[# ]*database.default.password.*|database.default.password = '$input_dbpass'|" "$PROJECT_DIR/src/.env"
        
        # Set Redis Configuration
        sed -i "s|^[# ]*cache.redis.host.*|cache.redis.host = '${input_prefix}_redis'|" "$PROJECT_DIR/src/.env"
        sed -i "s|^[# ]*redis.host.*|redis.host = '${input_prefix}_redis'|" "$PROJECT_DIR/src/.env"
        sed -i "s|^[# ]*session.savePath =.*|session.savePath = 'tcp://${input_prefix}_redis:6379'|" "$PROJECT_DIR/src/.env"
        
        if ! grep -q "^[# ]*REDIS_PASSWORD[ ]*=" "$PROJECT_DIR/src/.env"; then
            echo "REDIS_PASSWORD='$input_redispass'" >> "$PROJECT_DIR/src/.env"
        else
            sed -i "s|^[# ]*REDIS_PASSWORD[ ]*=.*|REDIS_PASSWORD='$input_redispass'|" "$PROJECT_DIR/src/.env"
        fi
        
        if ! grep -q "^[# ]*cache\.redis\.password[ ]*=" "$PROJECT_DIR/src/.env"; then
            echo "cache.redis.password = '$input_redispass'" >> "$PROJECT_DIR/src/.env"
        else
            sed -i "s|^[# ]*cache\.redis\.password[ ]*=.*|cache.redis.password = '$input_redispass'|" "$PROJECT_DIR/src/.env"
        fi
        
        # Lock installer
        if ! grep -q "^[# ]*INSTALLER_LOCKED[ ]*=" "$PROJECT_DIR/src/.env"; then
            echo "INSTALLER_LOCKED=true" >> "$PROJECT_DIR/src/.env"
        else
            sed -i "s|^[# ]*INSTALLER_LOCKED[ ]*=.*|INSTALLER_LOCKED=true|" "$PROJECT_DIR/src/.env"
        fi
    fi
    
    # Reload environment variables so script knows the right container names
    export $(grep -v '^#' "$PROJECT_DIR/.env" | grep -v '^$' | xargs)
    PHP_CONTAINER="${CONTAINER_PHP:-${input_prefix}_php}"
    DB_CONTAINER="${CONTAINER_DB:-${input_prefix}_mariadb}"
    NGINX_CONTAINER="${CONTAINER_NGINX:-${input_prefix}_nginx}"
    REDIS_CONTAINER="${CONTAINER_REDIS:-${input_prefix}_redis}"
    WEBSOCKET_CONTAINER="${CONTAINER_WEBSOCKET:-${input_prefix}_websocket}"
    
    echo -e "${GREEN}✓ Konfigurasi database berhasil disimpan.${NC}"
    
    echo -e "\n${YELLOW}Memulai Docker Containers (membangun ulang jika perlu)...${NC}"
    cd "$PROJECT_DIR"
    if ! $COMPOSE up -d --build --remove-orphans; then
        echo -e "${RED}Error: Docker Compose gagal berjalan! Cek log di atas untuk detailnya.${NC}"
        exit 1
    fi
    
    echo -e "\n${YELLOW}Menunggu Database siap (estimasi 15 detik)...${NC}"
    sleep 15
    
    echo -e "\n${YELLOW}Memulai Migrasi Database...${NC}"
    if ! docker ps | grep -q "$PHP_CONTAINER"; then
        echo -e "${RED}Error fatal: Container $PHP_CONTAINER gagal berjalan! Cek docker logs.${NC}"
    else
        echo -e "\n${CYAN}🔒 Memastikan folder sistem ada dan mengamankan permissions (tanpa 777)...${NC}"
        if ! (mkdir -p "$PROJECT_DIR/src/writable/cache" "$PROJECT_DIR/src/writable/session" "$PROJECT_DIR/src/writable/debugbar" "$PROJECT_DIR/src/writable/uploads" "$PROJECT_DIR/src/writable/logs" "$PROJECT_DIR/src/public/uploads" "$PROJECT_DIR/src/public/static" && chown -R :33 "$PROJECT_DIR/src/writable" "$PROJECT_DIR/src/public/uploads" "$PROJECT_DIR/src/public/static" && chmod -R 775 "$PROJECT_DIR/src/writable" "$PROJECT_DIR/src/public/uploads" "$PROJECT_DIR/src/public/static"); then
            echo -e "${RED}Error: Gagal mengatur permission folder pada host!${NC}"
            exit 1
        fi

        echo -e "${CYAN}Mengupdate dan Menginstall dependensi Composer...${NC}"
        docker exec -i $PHP_CONTAINER composer update --no-dev --optimize-autoloader
        docker exec -i $PHP_CONTAINER composer install --no-dev --optimize-autoloader
        
        echo -e "${CYAN}Menjalankan 'php spark migrate'...${NC}"
        if ! docker exec -i -e CI_ENVIRONMENT=development --user 33:33 $PHP_CONTAINER php spark migrate; then
            echo -e "${RED}Error: Migrasi database gagal!${NC}"
        else
            echo -e "${CYAN}Membuat akun Admin awal...${NC}"
            HASHED_ADMIN_PASS=$(echo -n "$input_admin_pass" | docker exec -i $PHP_CONTAINER php -r "echo password_hash(file_get_contents('php://stdin'), PASSWORD_BCRYPT);")
            
            if [ -z "$HASHED_ADMIN_PASS" ]; then
                echo -e "${RED}Error: Gagal melakukan hash password Admin!${NC}"
            else
                SAFE_ADMIN_USER=$(echo -n "$input_admin_user" | docker exec -i $PHP_CONTAINER php -r "echo addslashes(file_get_contents('php://stdin'));")
                
                if docker exec -i $DB_CONTAINER mariadb -u "$input_dbuser" -p"$input_dbpass" "$input_dbname" -e "
                    INSERT INTO users (username, password, role, firstname) 
                    VALUES ('$SAFE_ADMIN_USER', '$HASHED_ADMIN_PASS', 'admin', 'Administrator');
                "; then
                    echo -e "\n${GREEN}✅ Migrasi dan Setup Selesai!${NC}"
                    echo -e "\n=== 🛠️ DAFTAR CONTAINER ===\nPHP: $PHP_CONTAINER\nMariaDB: $DB_CONTAINER\nNginx: $NGINX_CONTAINER\nRedis: $REDIS_CONTAINER\nWebSocket: $WEBSOCKET_CONTAINER"
                    echo -e "\nInstalasi berhasil. Silakan login ke aplikasi menggunakan:"
                    echo -e "URL: ${CYAN}$input_baseurl${NC}"
                    echo -e "Username: $input_admin_user"
                else
                    echo -e "${RED}Error: Gagal membuat akun Admin di database!${NC}"
                fi
            fi
        fi
    fi
    pause
    set -euo pipefail
}

# 6. Testing
do_test_k6() {
    local vus="${1:-50}" turl="${2:-http://localhost:8080}" tid="${3:-1}"
    cd "$PROJECT_DIR"
    if command -v k6 >/dev/null 2>&1; then
        BASE_URL="$turl" TEST_ID="$tid" k6 run --vus "$vus" --duration 2m scripts/k6_exam_simulation.js
    else
        docker run --rm -i --net=host -e BASE_URL="$turl" -e TEST_ID="$tid" \
            -v "$PROJECT_DIR/scripts:/scripts" grafana/k6 run --vus "$vus" --duration 2m /scripts/k6_exam_simulation.js
    fi
}

# --- Main Interactive Menu ---
menu_group() {
    local group="$1" entry g n fn danger desc
    while true; do
        print_header
        printf '%b\n' "${BLUE}=== ${group} ===${NC}"
        local -a names=() fns=() dangers=()
        local i=1
        for entry in "${CMD[@]}"; do
            IFS='|' read -r g n fn danger desc <<< "$entry"
            [ "$g" = "$group" ] || continue
            names+=("$n"); fns+=("$fn"); dangers+=("$danger")
            if [ "$danger" = "1" ]; then
                printf '%b%d) %s — %s%b\n' "$RED" "$i" "$n" "$desc" "$NC"
            else
                printf '%d) %s — %s\n' "$i" "$n" "$desc"
            fi
            i=$((i + 1))
        done
        echo "0) Kembali"
        echo ""
        read -r -p "Pilih: " pick
        [ "$pick" = "0" ] && return 0
        if [[ "$pick" =~ ^[0-9]+$ ]] && [ "$pick" -ge 1 ] && [ "$pick" -lt "$i" ]; then
            local idx=$((pick - 1))
            run_entry "${fns[$idx]}" "${dangers[$idx]}" "$group ${names[$idx]}"
            echo ""; read -r -p "Tekan [Enter] untuk lanjut..."
        else
            warn "Pilihan tidak valid."; sleep 1
        fi
    done
}

main_menu() {
    local entry g n fn danger desc
    while true; do
        print_header
        local -a kinds=() labels=() fns=() dangers=()
        local i=1 grp
        while IFS= read -r grp; do
            kinds+=("group"); labels+=("$grp"); fns+=(""); dangers+=("0")
            printf '%d) %s\n' "$i" "$grp"
            i=$((i + 1))
        done < <(groups)
        for entry in "${CMD[@]}"; do
            IFS='|' read -r g n fn danger desc <<< "$entry"
            [ -z "$g" ] || continue
            kinds+=("cmd"); labels+=("$n"); fns+=("$fn"); dangers+=("$danger")
            if [ "$danger" = "1" ]; then
                printf '%b%d) %s — %s%b\n' "$RED" "$i" "$n" "$desc" "$NC"
            else
                printf '%d) %s — %s\n' "$i" "$n" "$desc"
            fi
            i=$((i + 1))
        done
        echo "0) Keluar"
        echo ""
        read -r -p "Pilih: " pick
        [ "$pick" = "0" ] && { ok "Selesai."; exit 0; }
        if [[ "$pick" =~ ^[0-9]+$ ]] && [ "$pick" -ge 1 ] && [ "$pick" -lt "$i" ]; then
            local idx=$((pick - 1))
            if [ "${kinds[$idx]}" = "group" ]; then
                menu_group "${labels[$idx]}"
            else
                run_entry "${fns[$idx]}" "${dangers[$idx]}" "${labels[$idx]}"
                echo ""; read -r -p "Tekan [Enter] untuk lanjut..."
            fi
        else
            warn "Pilihan tidak valid."; sleep 1
        fi
    done
}

# --- Argument Parser ---
do_help() {
    printf '%b\n' "${CYAN}${BOLD}CBT-MF CLI Helper${NC}"
    echo "Pemakaian: sudo ./scripts/cbt.sh [grup] <perintah> [argumen]"
    echo "Tanpa argumen, menu interaktif akan terbuka."
    echo ""
    local entry g n fn danger desc last=""
    for entry in "${CMD[@]}"; do
        IFS='|' read -r g n fn danger desc <<< "$entry"
        if [ "$g" != "$last" ]; then
            echo ""
            [ -n "$g" ] && printf '%b\n' "${BOLD}${g}${NC}" || printf '%b\n' "${BOLD}umum${NC}"
            last="$g"
        fi
        if [ "$danger" = "1" ]; then
            printf '  %b%-24s%b %s\n' "$RED" "$n" "$NC" "$desc"
        else
            printf '  %-24s %s\n' "$n" "$desc"
        fi
    done
}

dispatch() {
    local entry fn danger
    if [ $# -eq 0 ]; then main_menu; return; fi

    if entry=$(find_cmd "" "$1"); then
        IFS='|' read -r fn danger <<< "$entry"
        local label="$1"; shift
        run_entry "$fn" "$danger" "$label" "$@"
        return
    fi

    if [ $# -lt 2 ]; then
        die "Perintah '$1' butuh subperintah. Lihat: ./scripts/cbt.sh help"
    fi

    if entry=$(find_cmd "$1" "$2"); then
        IFS='|' read -r fn danger <<< "$entry"
        local label="$1 $2"; shift 2
        run_entry "$fn" "$danger" "$label" "$@"
        return
    fi

    die "Perintah tidak dikenal: $1 $2
Lihat daftar lengkap: ./scripts/cbt.sh help"
}

dispatch "$@"
