#!/bin/bash
# Production deploy script — run on server inside /var/www/html/mimsms
set -euo pipefail

DEPLOY_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$DEPLOY_DIR"

echo "==> [1/5] Pulling latest code..."
git pull origin main

# Copy .htaccess vào thư mục web
cp docker/htaccess-mimsms /var/www/html/mimsms/.htaccess

echo "==> [2/5] Stopping containers..."
docker compose down --remove-orphans

echo "==> [3/5] Building images (no cache)..."
docker compose build --no-cache

echo "==> [4/5] Starting containers..."
docker compose up -d

echo "==> [5/5] Pruning dangling images..."
docker image prune -f

echo ""
echo "Deploy complete. Container status:"
docker compose ps
