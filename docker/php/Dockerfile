# Absensi Sekolah (Laravel) - image PHP-FPM berisi kode + vendor.
# Dipakai oleh service "app" (php-fpm) dan "scheduler" (php artisan schedule:work).

# 1) Pasang dependensi Composer
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --ignore-platform-reqs
COPY . .
RUN composer dump-autoload --optimize --no-dev --classmap-authoritative --no-scripts

# 2) Image aplikasi
FROM php:8.4-fpm-alpine

RUN apk add --no-cache libzip \
    && apk add --no-cache --virtual .build-deps libzip-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql \
    && docker-php-ext-install -j"$(nproc)" zip \
    && docker-php-ext-install -j"$(nproc)" opcache \
    && apk del --no-network .build-deps

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php/php.ini "$PHP_INI_DIR/conf.d/zz-absensi.ini"
COPY docker/php/entrypoint.sh /usr/local/bin/app-entrypoint
RUN chmod +x /usr/local/bin/app-entrypoint

WORKDIR /var/www/html
COPY --from=vendor /app /var/www/html
RUN rm -f .env bootstrap/cache/*.php \
    && mkdir -p storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 9000
ENTRYPOINT ["app-entrypoint"]
CMD ["php-fpm"]
