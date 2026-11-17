#!/bin/sh
# Everything that has to happen between the image being built and the
# application answering a request.
#
# The image is built once and may run against any database; these steps
# depend on the environment it is actually started in, so they belong here
# rather than in the Dockerfile.

set -e

cd /var/www/html

# ------------------------------------------------------------- the key
# Generated if the environment has not supplied one. A key that changes
# between restarts invalidates every session and makes every encrypted
# column unreadable, so in production APP_KEY belongs in the environment
# and this is only a convenience for a first local run.
if [ -z "${APP_KEY:-}" ] && ! grep -qs '^APP_KEY=base64:' .env 2>/dev/null; then
    echo "[boot] no APP_KEY supplied; generating one for this container"
    php artisan key:generate --force --no-interaction || true
fi

# ------------------------------------------------------- wait for MySQL
# The database container accepts connections some seconds after it starts
# listening. Without this the first migration races it and the container
# dies on a refused connection, which reads like a configuration error.
if [ -n "${DB_HOST:-}" ]; then
    echo "[boot] waiting for ${DB_HOST}:${DB_PORT:-3306}"

    i=0
    until php -r "
        \$h = getenv('DB_HOST'); \$p = getenv('DB_PORT') ?: 3306;
        \$c = @fsockopen(\$h, (int) \$p, \$e, \$s, 2);
        exit(\$c ? 0 : 1);
    " 2>/dev/null; do
        i=$((i + 1))

        if [ "$i" -ge 60 ]; then
            echo "[boot] database did not answer after 60 tries; giving up" >&2
            exit 1
        fi

        sleep 2
    done

    echo "[boot] database is up"
fi

# -------------------------------------------------------------- schema
# --force because there is no terminal here to confirm at. Migrations run
# on every boot and are no-ops once applied, which is what makes a deploy
# a deploy rather than a deploy plus a remembered manual step.
if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
    echo "[boot] running migrations"
    php artisan migrate --force --no-interaction
fi

# -------------------------------------------------------- public files
# The symlink lives in public/, which is copied into the image fresh each
# build, so it has to be made again each boot.
php artisan storage:link --no-interaction 2>/dev/null || true

# -------------------------------------------------------------- caches
# The directories first. The image makes them, but a volume mounted over
# storage/ would hide them again, and `view:clear` does not create what it
# is pointed at -- it stops with "View path not found" and takes the boot
# with it.
mkdir -p \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    storage/app/private \
    storage/app/public \
    bootstrap/cache

# Cleared first: a cache built during `docker build` would have baked in
# whatever .env existed on the machine that built the image.
php artisan config:clear --no-interaction
php artisan route:clear --no-interaction
php artisan view:clear --no-interaction

php artisan config:cache --no-interaction
php artisan route:cache --no-interaction
php artisan view:cache --no-interaction

# --------------------------------------------------------- permissions
# The storage volume is mounted from the host, which owns it as root; the
# web user has to be able to write to it or every upload fails with a
# permission error that names no cause.
chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true

echo "[boot] ready"

exec "$@"
