#!/bin/bash
# Production deploy script — run on server inside /var/www/html/mimsms
set -euo pipefail

DEPLOY_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$DEPLOY_DIR"

echo "==> [1/4] Pulling latest code..."
git pull origin main

# Copy .htaccess vào thư mục web
cp docker/htaccess-mimsms /var/www/html/mimsms/.htaccess

echo "==> [2/4] Building images..."
docker compose build

echo "==> [3/4] Restarting containers..."
docker compose down --remove-orphans
docker compose up -d

echo "==> [4/4] Pruning dangling images..."
docker image prune -f

echo ""
echo "Deploy complete. Container status:"
docker compose ps
