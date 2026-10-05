# syntax=docker/dockerfile:1
FROM php:8.4-fpm-alpine AS base
RUN apk add --no-cache nginx bash tini curl icu-libs libzip libpq sqlite-libs oniguruma \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev libzip-dev postgresql-dev sqlite-dev oniguruma-dev \
    && docker-php-ext-install -j"$(nproc)" bcmath intl mbstring opcache pcntl pdo_pgsql pdo_mysql pdo_sqlite zip \
    && apk del .build-deps
WORKDIR /app
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
FROM base AS vendor
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
RUN composer check-platform-reqs --no-dev
FROM base AS app
COPY backend/ ./
COPY --from=vendor /app/vendor ./vendor
COPY scripts/ /app/infra/
RUN mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && sed -i 's/\r$//' infra/*.sh \
    && composer dump-autoload --optimize --no-dev --no-scripts --classmap-authoritative \
    && APP_ENV=local php artisan package:discover --ansi \
    && chown -R www-data:www-data storage bootstrap/cache \
    && cp infra/php.ini /usr/local/etc/php/conf.d/classlink.ini \
    && cp infra/fpm.conf /usr/local/etc/php-fpm.d/zz-classlink.conf
USER www-data
EXPOSE 8000
HEALTHCHECK --interval=30s --timeout=10s --start-period=90s --retries=3 \
    CMD curl --fail --silent --max-time 5 http://127.0.0.1:8000/up && php infra/operations.php health
ENTRYPOINT ["/sbin/tini", "-g", "--", "bash", "/app/infra/runtime.sh"]
