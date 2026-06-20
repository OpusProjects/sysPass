#!/bin/bash
# Prepare the sysPass runtime on container start.
set -e

cd /var/www/html

# Install PHP dependencies if the vendor tree is missing (first run, or the
# repo is bind-mounted without one). --no-dev keeps the legacy test stack
# (and roave/security-advisories) out of the runtime image.
if [ ! -f vendor/autoload.php ]; then
    echo "[entrypoint] vendor/ not found — running composer install..."
    composer install --no-interaction --no-progress --prefer-dist --no-dev
fi

# lib/Base.php loads a .env via Dotenv::createImmutable()->load(), which throws if the
# file is absent. The .env.example keys are all optional (commented), so a dev .env with
# DEBUG enabled is enough.
if [ ! -f .env ]; then
    echo "DEBUG=true" > .env
fi

# sysPass needs these writable at runtime (config.xml, caches, proxies, backups).
for dir in app/config app/cache app/temp app/backup; do
    mkdir -p "$dir"
    chown -R www-data:www-data "$dir"
done
chmod 750 app/config

exec "$@"
