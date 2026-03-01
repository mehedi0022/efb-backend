FROM serversideup/php:8.2-fpm-nginx
COPY --chown=www-data:www-data . /var/www/html
RUN composer install --no-dev --optimize-autoloader