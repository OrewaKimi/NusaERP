#!/bin/bash
set -euo pipefail

DATA_ROOT=/var/lib/nusaerp
PGDATA=${PGDATA:-$DATA_ROOT/postgres}
PG_BIN=$(pg_config --bindir)
DB_USERNAME=${DB_USERNAME:-nusaerp}
DB_PASSWORD=${DB_PASSWORD:-change-this-password}
DB_DATABASE=${DB_DATABASE:-nusaerp}
POSTGRES_PASSWORD=${POSTGRES_PASSWORD:-$DB_PASSWORD}

install -d -m 700 -o postgres -g postgres "$PGDATA"
chown postgres:postgres "$PGDATA"
chmod 700 "$PGDATA"
install -d -o node -g node "$N8N_USER_FOLDER"

if [[ ! -s "$PGDATA/PG_VERSION" ]]; then
    password_file=$(mktemp)
    chown postgres:postgres "$password_file"
    chmod 600 "$password_file"
    printf '%s\n' "$POSTGRES_PASSWORD" > "$password_file"
    runuser -u postgres -- "$PG_BIN/initdb" \
        --pgdata="$PGDATA" \
        --username=postgres \
        --auth-local=peer \
        --auth-host=scram-sha-256 \
        --pwfile="$password_file"
    rm -f "$password_file"
fi

runuser -u postgres -- "$PG_BIN/pg_ctl" \
    --pgdata="$PGDATA" \
    --options='-c listen_addresses=127.0.0.1 -c port=5432' \
    --wait start

runuser -u postgres -- psql --username=postgres --dbname=postgres \
    --set=app_user="$DB_USERNAME" \
    --set=app_password="$DB_PASSWORD" \
    --set=app_db="$DB_DATABASE" <<'SQL'
SELECT format('CREATE ROLE %I LOGIN PASSWORD %L', :'app_user', :'app_password')
WHERE NOT EXISTS (SELECT FROM pg_roles WHERE rolname = :'app_user')
\gexec
SELECT format('ALTER ROLE %I WITH LOGIN PASSWORD %L', :'app_user', :'app_password')
\gexec
SELECT format('CREATE DATABASE %I OWNER %I', :'app_db', :'app_user')
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = :'app_db')
\gexec
SQL

runuser -u postgres -- "$PG_BIN/pg_ctl" --pgdata="$PGDATA" --wait stop

if [[ -z "${APP_KEY:-}" ]]; then
    if [[ -s "$DATA_ROOT/app.key" ]]; then
        APP_KEY=$(<"$DATA_ROOT/app.key")
    else
        APP_KEY=$(php artisan key:generate --show --no-ansi)
        printf '%s' "$APP_KEY" > "$DATA_ROOT/app.key"
        chmod 600 "$DATA_ROOT/app.key"
    fi
    export APP_KEY
fi

export APP_URL=${APP_URL:-http://localhost:8080}
export FRONTEND_URL=${FRONTEND_URL:-http://localhost:8080}
export AUTOMATION_TOKEN=${AUTOMATION_TOKEN:-change-this-automation-token}
export NUSAERP_AUTOMATION_TOKEN=$AUTOMATION_TOKEN
export N8N_ENCRYPTION_KEY=${N8N_ENCRYPTION_KEY:-}

exec /usr/bin/supervisord -n -c /opt/nusaerp/container/supervisord.conf