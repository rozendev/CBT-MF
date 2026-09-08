#!/bin/bash
# ============================================================
# CBT-MF CLI Helper
# Satu perintah ditulis sekali di senarai CMD; menu dan CLI
# sama-sama dirender dari sana.
# ============================================================

set -euo pipefail

CYAN='\033[0;36m'; GREEN='\033[0;32m'; YELLOW='\033[0;33m'
RED='\033[0;31m'; BLUE='\033[0;34m'; MAGENTA='\033[0;35m'
WHITE='\033[0;37m'; DIM='\033[2m'; BOLD='\033[1m'; NC='\033[0m'

# Output non-interaktif (redirect, cron, CI) harus tetap bersih dan mudah
# diparsing. NO_COLOR mengikuti konvensi https://no-color.org/.
if [ ! -t 1 ] || [ -n "${NO_COLOR:-}" ]; then
    CYAN=''; GREEN=''; YELLOW=''; RED=''; BLUE=''; MAGENTA=''
    WHITE=''; DIM=''; BOLD=''; NC=''
fi

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
        value=$(printf '%s' "$value" | sed 's/^[[:space:]]*//; s/[[:space:]]*$//')
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

# Membaca satu kunci dari berkas env tanpa mengekspornya ke shell. Dipakai
# installer untuk membawa maju nilai yang TIDAK ditanyakan saat instalasi
# (setelan kapasitas dari 'tune set', token, rahasia opsional). Tanpa ini,
# penulisan berkas secara utuh akan menghapusnya diam-diam setiap kali
# installer dijalankan ulang.
env_get() {
    local file="$1" key="$2" line lhs value
    [ -f "$file" ] || return 0
    while IFS= read -r line || [ -n "$line" ]; do
        case "$line" in *=*) ;; *) continue ;; esac
        lhs=${line%%=*}
        lhs=$(printf '%s' "$lhs" | tr -d '[:space:]')
        [ "$lhs" = "$key" ] || continue

        value=${line#*=}
        value=$(printf '%s' "$value" | sed 's/^[[:space:]]*//; s/[[:space:]]*$//')
        case "$value" in
            \"*\") value=${value#\"}; value=${value%\"} ;;
            \'*\') value=${value#\'}; value=${value%\'} ;;
        esac
        printf '%s' "$value"
        return 0
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
print_rule() {
    printf '%b\n' "${DIM}  ────────────────────────────────────────────────────────────────────${NC}"
}

print_header() {
    if [ -t 1 ] && command -v clear >/dev/null 2>&1; then
        clear
    fi

    local setup_state setup_color
    if [ -f "$PROJECT_DIR/.env" ] && [ -f "$PROJECT_DIR/src/.env" ]; then
        setup_state="SIAP DIGUNAKAN"
        setup_color="$GREEN"
    else
        setup_state="BELUM DIKONFIGURASI"
        setup_color="$YELLOW"
    fi

    printf '%b\n' "${CYAN}  ╭────────────────────────────────────────────────────────────────────╮${NC}"
    printf '  %b│%b  %bCBT–MF CONTROL CENTER%b%*s%b│%b\n' \
        "$CYAN" "$NC" "$BOLD" "$NC" 45 "" "$CYAN" "$NC"
    printf '  %b│%b  %bKelola layanan, data, keamanan, dan konfigurasi deployment%b%*s%b│%b\n' \
        "$CYAN" "$NC" "$DIM" "$NC" 8 "" "$CYAN" "$NC"
    printf '%b\n' "${CYAN}  ╰────────────────────────────────────────────────────────────────────╯${NC}"
    printf '  Status  %b● %s%b    Project  %b%s%b\n' \
        "$setup_color" "$setup_state" "$NC" "$DIM" "$(basename "$PROJECT_DIR")" "$NC"
}

pause() {
    echo ""
    read -r -p "Tekan [Enter] untuk kembali..."
}

menu_group_label() {
    case "$1" in
        docker)  printf 'Docker & Layanan' ;;
        config)  printf 'Konfigurasi' ;;
        app)     printf 'Aplikasi' ;;
        db)      printf 'Database' ;;
        redis)   printf 'Redis' ;;
        bundle)  printf 'Bundle Kiosk' ;;
        data)    printf 'Pemeliharaan Data' ;;
        migrate) printf 'Migrasi' ;;
        tune)    printf 'Performa' ;;
        *)       printf '%s' "$1" ;;
    esac
}

menu_group_hint() {
    case "$1" in
        docker)  printf 'Kontrol container dan pantau kesehatan layanan' ;;
        config)  printf 'Ubah deployment tanpa menjalankan ulang installer' ;;
        app)     printf 'Akses shell, PHP, dan Composer di container' ;;
        db)      printf 'Operasi database, ekspor, impor, dan akun admin' ;;
        redis)   printf 'Kelola cache dan sesi aplikasi' ;;
        bundle)  printf 'Bangun dan periksa bundle UI kiosk' ;;
        data)    printf 'Rawat data ujian dan cache aplikasi' ;;
        migrate) printf 'Kelola skema database CodeIgniter' ;;
        tune)    printf 'Sesuaikan kapasitas PHP-FPM dan MariaDB' ;;
        *)       printf 'Perintah operasional CBT-MF' ;;
    esac
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

reg config  show        do_config_show       0 "Ringkasan konfigurasi aktif"
reg config  cloudflare  do_config_cloudflare 0 "Atur atau nonaktifkan Cloudflare Tunnel"
reg config  base-url    do_config_base_url   0 "Ubah URL publik aplikasi"
reg config  cors        do_config_cors       0 "Atur origin tambahan yang diizinkan"

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

reg migrate up          do_migrate_up       0 "Jalankan migrasi yang belum diterapkan"
reg migrate status      do_migrate_status   0 "Daftar migrasi dan statusnya"
reg migrate rollback    do_migrate_rollback 1 "Mundurkan batch migrasi terakhir"

reg tune    show        do_tune_show        0 "Tampilkan setelan kapasitas yang berlaku"
reg tune    set         do_tune_set         0 "Setel PHP_FPM_MAX_CHILDREN atau DB_BUFFER_POOL"

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
# Compose tetap mendefinisikan cloudflared agar instalasi lama kompatibel.
# Sesudah up/restart, tunnel tanpa token segera dihentikan supaya tidak masuk
# restart-loop dan memenuhi kontrak README: aktif hanya ketika token diisi.
ensure_cloudflare_state() {
    local token container
    token=$(env_get "$PROJECT_DIR/.env" CF_TUNNEL_TOKEN)
    [ -z "$token" ] || return 0
    container="${CONTAINER_CLOUDFLARED:-}"
    [ -n "$container" ] || return 0
    if docker ps --format '{{.Names}}' 2>/dev/null | grep -qx "$container"; then
        if ! (cd "$PROJECT_DIR" && $COMPOSE stop cloudflared >/dev/null); then
            warn "Cloudflare Tunnel tidak memiliki token, tetapi gagal dihentikan."
            return 1
        fi
        info "Cloudflare Tunnel tidak diaktifkan (token kosong)."
    fi
}

do_docker_up() {
    local base
    cd "$PROJECT_DIR" && $COMPOSE up -d --build
    ensure_cloudflare_state
    base=$(app_base_url || true)
    ok "Layanan siap: ${base:-http://localhost:8080}"
}
do_docker_down()    { cd "$PROJECT_DIR" && $COMPOSE down; }
do_docker_restart() { cd "$PROJECT_DIR" && $COMPOSE restart; ensure_cloudflare_state; }
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

# Penulis key=value yang tidak memakai sed. Temporary file dibuat pada
# filesystem yang sama, lalu di-rename secara atomik agar .env tidak pernah
# terlihat kosong/parsial. Owner lama dipertahankan, sedangkan mode selalu 0600
# karena berkas akar memuat password database, Redis, dan token tunnel.
env_set() {
    local key="$1" value="$2" file="$PROJECT_DIR/.env" tmp line lhs found=0
    case "$value" in
        *\'*) die "Nilai $key tidak boleh memuat kutip tunggal." ;;
        *$'\n'*|*$'\r'*) die "Nilai $key tidak boleh memuat baris baru." ;;
    esac
    tmp=$(mktemp "$PROJECT_DIR/.env.tmp.XXXXXX") \
        || die "Gagal membuat temporary file konfigurasi."
    if [ -f "$file" ]; then
        chown --reference="$file" "$tmp" \
            || { rm -f "$tmp"; die "Gagal mempertahankan owner $file."; }
        while IFS= read -r line || [ -n "$line" ]; do
            lhs=${line%%=*}
            lhs=$(printf '%s' "$lhs" | tr -d '[:space:]')
            if [ "$lhs" = "$key" ] && [ "$line" != "${line%%=*}" ]; then
                printf "%s='%s'\n" "$key" "$value" >> "$tmp"
                found=1
            else
                printf '%s\n' "$line" >> "$tmp"
            fi
        done < "$file"
    fi
    [ "$found" = "1" ] || printf "%s='%s'\n" "$key" "$value" >> "$tmp"
    chmod 600 "$tmp" || { rm -f "$tmp"; die "Gagal mengamankan temporary .env."; }
    mv -f "$tmp" "$file" || { rm -f "$tmp"; die "Gagal memasang konfigurasi baru ke $file."; }
}

# Penulis generik untuk .env CodeIgniter. Perbandingan dilakukan pada sisi
# kiri '=' setelah spasi dibuang, sehingga "app.baseURL = ..." diperbarui dan
# tidak ditambahkan sebagai kunci duplikat. Mode quoted melindungi spasi dan #.
env_file_set() {
    local file="$1" key="$2" value="$3" mode="${4:-plain}"
    local tmp line lhs replacement found=0
    [ -f "$file" ] || die "Berkas konfigurasi tidak ditemukan: $file
Jalankan installer lebih dulu."
    case "$value" in
        *$'\n'*|*$'\r'*) die "Nilai konfigurasi tidak boleh memuat baris baru." ;;
    esac
    if [ "$mode" = "quoted" ]; then
        case "$value" in *\'*) die "Nilai konfigurasi tidak boleh memuat kutip tunggal." ;; esac
        replacement="$key = '$value'"
    else
        replacement="$key=$value"
    fi

    tmp=$(mktemp "${file}.tmp.XXXXXX") \
        || die "Gagal membuat temporary file konfigurasi."
    chown --reference="$file" "$tmp" \
        || { rm -f "$tmp"; die "Gagal mempertahankan owner $file."; }
    if [ "$file" = "$PROJECT_DIR/src/.env" ]; then
        chgrp 33 "$tmp" \
            || { rm -f "$tmp"; die "Gagal memberi akses $file ke grup container (GID 33)."; }
        chmod 640 "$tmp" \
            || { rm -f "$tmp"; die "Gagal mengamankan izin $file ke mode 0640."; }
    else
        chmod --reference="$file" "$tmp" \
            || { rm -f "$tmp"; die "Gagal mempertahankan izin $file."; }
    fi
    while IFS= read -r line || [ -n "$line" ]; do
        lhs=${line%%=*}
        lhs=$(printf '%s' "$lhs" | tr -d '[:space:]')
        if [ "$lhs" = "$key" ] && [ "$line" != "${line%%=*}" ]; then
            if [ "$found" = "0" ]; then
                printf '%s\n' "$replacement" >> "$tmp"
                found=1
            fi
        else
            printf '%s\n' "$line" >> "$tmp"
        fi
    done < "$file"
    [ "$found" = "1" ] || printf '%s\n' "$replacement" >> "$tmp"
    mv -f "$tmp" "$file" || { rm -f "$tmp"; die "Gagal memasang konfigurasi baru ke $file."; }
}

config_require_installed() {
    [ -f "$PROJECT_DIR/.env" ] && [ -f "$PROJECT_DIR/src/.env" ] \
        || die "Konfigurasi belum tersedia. Jalankan: sudo ./scripts/cbt.sh install"
}

ask_yes_no() {
    local prompt="$1" default="${2:-y}" answer suffix
    [ "$default" = "y" ] && suffix="[Y/n]" || suffix="[y/N]"
    read -r -p "$prompt $suffix " answer
    answer=${answer:-$default}
    case "$answer" in y|Y|yes|YES|Ya|ya) return 0 ;; *) return 1 ;; esac
}

config_row() {
    local label="$1" value="$2" color="${3:-$WHITE}"
    printf '  %b%-20s%b %b%s%b\n' "$DIM" "$label" "$NC" "$color" "$value" "$NC"
}

do_config_show() {
    config_require_installed
    local base token cors secret tunnel_state tunnel_color container
    base=$(app_base_url || true)
    token=$(env_get "$PROJECT_DIR/.env" CF_TUNNEL_TOKEN)
    cors=$(env_get "$PROJECT_DIR/src/.env" CORS_ALLOWED_ORIGINS)
    secret=$(env_get "$PROJECT_DIR/src/.env" KIOSK_APP_SECRET)
    container="${CONTAINER_CLOUDFLARED:-}"

    if [ -z "$token" ]; then
        tunnel_state="Nonaktif — token belum diisi"
        tunnel_color="$DIM"
    elif [ -n "$container" ] && docker ps --format '{{.Names}}' 2>/dev/null | grep -qx "$container"; then
        tunnel_state="Aktif — container berjalan"
        tunnel_color="$GREEN"
    else
        tunnel_state="Dikonfigurasi — container tidak berjalan"
        tunnel_color="$YELLOW"
    fi

    printf '\n%b\n' "${BOLD}  RINGKASAN DEPLOYMENT${NC}"
    print_rule
    config_row "Base URL" "${base:-(belum diatur)}" "$CYAN"
    config_row "Cloudflare Tunnel" "$tunnel_state" "$tunnel_color"
    config_row "CORS tambahan" "${cors:-(tidak ada)}"
    config_row "Secret kiosk" "$([ -n "$secret" ] && printf 'terpasang' || printf 'tidak terpasang')" "$([ -n "$secret" ] && printf '%s' "$GREEN" || printf '%s' "$DIM")"
    config_row "PHP-FPM worker" "${PHP_FPM_MAX_CHILDREN:-(otomatis, 4x core)}"
    config_row "DB buffer pool" "${DB_BUFFER_POOL:-(default, 512M)}"
    printf '\n%b\n' "${DIM}  Nilai token dan secret sengaja tidak pernah ditampilkan.${NC}"
}

do_config_cloudflare() {
    config_require_installed
    local value="" apply=1 interactive=0 arg token_state

    if [ $# -eq 0 ]; then
        interactive=1
        if [ -n "$(env_get "$PROJECT_DIR/.env" CF_TUNNEL_TOKEN)" ]; then
            token_state="sudah terpasang"
        else
            token_state="belum diisi"
        fi
        printf '%b\n' "${BOLD}Cloudflare Tunnel${NC} — token $token_state."
        printf '%b\n' "${DIM}Token tidak akan ditampilkan atau dicatat ke layar.${NC}"
        read -r -s -p "Token baru, 'off' untuk menonaktifkan, kosong untuk batal: " value
        printf '\n'
        if [ -z "$value" ]; then
            info "Tidak ada perubahan."
            return 0
        fi
        apply=1
    else
        case "$1" in
            off|disable|disabled|none)
                value="off"
                shift
                ;;
            --token-stdin)
                shift
                IFS= read -r value || die "Token tidak terbaca dari stdin."
                [ -n "$value" ] || die "Token dari stdin tidak boleh kosong."
                ;;
            *)
                die "Demi keamanan, token tidak boleh dikirim sebagai argumen proses.
Jalankan tanpa argumen untuk prompt tersembunyi, atau gunakan --token-stdin." ;;
        esac
        for arg in "$@"; do
            case "$arg" in
                --apply) apply=1 ;;
                --no-apply) apply=0 ;;
                *) die "Opsi tidak dikenal: $arg
Pilihan yang didukung: --apply, --no-apply" ;;
            esac
        done
    fi

    case "${value,,}" in off|disable|disabled|none) value="" ;; esac
    if [ -n "$value" ]; then
        [ "${#value}" -ge 20 ] || die "Token Cloudflare tampak terlalu pendek. Periksa kembali token Anda."
        case "$value" in
            *[!A-Za-z0-9._=+/-]*) die "Token Cloudflare memuat karakter yang tidak valid." ;;
        esac
    fi

    env_set CF_TUNNEL_TOKEN "$value"
    chmod 600 "$PROJECT_DIR/.env" \
        || die "Token tersimpan, tetapi izin .env gagal diamankan ke mode 0600."
    export CF_TUNNEL_TOKEN="$value"
    if [ -n "$value" ]; then
        ok "Token Cloudflare Tunnel disimpan; izin .env disetel ke 0600."
    else
        ok "Cloudflare Tunnel dinonaktifkan."
    fi

    if [ "$interactive" = "1" ] && [ "$apply" = "1" ]; then
        ask_yes_no "Terapkan perubahan sekarang?" y || apply=0
    fi
    if [ "$apply" != "1" ]; then
        info "Konfigurasi disimpan tanpa diterapkan (--no-apply)."
        return 0
    fi

    if ! command -v docker >/dev/null 2>&1 || ! docker info >/dev/null 2>&1; then
        warn "Konfigurasi tersimpan, tetapi Docker tidak tersedia untuk menerapkannya."
        return 0
    fi

    cd "$PROJECT_DIR"
    if [ -n "$value" ]; then
        $COMPOSE up -d --no-deps --force-recreate cloudflared
        ok "Cloudflare Tunnel dijalankan ulang dengan token baru."
    else
        if ! $COMPOSE stop cloudflared >/dev/null 2>&1; then
            die "Konfigurasi tersimpan, tetapi container tunnel gagal dihentikan."
        fi
        if ! $COMPOSE rm -sf cloudflared >/dev/null 2>&1; then
            die "Tunnel sudah dihentikan, tetapi containernya gagal dihapus."
        fi
        ok "Container Cloudflare Tunnel telah dihentikan dan dihapus."
    fi
}

validate_url_authority() {
    local authority="$1" port="" host label
    local -a labels=()
    if [[ "$authority" =~ ^\[([0-9A-Fa-f:.]+)\](:([0-9]{1,5}))?$ ]]; then
        port="${BASH_REMATCH[3]:-}"
        [[ "${BASH_REMATCH[1]}" == *:* ]] || return 1
    elif [[ "$authority" =~ ^[A-Za-z0-9.-]+(:([0-9]{1,5}))?$ ]]; then
        port="${BASH_REMATCH[2]:-}"
        host=${authority%%:*}
        [ "${#host}" -le 253 ] || return 1
        IFS='.' read -r -a labels <<< "$host"
        for label in "${labels[@]}"; do
            [ -n "$label" ] && [ "${#label}" -le 63 ] || return 1
            case "$label" in -*|*-) return 1 ;; esac
        done
    else
        return 1
    fi
    if [ -n "$port" ] && { [ "$port" -lt 1 ] || [ "$port" -gt 65535 ]; }; then
        return 1
    fi
    return 0
}

validate_base_url() {
    local value="$1" rest authority
    case "$value" in http://*|https://*) ;; *) return 1 ;; esac
    case "$value" in *[[:space:]\'\"]*|*\?*|*\#*|*@*) return 1 ;; esac
    rest=${value#*://}
    authority=${rest%%/*}
    validate_url_authority "$authority"
}

do_config_base_url() {
    config_require_installed
    local value="${1:-}" current
    [ $# -le 1 ] || die "Pemakaian: ./scripts/cbt.sh config base-url https://ujian.example.sch.id/"
    current=$(app_base_url || true)
    if [ -z "$value" ]; then
        printf 'Base URL saat ini: %s\n' "${current:-(belum diatur)}"
        read -r -p "Base URL baru (kosong untuk batal): " value
        [ -n "$value" ] || { info "Tidak ada perubahan."; return 0; }
    fi
    validate_base_url "$value" || die "Base URL harus berupa URL http:// atau https:// yang valid tanpa spasi."
    value="${value%/}/"
    env_file_set "$PROJECT_DIR/src/.env" app.baseURL "$value" quoted
    ok "Base URL diubah menjadi $value"
    info "Perubahan berlaku pada request aplikasi berikutnya; restart tidak diperlukan."
}

normalize_cors_origins() {
    local raw="$1" item rest normalized=""
    local -a origins=()
    IFS=',' read -r -a origins <<< "$raw"
    for item in "${origins[@]}"; do
        item=$(printf '%s' "$item" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')
        [ -n "$item" ] || continue
        case "$item" in http://*|https://*) ;; *) return 1 ;; esac
        rest=${item#*://}
        case "$rest" in */*|*\?*|*\#*|*@*|'') return 1 ;; esac
        case "$item" in *\**) return 1 ;; esac
        validate_url_authority "$rest" || return 1
        if [ -n "$normalized" ]; then normalized="$normalized,$item"; else normalized="$item"; fi
    done
    printf '%s' "$normalized"
}

do_config_cors() {
    config_require_installed
    local value="${1:-}" current normalized
    [ $# -le 1 ] || die "Pemakaian: ./scripts/cbt.sh config cors https://panel.example.id,https://admin.example.id"
    current=$(env_get "$PROJECT_DIR/src/.env" CORS_ALLOWED_ORIGINS)
    if [ -z "$value" ]; then
        printf 'Origin tambahan saat ini: %s\n' "${current:-(tidak ada)}"
        printf '%b\n' "${DIM}Base URL dan origin WebView kiosk selalu diizinkan otomatis.${NC}"
        read -r -p "Origin tambahan (pisahkan dengan koma, 'off' untuk kosong): " value
        [ -n "$value" ] || { info "Tidak ada perubahan."; return 0; }
    fi
    case "${value,,}" in off|none|default) normalized="" ;;
        *) normalized=$(normalize_cors_origins "$value") \
            || die "Setiap origin harus berbentuk http(s)://host[:port], tanpa path, query, atau wildcard." ;;
    esac
    env_file_set "$PROJECT_DIR/src/.env" CORS_ALLOWED_ORIGINS "$normalized" plain
    if [ -n "$normalized" ]; then
        ok "Origin CORS tambahan diperbarui: $normalized"
    else
        ok "Origin CORS tambahan dikosongkan. Base URL dan WebView tetap diizinkan."
    fi
}

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

# redis-cli menjawab "NOAUTH Authentication required." lalu tetap keluar
# dengan status 0, jadi status keluar tidak bisa dipercaya. Balasannya yang
# harus diperiksa: FLUSHALL yang berhasil selalu menjawab "OK".
redis_flushall() {
    local c="$1"
    if [ -n "${REDIS_PASSWORD:-}" ]; then
        docker exec -i "$c" redis-cli -a "$REDIS_PASSWORD" --no-auth-warning FLUSHALL 2>&1
    else
        docker exec -i "$c" redis-cli FLUSHALL 2>&1
    fi
}

do_redis_flush() {
    local c out; c=$(redis_container); require_container "$c"
    out=$(redis_flushall "$c")
    [ "$out" = "OK" ] || die "Redis gagal dikosongkan: $out"
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

# 4d. Migrate
do_migrate_up()       { local c; c=$(php_container); require_container "$c"; docker exec "$c" php spark migrate; }
do_migrate_status()   { local c; c=$(php_container); require_container "$c"; docker exec "$c" php spark migrate:status; }
do_migrate_rollback() { local c; c=$(php_container); require_container "$c"; docker exec "$c" php spark migrate:rollback; }

# 4e. Tune
do_tune_show() {
    local p d
    p=$(php_container); d=$(db_container)
    require_container "$p"; require_container "$d"

    printf '%b\n' "${BOLD}Nilai yang benar-benar berlaku${NC}"
    printf 'Core CPU             : %s\n' "$(nproc)"
    docker exec "$p" sh -c 'php-fpm -tt 2>&1 | grep -E "pm\.(max_children|start_servers)" | sed "s/^.*NOTICE:[[:space:]]*/php-fpm : /"'
    db_exec_root -N -B -e "SELECT CONCAT('buffer pool         : ', @@innodb_buffer_pool_size/1024/1024, ' MB')"
    # Tanpa 'sh -c': lapisan escaping ganda (bash lalu sh) meluruhkan '\$'
    # jadi '$' polos, dan grep -P membaca '$' sebagai jangkar akhir-baris
    # sehingga polanya tidak pernah cocok. Panggil grep langsung saja.
    printf 'Handler cache        : %s\n' "$(docker exec "$p" grep -oP 'public string \$handler = .\K[a-z]+' app/Config/Cache.php)"

    printf '\n%b\n' "${BOLD}Nilai di .env${NC}"
    printf 'PHP_FPM_MAX_CHILDREN : %s\n' "${PHP_FPM_MAX_CHILDREN:-(kosong, otomatis 4x core)}"
    printf 'DB_BUFFER_POOL       : %s\n' "${DB_BUFFER_POOL:-(kosong, default 512M)}"
}

do_tune_set() {
    local key="${1:-}" value="${2:-}"
    case "$key" in
        PHP_FPM_MAX_CHILDREN|DB_BUFFER_POOL) ;;
        *) die "Kunci yang didukung: PHP_FPM_MAX_CHILDREN, DB_BUFFER_POOL
Contoh: ./scripts/cbt.sh tune set DB_BUFFER_POOL 1G" ;;
    esac
    [ -n "$value" ] || die "Nilai belum disebut. Contoh: tune set $key 1G"

    case "$key" in
        PHP_FPM_MAX_CHILDREN)
            [[ "$value" =~ ^[1-9][0-9]*$ ]] \
                || die "PHP_FPM_MAX_CHILDREN harus bilangan bulat positif." ;;
        DB_BUFFER_POOL)
            [[ "$value" =~ ^[1-9][0-9]*[KkMmGg]?$ ]] \
                || die "DB_BUFFER_POOL harus berupa ukuran seperti 512M atau 2G." ;;
    esac

    env_set "$key" "$value"
    export "$key=$value"
    ok "$key=$value disimpan ke .env"

    # Sengaja tidak diterapkan sendiri: menyalakan ulang layanan di tengah
    # ujian harus keputusan sadar.
    case "$key" in
        PHP_FPM_MAX_CHILDREN)
            info "Terapkan dengan:  cd $PROJECT_DIR && docker compose build php && docker compose up -d php" ;;
        DB_BUFFER_POOL)
            info "Terapkan dengan:  cd $PROJECT_DIR && docker compose up -d mariadb" ;;
    esac
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
    # run_entry sudah meminta ketik ulang "reset-install", jadi tidak ada
    # konfirmasi kedua di sini.
    local d r out db
    d=$(db_container); require_container "$d"
    r=$(redis_container); require_container "$r"
    db="${DB_DATABASE:-}"
    [ -n "$db" ] || die "DB_DATABASE belum ada di .env; menolak menebak nama database."
    case "$db" in
        [!A-Za-z]*|*[!A-Za-z0-9_-]*)
            die "DB_DATABASE tidak aman untuk reset. Perbaiki identifier di .env terlebih dahulu." ;;
    esac

    info "Memulai proses reset..."
    if [ -f "$PROJECT_DIR/src/.env" ]; then
        echo "Menghapus src/.env..."
        rm -f "$PROJECT_DIR/src/.env"
    fi

    echo "Menghapus dan membuat ulang database..."
    db_exec_root -e "DROP DATABASE IF EXISTS \`$db\`; CREATE DATABASE \`$db\`;"

    echo "Mengosongkan Redis..."
    out=$(redis_flushall "$r")
    [ "$out" = "OK" ] || die "Redis gagal dikosongkan: $out"

    echo "Membersihkan unggahan dan cache..."
    rm -rf "$PROJECT_DIR/src/public/uploads/questions/"* 2>/dev/null || true
    rm -rf "$PROJECT_DIR/src/writable/session/"* 2>/dev/null || true
    rm -rf "$PROJECT_DIR/src/writable/cache/"* 2>/dev/null || true

    ok "RESET SELESAI. Sistem kembali ke kondisi belum diinstall."
}

run_install() {
    # Installer berjalan dengan aturan lama: isinya belum diaudit untuk
    # mode ketat, dan satu-satunya cara mengujinya adalah instalasi dari
    # nol. Hapus dua baris ini bila nanti sudah diaudit.
    set +e +u +o pipefail

    # Installer sengaja tidak berhenti di kegagalan pertama supaya sisa
    # langkahnya tetap jalan dan pesannya terkumpul. Konsekuensinya status
    # keluar harus dilacak sendiri, kalau tidak instalasi yang gagal separuh
    # tetap terbaca "berhasil" oleh pemanggilnya.
    local install_failed=0

    print_header
    echo -e "${CYAN}=== 🛠️ CBT-MF Interactive Installer ===${NC}"
    echo "Installer ini akan memandu Anda untuk mengatur kredensial database,"
    echo "Cloudflare Tunnel, dan membuat akun Admin awal."
    echo "------------------------------------------------------------"
    
    read -p "Masukkan nama database [cbt-mf]: " input_dbname
    input_dbname=${input_dbname:-cbt-mf}
    
    read -p "Masukkan username database [cbt_user]: " input_dbuser
    input_dbuser=${input_dbuser:-cbt_user}
    
    read -p "Masukkan Prefix Nama Container [ujian]: " input_prefix
    input_prefix=${input_prefix:-ujian}

    read -sp "Masukkan Cloudflare Tunnel Token (Kosongkan jika tidak pakai): " input_cf_token
    echo ""
    
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
    
    # Identifier dipakai sebagai nama database/container dan sebagian masuk ke
    # SQL ber-backtick. Batasi ke bentuk yang memang didukung agar karakter
    # pemisah atau backtick tidak pernah mengubah struktur konfigurasi/perintah.
    case "$input_dbname" in
        ''|[!A-Za-z]*|*[!A-Za-z0-9_-]*)
            die "Nama database harus diawali huruf dan hanya memuat huruf, angka, _ atau -." ;;
    esac
    [ "${#input_dbname}" -le 64 ] || die "Nama database maksimal 64 karakter."
    case "$input_dbuser" in
        ''|[!A-Za-z_]*|*[!A-Za-z0-9_]*)
            die "Username database harus berupa identifier (huruf/angka/underscore)." ;;
    esac
    [ "${#input_dbuser}" -le 32 ] || die "Username database maksimal 32 karakter."
    case "$input_prefix" in
        ''|[!A-Za-z0-9]*|*[!A-Za-z0-9_-]*)
            die "Prefix container harus diawali huruf/angka dan hanya memuat huruf, angka, _ atau -." ;;
    esac
    [ "${#input_prefix}" -le 40 ] || die "Prefix container maksimal 40 karakter."

    # Nilai ditulis sebagai dotenv single-quoted supaya $, spasi, dan # tetap
    # literal dan konsisten dengan nilai yang dibaca CodeIgniter. Kutip tunggal
    # dan baris baru ditolak karena keduanya memutus serialisasi tersebut.
    local nilai
    for nilai in "$input_dbname" "$input_dbuser" "$input_dbpass" \
                 "$input_redispass" "$input_baseurl" "$input_cf_token" \
                 "$input_prefix"; do
        case "$nilai" in
            *\'*) die "Jawaban instalasi memuat kutip tunggal, yang merusak berkas env. Ulangi tanpa karakter itu." ;;
            *$'\n'*|*$'\r'*) die "Jawaban instalasi memuat baris baru, yang merusak berkas env." ;;
        esac
    done

    # Nilai yang tidak ditanyakan installer dibawa maju dari berkas yang ada,
    # supaya menjalankan ulang installer tidak menghapus setelan kapasitas
    # ('tune set') atau rahasia yang sudah dipasang sendiri oleh operator.
    local buffer_pool max_conn fpm_children kiosk_secret cors_origins intruder_token
    buffer_pool=$(env_get "$PROJECT_DIR/.env" DB_BUFFER_POOL)
    max_conn=$(env_get "$PROJECT_DIR/.env" DB_MAX_CONNECTIONS)
    fpm_children=$(env_get "$PROJECT_DIR/.env" PHP_FPM_MAX_CHILDREN)
    if [ -n "$buffer_pool" ] && [[ ! "$buffer_pool" =~ ^[1-9][0-9]*[KkMmGg]?$ ]]; then
        die "DB_BUFFER_POOL lama tidak valid: gunakan ukuran seperti 512M atau 2G."
    fi
    if [ -n "$max_conn" ] && [[ ! "$max_conn" =~ ^[1-9][0-9]*$ ]]; then
        die "DB_MAX_CONNECTIONS lama harus berupa bilangan bulat positif."
    fi
    if [ -n "$fpm_children" ] && [[ ! "$fpm_children" =~ ^[1-9][0-9]*$ ]]; then
        die "PHP_FPM_MAX_CHILDREN lama harus berupa bilangan bulat positif."
    fi
    kiosk_secret=$(env_get "$PROJECT_DIR/src/.env" KIOSK_APP_SECRET)
    cors_origins=$(env_get "$PROJECT_DIR/src/.env" CORS_ALLOWED_ORIGINS)
    cors_origins=${cors_origins:-https://appassets.androidplatform.net}

    for nilai in "$buffer_pool" "$max_conn" "$fpm_children" \
                 "$kiosk_secret" "$cors_origins"; do
        case "$nilai" in
            *\'*) die "Konfigurasi lama memuat kutip tunggal dan tidak dapat diserialisasi aman. Perbaiki berkas env lalu ulangi." ;;
            *$'\n'*|*$'\r'*) die "Konfigurasi lama memuat baris baru dan tidak dapat dipertahankan." ;;
        esac
    done

    # Token honeypot, unik per pemasangan. Nilai lama dipertahankan supaya
    # halaman 403/404 yang sudah disulih tidak berubah tanpa alasan.
    intruder_token=$(env_get "$PROJECT_DIR/src/.env" INTRUDER_TOKEN)
    if [ -z "$intruder_token" ]; then
        if command -v openssl >/dev/null 2>&1; then
            intruder_token=$(openssl rand -hex 32)
        else
            intruder_token=$(tr -dc a-f0-9 </dev/urandom | head -c 64)
        fi
    fi
    case "$intruder_token" in
        *\'*|*$'\n'*|*$'\r'*) die "INTRUDER_TOKEN lama tidak dapat diserialisasi aman." ;;
    esac

    # ── Berkas env DITULIS UTUH, bukan disalin lalu ditambal sed ─────────
    # Pola lama menyalin .env.example dan src/env lalu menjalankan sed per
    # kunci. Itu punya dua mode gagal yang sama-sama diam:
    #
    #   1. src/env memuat enam kunci dua kali (cache.handler, cache.redis.*,
    #      redis.*). sed mengganti SETIAP baris yang cocok, jadi src/.env
    #      hasilnya memuat kunci yang sama dua kali dalam keadaan aktif, dan
    #      nilai mana yang menang bergantung urutan baris.
    #
    #   2. session.savePath ditulis eksplisit tanpa '?auth=', padahal
    #      Config/Session.php hanya merakit savePath berpassword ketika kunci
    #      itu TIDAK ada. Akibatnya RedisHandler menyambung lalu melewati
    #      auth(), dan permintaan pertama mati dengan 'NOAUTH Authentication
    #      required' yang tidak menyebut-nyebut installer.
    #
    # Kedua berkas dirakit di temporary file pada filesystem yang sama, lalu
    # di-rename secara atomik. Mount ./src adalah mount DIREKTORI, sehingga
    # container tetap melihat src/.env baru setelah rename.
    local root_env_tmp app_env_tmp
    root_env_tmp=$(mktemp "$PROJECT_DIR/.env.tmp.XXXXXX") \
        || die "Gagal membuat temporary file untuk .env akar."

    cat > "$root_env_tmp" <<EOF
# Dibuat oleh 'cbt.sh install'. Jangan diedit sambil container berjalan.
# Berkas ini dibaca docker-compose untuk interpolasi \${...}.
#
# Rahasia tingkat aplikasi TIDAK ditaruh di sini. docker-compose hanya
# menyuntikkan DB_* dan REDIS_* ke container php, jadi INTRUDER_TOKEN,
# CORS_ALLOWED_ORIGINS, dan KIOSK_APP_SECRET hanya berarti kalau ditulis di
# src/.env, tempat CodeIgniter membacanya.

# ── Database ────────────────────────────────────────────────
DB_HOST='${input_prefix}_mariadb'
DB_PORT=3306
DB_DATABASE='$input_dbname'
DB_USERNAME='$input_dbuser'
DB_PASSWORD='$input_dbpass'
MYSQL_ROOT_PASSWORD='$input_dbpass'

# Kosong = 512M buffer pool dan 500 koneksi.
DB_BUFFER_POOL='$buffer_pool'
DB_MAX_CONNECTIONS='$max_conn'

# ── Redis ───────────────────────────────────────────────────
# Nilai ini disuntikkan ke container php; Config/Cache.php dan
# Config/Session.php membacanya dari sana. JANGAN menulis ulang di src/.env:
# dua sumber untuk nilai yang sama akan menyimpang diam-diam.
REDIS_HOST='${input_prefix}_redis'
REDIS_PORT=6379
REDIS_PASSWORD='$input_redispass'

# Kosong = 4x jumlah core, dirender entrypoint saat container start.
PHP_FPM_MAX_CHILDREN='$fpm_children'

# ── Cloudflare Tunnel (opsional) ────────────────────────────
CF_TUNNEL_TOKEN='$input_cf_token'

# ── Nama container ──────────────────────────────────────────
CONTAINER_NGINX='${input_prefix}_nginx'
CONTAINER_PHP='${input_prefix}_php'
CONTAINER_WEBSOCKET='${input_prefix}_websocket'
CONTAINER_CLOUDFLARED='${input_prefix}_cloudflared'
CONTAINER_DB='${input_prefix}_mariadb'
CONTAINER_REDIS='${input_prefix}_redis'
EOF
    if [ -f "$PROJECT_DIR/.env" ]; then
        chown --reference="$PROJECT_DIR/.env" "$root_env_tmp" \
            || { rm -f "$root_env_tmp"; die "Gagal mempertahankan owner .env akar."; }
    fi
    chmod 600 "$root_env_tmp" \
        || { rm -f "$root_env_tmp"; die "Gagal mengamankan izin .env ke mode 0600."; }
    mv -f "$root_env_tmp" "$PROJECT_DIR/.env" \
        || { rm -f "$root_env_tmp"; die "Gagal memasang .env akar."; }
    ok "✓ .env (akar) ditulis dengan izin 0600."

    app_env_tmp=$(mktemp "$PROJECT_DIR/src/.env.tmp.XXXXXX") \
        || die "Gagal membuat temporary file untuk src/.env."
    cat > "$app_env_tmp" <<EOF
# Dibuat oleh 'cbt.sh install'.
# Rujukan lengkap semua kunci yang dikenali aplikasi ada di src/env, yang
# seluruhnya berkomentar dan sengaja tidak dipakai sebagai bahan salinan.
#
# Kunci Redis SENGAJA tidak ada di sini. docker-compose menyuntikkan
# REDIS_HOST, REDIS_PORT, dan REDIS_PASSWORD ke container dari .env akar,
# lalu Config/Cache.php dan Config/Session.php membacanya dari sana.
# Menulis session.savePath di sini akan mematikan perakitan savePath
# berpassword di Config/Session.php dan membuat sesi gagal dengan NOAUTH.
#
# database.default.* HARUS ada: CodeIgniter tidak memetakan DB_HOST dan
# kawan-kawan ke kunci ini, jadi tanpa baris berikut aplikasi menyambung ke
# localhost.

app.baseURL = '$input_baseurl'

database.default.hostname = '${input_prefix}_mariadb'
database.default.port = 3306
database.default.database = '$input_dbname'
database.default.username = '$input_dbuser'
database.default.password = '$input_dbpass'

# Dibaca IntruderReportController lewat env(). Halaman honeypot 403/404
# disajikan nginx sebagai berkas statis dan tidak bisa membaca berkas ini,
# jadi nilainya disulihkan ke sana oleh installer.
INTRUDER_TOKEN='$intruder_token'

# Origin yang diizinkan untuk bundled UI kiosk (WebView lokal).
CORS_ALLOWED_ORIGINS='$cors_origins'

# Opsional. Kosong berarti lapisan ini dilewati, bukan galat.
KIOSK_APP_SECRET='$kiosk_secret'

INSTALLER_LOCKED=true
EOF
    if [ -f "$PROJECT_DIR/src/.env" ]; then
        chown --reference="$PROJECT_DIR/src/.env" "$app_env_tmp" \
            || { rm -f "$app_env_tmp"; die "Gagal mempertahankan owner src/.env."; }
    fi
    chgrp 33 "$app_env_tmp" \
        || { rm -f "$app_env_tmp"; die "Gagal memberi akses src/.env ke grup container (GID 33)."; }
    chmod 640 "$app_env_tmp" \
        || { rm -f "$app_env_tmp"; die "Gagal mengamankan izin src/.env ke mode 0640."; }
    mv -f "$app_env_tmp" "$PROJECT_DIR/src/.env" \
        || { rm -f "$app_env_tmp"; die "Gagal memasang src/.env."; }
    ok "✓ src/.env ditulis dengan izin 0640."

    # Template yang dilacak Git tidak boleh memuat state deployment. Buat salinan
    # runtime terabaikan lalu sulih token hanya di sana; docker-compose memasang
    # direktori ini sebagai document root nginx.
    local runtime_html berkas tmp_honeypot
    runtime_html="$PROJECT_DIR/.runtime/nginx/html"
    rm -rf "$runtime_html"
    mkdir -p "$runtime_html" \
        || die "Gagal membuat direktori runtime nginx."
    cp -a "$PROJECT_DIR/docker/nginx/html/." "$runtime_html/" \
        || die "Gagal menyalin template halaman nginx."
    for berkas in "$runtime_html/errors/403.html" "$runtime_html/errors/404.html"; do
        [ -f "$berkas" ] || continue
        tmp_honeypot=$(mktemp "${berkas}.tmp.XXXXXX") \
            || die "Gagal membuat temporary halaman honeypot."
        sed "s|var TOKEN = '[^']*';|var TOKEN = '${intruder_token}';|" "$berkas" > "$tmp_honeypot" \
            || { rm -f "$tmp_honeypot"; die "Gagal menyulih token honeypot."; }
        mv -f "$tmp_honeypot" "$berkas" \
            || { rm -f "$tmp_honeypot"; die "Gagal memasang halaman honeypot runtime."; }
    done
    ok "✓ Halaman 403/404 runtime dibuat tanpa mengubah template Git."

    # Muat ulang .env supaya nama container yang baru ditulis dikenali skrip.
    # load_env dipakai, bukan 'export $(... | xargs)': xargs memecah kata dan
    # memproses kutip, jadi sandi yang memuat spasi terpotong diam-diam dan
    # sisa katanya berubah menjadi nama variabel yang ikut diekspor.
    load_env "$PROJECT_DIR/.env" || warn "Gagal memuat ulang .env sesudah ditulis."
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
        install_failed=1
        exit 1
    fi
    if ! ensure_cloudflare_state; then
        install_failed=1
        warn "Instalasi dilanjutkan, tetapi status Cloudflare Tunnel perlu diperiksa manual."
    fi
    
    echo -e "\n${YELLOW}Menunggu Database siap (estimasi 15 detik)...${NC}"
    sleep 15
    
    echo -e "\n${YELLOW}Memulai Migrasi Database...${NC}"
    # Dicocokkan utuh terhadap daftar nama, bukan substring terhadap seluruh
    # baris 'docker ps'. Container cron bernama "${CONTAINER_PHP}_cron", jadi
    # pencocokan substring tetap lolos meski container php justru gagal naik.
    if ! docker ps --format '{{.Names}}' | grep -qx "$PHP_CONTAINER"; then
        echo -e "${RED}Error fatal: Container $PHP_CONTAINER gagal berjalan! Cek docker logs.${NC}"
        install_failed=1
    else
        echo -e "\n${CYAN}🔒 Memastikan folder sistem ada dan mengamankan permissions (tanpa 777)...${NC}"
        if ! (mkdir -p "$PROJECT_DIR/src/writable/cache" "$PROJECT_DIR/src/writable/session" "$PROJECT_DIR/src/writable/debugbar" "$PROJECT_DIR/src/writable/uploads" "$PROJECT_DIR/src/writable/logs" "$PROJECT_DIR/src/public/uploads" "$PROJECT_DIR/src/public/static" && chown -R :33 "$PROJECT_DIR/src/writable" "$PROJECT_DIR/src/public/uploads" "$PROJECT_DIR/src/public/static" && find "$PROJECT_DIR/src/writable" "$PROJECT_DIR/src/public/uploads" "$PROJECT_DIR/src/public/static" -type d -exec chmod 775 {} + && find "$PROJECT_DIR/src/writable" "$PROJECT_DIR/src/public/uploads" "$PROJECT_DIR/src/public/static" -type f -exec chmod 664 {} +); then
            echo -e "${RED}Error: Gagal mengatur permission folder pada host!${NC}"
            install_failed=1
            exit 1
        fi

        echo -e "${CYAN}Menginstall dependensi Composer dari lockfile...${NC}"
        # Installer harus reproducible: update dependency dilakukan terpisah,
        # direview, diuji, lalu lockfile-nya dikomit. Di deployment hanya install.
        # Tanpa '-i': perintah ini tidak membaca stdin, dan 'docker exec -i'
        # ikut melahap masukan yang tersisa di terminal.
        if ! docker exec "$PHP_CONTAINER" composer install --no-dev --optimize-autoloader --no-interaction; then
            echo -e "${RED}Error: composer install gagal!${NC}"
            install_failed=1
        fi

        echo -e "${CYAN}Menjalankan 'php spark migrate'...${NC}"
        if ! docker exec -e CI_ENVIRONMENT=development --user 33:33 "$PHP_CONTAINER" php spark migrate; then
            echo -e "${RED}Error: Migrasi database gagal!${NC}"
            install_failed=1
        else
            echo -e "${CYAN}Membuat akun Admin awal...${NC}"
            HASHED_ADMIN_PASS=$(echo -n "$input_admin_pass" | docker exec -i $PHP_CONTAINER php -r "echo password_hash(file_get_contents('php://stdin'), PASSWORD_BCRYPT);")
            
            if [ -z "$HASHED_ADMIN_PASS" ]; then
                echo -e "${RED}Error: Gagal melakukan hash password Admin!${NC}"
                install_failed=1
            else
                SAFE_ADMIN_USER=$(echo -n "$input_admin_user" | docker exec -i $PHP_CONTAINER php -r "echo addslashes(file_get_contents('php://stdin'));")
                
                # Sandi lewat MYSQL_PWD, bukan -p"$pass": argumen proses
                # terbaca siapa pun lewat 'ps'. Pola ini sudah dipakai
                # db_exec_root dan run_backup di berkas yang sama.
                #
                # ON DUPLICATE KEY UPDATE dipakai karena users.username unik.
                # Tanpa itu, installer yang dijalankan ulang mati di INSERT dan
                # melaporkan instalasi gagal padahal seluruh langkah lain
                # berhasil. Sandi yang baru diketik operator memang harus
                # berlaku; kalau tidak, mereka mengetik sandi yang diam-diam
                # tidak berpengaruh lalu gagal login.
                if docker exec -e MYSQL_PWD="$input_dbpass" "$DB_CONTAINER" \
                    mariadb -u "$input_dbuser" "$input_dbname" -e "
                    INSERT INTO users (username, password, role, firstname)
                    VALUES ('$SAFE_ADMIN_USER', '$HASHED_ADMIN_PASS', 'admin', 'Administrator')
                    ON DUPLICATE KEY UPDATE
                        password = '$HASHED_ADMIN_PASS',
                        role     = 'admin';
                "; then
                    echo -e "\n${GREEN}✅ Migrasi dan Setup Selesai!${NC}"
                    echo -e "\n=== 🛠️ DAFTAR CONTAINER ===\nPHP: $PHP_CONTAINER\nMariaDB: $DB_CONTAINER\nNginx: $NGINX_CONTAINER\nRedis: $REDIS_CONTAINER\nWebSocket: $WEBSOCKET_CONTAINER"
                    echo -e "\nInstalasi berhasil. Silakan login ke aplikasi menggunakan:"
                    echo -e "URL: ${CYAN}$input_baseurl${NC}"
                    echo -e "Username: $input_admin_user"
                else
                    echo -e "${RED}Error: Gagal membuat akun Admin di database!${NC}"
                    install_failed=1
                fi
            fi
        fi
    fi
    if [ "$install_failed" -ne 0 ]; then
        echo -e "\n${RED}${BOLD}INSTALASI TIDAK SELESAI.${NC} ${RED}Baca pesan galat di atas sebelum memakai sistem ini.${NC}"
    fi
    pause
    set -euo pipefail
    return "$install_failed"
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
    local group="$1" entry g n fn danger desc title hint
    title=$(menu_group_label "$group")
    hint=$(menu_group_hint "$group")
    while true; do
        print_header
        printf '\n  %b%s%b\n' "$BOLD" "$title" "$NC"
        printf '  %b%s%b\n' "$DIM" "$hint" "$NC"
        print_rule
        local -a names=() fns=() dangers=()
        local i=1
        for entry in "${CMD[@]}"; do
            IFS='|' read -r g n fn danger desc <<< "$entry"
            [ "$g" = "$group" ] || continue
            names+=("$n"); fns+=("$fn"); dangers+=("$danger")
            if [ "$danger" = "1" ]; then
                printf '  %b[%02d]  %-17s  %s%b\n' "$RED" "$i" "$n" "$desc" "$NC"
            else
                printf '  %b[%02d]%b  %b%-17s%b  %s\n' "$CYAN" "$i" "$NC" "$BOLD" "$n" "$NC" "$desc"
            fi
            i=$((i + 1))
        done
        print_rule
        printf '  %b[00]%b  Kembali ke menu utama\n\n' "$DIM" "$NC"
        read -r -p "  Pilih menu › " pick
        case "$pick" in 0|00) return 0 ;; esac
        if [[ "$pick" =~ ^[0-9]+$ ]] && [ "$pick" -ge 1 ] && [ "$pick" -lt "$i" ]; then
            local idx=$((pick - 1))
            printf '\n'
            run_entry "${fns[$idx]}" "${dangers[$idx]}" "$group ${names[$idx]}"
            pause
        else
            warn "Pilihan tidak valid."; sleep 1
        fi
    done
}

main_menu() {
    local entry g n fn danger desc display
    while true; do
        print_header
        local -a kinds=() labels=() fns=() dangers=()
        local i=1 grp
        printf '\n  %bMENU OPERASIONAL%b\n' "$BOLD" "$NC"
        print_rule
        while IFS= read -r grp; do
            kinds+=("group"); labels+=("$grp"); fns+=(""); dangers+=("0")
            display=$(menu_group_label "$grp")
            printf '  %b[%02d]%b  %b%-23s%b  %s\n' \
                "$CYAN" "$i" "$NC" "$BOLD" "$display" "$NC" "$(menu_group_hint "$grp")"
            i=$((i + 1))
        done < <(groups)

        printf '\n  %bAKSI CEPAT%b\n' "$BOLD" "$NC"
        print_rule
        for entry in "${CMD[@]}"; do
            IFS='|' read -r g n fn danger desc <<< "$entry"
            [ -z "$g" ] || continue
            kinds+=("cmd"); labels+=("$n"); fns+=("$fn"); dangers+=("$danger")
            if [ "$danger" = "1" ]; then
                printf '  %b[%02d]  %-23s  %s%b\n' "$RED" "$i" "$n" "$desc" "$NC"
            else
                printf '  %b[%02d]%b  %b%-23s%b  %s\n' "$MAGENTA" "$i" "$NC" "$BOLD" "$n" "$NC" "$desc"
            fi
            i=$((i + 1))
        done
        print_rule
        printf '  %b[00]%b  Keluar\n\n' "$DIM" "$NC"
        read -r -p "  Pilih menu › " pick
        case "$pick" in 0|00) ok "Selesai."; exit 0 ;; esac
        if [[ "$pick" =~ ^[0-9]+$ ]] && [ "$pick" -ge 1 ] && [ "$pick" -lt "$i" ]; then
            local idx=$((pick - 1))
            if [ "${kinds[$idx]}" = "group" ]; then
                menu_group "${labels[$idx]}"
            else
                printf '\n'
                # Jalankan langsung agar `set -e` tetap aktif di dalam handler.
                # Lebih aman menutup menu saat gagal daripada meneruskan langkah
                # berikutnya dan mencetak status sukses yang keliru.
                run_entry "${fns[$idx]}" "${dangers[$idx]}" "${labels[$idx]}"
                pause
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
