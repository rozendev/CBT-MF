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
echo -e "\n${YELLOW}[1/4] Environment Initialization${NC}"
if [ ! -f ".env" ]; then
    cp .env.example .env
    echo "✓ Copied .env.example to .env (Root)"
else
    echo "✓ Root .env already exists."
fi

if [ ! -f "src/.env" ]; then
    cp src/env src/.env
    echo "✓ Copied src/env to src/.env (App)"
else
    echo "✓ App src/.env already exists."
fi

# 3. Database Selection
echo -e "\n${YELLOW}[2/4] Database Configuration${NC}"
echo "1) Gunakan MariaDB Bawaan Docker (Rekomendasi)"
echo "2) Gunakan Database Eksternal (AWS RDS, cPanel DB, Localhost MySQL, dll)"
read -p "Pilih opsi (1/2) [Default: 1]: " DB_CHOICE
DB_CHOICE=${DB_CHOICE:-1}

# Password Generator Function
generate_password() {
    tr -dc A-Za-z0-9 </dev/urandom | head -c 16
}

if [ "$DB_CHOICE" == "1" ]; then
    echo -e "${GREEN}✓ Menggunakan MariaDB Bawaan.${NC}"
    
    # Generate random passwords if they are still defaults
    NEW_DB_PASS=$(generate_password)
    NEW_ROOT_PASS=$(generate_password)
    
    sed -i "s/^DB_PASSWORD=sayasukakamu/DB_PASSWORD=$NEW_DB_PASS/" .env
    sed -i "s/^MYSQL_ROOT_PASSWORD=root_secret/MYSQL_ROOT_PASSWORD=$NEW_ROOT_PASS/" .env
    sed -i "s/^database.default.password = ''/database.default.password = '$NEW_DB_PASS'/" src/.env
    sed -i "s/^database.default.hostname = localhost/database.default.hostname = mariadb/" src/.env
    sed -i "s/^database.default.database = ci4/database.default.database = cbt-mf/" src/.env
    sed -i "s/^database.default.username = root/database.default.username = sayasukakamu/" src/.env
    
else
    echo -e "\n${CYAN}--- Konfigurasi Database Eksternal ---${NC}"
    read -p "Database Host (contoh: 192.168.1.100 atau aws.rds...): " EXT_DB_HOST
    read -p "Database Port [Default: 3306]: " EXT_DB_PORT
    EXT_DB_PORT=${EXT_DB_PORT:-3306}
    read -p "Database Name: " EXT_DB_NAME
    read -p "Database Username: " EXT_DB_USER
    read -sp "Database Password: " EXT_DB_PASS
    echo ""

    # Update Root .env
    sed -i "s/^DB_HOST=.*/DB_HOST=$EXT_DB_HOST/" .env
    sed -i "s/^DB_PORT=.*/DB_PORT=$EXT_DB_PORT/" .env
    sed -i "s/^DB_DATABASE=.*/DB_DATABASE=$EXT_DB_NAME/" .env
    sed -i "s/^DB_USERNAME=.*/DB_USERNAME=$EXT_DB_USER/" .env
    sed -i "s/^DB_PASSWORD=.*/DB_PASSWORD=$EXT_DB_PASS/" .env

    # Update App .env
    sed -i "s/^database.default.hostname.*/database.default.hostname = '$EXT_DB_HOST'/" src/.env
    sed -i "s/^database.default.port.*/database.default.port = $EXT_DB_PORT/" src/.env
    sed -i "s/^database.default.database.*/database.default.database = '$EXT_DB_NAME'/" src/.env
    sed -i "s/^database.default.username.*/database.default.username = '$EXT_DB_USER'/" src/.env
    sed -i "s/^database.default.password.*/database.default.password = '$EXT_DB_PASS'/" src/.env

    echo -e "${YELLOW}Menonaktifkan kontainer MariaDB dan phpMyAdmin untuk menghemat RAM...${NC}"
    # Comment out mariadb & phpmyadmin blocks safely
    sed -i '/^  mariadb:/,/^  redis:/ { /^  redis:/! s/^/#/ }' docker-compose.yml
    # Remove mariadb from depends_on arrays
    sed -i '/mariadb:/,+1s/^/#/' docker-compose.yml
    
    echo -e "${GREEN}✓ Konfigurasi Database Eksternal Tersimpan.${NC}"
fi

# Ensure App Base URL is set to localhost for initial setup
sed -i "s|^app.baseURL =.*|app.baseURL = 'http://localhost:8080/'|" src/.env

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

PHP_CONTAINER="ujian_php"

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
