#!/bin/bash
set -e

# Install PHP dependencies if not present
if [ ! -d "vendor" ]; then
    echo "Installing Composer dependencies..."
    composer install
fi

# Install Node dependencies if not present
if [ ! -d "node_modules" ]; then
    echo "Installing Node dependencies..."
    npm install
fi



# Run migrations
php artisan migrate --force

echo "Starting Laravel server and Vite..."

# Start Vite in background
npm run dev -- --host 0.0.0.0 &

# Start Laravel server in foreground
exec php artisan serve --host=0.0.0.0 --port=8000
