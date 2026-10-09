#!/bin/sh
set -eu
umask 007

mkdir -p /app/backend/var/cache \
    /app/backend/var/phpstan \
    /app/backend/var/phpunit \
    /app/backend/var/storage/uploads \
    /app/backend/var/storage/images \
    /app/backend/var/storage/sessions

if [ "$(id -u)" = 0 ]; then
    chown -R www-data:www-data /app/backend/var
    # The FPM supervisor opens container logging descriptors as root; HTTP pool workers stay www-data.
    if [ "$1" = "php-fpm" ]; then
        exec "$@"
    fi
    exec su-exec www-data "$@"
fi

exec "$@"
