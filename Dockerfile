FROM serversideup/php:8.2-fpm-nginx
COPY --chown=www-data:www-data . /var/www/html
RUN composer install --no-dev --optimize-autoloader
```

**Step 2** — In Coolify → your Laravel service → **Post-deployment commands** add:
```
php artisan migrate --force && php artisan storage:link && php artisan config:cache && php artisan route:cache && php artisan view:cache