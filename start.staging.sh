#!/bin/bash
set -e

echo "==> Optimizing Laravel..."
php artisan optimize
php artisan view:cache

echo "==> Starting Supervisord (Nginx + PHP-FPM)..."
exec /usr/bin/supervisord -n -c /etc/supervisor/conf.d/supervisord.conf