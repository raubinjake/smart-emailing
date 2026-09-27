#!/bin/sh
# One image, two roles. `web` serves the app; `worker` drains the queue.
# Railway starts each as its own service with the same image.
set -e

role="${1:-web}"

# Laravel refuses to boot without APP_KEY, and a missing one is the single most
# common first-deploy failure. Fail loudly here rather than with a 500 later.
if [ -z "${APP_KEY:-}" ]; then
    echo "FATAL: APP_KEY is not set." >&2
    echo "Generate one locally with:  php artisan key:generate --show" >&2
    echo "then add it to this service's variables." >&2
    exit 1
fi

# Config, route and view caches are built at boot rather than in the image, so
# they pick up the environment variables this service was actually given.
php artisan config:cache
php artisan route:cache
php artisan view:cache

case "$role" in
    web)
        # Migrations run from the web role only. Running them from both would
        # have two processes racing the same schema on every deploy.
        echo "Running migrations..."
        php artisan migrate --force

        # Railway assigns the port at runtime.
        port="${PORT:-8080}"
        sed -i "s/__PORT__/${port}/" /etc/nginx/nginx.conf
        echo "Serving on ${port}"

        exec supervisord -c /etc/supervisord.conf
        ;;

    worker)
        # Without this process nothing is ever delivered.
        echo "Starting queue worker..."
        exec php artisan queue:work \
            --tries=3 \
            --timeout=90 \
            --sleep=3 \
            --max-jobs=1000 \
            --max-time=3600
        ;;

    *)
        # Anything else is run verbatim, which makes `php artisan tinker` and
        # one-off commands possible without a separate image.
        exec "$@"
        ;;
esac
