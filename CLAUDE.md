# CLAUDE.md

OpusProjects fork of **sysPass** — a PHP web-based password manager. A maintenance
fork of the discontinued [nuxsmin/sysPass](https://github.com/nuxsmin/sysPass),
baselined at upstream release **3.2.11**.

The active direction is to adopt and finish **nuxsmin's unfinished PHP 8.2 hexagonal
rewrite** (his `feat/code_refactoring` branch) — see [PHP 8.2 rewrite](#php-82-rewrite-the-direction).

## Workflow — every change is its own PR

**`main` history is intentionally minimal** and must stay that way:

1. `sysPass 3.2.11` — squashed baseline (upstream commit history omitted).
2. `Update documentation` — README + `docs/` + this CLAUDE.md.

**Never commit directly to `main`.** Every change — a dependency bump, the Docker
setup, the LICENSE, the PHP 8.2 rewrite, anything — is a **separate pull request**
opened against `main`. One logical change per PR so each is independently reviewable,
bisectable and revertable.

```bash
git checkout main && git pull opus main
git checkout -b <type>/<short-name>
# ... make exactly one change, test it ...
git commit -am "Short title — what & why"
git push -u opus <type>/<short-name>
gh pr create --repo OpusProjects/sysPass --base main --head <type>/<short-name> --title "..." --body "..."
```

- **Remotes:** `origin` = upstream `nuxsmin/sysPass` (read-only reference) · `opus` = our fork `OpusProjects/sysPass` (push here). Default branch: `main`.
- **Git identity** is set for this repo (`blaipr` / `blaipr@hotmail.com`) — a plain `git commit` works.
- `gh pr edit` fails on some repos via the classic-Projects GraphQL field; if a body edit silently no-ops, PATCH via `gh api -X PATCH repos/OpusProjects/sysPass/pulls/<n> -F body=@file`.

## Layout (3.2.11 baseline)

| Area | Path |
|---|---|
| Core library (Services, Repositories, DataModel, Http, Core, Providers) | `lib/SP/` |
| Web + API modules (controllers, themes, views) | `app/modules/{web,api}/` |
| Theme (only one) | `app/modules/web/themes/material-blue/` |
| Front-end JS/CSS (served via a PHP `file` route, **not** static) | `app/.../themes/.../{js,css}`, vendored libs in `public/vendor/` |
| Runtime-writable (config.xml, caches, backups) | `app/{config,cache,temp,backup}` |
| DB schema | `schemas/dbstructure.sql` |
| Tests (PHPUnit) | `tests/` (`tests/phpunit.xml`) |
| Entry points | `index.php` (web), `api.php` (api) → both require `lib/Base.php` |

`lib/Base.php` defines path constants and boots via PHP-DI (`lib/Definitions.php`) + the klein router.

## Local dev environment (Docker)

Shipped via a PR (not on `main`). Once merged / checked out:

```bash
docker compose up --build -d      # PHP 7.4 + Apache app + MariaDB
# Web UI: http://localhost:8090    (first run -> installer at ?r=install/index)
```

- Host **port 8090** (8080 is taken by another container on this machine).
- Installer DB settings: host `db`, user `root`, pass `syspass`, database `syspass`.
- Installer password fields are RSA-encrypted client-side — complete the install in a **browser**, not curl.

## PHP 8.2 rewrite (the direction)

nuxsmin's `feat/code_refactoring` is a near-complete, never-released rewrite — adopting and
finishing it skips the dependency gridlock of the 3.2.x stack (`roave/security-advisories`
+ `fabpot/goutte`'s guzzle-6 pin block incremental bumps). It ships as a PR.

- **PHP 8.2 / 8.3**, **hexagonal architecture**: `lib/SP/Domain` (~460 classes) + `lib/SP/Infrastructure`.
- **PHPUnit 11**, ~1978 unit tests + DB-backed integration tests; **GitHub Actions** CI; PHPStan level 6; CLI module (`cli.php`); `.env` config (`vlucas/phpdotenv`).
- Deps: `php-di` 7, `symfony` 5 components + `error-handler`, `league/fractal`, `laminas/laminas-ldap`, `symfony/dom-crawler` (replaces goutte), `fakerphp/faker`. Still on `phpseclib` 2, `guzzle` 6, `monolog` 1.
- **The UI is unchanged** — same `material-blue` theme; the front-end diff is plumbing (namespace/route updates), not a redesign. CSS is essentially untouched.

Running its tests (it targets PHP 8.2, so use an 8.2 image):

```bash
# unit suite
vendor/bin/phpunit -c tests/phpunit.xml --group unitary --testsuite core --no-coverage
# integration suite (needs a MariaDB with the schema loaded into DB `syspass`)
DB_SERVER=db DB_NAME=syspass DB_USER=root DB_PASS=syspass DB_PORT=3306 \
  vendor/bin/phpunit -c tests/phpunit.xml --group integration --no-coverage
```

## Dependency status (3.2.x baseline)

- **Healthy:** `defuse/php-encryption` (crypto core), `phpmailer/phpmailer`.
- **Abandoned (replace):** `fzaninotto/faker` → `fakerphp/faker`; `fabpot/goutte` → `symfony/browser-kit`; `phpunit/dbunit` (drop); `symfony/debug` → `symfony/error-handler`. `klein/klein` (router) is abandoned at v2.1.2 with no PHP 8.1-clean release.
- **Major bumps:** monolog 1→3, guzzle 6→7 (blocked by goutte's guzzle-6 pin), doctrine/common 2→3, php-di 6→7, phpseclib 2→3, nikic/php-parser 4→5.

The 3.2.x line is gridlocked by `roave/security-advisories: dev-latest` (forbids the whole old stack) — which is the main reason to pursue the PHP 8.2 rewrite instead of incremental bumps.

## Conventions

- No AI/assistant attribution anywhere (commits, PRs, comments).
- One logical change per PR; clear title (`old → new` + why) and body.
- `app/config/config.xml` holds DB creds + crypto keys — never commit it.
