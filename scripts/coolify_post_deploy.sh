#!/usr/bin/env bash
set -euo pipefail

APP_DIR="/var/www/html"
cd "$APP_DIR"

if [ ! -f artisan ]; then
    echo "[post-deploy] artisan not found in ${APP_DIR}"
    exit 1
fi

echo "[post-deploy] Running migrations..."
php artisan migrate --force --no-interaction

if [ "${RUN_DB_SEED:-true}" = "true" ] || [ "${RUN_DB_SEED:-true}" = "1" ]; then
    SEEDER_CLASS_VALUE="${SEEDER_CLASS:-Database\\Seeders\\SuperAdminUsersSeeder}"

    if [ -n "$SEEDER_CLASS_VALUE" ]; then
        echo "[post-deploy] Running seeder: ${SEEDER_CLASS_VALUE}"
        php artisan db:seed --class="$SEEDER_CLASS_VALUE" --force --no-interaction
    else
        echo "[post-deploy] Running default DatabaseSeeder..."
        php artisan db:seed --force --no-interaction
    fi
else
    echo "[post-deploy] RUN_DB_SEED disabled; skipping seeding."
fi

echo "[post-deploy] Completed."
