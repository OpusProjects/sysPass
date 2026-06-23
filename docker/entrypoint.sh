#!/bin/bash
# Prepare the sysPass runtime on container start.
set -e

cd /var/www/html

# Install PHP dependencies if the vendor tree is missing (first run, or the
# repo is bind-mounted without one). --no-dev keeps the dev/test stack
# (and roave/security-advisories) out of the runtime image.
if [ ! -f vendor/autoload.php ]; then
    echo "[entrypoint] vendor/ not found — running composer install..."
    # syspass.ini sets auto_prepend_file=vendor/autoload.php for web requests, but that
    # file doesn't exist yet on first run, so composer itself would fatal before it could
    # build vendor/. Disable the prepend for this one bootstrap invocation.
    COMPOSER_ALLOW_SUPERUSER=1 php -d auto_prepend_file= /usr/bin/composer install --no-interaction --no-progress --prefer-dist --no-dev
fi

# src/Base.php loads a .env via Dotenv::createImmutable()->load(), which throws if the
# file is absent. The .env.example keys are all optional (commented), so a dev .env with
# DEBUG enabled is enough.
if [ ! -f .env ]; then
    echo "DEBUG=true" > .env
fi

# sysPass needs these writable at runtime (config.xml, caches, proxies, backups).
for dir in config var/cache var/temp var/backup; do
    mkdir -p "$dir"
    chown -R www-data:www-data "$dir"
done
chmod 750 config

exec "$@"
