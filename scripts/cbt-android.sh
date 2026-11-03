#!/bin/bash
set -euo pipefail

# A root control-center invocation should build as the invoking developer.
if [ "$EUID" -eq 0 ] && [ -n "${SUDO_USER:-}" ] && [ "${SUDO_UID:-0}" != 0 ]; then
    exec sudo -u "$SUDO_USER" -H -- bash "$0" "$@"
fi

usage() {
    cat <<'HELP'
Pemakaian: bash scripts/cbt.sh android <doctor|setup|build> [opsi]
  android doctor       Periksa dependensi tanpa memasang atau mengunduh
  android setup        Pasang dependensi build yang belum tersedia
  android build        Bangun APK rilis dengan signing lokal
  --name NAMA          Nama aplikasi sendiri
  --icon BERKAS        Icon PNG/JPEG/WebP; 'default' memakai icon bawaan
  --output BERKAS.apk  Lokasi APK hasil build
  --yes                Terima lisensi Android SDK saat pemasangan otomatis
Build tidak memerlukan Docker atau instalasi server. Gunakan tanpa sudo.
HELP
}

case "${1:-}" in
    ''|-h|--help|help) usage; exit 0 ;;
    doctor|setup|build) ;;
    *) usage >&2; exit 2 ;;
esac
for arg in "$@"; do
    case "$arg" in -h|--help) usage; exit 0 ;; esac
done

[ "$(uname -s)" = Linux ] && [ "$(uname -m)" = x86_64 ] || {
    echo 'Error: builder ini memerlukan Linux x86_64.' >&2; exit 1;
}

if ! command -v python3 >/dev/null 2>&1; then
    if [ "$1" = doctor ]; then
        echo 'Python 3: BELUM TERSEDIA; jalankan android setup.' >&2; exit 1
    fi
    privilege=()
    if [ "$EUID" -ne 0 ]; then
        command -v sudo >/dev/null 2>&1 || { echo 'Error: sudo diperlukan untuk memasang Python 3.' >&2; exit 1; }
        privilege=(sudo)
    fi
    if command -v apt-get >/dev/null 2>&1; then
        "${privilege[@]}" apt-get update
        "${privilege[@]}" apt-get install -y python3 ca-certificates
    elif command -v dnf >/dev/null 2>&1; then
        "${privilege[@]}" dnf install -y python3 ca-certificates
    elif command -v pacman >/dev/null 2>&1; then
        "${privilege[@]}" pacman -S --needed --noconfirm python ca-certificates
    elif command -v zypper >/dev/null 2>&1; then
        "${privilege[@]}" zypper --non-interactive install python3 ca-certificates
    else
        echo 'Error: pasang Python 3 dan sertifikat CA; package manager belum didukung.' >&2; exit 1
    fi
fi

exec python3 "$(dirname "$0")/android_build.py" "$@"
