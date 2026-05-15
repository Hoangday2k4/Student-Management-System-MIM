# ── PHP 8.2 FPM (Alpine) ─────────────────────────────────────────────────────
FROM php:8.2-fpm-alpine

# System dependencies
RUN apk add --no-cache \
    sqlite-dev \
    libzip-dev \
    oniguruma-dev \
    linux-headers

# PHP extensions
RUN docker-php-ext-install \
    pdo \
    pdo_sqlite \
    pdo_mysql \
    mbstring \
    zip \
    exif \
    pcntl

# PHP runtime config
RUN { \
    echo "session.save_path = /var/php/sessions"; \
    echo "session.gc_maxlifetime = 86400"; \
    echo "upload_max_filesize = 20M"; \
    echo "post_max_size = 20M"; \
} > /usr/local/etc/php/conf.d/app.ini

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Install PHP dependencies first (layer cache)
COPY composer.json ./
RUN composer install --no-dev --optimize-autoloader --no-interaction 2>/dev/null || true

# Copy application code
COPY . .

# Move seed DB to a separate path so volume mount doesn't overwrite it
RUN mkdir -p /var/www/html/storage_seed && \
    cp storage/ltweb.sqlite /var/www/html/storage_seed/ltweb.sqlite 2>/dev/null || true

# Create persistent directories
RUN mkdir -p /var/www/html/storage /var/php/sessions && \
    chown -R www-data:www-data /var/www/html /var/php/sessions && \
    chmod +x /var/www/html/docker-entrypoint.sh

EXPOSE 9000

HEALTHCHECK --interval=30s --timeout=5s --start-period=15s --retries=3 \
  CMD php-fpm -t || exit 1

ENTRYPOINT ["/var/www/html/docker-entrypoint.sh"]
