# The API in one image, run in four roles (deploy/api/entrypoint.sh):
#   ROLE=web        FrankenPHP serving public/ on port 8000
#   ROLE=worker     php artisan queue:work
#   ROLE=scheduler  php artisan schedule:work (exactly one)
#   ROLE=migrate    migrations as the owner role, then the app role's grants
# Build from the repository root: docker build -f apps/api/Dockerfile .

FROM dunglas/frankenphp:1-php8.3-bookworm AS base

RUN install-php-extensions pdo_pgsql redis pcntl intl gd exif opcache \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY deploy/api/php.ini $PHP_INI_DIR/conf.d/zz-groupbuy.ini

WORKDIR /app

FROM base AS vendor

RUN apt-get update && apt-get install -y --no-install-recommends unzip && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY apps/api/composer.json apps/api/composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts --no-autoloader

COPY apps/api/ ./
# storage/ is not copied (local files and logs); the final stage makes its folders.
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative \
    && mkdir -p storage/framework/cache storage/framework/views storage/logs bootstrap/cache \
    && php artisan package:discover --ansi

FROM base

ENV SERVER_NAME=:8000 \
    APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr

COPY --from=vendor /app /app
COPY deploy/api/entrypoint.sh /usr/local/bin/groupbuy-entrypoint

RUN useradd --uid 1000 --create-home app \
    && mkdir -p storage/app storage/logs storage/framework/cache/mpdf storage/framework/sessions storage/framework/views bootstrap/cache \
    && chown -R app:app storage bootstrap/cache /data/caddy /config/caddy \
    && chmod +x /usr/local/bin/groupbuy-entrypoint

USER app

EXPOSE 8000

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s CMD ["sh", "-c", "[ \"${ROLE:-web}\" != web ] || php -r 'exit(@file_get_contents(\"http://127.0.0.1:8000/up\") === false ? 1 : 0);'"]

ENTRYPOINT ["groupbuy-entrypoint"]
