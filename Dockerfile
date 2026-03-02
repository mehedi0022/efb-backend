FROM serversideup/php:8.2-fpm-nginx

WORKDIR /var/www/html

COPY --chown=www-data:www-data . /var/www/html
COPY docker/entrypoint.d/ /etc/entrypoint.d/

RUN chmod +x /etc/entrypoint.d/*.sh \
    && composer install --no-dev --optimize-autoloader --no-interaction
