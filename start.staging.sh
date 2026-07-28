#!/bin/bash
set -e

echo "==> Optimizing Laravel..."
php artisan optimize
php artisan view:cache

echo "==> Starting Laravel..."
exec php artisan serve --host=0.0.0.0 --port=8000