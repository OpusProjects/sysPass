# CLAUDE.md

OpusProjects fork of **sysPass** — a PHP web-based password manager. This is a
maintenance fork of the discontinued [nuxsmin/sysPass](https://github.com/nuxsmin/sysPass),
baselined at upstream release **3.2.11**. Goal: modernize the stack (PHP 7.4 → 8.x,
dependency bumps) **one change per PR**.

## Repo facts

- **Remotes:** `origin` = upstream `nuxsmin/sysPass` (read-only reference) · `opus` = our fork `OpusProjects/sysPass` (push here). Default branch: `main`.
- **Git identity is not global** — always commit with `git -c user.name="blaipr" -c user.email="blaipr@hotmail.com" ...`.
- **Custom MVC**, no full framework. ~96k lines PHP, PSR-4 autoload. PHP written in 5/7 style (no `strict_types`, no typed properties).

## Layout

| Area | Path |
|---|---|
| Core library (Services, Repositories, DataModel, Http, Core, Providers) | `lib/SP/` |
| Web + API modules (controllers, themes, views) | `app/modules/{web,api}/` |
| Theme (only one) | `app/modules/web/themes/material-blue/` |
| Front-end JS/CSS (served via PHP `file` route, **not** static) | `app/.../themes/.../{js,css}`, plus vendored libs in `public/vendor/` |
| Runtime-writable (config.xml, caches, backups) | `app/{config,cache,temp,backup}` |
| DB schema + upgrade SQL | `schemas/` (`dbstructure.sql` = full schema) |
| Tests (PHPUnit) | `tests/` (`tests/phpunit.xml`, suite `Core`) |
| Entry points (front controllers) | `index.php` (web), `api.php` (api) → both require `lib/Base.php` |

`lib/Base.php` defines all path constants and boots via PHP-DI (`lib/Definitions.php`) + klein router (`SP\Bootstrap`).

## Local dev environment (Docker)

```bash
docker compose up --build -d         # build + start app (PHP 7.4 + Apache) and MariaDB
# Web UI:  http://localhost:8090      (first run -> installer at ?r=install/index)
```

- Host **port 8090** (8080 is taken by another project's container on this machine).
- Installer DB settings: host `db`, user `root`, pass `syspass`, database `syspass`.
- The app bind-mounts the repo; `vendor/` and `app/config` are named volumes so edits/installs persist. Entrypoint runs `composer install --no-dev` if `vendor/` is empty.
- Password fields in the installer are RSA-encrypted client-side (JSEncrypt) — complete the install in a **browser**, not curl.
- Docker files live in `docker/` (`Dockerfile`, `apache/syspass.conf`, `php/syspass.ini`, `entrypoint.sh`) + `docker-compose.yml`.

## Tests

```bash
docker compose exec app composer install            # add dev deps (phpunit etc.) into the vendor volume
docker compose exec app vendor/bin/phpunit -c tests/phpunit.xml --testsuite Core
```

The dev/test stack is **legacy and partly abandoned** (PHPUnit 6, `dbunit`, `fzaninotto/faker`,
`fabpot/goutte`) — modernizing it is an early roadmap item. CI: old `.travis.yml` is dead
(travis-ci.org shut down); GitHub Actions is TODO.

## Upgrade workflow — one change per PR

Branch from `main`, isolate **one** dependency bump (or one discrete change) per PR so a bad
bump is bisectable/revertable on its own. Verify against the test suite (and a browser smoke
test for runtime-affecting changes) before merging.

```bash
git checkout main && git pull opus main
git checkout -b <type>/<short-name>
# ... change exactly one thing ...
git -c user.name="blaipr" -c user.email="blaipr@hotmail.com" commit -m "..."
git push -u opus <type>/<short-name>
gh pr create --repo OpusProjects/sysPass --base main --head <type>/<short-name> ...
```

### Dependency status (composer.json)

- **Healthy / current:** `defuse/php-encryption` (crypto core), `phpmailer/phpmailer`.
- **Major bumps needed:** monolog 1→3, guzzle 6→7, doctrine/common 2→3, php-di 6→7, phpseclib 2→3 (API-breaking), nikic/php-parser 4→5.
- **Removed/abandoned (replace):** `symfony/debug` → `symfony/error-handler`; `fzaninotto/faker` → `fakerphp/faker`; `fabpot/goutte` → `symfony/browser-kit`+`http-client`; `phpunit/dbunit` (drop). PHPUnit 6 → modern.

## Conventions

- No AI/assistant attribution anywhere (commits, PRs, comments).
- Keep PRs scoped to one package; keep the title `old → new` + why.
- `app/config/config.xml` holds DB creds + crypto keys — never commit it; it's web-denied in Apache and in `.dockerignore`.
