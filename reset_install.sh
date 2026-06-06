#!/bin/bash
# ============================================================
# Script Reset Instalasi Sistem Ujian
# ============================================================
# PERINGATAN: Skrip ini akan menghapus semua konfigurasi dan 
# seluruh data di database. Sistem akan kembali ke kondisi 
# awal (belum ter-install).
# ============================================================

echo "🛑 PERINGATAN: Semua data akan dihapus!"
read -p "Ketik 'YES' untuk melanjutkan reset instalasi: " CONFIRM

if [ "$CONFIRM" != "YES" ]; then
    echo "❌ Reset dibatalkan."
    exit 1
fi

echo "🔄 Memulai proses reset..."

# 1. Hapus konfigurasi .env
if [ -f "src/.env" ]; then
    echo "🗑️ Menghapus file src/.env..."
    rm -f src/.env
else
    echo "ℹ️ File src/.env tidak ditemukan, lewati."
fi

# 2. Reset Database
echo "🗄️ Menghapus dan membuat ulang database sistem_ujian..."
docker exec ujian_mariadb mariadb -u root -proot_secret -e "DROP DATABASE IF EXISTS sistem_ujian; CREATE DATABASE sistem_ujian;"

# 3. Reset Redis (Sessions/Cache)
echo "📮 Membersihkan memori Redis..."
docker exec ujian_redis redis-cli FLUSHALL > /dev/null

# 4. Bersihkan folder sementara dan unggahan (opsional tapi disarankan)
echo "🧹 Membersihkan file unggahan (gambar soal) dan cache..."
rm -rf src/public/uploads/questions/*
rm -rf src/writable/session/*
rm -rf src/writable/cache/*

echo ""
echo "✅ RESET SELESAI!"
echo "Sistem sekarang dalam kondisi perawan (belum diinstall)."
echo "Silakan buka http://localhost:8080 di browser Anda untuk menguji Web Installer."
