#!/usr/bin/env bash
set -euo pipefail

APP_DIR="/var/www/html"

to_bool() {
    case "${1:-}" in
        1|true|TRUE|yes|YES|on|ON) return 0 ;;
        *) return 1 ;;
    esac
}

run_with_retry() {
    local attempts="$1"
    local delay="$2"
    shift 2

    local attempt=1
    until "$@"; do
        if [ "$attempt" -ge "$attempts" ]; then
            echo "[startup] Command failed after ${attempts} attempts: $*"
            return 1
        fi

        echo "[startup] Command failed (attempt ${attempt}/${attempts}); retrying in ${delay}s: $*"
        attempt=$((attempt + 1))
        sleep "$delay"
    done
}

cd "$APP_DIR"

if [ ! -f artisan ]; then
    echo "[startup] artisan not found in ${APP_DIR}; skipping migration and seeding."
    exit 0
fi

retry_attempts="${DB_READY_MAX_ATTEMPTS:-30}"
retry_delay="${DB_READY_RETRY_DELAY:-2}"

if to_bool "${RUN_DB_MIGRATIONS:-true}"; then
    echo "[startup] Running database migrations..."
    run_with_retry "$retry_attempts" "$retry_delay" php artisan migrate --force --no-interaction
else
    echo "[startup] RUN_DB_MIGRATIONS disabled; skipping migrations."
fi

if to_bool "${RUN_DB_SEED:-true}"; then
    seeder_class="${SEEDER_CLASS:-Database\\Seeders\\SuperAdminUsersSeeder}"

    if [ -n "$seeder_class" ]; then
        echo "[startup] Running database seeder: ${seeder_class}"
        php artisan db:seed --class="$seeder_class" --force --no-interaction
    else
        echo "[startup] Running default DatabaseSeeder..."
        php artisan db:seed --force --no-interaction
    fi
else
    echo "[startup] RUN_DB_SEED disabled; skipping seeding."
fi
