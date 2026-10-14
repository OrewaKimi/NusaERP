#!/bin/bash
set -euo pipefail

until pg_isready --host=127.0.0.1 --port=5432 --username="$DB_USERNAME" --dbname="$DB_DATABASE" >/dev/null 2>&1; do
    sleep 1
done

cd /opt/nusaerp/backend
php artisan migrate --force

if [[ ! -f /var/lib/nusaerp/.seeded ]]; then
    php artisan db:seed --force
    touch /var/lib/nusaerp/.seeded
fi

exec php artisan serve --host=127.0.0.1 --port=8000