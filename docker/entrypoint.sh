#!/bin/sh
# Arranque del contenedor de producción (FrankenPHP).
# - APP_KEY: si no viene por variable de entorno, se genera una vez y se guarda
#   en el volumen de storage para que sobreviva a reinicios y actualizaciones.
# - Espera a MySQL, corre migraciones, siembra proveedores en una instalación
#   nueva y cachea config/rutas/vistas.
set -e
cd /app

mkdir -p storage/app storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache

if [ -z "${APP_KEY}" ]; then
    if [ ! -s storage/app/.app_key ]; then
        php -r 'echo "base64:".base64_encode(random_bytes(32));' > storage/app/.app_key
        echo "[pricetrack] APP_KEY generada y guardada en storage/app/.app_key"
    fi
    APP_KEY="$(cat storage/app/.app_key)"
    export APP_KEY
fi

if [ "${DB_CONNECTION:-mysql}" = "mysql" ]; then
    i=0
    until php -r 'try { new PDO("mysql:host=".getenv("DB_HOST").";port=".(getenv("DB_PORT") ?: 3306), getenv("DB_USERNAME"), getenv("DB_PASSWORD")); } catch (Throwable $e) { exit(1); }'; do
        i=$((i + 1))
        if [ "$i" -ge 60 ]; then
            echo "[pricetrack] MySQL no respondió a tiempo" >&2
            exit 1
        fi
        echo "[pricetrack] Esperando MySQL ($i)…"
        sleep 2
    done
fi

php artisan migrate --force
php artisan pricetrack:install
php artisan config:cache
php artisan route:cache
php artisan view:cache

exec docker-php-entrypoint "$@"
