# syntax=docker/dockerfile:1

# Image of the application: PHP-FPM with the code and the production dependencies.
# The web server is a separate container (see compose.yaml and docker/apache/httpd.conf).

# 1. Production dependencies only, no development tools
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-progress
COPY src ./src
RUN composer dump-autoload --no-dev --classmap-authoritative --no-scripts

# 2. The application
FROM php:8.4-fpm

RUN docker-php-ext-install opcache

COPY docker/php/analytics.ini /usr/local/etc/php/conf.d/zz-analytics.ini

WORKDIR /var/www/html
COPY --from=vendor /app/vendor ./vendor
COPY bin ./bin
COPY config ./config
COPY migrations ./migrations
COPY public ./public
COPY src ./src
COPY composer.json LICENSE ./

# The application loads a .env file at start and stops if there is none. In the container the settings
# are real environment variables, which win over the file, so it can stay empty and keeps no secret.
# var/ is a volume in compose.yaml: API keys, logs and the files of the collector.
RUN touch .env \
    && mkdir -p var/cache var/logs var/keys var/collector/smil/upload \
    && chown -R www-data:www-data var \
    && chmod 700 var/keys
