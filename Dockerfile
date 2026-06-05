FROM php:8.4-cli

RUN apt-get update && apt-get install -y \
    git curl libpng-dev libonig-dev libxml2-dev \
    zip unzip sqlite3 libsqlite3-dev libpq-dev libzip-dev

RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y nodejs

RUN apt-get clean && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install pdo_mysql pdo_pgsql pgsql mbstring exif pcntl bcmath gd pdo_sqlite zip

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY start.sh /usr/local/bin/start.sh
COPY start.staging.sh /usr/local/bin/start.staging.sh
RUN chmod +x /usr/local/bin/start.sh /usr/local/bin/start.staging.sh

ENTRYPOINT ["/usr/local/bin/start.sh"]

EXPOSE 8000 5173