#!/usr/bin/env bash
# Builds an upload-ready zip for shared hosting (no Composer/Node needed on the server).
# Usage: deploy/build-release.sh [output.zip]
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="${1:-$ROOT/dice-release-$(date +%Y%m%d-%H%M).zip}"
BUILD="$(mktemp -d)"
trap 'rm -rf "$BUILD"' EXIT

echo "→ Copying project"
rsync -a --exclude='.git' --exclude='node_modules' --exclude='vendor' --exclude='.env' \
      --exclude='storage/logs/*' --exclude='storage/framework/*/*' --exclude='database/*.sqlite' \
      --exclude='tests' --exclude='*.zip' "$ROOT/" "$BUILD/app/"

echo "→ Installing production dependencies"
(cd "$BUILD/app" && composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist --quiet)

# Front-end assets are plain CSS/JS in public/assets: nothing to compile.

echo "→ Creating $OUT"
(cd "$BUILD/app" && zip -qr "$OUT" . -x '*.DS_Store')
echo "Done. Upload and follow docs/DEPLOYMENT.md."
