# Installation

The original online documentation (`doc.syspass.org`) is offline. This guide is maintained
in-tree: it consolidates the still-relevant upstream installation instructions with this fork's
verified Docker development setup.

> ⚠️ This is the legacy **3.2.x** baseline. It targets **PHP 7.4** (end-of-life); raising the PHP
> version and refreshing dependencies is the goal of this fork. Treat it as development-only until
> the modernization work lands.

## Requirements

- **PHP 7.4** with extensions: `pdo_mysql`, `gd`, `gettext`, `mbstring`, `dom` / `xml`, `json`,
  `curl`, `fileinfo`, `zlib`. Optional: `ldap` (LDAP / Active Directory auth) and `mcrypt`
  (only needed to import pre-3.0 XML exports).
- **MariaDB ≥ 10.1** (or MySQL).
- A **web server** (Apache or Nginx); SSL strongly recommended.
- **[Composer](https://getcomposer.org/)** to install the PHP dependencies.

## Option A — Docker (recommended for development)

This fork ships a one-command development stack (PHP 7.4 + Apache + MariaDB). See the
[`docker/`](../docker) directory and [`docker-compose.yml`](../docker-compose.yml).

```bash
docker compose up --build -d
```

- Web UI: <http://localhost:8090> — the first request redirects to the installer.
- Complete the installer **in a browser** (the password fields are encrypted client-side, so it
  can't be scripted with curl). Use these database settings:

  | Field | Value |
  |---|---|
  | Database host | `db` |
  | Database user | `root` |
  | Database password | `syspass` |
  | Database name | `syspass` |

- The installer also asks for an **admin account** and a **master password** (used to encrypt the
  stored secrets — don't lose it).

## Option B — Manual installation

The procedure is the same on any Linux distribution; only the package names, the web-root path,
and the web-server user differ.

1. **Install the dependencies** — a web server, PHP 7.4 with the extensions listed under
   [Requirements](#requirements), MariaDB, and Composer:

   - **Debian / Ubuntu:**
     ```bash
     apt install apache2 libapache2-mod-php php php-mysql php-gd php-curl \
       php-json php-xml php-mbstring php-gettext php-ldap mariadb-server composer
     ```
   - **RHEL / CentOS / Rocky / AlmaLinux:**
     ```bash
     dnf install httpd php php-mysqlnd php-gd php-curl php-json php-xml \
       php-mbstring php-gettext php-ldap mariadb-server composer
     ```
   - **Arch Linux:**
     ```bash
     pacman -S apache php php-gd php-ldap mariadb composer
     ```

   For Nginx, use PHP-FPM instead of the Apache PHP module. Make sure `pdo_mysql`, `gd`,
   `mbstring`, `gettext`, `curl`, `xml` and `json` are enabled (plus optional `ldap` / `mcrypt`).

2. Place the sysPass source under the web root — e.g. `/var/www/html/syspass`
   (`/srv/http/syspass` on Arch).
3. Make the runtime directories writable by the web-server user — `www-data` on Debian/Ubuntu,
   `apache` on RHEL-family, `http` on Arch:
   ```bash
   chown -R www-data:www-data app/config app/backup
   ```
4. Install the PHP dependencies (production only):
   ```bash
   composer install --no-dev
   ```
5. Start the database and web server, then browse to `https://<server>/syspass/index.php` and
   complete the web installer (database connection, admin account, master password).

## Running the test suite

The legacy PHPUnit suite is database-backed. Using the Docker stack:

```bash
# 1. Install dev dependencies (PHPUnit, etc.). The bundled Composer plugin is blocked by
#    Composer 2 and isn't needed for tests, so skip plugins.
docker compose exec app composer install --no-plugins

# 2. Seed a dedicated test database (schema + fixtures).
docker compose exec -T db mariadb -uroot -psyspass \
  -e 'DROP DATABASE IF EXISTS `syspass-test`; CREATE DATABASE `syspass-test` DEFAULT CHARACTER SET utf8 COLLATE utf8_general_ci;'
docker compose exec -T db mariadb -uroot -psyspass syspass-test < schemas/dbstructure.sql
docker compose exec -T db mariadb -uroot -psyspass syspass-test < tests/res/scripts/db.sql

# 3. Run the Core suite.
docker compose exec \
  -e DB_SERVER=db -e DB_NAME=syspass-test -e DB_USER=root -e DB_PASS=syspass \
  -w /var/www/html app vendor/bin/phpunit -c tests/phpunit.xml --testsuite Core --no-coverage
```

**Baseline** (untouched 3.2.11): `Tests: 1454`, with **4 environment-specific errors** — three
`InstallerTest` cases that create a dedicated DB user, and one memory-limit test. Treat those 4 as
the known baseline; a dependency change is clean if it introduces no *new* failures.
