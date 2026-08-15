#!/bin/bash
set -e

mkdir -p storage/app/private/imports/products \
         storage/app/private/imports/sales-orders \
         storage/app/private/imports/rack-allocation \
         storage/app/private/exports \
         storage/framework/cache/laravel-excel \
         storage/framework/sessions \
         storage/framework/views \
         storage/logs \
         bootstrap/cache 2>/dev/null || true
chmod -R 777 storage bootstrap/cache /tmp 2>/dev/null || true

echo "==> Optimizing Laravel..."
php artisan optimize
php artisan view:cache

echo "==> Starting Supervisord (Nginx + PHP-FPM)..."
exec /usr/bin/supervisord -n -c /etc/supervisor/conf.d/supervisord.conf