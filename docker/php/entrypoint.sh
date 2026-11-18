#!/bin/sh
# Entrypoint container "app" dan "scheduler".
set -eu
cd /var/www/html

# 1. Folder runtime di volume storage harus bisa ditulis www-data.
mkdir -p storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs
chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true

# 2. APP_KEY: bila tidak diisi di .env, dibuat sekali dan disimpan di volume storage.
if [ -z "${APP_KEY:-}" ]; then
    if [ ! -s storage/app/.app_key ]; then
        echo "base64:$(head -c 32 /dev/urandom | base64)" > storage/app/.app_key
        chown www-data:www-data storage/app/.app_key
        chmod 600 storage/app/.app_key
    fi
    APP_KEY="$(cat storage/app/.app_key)"
    export APP_KEY
fi

# 3. Hanya container "app": migrasi database, pindahkan data versi lama (sekali), cache konfigurasi.
if [ "${RUN_SETUP:-true}" = "true" ]; then
    php artisan migrate --force
    php artisan absensi:impor-lama
    php artisan optimize
    chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true
fi

exec "$@"
