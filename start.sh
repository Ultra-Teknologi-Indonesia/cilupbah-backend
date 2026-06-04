#!/bin/bash
set -e

if [ ! -d "vendor" ]; then
    echo "Installing Composer dependencies..."
    composer install
fi

if [ ! -d "node_modules" ]; then
    echo "Installing Node dependencies..."
    npm install
fi

php artisan migrate --force

echo "Starting Laravel server and Vite..."

npm run dev -- --host 0.0.0.0 &

exec php artisan serve --host=0.0.0.0 --port=8000
