# Installation

## Requirements

- **PHP 8.4+** with extensions: `pdo_mysql`, `gd`, `gettext`, `mbstring`, `intl`,
  `dom` / `xml`, `json`, `curl`, `fileinfo`, `zlib`, and `ldap` (optional — only for
  LDAP / Active Directory authentication).
- **MariaDB 10.1+** (or MySQL 5.7+).
- A **web server** (Apache or Nginx); SSL strongly recommended for production.
- **[Composer](https://getcomposer.org/)** to install the PHP dependencies.

## Option A — Docker (recommended for development)

This fork ships a one-command development stack (PHP 8.5 + Apache + MariaDB). See the
[`docker/`](../docker) directory and [`docker-compose.yml`](../docker-compose.yml).

```bash
docker compose up --build -d
```

The app is served on <http://localhost:8090>. MariaDB is reachable as host `db`
(user `root`, password `syspass`, database `syspass`).

The image installs all required PHP extensions, prepends the Composer autoloader
(`auto_prepend_file`), and the entrypoint runs `composer install` and writes a
dev `.env`. Once the containers are up, open the browser and complete the web
installer.

### Docker install mode

The Docker MariaDB container auto-creates the `syspass` database, so select
**Hosting** mode in the installer — it uses the provided credentials directly
instead of trying to create the database. Use `db` as the database server,
`root` / `syspass` as the DB credentials, and `syspass` as the database name.

## Option B — Manual installation

The procedure is the same on any Linux distribution; only the package names,
the web-root path, and the web-server user differ.

1. **Install the dependencies** — a web server, **PHP 8.4+** with the extensions
   listed under [Requirements](#requirements), MariaDB, and Composer:

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
   Point the web server's document root at the `public/` subdirectory.

3. Make the runtime directories writable by the web-server user (`www-data` on
   Debian/Ubuntu, `apache` on RHEL-family, `http` on Arch):
   ```bash
   chown -R www-data:www-data config var/backup var/cache var/temp
   ```

4. Install the PHP dependencies and create a `.env`:
   ```bash
   composer install --no-dev
   cp .env.example .env
   ```

5. Start the database and web server, then open the browser and complete the
   web installer. The installer creates the database schema, sets up the admin
   account and master password, and writes `config/config.xml`.

   - **Standard mode** — the installer creates the database and a restricted DB
     user with the credentials you provide.
   - **Hosting mode** — the database must already exist **and contain no sysPass
     tables**; the installer uses the provided credentials directly.

   The database server field accepts `host`, `host:port`, `[ipv6]:port`, or
   `unix:/path/to/socket`.

## CLI install

The installer can also run headless:

```bash
php bin/cli.php sp:install <adminLogin> <databaseHost> <databaseName> <databaseUser> \
  --databasePassword=... --adminPassword=... --masterPassword=... --install
```

Options left out are prompted for interactively. Every value can also come from
environment variables (`ADMIN_LOGIN`, `ADMIN_PASSWORD`, `DATABASE_HOST`,
`DATABASE_NAME`, `DATABASE_USER`, `DATABASE_PASSWORD`, `MASTER_PASSWORD`,
`SYSPASS_LANGUAGE`, `HOSTING_MODE`, `INSTALL`, `FORCE_INSTALL`), which take
precedence over the arguments. `--install` skips the confirmation prompt;
`--forceInstall` is only needed to reinstall over an existing installation.

---

See [`TESTING.md`](TESTING.md) for how to run the test suites.
