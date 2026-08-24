#!/bin/bash
# ============================================================
# Sistem-Ujian Installer Script
# ============================================================

# Color Definitions
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

echo -e "${CYAN}"
echo "============================================================"
echo "          SISTEM UJIAN (CBT) - INSTALLER"
echo "============================================================"
echo -e "${NC}"

# 1. Prerequisite Check
if ! command -v docker &> /dev/null || ! docker compose version &> /dev/null; then
    echo -e "${RED}ERROR: Docker and Docker Compose (V2) are required to run this installer.${NC}"
    echo "Please install Docker from https://docs.docker.com/get-docker/"
    exit 1
fi

echo -e "${GREEN}✓ Docker & Docker Compose detected.${NC}"

# 2. Environment Initialization
#
# Berkas env DITULIS UTUH, bukan disalin dari template lalu ditambal sed.
# Pola lama gagal diam-diam: template CI4 (src/env) mengomentari semua
# kuncinya, sehingga sed berjangkar "^database.default..." tidak pernah cocok
# dengan "# database.default..." dan src/.env hasil instalasi berisi 134 baris
# komentar tanpa satu pun nilai. Substitusi yang meleset tidak bersuara;
# menulis berkas utuh tidak punya mode gagal itu.
echo -e "\n${YELLOW}[1/4] Environment Initialization${NC}"

ROOT_ENV_EXISTS=0
APP_ENV_EXISTS=0
[ -f ".env" ] && ROOT_ENV_EXISTS=1
[ -f "src/.env" ] && APP_ENV_EXISTS=1

if [ "$ROOT_ENV_EXISTS" == "1" ] && [ "$APP_ENV_EXISTS" == "1" ]; then
    echo -e "${GREEN}✓ .env dan src/.env sudah ada — dipertahankan apa adanya.${NC}"
    SKIP_ENV=1
elif [ "$ROOT_ENV_EXISTS" == "1" ] || [ "$APP_ENV_EXISTS" == "1" ]; then
    # Menulis salah satu saja akan membuat kredensial kedua berkas berbeda,
    # dan gejalanya baru muncul jauh kemudian sebagai galat koneksi database.
    echo -e "${RED}ERROR: Hanya salah satu berkas environment yang ada.${NC}"
    [ "$ROOT_ENV_EXISTS" == "1" ] && echo "  ada     : .env" || echo "  hilang  : .env"
    [ "$APP_ENV_EXISTS" == "1" ] && echo "  ada     : src/.env" || echo "  hilang  : src/.env"
    echo ""
    echo "Installer tidak akan menebak. Pilih salah satu:"
    echo "  - hapus berkas yang tersisa supaya keduanya dibuat ulang bersama, atau"
    echo "  - lengkapi berkas yang hilang secara manual lalu jalankan ulang."
    exit 1
else
    SKIP_ENV=0
fi

# Nilai yang tidak ditanyakan installer tapi tetap harus ada di .env akar.
CORS_ORIGINS_DEFAULT="https://appassets.androidplatform.net"

# Menulis .env akar. Inilah berkas yang diinterpolasi docker-compose.
write_root_env() {
    cat > .env <<EOF
# Dibuat oleh install.sh — jangan diedit sambil container berjalan.
# Berkas ini dibaca docker-compose DAN disuntikkan ke container php.

# ── Database ────────────────────────────────────────────────
DB_HOST=${DB_HOST_VAL}
DB_PORT=${DB_PORT_VAL}
DB_DATABASE=${DB_NAME_VAL}
DB_USERNAME=${DB_USER_VAL}
DB_PASSWORD=${DB_PASS_VAL}
MYSQL_ROOT_PASSWORD=${DB_ROOT_PASS_VAL}

# Kosong = 512M buffer pool dan 500 koneksi.
DB_BUFFER_POOL=
DB_MAX_CONNECTIONS=

# ── Redis ───────────────────────────────────────────────────
# Nilai ini disuntikkan ke container php; Config/Cache.php dan
# Config/Session.php membacanya dari sana. JANGAN menulis ulang di
# src/.env — dua sumber untuk nilai yang sama akan menyimpang.
REDIS_HOST=redis
REDIS_PORT=6379
REDIS_PASSWORD=${REDIS_PASS_VAL}

# Kosong = 4x jumlah core, dirender entrypoint saat container start.
PHP_FPM_MAX_CHILDREN=

# ── Cloudflare Tunnel (opsional) ────────────────────────────
CF_TUNNEL_TOKEN=

# ── Nama container ──────────────────────────────────────────
CONTAINER_NGINX=${PREFIX_VAL}_nginx
CONTAINER_PHP=${PREFIX_VAL}_php
CONTAINER_WEBSOCKET=${PREFIX_VAL}_websocket
CONTAINER_CLOUDFLARED=${PREFIX_VAL}_cloudflared
CONTAINER_DB=${PREFIX_VAL}_mariadb
CONTAINER_REDIS=${PREFIX_VAL}_redis

# ── Keamanan ────────────────────────────────────────────────
# WAJIB diisi sebelum dipakai sungguhan: \`openssl rand -hex 32\`.
# Kalau kosong, kode memakai token bawaan yang tertulis di dalam
# repositori, jadi semua pemasangan berbagi token yang sama.
INTRUDER_TOKEN=

# Opsional. Kosong berarti lapisan ini dilewati, bukan galat.
KIOSK_APP_SECRET=

CORS_ALLOWED_ORIGINS=${CORS_ORIGINS_DEFAULT}
EOF
    echo -e "${GREEN}✓ .env (root) ditulis.${NC}"
}

# Menulis src/.env. Hanya kunci yang benar-benar dibaca aplikasi.
write_app_env() {
    cat > src/.env <<EOF
# Dibuat oleh install.sh.
# Rujukan lengkap semua kunci yang dikenali aplikasi ada di src/env
# (seluruhnya berkomentar, tidak dipakai sebagai bahan salinan).
#
# Kunci Redis SENGAJA tidak ada di sini: docker-compose menyuntikkan
# REDIS_HOST/REDIS_PORT/REDIS_PASSWORD ke container dari .env akar, dan
# Config/Cache.php serta Config/Session.php sudah membacanya dari sana.
#
# database.default.* HARUS ada: CodeIgniter tidak memetakan DB_HOST dkk
# ke kunci ini, jadi tanpa baris berikut aplikasi memakai localhost.

app.baseURL = 'http://localhost:8080/'

database.default.hostname = '${DB_HOST_VAL}'
database.default.port = ${DB_PORT_VAL}
database.default.database = '${DB_NAME_VAL}'
database.default.username = '${DB_USER_VAL}'
database.default.password = '${DB_PASS_VAL}'

INSTALLER_LOCKED = true
EOF
    echo -e "${GREEN}✓ src/.env (app) ditulis.${NC}"
}

# 3. Database Selection
echo -e "\n${YELLOW}[2/4] Database Configuration${NC}"

# Password Generator Function
generate_password() {
    tr -dc A-Za-z0-9 </dev/urandom | head -c 16
}

# Nilai yang akan merusak berkas env kalau ditulis apa adanya.
# Tolak lebih awal dengan pesan jelas daripada menghasilkan env rusak yang
# gejalanya baru muncul sebagai galat koneksi yang membingungkan.
env_value_is_safe() {
    case "$1" in
        *\'*) return 1 ;;
        *\$*) return 1 ;;
        *) return 0 ;;
    esac
}

# Prefix nama container. Tetap 'ujian' seperti sebelumnya; kalau diubah manual
# di .env, langkah pasca-instalasi di bawah tetap mengikuti karena membaca
# CONTAINER_PHP dari berkas, bukan menghardcode namanya.
PREFIX_VAL="ujian"

# Di luar lingkup perubahan ini: installer belum membangkitkan password Redis.
REDIS_PASS_VAL=""

if [ "$SKIP_ENV" == "1" ]; then
    EXISTING_DB_HOST=$(grep -E '^DB_HOST=' .env | head -1 | cut -d= -f2-)
    if [ "$EXISTING_DB_HOST" == "mariadb" ]; then
        DB_CHOICE=1
    else
        DB_CHOICE=2
    fi
    echo -e "${GREEN}✓ Mengikuti .env yang sudah ada (DB_HOST=$EXISTING_DB_HOST).${NC}"
else
    echo "1) Gunakan MariaDB Bawaan Docker (Rekomendasi)"
    echo "2) Gunakan Database Eksternal (AWS RDS, cPanel DB, Localhost MySQL, dll)"
    read -p "Pilih opsi (1/2) [Default: 1]: " DB_CHOICE
    DB_CHOICE=${DB_CHOICE:-1}

    if [ "$DB_CHOICE" == "1" ]; then
        echo -e "${GREEN}✓ Menggunakan MariaDB Bawaan.${NC}"

        DB_HOST_VAL="mariadb"
        DB_PORT_VAL="3306"
        DB_NAME_VAL="cbt-mf"
        DB_USER_VAL="sayasukakamu"
        DB_PASS_VAL=$(generate_password)
        DB_ROOT_PASS_VAL=$(generate_password)
    else
        echo -e "\n${CYAN}--- Konfigurasi Database Eksternal ---${NC}"
        read -p "Database Host (contoh: 192.168.1.100 atau aws.rds...): " EXT_DB_HOST
        read -p "Database Port [Default: 3306]: " EXT_DB_PORT
        EXT_DB_PORT=${EXT_DB_PORT:-3306}
        read -p "Database Name: " EXT_DB_NAME
        read -p "Database Username: " EXT_DB_USER
        read -sp "Database Password: " EXT_DB_PASS
        echo ""

        for pair in "Host:$EXT_DB_HOST" "Name:$EXT_DB_NAME" "Username:$EXT_DB_USER" "Password:$EXT_DB_PASS"; do
            if ! env_value_is_safe "${pair#*:}"; then
                echo -e "${RED}ERROR: ${pair%%:*} mengandung kutip tunggal (') atau tanda dolar (\$).${NC}"
                echo "Kutip tunggal merusak format src/.env, dan tanda dolar akan"
                echo "diinterpolasi docker-compose di .env akar."
                echo "Ubah nilainya, atau tulis kedua berkas env secara manual."
                exit 1
            fi
        done

        DB_HOST_VAL="$EXT_DB_HOST"
        DB_PORT_VAL="$EXT_DB_PORT"
        DB_NAME_VAL="$EXT_DB_NAME"
        DB_USER_VAL="$EXT_DB_USER"
        DB_PASS_VAL="$EXT_DB_PASS"
        DB_ROOT_PASS_VAL=$(generate_password)
    fi

    write_root_env
    write_app_env

    if [ "$DB_CHOICE" != "1" ]; then
        echo -e "${YELLOW}Menonaktifkan kontainer MariaDB dan phpMyAdmin untuk menghemat RAM...${NC}"
        # Comment out mariadb & phpmyadmin blocks safely
        sed -i '/^  mariadb:/,/^  redis:/ { /^  redis:/! s/^/#/ }' docker-compose.yml
        # Remove mariadb from depends_on arrays
        sed -i '/mariadb:/,+1s/^/#/' docker-compose.yml
        echo -e "${GREEN}✓ Konfigurasi Database Eksternal Tersimpan.${NC}"
    fi
fi


# 4. Start Services
echo -e "\n${YELLOW}[3/4] Menjalankan Docker Container...${NC}"
chmod +x scripts/cmd.sh
./scripts/cmd.sh up

# 5. Database Migration & Seeding
echo -e "\n${YELLOW}[4/4] Inisialisasi Database & Dependensi...${NC}"

if [ "$DB_CHOICE" == "1" ]; then
    echo "Menunggu MariaDB siap (estimasi 15 detik)..."
    sleep 15
else
    echo "Menunggu container PHP siap..."
    sleep 5
fi

# Prefix container ditentukan saat .env ditulis dan sah berbeda tiap
# instalasi (pernah ujian_*, ex_*, tx_*). Menghardcode namanya membuat semua
# langkah di bawah ini gagal begitu prefiksnya diubah.
PHP_CONTAINER=$(grep -E '^CONTAINER_PHP=' .env | head -1 | cut -d= -f2-)
if [ -z "$PHP_CONTAINER" ]; then
    echo -e "${RED}ERROR: CONTAINER_PHP tidak ditemukan di .env.${NC}"
    echo "Tambahkan baris CONTAINER_PHP=<nama_container_php> lalu jalankan ulang."
    exit 1
fi
echo "-> Container PHP: $PHP_CONTAINER"

echo "-> Memperbaiki permission direktori writable/ di dalam container..."
docker exec -i $PHP_CONTAINER chown -R www-data:www-data /var/www/html/writable
docker exec -i $PHP_CONTAINER chmod -R 775 /var/www/html/writable

echo "-> Menginstall dependensi Composer..."
docker exec -i $PHP_CONTAINER composer install --no-interaction --quiet

echo "-> Menjalankan Migrasi Database..."
docker exec -i $PHP_CONTAINER php spark migrate -f

echo "-> Membuat Akun Admin (Seeding)..."
docker exec -i $PHP_CONTAINER php spark db:seed InitialSeeder

# Finish
echo -e "\n============================================================"
echo -e "${GREEN}🎉 INSTALASI SELESAI! 🎉${NC}"
echo "============================================================"
echo -e "Aplikasi telah berjalan. Silakan akses:"
echo -e "🔗 URL Aplikasi : ${CYAN}http://localhost:8080${NC}"
if [ "$DB_CHOICE" == "1" ]; then
    echo -e "🔗 phpMyAdmin   : ${CYAN}http://localhost:8081${NC}"
fi
echo -e "\nAkun Administrator Default:"
echo -e "👤 Username : ${YELLOW}admin${NC}"
echo -e "🔑 Password : ${YELLOW}admin123${NC}"
echo -e "============================================================"
echo -e "Catatan Penting:"
echo -e "- Segera ubah password admin setelah login!"
echo -e "- Jika aplikasi diakses dari komputer lain, ubah 'app.baseURL' di file src/.env menjadi IP server (misal: http://192.168.1.100:8080/)"
echo -e "============================================================"
