#!/bin/bash

# ============================================================
# CBT-MF CLI Helper
# Unified interactive script for managing the CBT-MF project
# ============================================================

# --- Color Definitions ---
CYAN='\033[0;36m'
GREEN='\033[0;32m'
YELLOW='\033[0;33m'
RED='\033[0;31m'
PURPLE='\033[0;35m'
BLUE='\033[0;34m'
BOLD='\033[1m'
NC='\033[0m' # No Color

# --- Environment Setup ---
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"
ENV_FILE="$PROJECT_DIR/.env"

if [ ! -f "$ENV_FILE" ]; then
    ENV_FILE="$PROJECT_DIR/.env.example"
fi

if [ -f "$ENV_FILE" ]; then
    # Export variables (ignore comments and empty lines)
    export $(grep -v '^#' "$ENV_FILE" | grep -v '^$' | xargs)
else
    echo -e "${RED}Error: .env or .env.example file not found at $PROJECT_DIR${NC}"
    exit 1
fi

COMPOSE="docker compose"
PHP_CONTAINER="${CONTAINER_PHP:-ujian_php}"
DB_CONTAINER="${CONTAINER_DB:-ujian_mariadb}"
REDIS_CONTAINER="${CONTAINER_REDIS:-ujian_redis}"
DB_USER="${DB_USERNAME:-sayasukakamu}"
DB_PASS="${DB_PASSWORD:-sayasukakamu}"
DB_NAME="${DB_DATABASE:-cbt-mf}"
ROOT_PASS="${MYSQL_ROOT_PASSWORD:-root_secret}"

# --- Helper Functions ---
print_header() {
    clear
    echo -e "${CYAN}${BOLD}"
    echo "============================================================"
    echo "                 🚀 CBT-MF CLI HELPER 🚀                    "
    echo "============================================================"
    echo -e "${NC}"
}

pause() {
    echo ""
    read -p "Press [Enter] to continue..."
}

# --- Command Functions ---

# 1. Docker
cmd_docker() {
    while true; do
        print_header
        echo -e "${BLUE}=== 🐳 Docker Operations ===${NC}"
        echo "1) Start all services (up -d --build)"
        echo "2) Stop all services (down)"
        echo "3) Restart all services"
        echo "4) View logs"
        echo "5) Container status"
        echo "0) Back to main menu"
        echo ""
        read -p "Select an option: " d_opt
        case $d_opt in
            1) echo -e "${GREEN}Starting services...${NC}"; cd "$PROJECT_DIR" && $COMPOSE up -d --build; echo -e "\n✅ Services ready:\n   App:        http://localhost:8080\n   phpMyAdmin: http://localhost:8081"; pause ;;
            2) echo -e "${YELLOW}Stopping services...${NC}"; cd "$PROJECT_DIR" && $COMPOSE down; pause ;;
            3) echo -e "${YELLOW}Restarting services...${NC}"; cd "$PROJECT_DIR" && $COMPOSE restart; pause ;;
            4) cd "$PROJECT_DIR" && $COMPOSE logs -f; pause ;;
            5) cd "$PROJECT_DIR" && $COMPOSE ps; pause ;;
            0) break ;;
            *) echo -e "${RED}Invalid option!${NC}"; sleep 1 ;;
        esac
    done
}

# 2. App Services (PHP/Composer)
cmd_app() {
    while true; do
        print_header
        echo -e "${BLUE}=== 🐘 App Services ===${NC}"
        echo "1) Open bash shell in PHP container"
        echo "2) Run composer command"
        echo "3) Run php command"
        echo "0) Back to main menu"
        echo ""
        read -p "Select an option: " a_opt
        case $a_opt in
            1) echo -e "${GREEN}Opening shell...${NC}"; docker exec -it $PHP_CONTAINER bash; pause ;;
            2) 
                read -p "Enter composer arguments (e.g., install): " comp_args
                docker exec -it $PHP_CONTAINER composer $comp_args
                pause 
                ;;
            3) 
                read -p "Enter php arguments (e.g., -v): " php_args
                docker exec -it $PHP_CONTAINER php $php_args
                pause 
                ;;
            0) break ;;
            *) echo -e "${RED}Invalid option!${NC}"; sleep 1 ;;
        esac
    done
}

# 3. Database Operations
cmd_db() {
    while true; do
        print_header
        echo -e "${BLUE}=== 🗄️ Database Operations ===${NC}"
        echo "1) Open MariaDB CLI (App User)"
        echo "2) Open MariaDB CLI (Root)"
        echo "3) Export Database"
        echo "4) Import Database"
        echo "5) Reset Superadmin Password"
        echo "0) Back to main menu"
        echo ""
        read -p "Select an option: " db_opt
        case $db_opt in
            1) echo -e "${GREEN}Connecting as $DB_USER...${NC}"; docker exec -it $DB_CONTAINER mariadb -u $DB_USER -p$DB_PASS $DB_NAME; pause ;;
            2) echo -e "${GREEN}Connecting as root...${NC}"; docker exec -it $DB_CONTAINER mariadb -u root -p$ROOT_PASS; pause ;;
            3) 
                FILENAME="backup_$(date +%Y%m%d_%H%M%S).sql"
                echo -e "${YELLOW}Exporting to $PROJECT_DIR/$FILENAME...${NC}"
                docker exec $DB_CONTAINER mariadb-dump -u root -p$ROOT_PASS $DB_NAME > "$PROJECT_DIR/$FILENAME"
                echo -e "${GREEN}✅ Exported successfully!${NC}"
                pause
                ;;
            4) 
                read -p "Enter path to SQL file to import (relative to project root): " sql_file
                if [ -f "$PROJECT_DIR/$sql_file" ]; then
                    echo -e "${YELLOW}Importing $sql_file...${NC}"
                    docker exec -i $DB_CONTAINER mariadb -u root -p$ROOT_PASS $DB_NAME < "$PROJECT_DIR/$sql_file"
                    echo -e "${GREEN}✅ Import complete!${NC}"
                else
                    echo -e "${RED}File not found at $PROJECT_DIR/$sql_file!${NC}"
                fi
                pause
                ;;
            5)
                read -p "Enter superadmin username (default: admin): " super_user
                super_user=${super_user:-admin}
                read -p "Enter new password: " super_pass
                if [ -z "$super_pass" ]; then
                    echo -e "${RED}Password cannot be empty!${NC}"
                else
                    echo -e "${YELLOW}Hashing password and updating database...${NC}"
                    HASHED_PASS=$(docker exec -i $PHP_CONTAINER php -r "echo password_hash('$super_pass', PASSWORD_BCRYPT);")
                    docker exec -i $DB_CONTAINER mariadb -u $DB_USER -p$DB_PASS $DB_NAME -e "UPDATE users SET password = '$HASHED_PASS' WHERE username = '$super_user' AND role = 'admin';"
                    
                    if [ $? -eq 0 ]; then
                        echo -e "${GREEN}✅ Superadmin password reset successfully for user '$super_user'!${NC}"
                    else
                        echo -e "${RED}❌ Failed to reset password. Are you sure the user exists?${NC}"
                    fi
                fi
                pause
                ;;
            0) break ;;
            *) echo -e "${RED}Invalid option!${NC}"; sleep 1 ;;
        esac
    done
}

# 4. Redis Operations
cmd_redis() {
     while true; do
        print_header
        echo -e "${BLUE}=== 📮 Redis Operations ===${NC}"
        echo "1) Open Redis CLI"
        echo "2) Flush all Redis data"
        echo "0) Back to main menu"
        echo ""
        read -p "Select an option: " r_opt
        case $r_opt in
            1) echo -e "${GREEN}Connecting to Redis...${NC}"; docker exec -it $REDIS_CONTAINER redis-cli; pause ;;
            2) 
                echo -e "${YELLOW}Flushing Redis...${NC}"
                docker exec -it $REDIS_CONTAINER redis-cli FLUSHALL
                echo -e "${GREEN}✅ Redis flushed${NC}"
                pause 
                ;;
            0) break ;;
            *) echo -e "${RED}Invalid option!${NC}"; sleep 1 ;;
        esac
    done
}

# 5. Maintenance (Backup, Log rotate, Reset)
run_backup() {
    BACKUP_DIR="$PROJECT_DIR/backups"
    TIMESTAMP=$(date +%Y%m%d_%H%M%S)
    DB_BACKUP="$BACKUP_DIR/db_${DB_DATABASE}_${TIMESTAMP}.sql.gz"
    KEEP_DAYS=7

    mkdir -p "$BACKUP_DIR"
    echo -e "${YELLOW}[$(date '+%Y-%m-%d %H:%M:%S')] Starting automated backup...${NC}"

    docker exec -e MYSQL_PWD="$DB_PASSWORD" "$CONTAINER_DB" \
        mariadb-dump -u"$DB_USERNAME" --single-transaction --routines --triggers --databases "$DB_DATABASE" \
        | gzip > "$DB_BACKUP"

    if [ $? -eq 0 ] && [ -s "$DB_BACKUP" ]; then
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
    read -p "Ketik 'YES' untuk melanjutkan reset instalasi: " CONFIRM

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
    docker exec $DB_CONTAINER mariadb -u root -p$ROOT_PASS -e "DROP DATABASE IF EXISTS $DB_NAME; CREATE DATABASE $DB_NAME;"

    echo "📮 Membersihkan memori Redis..."
    docker exec $REDIS_CONTAINER redis-cli FLUSHALL > /dev/null

    echo "🧹 Membersihkan file unggahan dan cache..."
    rm -rf "$PROJECT_DIR/src/public/uploads/questions/"* 2>/dev/null || true
    rm -rf "$PROJECT_DIR/src/writable/session/"* 2>/dev/null || true
    rm -rf "$PROJECT_DIR/src/writable/cache/"* 2>/dev/null || true

    echo -e "\n${GREEN}✅ RESET SELESAI!${NC}"
    echo "Sistem sekarang dalam kondisi awal (belum diinstall)."
}

run_install() {
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
    PHP_CONTAINER="${CONTAINER_PHP:-ujian_php}"
    
    echo -e "${GREEN}✓ Konfigurasi database berhasil disimpan.${NC}"
    
    echo -e "\n${YELLOW}Memulai Docker Containers (membangun ulang jika perlu)...${NC}"
    cd "$PROJECT_DIR"
    $COMPOSE up -d --build --remove-orphans
    
    echo -e "\n${YELLOW}Menunggu Database siap (estimasi 15 detik)...${NC}"
    sleep 15
    
    echo -e "\n${YELLOW}Memulai Migrasi Database...${NC}"
    if ! docker ps | grep -q "$PHP_CONTAINER"; then
        echo -e "${RED}Error fatal: Container $PHP_CONTAINER gagal berjalan! Cek docker logs.${NC}"
    else
        echo -e "${CYAN}Menyiapkan kredensial sementara untuk migrasi...${NC}"
        cp "$PROJECT_DIR/src/.env" "$PROJECT_DIR/src/.env.bak"
        cp "$PROJECT_DIR/src/env" "$PROJECT_DIR/src/.env"
        sed -i "s/^[# ]*database.default.hostname.*/database.default.hostname = '${input_prefix}_mariadb'/" "$PROJECT_DIR/src/.env"
        sed -i "s/^[# ]*database.default.database.*/database.default.database = '$input_dbname'/" "$PROJECT_DIR/src/.env"
        sed -i "s/^[# ]*database.default.username.*/database.default.username = '$input_dbuser'/" "$PROJECT_DIR/src/.env"
        sed -i "s/^[# ]*database.default.password.*/database.default.password = '$input_dbpass'/" "$PROJECT_DIR/src/.env"
        
        echo -e "${CYAN}Menjalankan 'php spark migrate'...${NC}"
        docker exec -it $PHP_CONTAINER php spark migrate
        
        echo -e "${CYAN}Membersihkan jejak kredensial dari src/.env...${NC}"
        mv "$PROJECT_DIR/src/.env.bak" "$PROJECT_DIR/src/.env"
        echo -e "\n${GREEN}✅ Migrasi Selesai! Struktur tabel telah dibuat.${NC}"
        echo -e "\n=== 🛠️ DAFTAR CONTAINER ===\nPHP: ${input_prefix}_php\nMariaDB: ${input_prefix}_mariadb\nNginx: ${input_prefix}_nginx\nRedis: ${input_prefix}_redis\nWebSocket: ${input_prefix}_websocket"
        echo -e "\nSilakan buka Web Installer di browser Anda untuk membuat akun Admin."
        echo -e "URL: ${CYAN}http://localhost:8080/install${NC} (atau sesuai IP server Anda)."
    fi
    pause
}

cmd_maintenance() {
     while true; do
        print_header
        echo -e "${BLUE}=== 🛡️ Maintenance ===${NC}"
        echo "1) Run Automated Backup (DB & Redis)"
        echo "2) Rotate Application Logs"
        echo -e "3) ${RED}Reset Installation (DANGER)${NC}"
        echo "0) Back to main menu"
        echo ""
        read -p "Select an option: " m_opt
        case $m_opt in
            1) run_backup; pause ;;
            2) run_log_rotate; pause ;;
            3) run_reset; pause ;;
            0) break ;;
            *) echo -e "${RED}Invalid option!${NC}"; sleep 1 ;;
        esac
    done
}

# 6. Testing
cmd_testing() {
    print_header
    echo -e "${BLUE}=== 🚀 k6 Load Testing ===${NC}"
    read -p "Number of Virtual Users (default 50): " vus
    vus=${vus:-50}
    read -p "Target URL (default http://localhost:8080): " turl
    turl=${turl:-"http://localhost:8080"}
    read -p "Test ID (default 1): " tid
    tid=${tid:-1}

    echo -e "\n${CYAN}Starting CBT-MF k6 Simulation with $vus virtual students...${NC}"
    
    cd "$PROJECT_DIR"
    if command -v k6 &> /dev/null; then
        BASE_URL="$turl" TEST_ID="$tid" k6 run --vus "$vus" --duration 2m scripts/k6_exam_simulation.js
    elif command -v docker &> /dev/null; then
        echo -e "${YELLOW}🐳 k6 CLI not found locally. Running via Docker...${NC}"
        docker run --rm -i --net=host -e BASE_URL="$turl" -e TEST_ID="$tid" -v "$(pwd)/scripts:/scripts" grafana/k6 run --vus "$vus" --duration 2m /scripts/k6_exam_simulation.js
    else
        echo -e "${RED}❌ Neither local k6 nor Docker found.${NC}"
    fi
    pause
}

# --- Main Interactive Menu ---
main_menu() {
    while true; do
        print_header
        echo -e "1) 🐳 Docker Operations"
        echo -e "2) 🐘 App Services (PHP & Composer)"
        echo -e "3) 🗄️ Database Operations"
        echo -e "4) 📮 Redis Operations"
        echo -e "5) 🛡️ Maintenance (Backup, Logs, Reset)"
        echo -e "6) 🚀 Testing (k6 Load Test)"
        echo -e "7) 🛠️ Install / Setup CBT-MF"
        echo -e "0) ❌ Exit"
        echo ""
        read -p "Select an option [0-7]: " opt
        case $opt in
            1) cmd_docker ;;
            2) cmd_app ;;
            3) cmd_db ;;
            4) cmd_redis ;;
            5) cmd_maintenance ;;
            6) cmd_testing ;;
            7) run_install ;;
            0) echo -e "${GREEN}Goodbye!${NC}"; exit 0 ;;
            *) echo -e "${RED}Invalid option!${NC}"; sleep 1 ;;
        esac
    done
}

# --- Argument Parser ---
show_cli_help() {
    echo -e "${CYAN}${BOLD}CBT-MF CLI Helper${NC}"
    echo "Usage: ./scripts/cbt.sh [command] [args]"
    echo ""
    echo "If run without arguments, an interactive menu will be launched."
    echo ""
    echo "Commands:"
    echo "  docker <up|down|restart|logs|status>   Docker operations"
    echo "  php <args>                             Run PHP commands in container"
    echo "  composer <args>                        Run Composer in container"
    echo "  db <shell|root|export|import <file>>   Database operations"
    echo "  redis <shell|flush>                    Redis operations"
    echo "  backup                                 Run automated backup"
    echo "  log-rotate                             Rotate application logs"
    echo "  reset-install                          Reset application installation"
    echo "  test-k6 [VUs] [URL] [TestID]           Run k6 load test"
    echo "  install                                Run interactive installer"
    echo "  help                                   Show this help message"
}

if [ $# -gt 0 ]; then
    case "$1" in
        docker)
            shift
            subcmd=${1:-}
            cd "$PROJECT_DIR"
            case "$subcmd" in
                up) $COMPOSE up -d --build ;;
                down) $COMPOSE down ;;
                restart) $COMPOSE restart ;;
                logs) $COMPOSE logs -f ;;
                status) $COMPOSE ps ;;
                *) echo "Usage: ./scripts/cbt.sh docker <up|down|restart|logs|status>" ;;
            esac
            ;;
        php) shift; docker exec -it $PHP_CONTAINER php "$@" ;;
        composer) shift; docker exec -it $PHP_CONTAINER composer "$@" ;;
        db)
            shift
            subcmd=${1:-}
            case "$subcmd" in
                shell) docker exec -it $DB_CONTAINER mariadb -u $DB_USER -p$DB_PASS $DB_NAME ;;
                root) docker exec -it $DB_CONTAINER mariadb -u root -p$ROOT_PASS ;;
                export) 
                    FILENAME=${2:-"backup_$(date +%Y%m%d_%H%M%S).sql"}
                    docker exec $DB_CONTAINER mariadb-dump -u root -p$ROOT_PASS $DB_NAME > "$PROJECT_DIR/$FILENAME"
                    echo "Exported to $FILENAME"
                    ;;
                import)
                    if [ -z "${2:-}" ]; then echo "Missing SQL file. Usage: ./scripts/cbt.sh db import <file>"; exit 1; fi
                    if [ -f "$PROJECT_DIR/$2" ]; then
                        docker exec -i $DB_CONTAINER mariadb -u root -p$ROOT_PASS $DB_NAME < "$PROJECT_DIR/$2"
                        echo "Imported $2"
                    else
                         echo "File not found at $PROJECT_DIR/$2"
                    fi
                    ;;
                *) echo "Usage: ./scripts/cbt.sh db <shell|root|export|import>" ;;
            esac
            ;;
        redis)
            shift
            subcmd=${1:-}
            case "$subcmd" in
                shell) docker exec -it $REDIS_CONTAINER redis-cli ;;
                flush) docker exec -it $REDIS_CONTAINER redis-cli FLUSHALL; echo "Redis flushed" ;;
                *) echo "Usage: ./scripts/cbt.sh redis <shell|flush>" ;;
            esac
            ;;
        backup) run_backup ;;
        log-rotate) run_log_rotate ;;
        reset-install) run_reset ;;
        test-k6) 
            VUS=${2:-50}
            TURL=${3:-"http://localhost:8080"}
            TID=${4:-1}
            cd "$PROJECT_DIR"
            if command -v k6 &> /dev/null; then
                BASE_URL="$TURL" TEST_ID="$TID" k6 run --vus "$VUS" --duration 2m scripts/k6_exam_simulation.js
            elif command -v docker &> /dev/null; then
                docker run --rm -i --net=host -e BASE_URL="$TURL" -e TEST_ID="$TID" -v "$(pwd)/scripts:/scripts" grafana/k6 run --vus "$VUS" --duration 2m /scripts/k6_exam_simulation.js
            else
                echo "k6 or Docker required."
            fi
            ;;
        install) run_install ;;
        help) show_cli_help ;;
        *) echo "Unknown command. Run './scripts/cbt.sh help' for usage." ;;
    esac
else
    main_menu
fi
