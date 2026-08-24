# Upgrade `scripts/cbt.sh` Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Jadikan senarai perintah deklaratif sebagai sumber tunggal untuk menu dan CLI, tambahkan empat grup perintah baru (bundle, data, migrate, tune), dan tutup lubang keandalan tanpa menyentuh isi installer.

**Architecture:** Satu berkas `scripts/cbt.sh` dengan tujuh bagian bertanda. Senarai `CMD` berisi baris `grup|nama|fungsi|bahaya|deskripsi`; dispatcher dan menu sama-sama membacanya. Nama container diselesaikan secara malas agar `install` tetap bisa dijalankan di atas `.env` kosong. `run_install` dilonggarkan mode ketatnya dan isinya tidak diubah.

**Tech Stack:** Bash 4.4+, Docker Compose, CodeIgniter 4 spark, MariaDB, Redis, shellcheck.

Spec: `docs/superpowers/specs/2026-08-18-cbt-cli-upgrade-design.md`

---

## Struktur berkas

| Berkas | Tanggung jawab |
|---|---|
| `scripts/cbt.sh` (ubah) | Seluruh CLI: fondasi, senarai, fungsi perintah, dispatcher, menu, installer |
| `src/app/Libraries/UiBundleBuilder.php` (ubah) | Baca `kiosk_logo` lebih dulu, jatuh ke `app_logo` |
| `src/app/Commands/BuildUiBundle.php` (ubah) | Opsi `--logo`, simpan setelan `kiosk_logo` |
| `.env.example` (ubah) | Selaraskan dengan variabel yang benar-benar dipakai |
| `src/public/uploads/kiosk/.gitkeep` (buat) | Tempat logo kiosk yang diunggah lewat CLI |

---

### Task 1: Fondasi — mode ketat, pemuat `.env`, resolusi container malas

**Files:**
- Modify: `scripts/cbt.sh:1-50` (kepala berkas), `scripts/cbt.sh:309` (`run_install`)

- [ ] **Step 1: Ganti kepala berkas**

Ganti baris 1–49 (dari `#!/bin/bash` sampai sebelum `# --- Helper Functions ---`) dengan:

```bash
#!/bin/bash
# ============================================================
# CBT-MF CLI Helper
# Satu perintah ditulis sekali di senarai CMD; menu dan CLI
# sama-sama dirender dari sana.
# ============================================================

set -euo pipefail

CYAN='\033[0;36m'; GREEN='\033[0;32m'; YELLOW='\033[0;33m'
RED='\033[0;31m'; BLUE='\033[0;34m'; BOLD='\033[1m'; NC='\033[0m'

die() { printf '%b\n' "${RED}Error: $*${NC}" >&2; exit 1; }
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
```

- [ ] **Step 2: Longgarkan installer**

Di `run_install()`, sisipkan sebagai baris pertama badan fungsi:

```bash
    # Installer berjalan dengan aturan lama: isinya belum diaudit untuk
    # mode ketat, dan satu-satunya cara mengujinya adalah instalasi dari
    # nol. Hapus dua baris ini bila nanti sudah diaudit.
    set +e +u +o pipefail
```

dan sebagai baris terakhir sebelum `}`:

```bash
    set -euo pipefail
```

- [ ] **Step 3: Ganti rujukan container lama**

Ganti seluruh `$PHP_CONTAINER` menjadi `"$(php_container)"`, `$DB_CONTAINER` menjadi `"$(db_container)"`, `$REDIS_CONTAINER` menjadi `"$(redis_container)"` di luar `run_install`. Di dalam `run_install`, biarkan apa adanya.

Run: `grep -n 'PHP_CONTAINER\|DB_CONTAINER\|REDIS_CONTAINER' scripts/cbt.sh`
Expected: hanya baris di dalam `run_install` (sekitar baris 450-500) yang masih memakai bentuk lama.

- [ ] **Step 4: Verifikasi ketiga keadaan `.env`**

```bash
sudo bash scripts/cbt.sh help
sudo mv .env /tmp/env.bak && sudo bash scripts/cbt.sh help; sudo mv /tmp/env.bak .env
```

Expected: keduanya menampilkan bantuan tanpa galat. Yang kedua menampilkan peringatan `.env` tidak ditemukan bila `.env.example` juga tidak ada.

- [ ] **Step 5: shellcheck**

Run: `shellcheck scripts/cbt.sh`
Expected: tidak ada peringatan. Perbaiki yang muncul sebelum lanjut.

- [ ] **Step 6: Commit**

```bash
git add scripts/cbt.sh
git commit -m "refactor(cli): mode ketat, pemuat .env aman, resolusi container malas"
```

---

### Task 2: Senarai perintah, dispatcher, dan `help`

**Files:**
- Modify: `scripts/cbt.sh` (tambah bagian senarai; ganti parser argumen di akhir berkas)

- [ ] **Step 1: Tambahkan senarai dan pembantunya**

Sisipkan sebelum bagian fungsi perintah:

```bash
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
```

- [ ] **Step 2: Ganti parser argumen**

Hapus seluruh blok `if [ $# -gt 0 ]; then case "$1" in ... esac else main_menu fi` di akhir berkas, ganti dengan:

```bash
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
```

- [ ] **Step 3: Ubah fungsi lama jadi fungsi perintah tunggal**

Pecah `cmd_docker`, `cmd_app`, `cmd_db`, `cmd_redis`, `cmd_testing` menjadi fungsi satu-perintah yang namanya sudah didaftarkan di Step 1. Contoh untuk docker:

```bash
do_docker_up()      { cd "$PROJECT_DIR" && $COMPOSE up -d --build; ok "Layanan siap: http://localhost:8080"; }
do_docker_down()    { cd "$PROJECT_DIR" && $COMPOSE down; }
do_docker_restart() { cd "$PROJECT_DIR" && $COMPOSE restart; }
do_docker_logs()    { cd "$PROJECT_DIR" && $COMPOSE logs -f; }
do_docker_status()  { cd "$PROJECT_DIR" && $COMPOSE ps; }

do_app_shell()    { local c; c=$(php_container); require_container "$c"; docker exec -it "$c" bash; }
do_app_php()      { local c; c=$(php_container); require_container "$c"; docker exec -it "$c" php "$@"; }
do_app_composer() { local c; c=$(php_container); require_container "$c"; docker exec -it "$c" composer "$@"; }

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
```

Catatan: pesan phpMyAdmin di `do_docker_up` dibuang — service-nya sudah tidak ada di `docker-compose.yml`.

Fungsi `do_db_*` dan `do_redis_*` ditulis lengkap di Task 3, yang menyusul
langsung sesudah ini. Sampai Task 3 selesai, `help` dan menu tetap merender
perintah itu — keduanya hanya membaca teks senarai — tetapi memanggilnya akan
gagal dengan "command not found". Itu keadaan yang diharapkan di antara dua
task, bukan cacat.

- [ ] **Step 4: Verifikasi**

```bash
sudo bash scripts/cbt.sh help
sudo bash scripts/cbt.sh docker status
sudo bash scripts/cbt.sh redis flush   # jawab dengan teks salah
```

Expected: `help` menampilkan semua grup; `docker status` menampilkan tabel container; `redis flush` menolak jalan dan mencetak "Dibatalkan." saat konfirmasi tidak cocok.

- [ ] **Step 5: shellcheck lalu commit**

```bash
shellcheck scripts/cbt.sh
git add scripts/cbt.sh
git commit -m "refactor(cli): senarai perintah jadi sumber tunggal untuk CLI dan help"
```

---

### Task 3: Pengetatan keamanan pada perintah database dan Redis

**Files:**
- Modify: `scripts/cbt.sh` (fungsi `do_db_*`, `do_redis_*`)

- [ ] **Step 1: Tulis fungsi db dan redis yang aman**

```bash
# Password TIDAK PERNAH lewat argumen: -p"$pass" terbaca siapa pun di
# 'ps'. MYSQL_PWD adalah pola yang sudah dipakai run_backup di berkas ini.
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
```

- [ ] **Step 2: Tulis `db reset-password` tanpa interpolasi SQL**

```bash
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

# Kutip nilai untuk MariaDB: gandakan kutip tunggal, bungkus.
sql_quote() { printf "'%s'" "$(printf '%s' "$1" | sed "s/'/''/g")"; }
```

- [ ] **Step 3: Verifikasi**

```bash
sudo bash scripts/cbt.sh db export uji.sql && ls -l uji.sql && rm uji.sql
sudo bash scripts/cbt.sh db reset-password    # pakai user yang tidak ada
```

Expected: ekspor menghasilkan berkas tidak kosong. `reset-password` untuk user yang tidak ada mencetak `diubah` bernilai `0` tanpa galat SQL.

Run: `ps aux | grep -c 'mariadb -p'`
Expected: `0` — password tidak lagi muncul di daftar proses. Jalankan bersamaan dengan `db shell` di terminal lain untuk memastikan.

- [ ] **Step 4: shellcheck lalu commit**

```bash
shellcheck scripts/cbt.sh
git add scripts/cbt.sh
git commit -m "fix(cli): password berhenti lewat argumen proses, kueri reset-password diparameterkan"
```

---

### Task 4: Menu interaktif dirender dari senarai

**Files:**
- Modify: `scripts/cbt.sh` (ganti `main_menu` dan hapus `cmd_*` lama)

- [ ] **Step 1: Ganti `main_menu`**

```bash
print_header() {
    clear
    printf '%b\n' "${CYAN}${BOLD}"
    echo "============================================================"
    echo "                 CBT-MF CLI HELPER                          "
    echo "============================================================"
    printf '%b\n' "${NC}"
}

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
```

- [ ] **Step 2: Hapus fungsi menu lama**

Hapus `cmd_docker`, `cmd_app`, `cmd_db`, `cmd_redis`, `cmd_maintenance`,
`cmd_testing`, dan `show_cli_help`.

**JANGAN hapus `pause()`** — `run_install` memanggilnya di baris terakhir, dan
installer tidak boleh disentuh.

Run: `grep -n 'cmd_docker\|cmd_app\|cmd_db\|cmd_redis\|cmd_maintenance\|cmd_testing\|show_cli_help' scripts/cbt.sh`
Expected: tidak ada keluaran.

Run: `grep -c '^pause()' scripts/cbt.sh`
Expected: `1`.

- [ ] **Step 3: Verifikasi menu**

Run: `sudo bash scripts/cbt.sh`
Expected: menu menampilkan grup `docker app db redis` lalu perintah tingkat atas; `reset-install` berwarna merah. Masuk ke `docker`, pilih `status`, kembali, keluar.

- [ ] **Step 4: shellcheck lalu commit**

```bash
shellcheck scripts/cbt.sh
git add scripts/cbt.sh
git commit -m "refactor(cli): menu interaktif dirender dari senarai perintah"
```

---

### Task 5: Setelan `kiosk_logo` di sisi PHP

**Files:**
- Modify: `src/app/Libraries/UiBundleBuilder.php` (metode `schoolIdentity`)
- Modify: `src/app/Commands/BuildUiBundle.php`
- Create: `src/public/uploads/kiosk/.gitkeep`

- [ ] **Step 1: Baca `kiosk_logo` lebih dulu**

Di `UiBundleBuilder::schoolIdentity()`, ganti baris `'logo' => ...` menjadi:

```php
            // kiosk_logo menang; app_logo jadi cadangan supaya instalasi yang
            // sudah berjalan tidak kehilangan logonya tanpa menyetel apa pun.
            'logo'    => (string) ($settings->getValue('kiosk_logo', '') ?: $settings->getValue('app_logo', '')),
```

- [ ] **Step 2: Terima `--logo` di perintah build**

Ganti isi `src/app/Commands/BuildUiBundle.php` menjadi:

```php
<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use App\Libraries\UiBundleBuilder;
use App\Models\SettingModel;

class BuildUiBundle extends BaseCommand
{
    protected $group       = 'Tools';
    protected $name        = 'cbt:build-ui-bundle';
    protected $description = 'Generate kiosk UI bundle (5 pages + assets + manifest + zip) ke public/ui-bundle/';
    protected $usage       = 'cbt:build-ui-bundle [--logo <path relatif ke public/>]';
    protected $options     = [
        '--logo' => 'Path gambar relatif terhadap public/, disimpan sebagai setelan kiosk_logo.',
    ];

    public function run(array $params)
    {
        try {
            $logo = CLI::getOption('logo');
            if (is_string($logo) && $logo !== '') {
                $logo = ltrim($logo, '/');
                if (!is_file(FCPATH . $logo)) {
                    CLI::error("Berkas logo tidak ditemukan: public/{$logo}");
                    exit(1);
                }
                // Lewat model, bukan UPDATE mentah: SettingModel menyimpan
                // cache di Redis dan berkas, jadi tulisan langsung ke tabel
                // akan meninggalkan nilai basi.
                (new SettingModel())->setValue('kiosk_logo', $logo, 'string', 'kiosk');
                CLI::write("Setelan kiosk_logo diarahkan ke public/{$logo}", 'green');
            }

            (new UiBundleBuilder())->build();
        } catch (\Throwable $e) {
            CLI::error('Build gagal: ' . $e->getMessage());
            exit(1);
        }
    }
}
```

- [ ] **Step 3: Siapkan folder logo**

```bash
mkdir -p src/public/uploads/kiosk
touch src/public/uploads/kiosk/.gitkeep
```

- [ ] **Step 4: Verifikasi**

```bash
cp src/public/uploads/questions/$(ls src/public/uploads/questions | head -1) src/public/uploads/kiosk/uji.png
docker exec ex_php php spark cbt:build-ui-bundle --logo uploads/kiosk/uji.png
docker exec ex_mariadb mariadb -uroot -p1234 -N -B -e \
  "SELECT \`key\`, value FROM settings WHERE \`key\`='kiosk_logo'" mine
ls -l src/public/ui-bundle/assets/school-logo.png
```

Expected: perintah mencetak "Setelan kiosk_logo diarahkan ke...", baris setelan muncul di database, dan `school-logo.png` bertanggal baru.

Lalu kembalikan ke logo semula agar tampilan tidak berubah:

```bash
docker exec ex_mariadb mariadb -uroot -p1234 -e \
  "DELETE FROM settings WHERE \`key\`='kiosk_logo'" mine
rm src/public/uploads/kiosk/uji.png
docker exec ex_php php spark cbt:build-ui-bundle
```

- [ ] **Step 5: Commit**

```bash
git add src/app/Libraries/UiBundleBuilder.php src/app/Commands/BuildUiBundle.php src/public/uploads/kiosk/.gitkeep
git commit -m "feat(kiosk): setelan kiosk_logo terpisah dari app_logo"
```

---

### Task 6: Grup `bundle`

**Files:**
- Modify: `scripts/cbt.sh`

- [ ] **Step 1: Daftarkan perintah**

```bash
reg bundle  build       do_bundle_build     0 "Bangun ulang bundle UI kiosk"
reg bundle  status      do_bundle_status    0 "Bandingkan versi bundle lokal, server, dan zip publik"
```

- [ ] **Step 2: Tulis `do_bundle_build`**

```bash
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
```

- [ ] **Step 3: Tulis `do_bundle_status`**

```bash
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
```

- [ ] **Step 4: Verifikasi**

```bash
sudo bash scripts/cbt.sh bundle status
sudo bash scripts/cbt.sh bundle build
sudo bash scripts/cbt.sh bundle build --logo /path/ke/logo.png
sudo bash scripts/cbt.sh bundle build --logo /etc/hostname
```

Expected: `status` mencetak tiga baris versi. `build` menaikkan versi bundle. `--logo` dengan gambar sah menyalin berkas dan membangun ulang. `--logo /etc/hostname` ditolak dengan "Bukan gambar yang didukung (terbaca: text/plain)".

- [ ] **Step 5: shellcheck lalu commit**

```bash
shellcheck scripts/cbt.sh
git add scripts/cbt.sh
git commit -m "feat(cli): grup bundle — build dengan logo dan status tiga arah"
```

---

### Task 7: Grup `data`

**Files:**
- Modify: `scripts/cbt.sh`

- [ ] **Step 1: Daftarkan perintah**

```bash
reg data    images      do_data_images      0 "Keluarkan gambar base64 dari teks soal"
reg data    optimize    do_data_optimize    1 "OPTIMIZE TABLE (mengunci tabel)"
reg data    cache-clear do_data_cache_clear 0 "Bersihkan cache aplikasi"
reg data    finalize    do_data_finalize    0 "Tutup attempt yang lewat batas waktu"
reg data    prune-kiosk do_data_prune_kiosk 0 "Bersihkan kunci kiosk_live basi"
```

- [ ] **Step 2: Tulis fungsinya**

```bash
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
```

- [ ] **Step 3: Verifikasi**

```bash
sudo bash scripts/cbt.sh data images
sudo bash scripts/cbt.sh data cache-clear
sudo bash scripts/cbt.sh data finalize
sudo bash scripts/cbt.sh data prune-kiosk
sudo bash scripts/cbt.sh data optimize      # ketik "data optimize" saat diminta
```

Expected: `images` melaporkan "Tidak ada gambar base64 yang tersisa" (sudah dibereskan sebelumnya). `optimize` menampilkan tabel hasil `OPTIMIZE` dengan status `OK` untuk empat tabel.

- [ ] **Step 4: shellcheck lalu commit**

```bash
shellcheck scripts/cbt.sh
git add scripts/cbt.sh
git commit -m "feat(cli): grup data — perawatan gambar, ruang tabel, cache, dan attempt"
```

---

### Task 8: Grup `migrate`

**Files:**
- Modify: `scripts/cbt.sh`

- [ ] **Step 1: Daftarkan dan tulis**

```bash
reg migrate up          do_migrate_up       0 "Jalankan migrasi yang belum diterapkan"
reg migrate status      do_migrate_status   0 "Daftar migrasi dan statusnya"
reg migrate rollback    do_migrate_rollback 1 "Mundurkan batch migrasi terakhir"
```

```bash
do_migrate_up()       { local c; c=$(php_container); require_container "$c"; docker exec "$c" php spark migrate; }
do_migrate_status()   { local c; c=$(php_container); require_container "$c"; docker exec "$c" php spark migrate:status; }
do_migrate_rollback() { local c; c=$(php_container); require_container "$c"; docker exec "$c" php spark migrate:rollback; }
```

- [ ] **Step 2: Verifikasi**

```bash
sudo bash scripts/cbt.sh migrate status
sudo bash scripts/cbt.sh migrate up
```

Expected: `status` menampilkan daftar migrasi termasuk `AddRequireKioskToTests`. `up` melaporkan tidak ada migrasi baru.

- [ ] **Step 3: shellcheck lalu commit**

```bash
shellcheck scripts/cbt.sh
git add scripts/cbt.sh
git commit -m "feat(cli): grup migrate tanpa perlu masuk container"
```

---

### Task 9: Grup `tune` dan penulis `.env`

**Files:**
- Modify: `scripts/cbt.sh`

- [ ] **Step 1: Tulis penulis `.env` yang benar**

```bash
# Penulis key=value yang tidak memakai sed. Delimiter sed di installer
# adalah '|', jadi nilai yang memuat '|' merusak berkasnya; ini menulis
# nilai apa adanya. 'cat >' dipakai, bukan 'mv', agar kepemilikan dan
# izin berkas .env tidak berubah.
env_set() {
    local key="$1" value="$2" file="$PROJECT_DIR/.env" tmp line found=0
    tmp=$(mktemp)
    if [ -f "$file" ]; then
        while IFS= read -r line || [ -n "$line" ]; do
            if [ "${line%%=*}" = "$key" ] && [ "$line" != "${line%%=*}" ]; then
                printf '%s=%s\n' "$key" "$value" >> "$tmp"
                found=1
            else
                printf '%s\n' "$line" >> "$tmp"
            fi
        done < "$file"
    fi
    [ "$found" = "1" ] || printf '%s=%s\n' "$key" "$value" >> "$tmp"
    cat "$tmp" > "$file"
    rm -f "$tmp"
}
```

- [ ] **Step 2: Daftarkan dan tulis perintahnya**

```bash
reg tune    show        do_tune_show        0 "Tampilkan setelan kapasitas yang berlaku"
reg tune    set         do_tune_set         0 "Setel PHP_FPM_MAX_CHILDREN atau DB_BUFFER_POOL"
```

```bash
do_tune_show() {
    local p d
    p=$(php_container); d=$(db_container)
    require_container "$p"; require_container "$d"

    printf '%b\n' "${BOLD}Nilai yang benar-benar berlaku${NC}"
    printf 'Core CPU             : %s\n' "$(nproc)"
    docker exec "$p" sh -c 'php-fpm -tt 2>&1 | grep -E "pm\.(max_children|start_servers)" | sed "s/^.*NOTICE:[[:space:]]*/php-fpm : /"'
    docker exec -e MYSQL_PWD="${MYSQL_ROOT_PASSWORD:-}" "$d" \
        mariadb -uroot -N -B -e "SELECT CONCAT('buffer pool         : ', @@innodb_buffer_pool_size/1024/1024, ' MB')"
    printf 'Handler cache        : %s\n' "$(docker exec "$p" sh -c "grep -oP \"public string \\\$handler = '\\K[a-z]+\" app/Config/Cache.php")"

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

    env_set "$key" "$value"
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
```

- [ ] **Step 3: Verifikasi**

```bash
sudo bash scripts/cbt.sh tune show
cp .env /tmp/env.before
sudo bash scripts/cbt.sh tune set DB_BUFFER_POOL 768M
grep DB_BUFFER_POOL .env
sudo bash scripts/cbt.sh tune set DB_BUFFER_POOL 512M
diff /tmp/env.before .env && echo "SAMA"
sudo bash scripts/cbt.sh tune set FOO bar
```

Expected: `show` menampilkan `max_children=16`, buffer pool `512 MB`, handler `redis`, core `4`. Menyetel lalu mengembalikan menghasilkan `.env` identik (`SAMA`). Kunci `FOO` ditolak dengan daftar kunci yang didukung.

- [ ] **Step 4: shellcheck lalu commit**

```bash
shellcheck scripts/cbt.sh
git add scripts/cbt.sh
git commit -m "feat(cli): grup tune dan penulis .env yang tidak memakai sed"
```

---

### Task 10: Segarkan `.env.example`

**Files:**
- Modify: `.env.example`

- [ ] **Step 1: Selaraskan dengan variabel yang dipakai**

Hapus `CONTAINER_PHPMYADMIN` (service-nya sudah tidak ada di `docker-compose.yml`). Tambahkan di bagian yang sesuai:

```bash
# Password Redis. Dipakai run_backup, cache, dan sesi. Kosongkan bila
# Redis tidak memakai password.
REDIS_PASSWORD=

# Batas worker php-fpm. Kosong = 4x jumlah core, dirender entrypoint saat
# container start. Isi manual bila RAM terbatas (tiap worker ~30-60 MB).
PHP_FPM_MAX_CHILDREN=

# Buffer pool InnoDB. 256M untuk VPS 1 GB, 512M untuk 2 GB, 1G-2G untuk
# 4-8 GB. Kosong = 512M.
DB_BUFFER_POOL=

# Batas koneksi MariaDB. Kosong = 500.
DB_MAX_CONNECTIONS=
```

- [ ] **Step 2: Verifikasi tidak ada variabel yang hilang**

```bash
comm -23 <(grep -oE '^[A-Za-z_]+=' .env | sort -u) <(grep -oE '^[A-Za-z_]+=' .env.example | sort -u)
```

Expected: tidak ada keluaran — setiap variabel di `.env` punya padanan di `.env.example`.

- [ ] **Step 3: Commit**

```bash
git add .env.example
git commit -m "chore(env): .env.example selaras dengan variabel yang benar-benar dipakai"
```

---

### Task 11: Verifikasi menyeluruh dan uji merusak

Lingkungan ini lokal dan pemiliknya mengizinkan datanya dihapus. Tiga hal berikut bukan data dan diamankan lebih dulu.

**Files:** tidak ada perubahan kode; ini gerbang penerimaan.

- [ ] **Step 1: Amankan yang bukan data**

```bash
cp .env /tmp/cbt-env-root.bak
cp src/.env /tmp/cbt-env-app.bak
sudo bash scripts/cbt.sh db export pra-uji.sql
git status --short
```

Expected: dua salinan `.env` tersimpan, `pra-uji.sql` tidak kosong, working tree bersih selain berkas yang memang sedang dikerjakan.

Catat dari `/tmp/cbt-env-root.bak`: nilai `CF_TUNNEL_TOKEN`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, dan prefix container (`ex`). Semuanya akan dimasukkan ulang di Step 3 agar tunnel tetap hidup dan container tidak berganti nama.

- [ ] **Step 2: Uji `reset-install`**

```bash
sudo bash scripts/cbt.sh reset-install     # ketik "reset-install", lalu "YES"
```

Expected: database dihapus dan dibuat ulang, Redis dikosongkan, `src/.env` terhapus.

Run: `git status --short`
Expected: tidak ada berkas kode yang hilang atau berubah. Bila ada, hentikan dan pulihkan lewat `git checkout`.

- [ ] **Step 3: Uji installer dari nol**

```bash
sudo bash scripts/cbt.sh install
```

Masukkan nilai yang sama persis dengan yang dicatat di Step 1: nama database, username, prefix `ex`, token Cloudflare, base URL, dan username admin. Password database harus sama dengan sebelumnya agar `.env` yang dihasilkan cocok dengan volume MariaDB yang lama.

Expected: installer selesai tanpa galat, migrasi berjalan, akun admin terbuat.

- [ ] **Step 4: Pulihkan yang bukan data**

```bash
git diff --stat src/composer.lock
git checkout src/composer.lock 2>/dev/null || true
diff /tmp/cbt-env-root.bak .env || true
curl -s -o /dev/null -w "%{http_code}\n" https://development.rozendev.my.id/api/kiosk/config
```

Expected: `composer.lock` kembali ke versi tercatat bila installer mengubahnya; `curl` menjawab `200`, membuktikan tunnel Cloudflare masih hidup.

- [ ] **Step 5: Bangun ulang bundle dan pulihkan data uji**

```bash
sudo bash scripts/cbt.sh bundle build
sudo bash scripts/cbt.sh db import pra-uji.sql
sudo bash scripts/cbt.sh data cache-clear
sudo bash scripts/cbt.sh bundle status
```

Expected: `bundle status` menampilkan tiga versi yang cocok.

- [ ] **Step 6: Jalankan setiap perintah sekali**

```bash
for c in "docker status" "migrate status" "tune show" "data images" "bundle status"; do
    echo "=== $c"; sudo bash scripts/cbt.sh $c || echo "GAGAL: $c"
done
sudo bash scripts/cbt.sh help
```

Expected: tidak ada baris `GAGAL:`.

- [ ] **Step 7: Uji menu pada tiga keadaan `.env`**

```bash
sudo bash scripts/cbt.sh help
sudo mv .env /tmp/e && sudo bash scripts/cbt.sh help; sudo bash scripts/cbt.sh docker status; sudo mv /tmp/e .env
```

Expected: dengan `.env` hilang, `help` tetap jalan sedangkan `docker status` berhenti dengan pesan yang menunjuk installer — bukan galat docker mentah.

- [ ] **Step 8: shellcheck terakhir dan commit**

```bash
shellcheck scripts/cbt.sh
rm -f pra-uji.sql
git add -A scripts docs
git commit -m "test(cli): verifikasi menyeluruh termasuk reset dan instalasi dari nol"
```
