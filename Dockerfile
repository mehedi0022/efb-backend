FROM serversideup/php:8.2-fpm-nginx

WORKDIR /var/www/html

COPY --chown=www-data:www-data . /var/www/html
COPY --chmod=755 docker/entrypoint.d/10-laravel-migrate-seed.sh /etc/entrypoint.d/10-laravel-migrate-seed.sh

RUN composer install --no-dev --optimize-autoloader --no-interaction
