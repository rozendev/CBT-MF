#!/bin/sh
set -e

# php-fpm TIDAK menerima variabel lingkungan pada direktif numerik seperti
# pm.max_children (diuji: "unable to parse value ... is not a valid number"),
# jadi konfignya dirender di sini saat container start. Dengan begitu satu
# image yang sama bisa dipakai di VPS 1 core maupun server 8 core tanpa
# rebuild.
if [ -z "$PHP_FPM_MAX_CHILDREN" ]; then
    CORES=$(nproc 2>/dev/null || echo 2)
    # 4 worker per core: request ujian didominasi tunggu Redis/MariaDB, bukan
    # hitung PHP, jadi worker melebihi jumlah core masih berguna. Lebih dari
    # itu cuma menambah rebutan CPU dan memori.
    PHP_FPM_MAX_CHILDREN=$((CORES * 4))
fi

PHP_FPM_START_SERVERS=${PHP_FPM_START_SERVERS:-$(( PHP_FPM_MAX_CHILDREN / 4 ))}
[ "$PHP_FPM_START_SERVERS" -lt 2 ] && PHP_FPM_START_SERVERS=2

cat > /usr/local/etc/php-fpm.d/zzz-tuning.conf <<CONF
[www]
pm = dynamic
pm.max_children = ${PHP_FPM_MAX_CHILDREN}
pm.start_servers = ${PHP_FPM_START_SERVERS}
pm.min_spare_servers = ${PHP_FPM_START_SERVERS}
pm.max_spare_servers = ${PHP_FPM_MAX_CHILDREN}
pm.max_requests = ${PHP_FPM_MAX_REQUESTS:-500}
CONF

echo "[entrypoint] php-fpm: max_children=${PHP_FPM_MAX_CHILDREN} start_servers=${PHP_FPM_START_SERVERS} (cores=$(nproc 2>/dev/null || echo '?'))"

exec docker-php-entrypoint "$@"
