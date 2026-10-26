FROM php:8.5-cli

WORKDIR /var/www/html

RUN apt-get update && apt-get install -y \
    git unzip libzip-dev libpng-dev libonig-dev libxml2-dev \
    && docker-php-ext-install pdo_mysql mbstring zip bcmath \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY . .

RUN composer install --no-dev --optimize-autoloader --no-interaction

RUN php artisan key:generate --force || true

EXPOSE 8000

CMD ["sh", "-c", "php artisan key:generate --force && php artisan migrate --force && php artisan db:seed --class=PermissionSeeder --force && php artisan serve --host=0.0.0.0 --port=8000"]
