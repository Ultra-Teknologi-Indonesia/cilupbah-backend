# ==========================================
# Stage 1 - Build Frontend Assets
# ==========================================
FROM node:20-bookworm AS frontend

WORKDIR /app

# Install dependencies (cache friendly)
COPY package*.json ./
RUN npm ci

# Copy source
COPY . .

# Build Vite assets
RUN npm run build


# ==========================================
# Stage 2 - Laravel Runtime
# ==========================================
FROM php:8.4-fpm

ENV COMPOSER_ALLOW_SUPERUSER=1

# Install system packages & PHP extensions
RUN apt-get update && apt-get install -y \
    git \
    curl \
    unzip \
    zip \
    nginx \
    supervisor \
    ghostscript \
    libpng-dev \
    libjpeg62-turbo-dev \
    libwebp-dev \
    libfreetype6-dev \
    libonig-dev \
    libxml2-dev \
    libpq-dev \
    libzip-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql \
        pdo_pgsql \
        pgsql \
        mbstring \
        exif \
        pcntl \
        bcmath \
        gd \
        zip \
        opcache \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Konfigurasi PHP disalin ke conf.d — direktori yang SELALU dipindai PHP.
#
# Sebelumnya berkas ini hanya terbaca lewat PHP_INI_SCAN_DIR yang disetel di
# docker-compose staging. Manifest Kubernetes production tidak menyetelnya,
# sehingga production diam-diam memakai nilai bawaan PHP: upload_max_filesize
# 2M (bukan 60M seperti yang diniatkan) dan opcache tidak aktif. Menyalinnya
# ke sini membuat kedua environment memakai konfigurasi yang sama.
COPY docker/php/*.ini /usr/local/etc/php/conf.d/
# Override default pool (pm.max_children=5) dengan konfigurasi production
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/www.conf

# Copy Nginx and Supervisor configurations
COPY docker/nginx/nginx.conf /etc/nginx/conf.d/default.conf
COPY docker/supervisor/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copy entire Laravel application
COPY . .

# Install PHP dependencies
RUN composer install \
    --no-dev \
    --prefer-dist \
    --optimize-autoloader \
    --classmap-authoritative \
    --no-interaction \
    --no-progress \
    && composer clear-cache

# Copy frontend build (overwrite local build if any)
COPY --from=frontend /app/public/build ./public/build

# Startup scripts
COPY start.sh /usr/local/bin/start.sh
COPY start.staging.sh /usr/local/bin/start.staging.sh
COPY start.production.sh /usr/local/bin/start.production.sh

RUN chmod +x \
    /usr/local/bin/start.sh \
    /usr/local/bin/start.staging.sh \
    /usr/local/bin/start.production.sh

# Laravel writable directories
RUN mkdir -p \
    storage/app/private/imports/products \
    storage/app/private/imports/sales-orders \
    storage/app/private/imports/rack-allocation \
    storage/app/private/exports \
    storage/framework/cache/laravel-excel \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache \
    && chmod -R 777 storage bootstrap/cache /tmp

ENTRYPOINT ["/usr/local/bin/start.sh"]

EXPOSE 8000