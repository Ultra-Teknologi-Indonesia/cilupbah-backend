#!/bin/bash
set -e
 
# Composer deps & frontend assets sudah di-build di CI/CD (multi-stage Dockerfile),
# jadi TIDAK perlu composer install / npm ci / npm run build lagi di sini.
# Menjalankannya ulang di runtime akan gagal (image production tidak punya npm)
# dan bertentangan dengan prinsip "build sekali, deploy berkali-kali".
 
# Auto-ensure writable directories on boot
mkdir -p storage/app/public/baseline-reports \
         storage/app/private/imports/products \
         storage/app/private/imports/sales-orders \
         storage/app/private/imports/rack-allocation \
         storage/app/private/exports \
         storage/framework/cache/laravel-excel \
         storage/framework/sessions \
         storage/framework/views \
         storage/logs \
         bootstrap/cache 2>/dev/null || true
chmod -R 777 storage bootstrap/cache /tmp 2>/dev/null || true

echo "==> Linking public storage..."
php artisan storage:link --force 2>/dev/null || true

echo "==> Caching Laravel config..."
php artisan config:cache
php artisan route:cache
php artisan view:cache
 
# NOTE: migrations & seeders intentionally run as an explicit, gated CI deploy
# step (see .github/workflows/ci-cd-production.yml) — NOT on every container boot.
# Running them here re-fired on each restart, double-ran migrations, and re-seeded
# every time. Keeping the entrypoint boot-only avoids restart loops on migration
# failures and keeps schema changes visible/rollback-aware in the pipeline.
 
echo "==> Starting Supervisord (Nginx + PHP-FPM)..."
exec /usr/bin/supervisord -n -c /etc/supervisor/conf.d/supervisord.conf