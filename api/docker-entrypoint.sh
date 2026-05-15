#!/bin/sh
set -e

STORAGE_DIR=/var/www/html/storage
SEED_DB=/var/www/html/storage_seed/ltweb.sqlite
TARGET_DB="$STORAGE_DIR/ltweb.sqlite"
SESSION_DIR=/var/php/sessions

# Ensure session directory exists and has correct ownership
mkdir -p "$SESSION_DIR"
chown -R www-data:www-data "$SESSION_DIR"
chmod 750 "$SESSION_DIR"

# Ensure storage dir exists
mkdir -p "$STORAGE_DIR"
chown -R www-data:www-data "$STORAGE_DIR"

# Seed DB on first run (volume is empty)
if [ ! -f "$TARGET_DB" ] && [ -f "$SEED_DB" ]; then
  echo "[entrypoint] Seeding database from $SEED_DB ..."
  cp "$SEED_DB" "$TARGET_DB"
  chown www-data:www-data "$TARGET_DB"
  chmod 660 "$TARGET_DB"
  echo "[entrypoint] Database seeded."
fi

exec php-fpm
