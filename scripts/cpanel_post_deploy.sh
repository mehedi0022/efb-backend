#!/usr/bin/env bash
set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$PROJECT_ROOT"

echo "[1/5] Removing stale Laravel cache artifacts..."
find bootstrap/cache -maxdepth 1 -type f ! -name '.gitignore' -delete

echo "[2/5] Ensuring writable runtime directories..."
mkdir -p \
  storage/logs \
  storage/framework/cache/data \
  storage/framework/sessions \
  storage/framework/views \
  bootstrap/cache

echo "[3/5] Clearing framework caches..."
if [ -f artisan ]; then
  php artisan optimize:clear || true
fi

echo "[4/5] Fixing permissions for cPanel user..."
chmod -R ug+rwX storage bootstrap/cache

if command -v find >/dev/null 2>&1; then
  find storage bootstrap/cache -type d -exec chmod 775 {} \;
  find storage bootstrap/cache -type f -exec chmod 664 {} \;
fi

echo "[5/5] Done."
echo "Now run: php artisan config:cache"
