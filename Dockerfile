# syntax=docker/dockerfile:1
#
# Imagen de producción de PriceTrack: FrankenPHP (PHP 8.5 + Caddy) sirviendo /app/public.
# Uso: docker compose -f docker-compose.prod.yml up -d --build

ARG PHP_IMAGE=dunglas/frankenphp:1-php8.5

# ---------- Base con extensiones ----------
FROM ${PHP_IMAGE} AS base
RUN install-php-extensions pdo_mysql intl zip opcache
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app

# ---------- Dependencias PHP ----------
FROM base AS vendor
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress

# ---------- Assets (Vite + Tailwind) ----------
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY vite.config.js ./
COPY resources ./resources
# Tailwind también escanea las vistas de paginación de Laravel
COPY --from=vendor /app/vendor/laravel/framework/src/Illuminate/Pagination/resources/views ./vendor/laravel/framework/src/Illuminate/Pagination/resources/views
RUN npm run build

# ---------- Aplicación ----------
FROM base AS app
ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    SERVER_NAME=:80 \
    PHP_INI_SCAN_DIR=":$PHP_INI_DIR/app.conf.d"

COPY docker/php.ini $PHP_INI_DIR/app.conf.d/pricetrack.ini
COPY --from=vendor /app/vendor ./vendor
COPY . .
COPY --from=assets /app/public/build ./public/build

RUN composer dump-autoload --optimize --no-dev --no-interaction \
    && php artisan package:discover --ansi \
    && chmod +x docker/entrypoint.sh \
    && rm -f /usr/bin/composer

EXPOSE 80
VOLUME ["/app/storage"]

HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=3 \
    CMD php -r 'exit(@file_get_contents("http://127.0.0.1/up") === false ? 1 : 0);'

ENTRYPOINT ["/app/docker/entrypoint.sh"]
CMD ["--config", "/etc/frankenphp/Caddyfile", "--adapter", "caddyfile"]
