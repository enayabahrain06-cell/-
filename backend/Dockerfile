# Laravel API (php-fpm). The same image runs the app, the queue worker and the scheduler.
FROM composer:2 AS vendor
WORKDIR /app
# composer.json's path repository ../packages/certificates; mirrored (not symlinked) so vendor/ is self-contained.
COPY --from=packages certificates /packages/certificates
ENV COMPOSER_MIRROR_PATH_REPOS=1
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress --ignore-platform-reqs
COPY . .
RUN composer dump-autoload --optimize --no-dev --classmap-authoritative --ignore-platform-reqs

FROM php:8.3-fpm-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends \
        libicu-dev libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev libwebp-dev libpq-dev libsqlite3-dev \
        fonts-noto-core unzip default-mysql-client postgresql-client \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql pdo_pgsql pdo_sqlite gd intl zip bcmath exif opcache pcntl \
    && rm -rf /var/lib/apt/lists/*
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-ahl.ini
WORKDIR /var/www/html
COPY --from=vendor /app /var/www/html
RUN php artisan package:discover --ansi && chown -R www-data:www-data storage bootstrap/cache
COPY docker/entrypoint.sh /usr/local/bin/ahl-entrypoint
RUN chmod +x /usr/local/bin/ahl-entrypoint
USER www-data
ENTRYPOINT ["ahl-entrypoint"]
CMD ["php-fpm"]
