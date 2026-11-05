# Laravel Reverb, the WebSocket server for live campaign progress.
# Build from the repository root: docker build -f apps/realtime/Dockerfile .

FROM php:8.3-cli-bookworm AS base

# ext-uv gives Reverb an event loop without stream_select's ceiling of 1,024
# open files (FD_SETSIZE), so it can hold more than about 1,000 connections.
COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pcntl redis uv \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

WORKDIR /app

FROM base AS vendor

RUN apt-get update && apt-get install -y --no-install-recommends unzip && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY apps/realtime/composer.json apps/realtime/composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts --no-autoloader
COPY apps/realtime/ ./
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative \
    && mkdir -p storage/framework/cache storage/logs bootstrap/cache \
    && php artisan package:discover --ansi

FROM base

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    REVERB_SERVER_HOST=0.0.0.0 \
    REVERB_SERVER_PORT=8080

COPY --from=vendor /app /app

# Fail the build if Reverb would fall back to stream_select.
RUN php -r 'require "vendor/autoload.php"; exit(React\EventLoop\Loop::get() instanceof React\EventLoop\ExtUvLoop ? 0 : 1);'

RUN useradd --uid 1000 --create-home app \
    && mkdir -p storage/framework/cache storage/logs bootstrap/cache \
    && chown -R app:app storage bootstrap/cache

USER app

EXPOSE 8080

# Each open page holds a connection: raise the open-file limit where it runs
# (compose: ulimits.nofile). With ext-uv that limit is the only ceiling.
CMD ["sh", "-c", "php artisan config:cache --no-interaction && exec php artisan reverb:start --no-interaction"]
