# syntax=docker/dockerfile:1
# Multi-stage: base -> dev (Composer, git) | builder -> prod (kód bez dev závislostí).
# Debian 13 (trixie) = stejná distribuce jako VPS.

ARG PHP_VERSION=8.4

# ---------------------------------------------------------------- base
FROM php:${PHP_VERSION}-fpm-trixie AS base

ARG HOST_UID=1000
ARG HOST_GID=1000

RUN docker-php-ext-install -j"$(nproc)" pdo_mysql opcache \
    && groupadd --gid "${HOST_GID}" app \
    && useradd --uid "${HOST_UID}" --gid "${HOST_GID}" --create-home --shell /usr/sbin/nologin app \
    && mkdir -p /app \
    && chown app:app /app \
    && printf '[www]\nclear_env = no\ncatch_workers_output = yes\ndecorate_workers_output = no\n' \
        > /usr/local/etc/php-fpm.d/zz-app.conf

COPY docker/php/conf.d/app.ini /usr/local/etc/php/conf.d/zz-app.ini

WORKDIR /app
USER app
EXPOSE 9000

# ---------------------------------------------------------------- dev
FROM base AS dev

USER root
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/* \
    && cp "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini" \
    && mkdir -p /composer-cache \
    && chown app:app /composer-cache
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# Cache Composeru na pojmenovaném volume (adresář vlastní app, nový volume zdědí vlastníka).
ENV COMPOSER_CACHE_DIR=/composer-cache \
    COMPOSER_HOME=/tmp/composer
USER app

# ---------------------------------------------------------------- builder (jen pro prod)
FROM dev AS builder

WORKDIR /build
COPY --chown=app:app composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist
COPY --chown=app:app . .
RUN mkdir -p src public \
    && composer dump-autoload --no-dev --classmap-authoritative --no-interaction

# ---------------------------------------------------------------- prod
FROM base AS prod

USER root
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && printf 'opcache.validate_timestamps=0\nopcache.enable=1\n' \
        > /usr/local/etc/php/conf.d/zz-prod.ini
USER app

COPY --from=builder --chown=app:app /build/vendor /app/vendor
COPY --from=builder --chown=app:app /build/src /app/src
COPY --from=builder --chown=app:app /build/public /app/public
