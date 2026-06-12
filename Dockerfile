FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts --no-autoloader
COPY . .
RUN composer dump-autoload --optimize

FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY resources ./resources
COPY vite.config.js ./
COPY public ./public
RUN npm run build

FROM php:8.4-cli-alpine
WORKDIR /var/www/html

RUN apk add --no-cache sqlite bash

COPY --from=vendor /app ./
COPY --from=assets /app/public/build ./public/build
COPY docker/start.sh /usr/local/bin/team-status-start
RUN chmod +x /usr/local/bin/team-status-start \
    && mkdir -p /data storage bootstrap/cache \
    && chown -R www-data:www-data /data storage bootstrap/cache

USER www-data
EXPOSE 8000
ENTRYPOINT ["team-status-start"]
