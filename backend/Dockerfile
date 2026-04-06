FROM dunglas/frankenphp:php8.4

WORKDIR /app

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip zip sqlite3 \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN install-php-extensions pdo_sqlite

COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts

COPY . .

RUN rm -f bootstrap/cache/*.php \
    && php artisan package:discover --ansi \
    && mkdir -p storage/database \
    storage/framework/cache \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache \
    && chmod -R a+rw storage bootstrap/cache

EXPOSE 3000

CMD ["sh", "-c", "mkdir -p storage/database storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache && chmod -R a+rw storage bootstrap/cache && touch \"${DB_DATABASE:-/app/storage/database/database.sqlite}\" && php artisan migrate --force && php artisan optimize:clear && php artisan serve --host=0.0.0.0 --port=${PORT:-3000}"]
