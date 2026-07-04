# Command-line interface (CLI)

sysPass ships a command-line tool for headless / automated operations: installing
the application, taking backups, and rotating the master password. It is a
[Symfony Console](https://symfony.com/doc/current/components/console.html)
application whose entry point is **`bin/cli.php`**.

`bin/cli.php` boots the same php-di container as the web and API entry points
(`src/Base.php`), then runs the console application (`SP\Infrastructure\Adapter\In\Cli`).

---

## Running the CLI

The tool is invoked as `php bin/cli.php <command> [arguments] [options]`.

**Locally / on a manual install** (from the project root):

```bash
php bin/cli.php <command> [arguments] [options]
```

**Inside the Docker dev environment** (the app service is `app`; run from the
web root so the `.env` and autoloader resolve):

```bash
docker compose exec -w /var/www/html app php bin/cli.php <command> [arguments] [options]
```

> The CLI needs the same runtime prerequisites as the web app: a readable `.env`
> and a writable `config/` directory. Commands other than `sp:install` also
> require an already-installed instance (a `config.xml` with `<installed>1</installed>`).

All examples below use the bare `php bin/cli.php …` form; prefix them with
`docker compose exec -w /var/www/html app` when running against the container.

---

## Discovering commands

```bash
php bin/cli.php list             # list every available command
php bin/cli.php help <command>   # detailed help for a command
php bin/cli.php <command> --help  # same, via the option
```

`list` prints the application banner (`sysPass CLI <version>`) followed by the
available commands.

### Global options

These are provided by Symfony Console and work on every command:

| Option | Description |
|---|---|
| `-h`, `--help` | Show help for the command (or for `list` when no command is given). |
| `-V`, `--version` | Print the application version and exit. |
| `-q`, `--quiet` | Suppress all output except errors. |
| `--silent` | Suppress all output. |
| `-n`, `--no-interaction` | Never ask interactive questions (fail instead of prompting). |
| `-v`, `-vv`, `-vvv`, `--verbose` | Increase verbosity (normal / verbose / debug). |
| `--ansi`, `--no-ansi` | Force or disable ANSI-coloured output. |

---

## Commands

sysPass provides three application commands (all prefixed `sp:`):

| Command | Purpose |
|---|---|
| [`sp:install`](#spinstall) | Install sysPass (schema, admin user, master password, config). |
| [`sp:backup`](#spbackup) | Create a file-based backup of the database and application. |
| [`sp:crypt:update-master-password`](#spcryptupdate-master-password) | Re-encrypt all data under a new master password. |

### Environment variables vs. arguments/options

Every argument and option can also be supplied through an **environment
variable**, which is convenient for scripted / containerised installs. When both
are present, **the environment variable takes precedence** over the value passed
on the command line. Each command's env-var mapping is listed in its section below.

---

### `sp:install`

Installs sysPass: creates the database schema, the admin account, stores the
master password, and writes `config.xml`. This is the CLI equivalent of the web
installer wizard.

```
php bin/cli.php sp:install [options] [--] [<adminLogin> [<databaseHost> [<databaseName> [<databaseUser>]]]]
```

**Arguments** (all optional and positional, in this order):

| Argument | Env var | Description |
|---|---|---|
| `adminLogin` | `ADMIN_LOGIN` | Admin user to log into the application. |
| `databaseHost` | `DATABASE_HOST` | Database server host (e.g. `localhost`, `db`, `db:3306`). |
| `databaseName` | `DATABASE_NAME` | Application database name (e.g. `syspass`). |
| `databaseUser` | `DATABASE_USER` | A database user with administrative rights. |

**Options:**

| Option | Env var | Description |
|---|---|---|
| `--databasePassword[=…]` | `DATABASE_PASSWORD` | Password for the DB admin user (empty is allowed for passwordless roots). |
| `--adminPassword[=…]` | `ADMIN_PASSWORD` | Application administrator's password. |
| `--masterPassword[=…]` | `MASTER_PASSWORD` | Master password used to encrypt the data. |
| `--language[=…]` | `SYSPASS_LANGUAGE` | Global app language (e.g. `en_US`, `es_ES`). Unknown values are ignored and you're prompted. |
| `--hostingMode` | `HOSTING_MODE` | Use an existing database without creating it or managing DB-user permissions. |
| `--forceInstall` | `FORCE_INSTALL` | Re-install over an already-installed instance (required if `config.xml` reports installed). |
| `--install` | `INSTALL` | Skip the "Install sysPass?" confirmation prompt (for non-interactive runs). |

**Interactive behaviour:** any password that is not supplied (admin, master, DB)
is requested via a hidden prompt, and the admin/master passwords are asked twice
and must match. If `--language` is missing or invalid you're offered a choice
(default `en_US`). Without `--install`, the command asks for confirmation before
proceeding; pair `--install` with `-n`/`--no-interaction` for a fully unattended
run (all required values must then come from arguments/options/env vars).

**Safety:** if sysPass is already installed, `sp:install` aborts unless
`--forceInstall` is given.

**Examples:**

```bash
# Interactive: pass the non-secret values, get prompted for the passwords
php bin/cli.php sp:install admin localhost syspass root

# Fully unattended (e.g. CI / container provisioning)
php bin/cli.php sp:install admin db syspass root \
  --databasePassword='syspass' \
  --adminPassword='StrongAdminPass' \
  --masterPassword='StrongMasterPass' \
  --language=en_US --install --no-interaction

# Same, driven entirely by environment variables
ADMIN_LOGIN=admin DATABASE_HOST=db DATABASE_NAME=syspass DATABASE_USER=root \
DATABASE_PASSWORD=syspass ADMIN_PASSWORD=StrongAdminPass \
MASTER_PASSWORD=StrongMasterPass SYSPASS_LANGUAGE=en_US INSTALL=1 \
  php bin/cli.php sp:install --no-interaction

# Install against a pre-created database (hosting mode) and force a re-install
php bin/cli.php sp:install admin db syspass root \
  --databasePassword='syspass' --adminPassword='…' --masterPassword='…' \
  --hostingMode --forceInstall --install --no-interaction
```

---

### `sp:backup`

Creates a file-based backup of the sysPass database and application files. The
instance must already be installed.

```
php bin/cli.php sp:backup [--path[=PATH]]
```

**Options:**

| Option | Env var | Default | Description |
|---|---|---|---|
| `--path[=…]` | `BACKUP_PATH` | the app's `var/backup` directory | Directory where the backup files are written. |

**Examples:**

```bash
php bin/cli.php sp:backup                       # default location (var/backup)
php bin/cli.php sp:backup --path=/var/backups/syspass
BACKUP_PATH=/var/backups/syspass php bin/cli.php sp:backup
```

---

### `sp:crypt:update-master-password`

Rotates the master password: decrypts all stored data with the current master
password and re-encrypts it with a new one. The command is **locked** so two
runs cannot overlap. The instance must already be installed.

```
php bin/cli.php sp:crypt:update-master-password [options]
```

**Options:**

| Option | Env var | Description |
|---|---|---|
| `--currentMasterPassword=…` | `CURRENT_MASTER_PASSWORD` | The current master password (required). |
| `--masterPassword=…` | `MASTER_PASSWORD` | The new master password to encrypt the data with (required). |
| `--update` | `UPDATE` | Skip the confirmation prompt (for non-interactive runs). |

**Examples:**

```bash
# Interactive confirmation
php bin/cli.php sp:crypt:update-master-password \
  --currentMasterPassword='OldMaster' --masterPassword='NewMaster'

# Unattended
CURRENT_MASTER_PASSWORD='OldMaster' MASTER_PASSWORD='NewMaster' UPDATE=1 \
  php bin/cli.php sp:crypt:update-master-password --no-interaction
```

> Rotating the master password re-encrypts every account. Take a backup
> (`sp:backup`) first, and keep the new master password safe — it cannot be
> recovered.

---

## Exit codes

Commands follow the standard Symfony Console convention:

| Code | Meaning |
|---|---|
| `0` | Success. |
| `1` | Failure (validation error, aborted confirmation, or an exception — the message is printed and logged). |

This makes the CLI safe to use in shell scripts (`set -e`) and CI pipelines.

## Logging

All commands log to the application log (`var/log`, configured by the core
`LoggerInterface`). Use `-v`/`-vv`/`-vvv` to also surface progress/debug output on
the console.
