# syntax=docker/dockerfile:1

FROM node:22.21.0-alpine AS frontend-deps
WORKDIR /app/frontend
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci --no-audit --no-fund

FROM frontend-deps AS frontend-test
COPY frontend/ ./
RUN npm test

FROM node:22.21.0-alpine AS browser-test
RUN apk add --no-cache chromium ttf-freefont
WORKDIR /app/tests
ENV E2E_CHROMIUM_EXECUTABLE=/usr/bin/chromium-browser
COPY tests/package.json tests/package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY tests/ ./

FROM frontend-deps AS frontend-build
COPY frontend/ ./
RUN npm run build

FROM composer:2 AS composer-binary

FROM php:8.4-fpm-alpine AS php-base
ARG AMQP_VERSION=2.2.0
RUN apk add --no-cache \
        curl ca-certificates freetype libjpeg-turbo libpng libwebp \
        libzip libpq oniguruma rabbitmq-c su-exec \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS freetype-dev libjpeg-turbo-dev libpng-dev \
        libwebp-dev libzip-dev postgresql-dev rabbitmq-c-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j$(nproc) bcmath gd pcntl pdo_pgsql zip \
    && pecl install amqp-${AMQP_VERSION} \
    && docker-php-ext-enable amqp \
    && apk del .build-deps \
    && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && php -r 'foreach (["bcmath", "curl", "dom", "fileinfo", "gd", "mbstring", "pcntl", "pdo_pgsql", "zip", "amqp"] as $extension) { if (!extension_loaded($extension)) { throw new RuntimeException("Missing extension: ".$extension); } }'
WORKDIR /app/backend
COPY docker/php/app.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php/fpm.conf /usr/local/etc/php-fpm.d/zz-app.conf
COPY docker/php/entrypoint.sh /usr/local/bin/app-entrypoint
RUN chmod +x /usr/local/bin/app-entrypoint
ENTRYPOINT ["app-entrypoint"]
CMD ["php-fpm", "-F"]

FROM php-base AS composer-prod
COPY --from=composer-binary /usr/bin/composer /usr/local/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --no-scripts
COPY backend/ ./
RUN composer dump-autoload --no-dev --classmap-authoritative --no-interaction
RUN php bin/check-runtime.php

FROM php-base AS app
COPY --from=composer-prod --chown=www-data:www-data /app/backend /app/backend
RUN mkdir -p var/cache var/phpstan var/phpunit var/storage/uploads var/storage/images var/storage/sessions \
    && chown -R www-data:www-data var

FROM php-base AS composer-dev
# curl, mbstring, DOM, Fileinfo, OPcache and SQLite are already supplied by the
# official PHP image. Assert the SQLite test driver instead of recompiling it.
RUN php -r 'if (!extension_loaded("pdo_sqlite")) { throw new RuntimeException("Missing SQLite test driver"); }'
COPY --from=composer-binary /usr/bin/composer /usr/local/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1
COPY backend/composer.json backend/composer.lock ./
RUN composer install --prefer-dist --no-interaction --no-progress --no-scripts
COPY --chown=www-data:www-data backend/ ./
RUN composer dump-autoload --optimize --no-interaction \
    && mkdir -p var/cache var/phpstan var/phpunit var/storage/uploads var/storage/images var/storage/sessions \
    && chown -R www-data:www-data /app/backend

FROM composer-dev AS dev-test
CMD ["composer", "test"]

FROM nginx:1.28-alpine AS nginx
RUN apk add --no-cache curl
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=frontend-build /app/frontend/dist/frontend/browser /usr/share/nginx/html
