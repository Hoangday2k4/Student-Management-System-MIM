#!/bin/bash
# Production deploy script — run on server inside /var/www/html/mimsms
set -euo pipefail

DEPLOY_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$DEPLOY_DIR"

# Copy .htaccess vào thư mục web
cp docker/htaccess-mimsms /var/www/html/mimsms/.htaccess

echo "==> [1/3] Building images..."
docker compose build

echo "==> [2/3] Restarting containers..."
docker compose down --remove-orphans
docker compose up -d

echo "==> [3/3] Pruning dangling images..."
docker image prune -f

echo ""
echo "Deploy complete. Container status:"
docker compose ps
