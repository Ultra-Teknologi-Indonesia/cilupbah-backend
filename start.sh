#!/bin/bash
set -e

if [ -f .env.docker ]; then
    cp .env.docker .env
fi

echo "Installing Composer dependencies..."
composer install

echo "Installing Node dependencies..."
npm install

php artisan migrate --force

echo "Starting Laravel server and Vite..."

npm run dev -- --host 0.0.0.0 &

exec php artisan serve --host=0.0.0.0 --port=8000
