#!/usr/bin/env sh
set -eu

if [ "${DB_CONNECTION:-sqlite}" = "sqlite" ]; then
    mkdir -p "$(dirname "${DB_DATABASE:-/data/database.sqlite}")"
    touch "${DB_DATABASE:-/data/database.sqlite}"
fi

if [ -z "${APP_KEY:-}" ]; then
    export APP_KEY="$(php artisan key:generate --show --no-interaction)"
fi

php artisan config:clear --no-interaction
php artisan migrate --force --no-interaction
php artisan db:seed --force --no-interaction

exec php artisan serve --host=0.0.0.0 --port=8000
