#!/bin/bash
set -e
 
# Composer deps & frontend assets sudah di-build di CI/CD (multi-stage Dockerfile),
# jadi TIDAK perlu composer install / npm ci / npm run build lagi di sini.
# Menjalankannya ulang di runtime akan gagal (image production tidak punya npm)
# dan bertentangan dengan prinsip "build sekali, deploy berkali-kali".
 
echo "==> Caching Laravel config..."
php artisan config:cache
php artisan route:cache
php artisan view:cache
 
# NOTE: migrations & seeders intentionally run as an explicit, gated CI deploy
# step (see .github/workflows/ci-cd-production.yml) — NOT on every container boot.
# Running them here re-fired on each restart, double-ran migrations, and re-seeded
# every time. Keeping the entrypoint boot-only avoids restart loops on migration
# failures and keeps schema changes visible/rollback-aware in the pipeline.
 
echo "==> Starting Laravel..."
exec php artisan serve --host=0.0.0.0 --port=8000