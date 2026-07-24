FROM php:8.4-cli

RUN apt-get update && apt-get install -y \
    git curl libpng-dev libonig-dev libxml2-dev \
    zip unzip sqlite3 libsqlite3-dev libpq-dev libzip-dev \
    ghostscript

RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y nodejs

RUN apt-get clean && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install pdo_mysql pdo_pgsql pgsql mbstring exif pcntl bcmath gd pdo_sqlite zip

RUN pecl install redis && docker-php-ext-enable redis

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY start.sh /usr/local/bin/start.sh
COPY start.staging.sh /usr/local/bin/start.staging.sh
COPY start.production.sh /usr/local/bin/start.production.sh
RUN chmod +x /usr/local/bin/start.sh /usr/local/bin/start.staging.sh /usr/local/bin/start.production.sh

ENTRYPOINT ["/usr/local/bin/start.sh"]

EXPOSE 8000 5173