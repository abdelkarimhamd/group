#!/bin/sh
# Starts the API image in its role (ROLE), with the configuration cached
# from this container's environment. Any other ROLE runs the command given,
# for example: docker compose run --rm -e ROLE=cli api php artisan about
set -eu

cache() {
    php artisan config:cache --no-interaction
    php artisan event:cache --no-interaction
    php artisan view:cache --no-interaction
}

case "${ROLE:-web}" in
    web)
        cache
        exec frankenphp run --config /etc/frankenphp/Caddyfile
        ;;
    worker)
        cache
        # Each job sets its own tries and backoff. A worker stops after an
        # hour (or after php artisan queue:restart) and is started again.
        # QUEUE_TIMEOUT must stay below REDIS_QUEUE_RETRY_AFTER, or a slow
        # job is handed to a second worker while the first still runs.
        exec php artisan queue:work --sleep=1 --max-time=3600 --timeout="${QUEUE_TIMEOUT:-150}"
        ;;
    scheduler)
        cache
        # Run exactly one. The commands that move money or state are also
        # onOneServer and withoutOverlapping, so a second one does no harm.
        exec php artisan schedule:work
        ;;
    migrate)
        # Run with the owner role's DB_USERNAME/DB_PASSWORD, never the app's.
        php artisan migrate --force --no-interaction
        if [ -n "${APP_DB_ROLE:-}" ]; then
            php artisan db:grant-app-role "$APP_DB_ROLE" --no-interaction
        fi
        # The admin permission catalogue, as the code of this release has it.
        php artisan admin:sync-roles --no-interaction
        ;;
    *)
        exec "$@"
        ;;
esac
