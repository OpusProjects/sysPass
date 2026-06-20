# Installation

The original online documentation (`doc.syspass.org`) is offline. This guide is maintained
in-tree for this fork's **PHP 8.4–8.5** codebase.

## Requirements

- **PHP 8.4 or 8.5** with extensions: `pdo_mysql`, `gd`, `gettext`, `mbstring`, `intl`,
  `dom` / `xml`, `json`, `curl`, `fileinfo`, `zlib`, and `ldap` (LDAP / Active Directory auth).
- **MariaDB ≥ 10.1** (or MySQL).
- A **web server** (Apache or Nginx); SSL strongly recommended.
- **[Composer](https://getcomposer.org/)** to install the PHP dependencies.

## Option A — Docker (recommended for development)

This fork ships a one-command development stack (**PHP 8.5** + Apache + MariaDB). See the
[`docker/`](../docker) directory and [`docker-compose.yml`](../docker-compose.yml).

```bash
docker compose up --build -d
```

- The app is served on <http://localhost:8090>; MariaDB is reachable as host `db`
  (user `root`, password `syspass`, database `syspass`).
- The image installs the PHP extensions, prepends the Composer autoloader
  (`auto_prepend_file`), and the entrypoint runs `composer install` and writes a dev `.env`.

> **Note:** the web UI installer flow of the hexagonal rewrite is still being completed
> (the web entry point was never exercised by upstream's CI). The stack builds, installs the
> PHP dependencies, and runs the full test suites; finishing the browser installer is
> tracked separately.

## Option B — Manual installation

The procedure is the same on any Linux distribution; only the package names, the web-root path,
and the web-server user differ.

1. **Install the dependencies** — a web server, **PHP 8.4–8.5** with the extensions listed under
   [Requirements](#requirements), MariaDB, and Composer:

   - **Debian / Ubuntu:**
     ```bash
     apt install apache2 libapache2-mod-php php php-mysql php-gd php-curl php-json \
       php-xml php-mbstring php-intl php-gettext php-ldap mariadb-server composer
     ```
   - **RHEL / CentOS / Rocky / AlmaLinux:**
     ```bash
     dnf install httpd php php-mysqlnd php-gd php-curl php-json php-xml \
       php-mbstring php-intl php-gettext php-ldap mariadb-server composer
     ```
   - **Arch Linux:**
     ```bash
     pacman -S apache php php-gd php-intl php-ldap mariadb composer
     ```

   For Nginx, use PHP-FPM instead of the Apache PHP module.

2. Place the sysPass source under the web root — e.g. `/var/www/html/syspass`.
3. Make the runtime directories writable by the web-server user (`www-data` on Debian/Ubuntu,
   `apache` on RHEL-family, `http` on Arch):
   ```bash
   chown -R www-data:www-data app/config app/backup app/cache app/temp
   ```
4. Install the PHP dependencies and create a `.env`:
   ```bash
   composer install --no-dev
   cp .env.example .env
   ```
5. Start the database and web server and complete the web installer.

## Running the test suite

The suite is PHPUnit 11; the integration tests are database-backed. Using the Docker stack:

```bash
# Install dependencies (including dev) into the container
docker compose exec app composer install

# Unit suite (no DB needed)
docker compose exec -w /var/www/html app \
  vendor/bin/phpunit -c tests/phpunit.xml --group unitary --testsuite core --no-coverage

# Integration suite — seed the schema into DB `syspass`, then run the integration group
docker compose exec -T db mariadb -uroot -psyspass syspass < schemas/dbstructure.sql
docker compose exec -e DB_SERVER=db -e DB_NAME=syspass -e DB_USER=root -e DB_PASS=syspass -e DB_PORT=3306 \
  -w /var/www/html app vendor/bin/phpunit -c tests/phpunit.xml --group integration --no-coverage
```

Both suites pass: **1979 unit tests** and **93 integration tests**.
