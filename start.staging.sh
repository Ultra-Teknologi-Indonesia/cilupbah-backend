#!/bin/bash
set -e

echo "==> Installing Composer dependencies..."
composer install --no-dev --optimize-autoloader

echo "==> Installing Node dependencies..."
npm ci

echo "==> Building frontend assets..."
npm run build

echo "==> Caching Laravel config..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "==> Running migrations..."
php artisan migrate --force

echo "==> Running seeders (if any new)..."
php artisan db:seed --force 2>/dev/null || echo "No seeders to run or already seeded."

echo "==> Starting Laravel..."
exec php artisan serve --host=0.0.0.0 --port=8000