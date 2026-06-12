#!/usr/bin/env sh
set -eu

if [ "${DB_CONNECTION:-sqlite}" = "mysql" ]; then
    echo "Waiting for database connection..."
    until php -r 'try { new PDO("mysql:host=" . getenv("DB_HOST") . ";port=" . getenv("DB_PORT") . ";dbname=" . getenv("DB_DATABASE"), getenv("DB_USERNAME"), getenv("DB_PASSWORD")); exit(0); } catch (\Exception $e) { exit(1); }'; do
        sleep 1
    done
    echo "Database is ready!"
fi

if [ -z "${APP_KEY:-}" ]; then
    export APP_KEY="$(php artisan key:generate --show --no-interaction)"
fi

php artisan config:clear --no-interaction
php artisan migrate --force --no-interaction
php artisan db:seed --force --no-interaction

exec php artisan serve --host=0.0.0.0 --port=8000
