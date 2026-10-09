#!/usr/bin/env bash
# Builds an upload-ready ZIP for shared hosting without terminal access.
# The ZIP contains the vendor/ folder, so the server needs neither Composer nor Node.js.
# Usage: deploy/build-release.sh [output.zip]
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="${1:-$ROOT/dice-rewards-release.zip}"
case "$OUT" in /*) ;; *) OUT="$PWD/$OUT" ;; esac
BUILD="$(mktemp -d)"
trap 'rm -rf "$BUILD"' EXIT

echo "→ Copying project"
rsync -a \
    --exclude='.git' --exclude='.github' --exclude='node_modules' --exclude='vendor' \
    --exclude='.env' --exclude='.env.backup*' --exclude='tests' --exclude='*.zip' \
    --exclude='storage/installed.lock' --exclude='storage/logs/*.log' \
    --exclude='storage/framework/cache/data/*' --exclude='storage/framework/sessions/*' \
    --exclude='storage/framework/views/*.php' --exclude='storage/framework/testing' \
    --exclude='bootstrap/cache/*.php' --exclude='database/*.sqlite' --exclude='phpunit.xml' \
    --exclude='.phpunit.result.cache' --exclude='public/hot' \
    "$ROOT/" "$BUILD/app/"

echo "→ Installing production dependencies"
(cd "$BUILD/app" && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist --no-progress --quiet)
# Packages installed from source carry their own .git folders: not needed on the server.
find "$BUILD/app/vendor" -name '.git' -type d -prune -exec rm -rf {} +

# Front-end assets are plain CSS/JS in public/assets: nothing to compile.

echo "→ Creating $OUT"
rm -f "$OUT"
(cd "$BUILD/app" && zip -qr "$OUT" . -x '*.DS_Store')
echo "Done: $OUT ($(du -h "$OUT" | cut -f1))"
echo "Upload it, extract it, then open https://your-domain/install.php"
