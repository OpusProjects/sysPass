# CLAUDE.md

OpusProjects fork of **sysPass** — a PHP web-based password manager, originally
[nuxsmin/sysPass](https://github.com/nuxsmin/sysPass) (discontinued). `main` is now
**nuxsmin's PHP 8.2/8.3 hexagonal rewrite** (adopted from his `feat/code_refactoring`
branch), which we are finishing.

## Workflow — every change is its own PR

`main` has a **flat history**: a squashed `sysPass 3.2.11` root (upstream commit history
omitted), then one squash-merged PR per change.

**Never commit directly to `main`.** Every change — a dependency bump, a fix, a doc edit —
is a **separate PR**, squash-merged. One logical change per PR (independently reviewable,
bisectable, revertable).

```bash
git checkout main && git pull opus main
git checkout -b <type>/<short-name>
# ... make exactly one change, test it ...
git commit -am "Short title — what & why"
git push -u opus <type>/<short-name>
gh pr create --repo OpusProjects/sysPass --base main --head <type>/<short-name> --title "..." --body "..."
gh pr merge <n> --repo OpusProjects/sysPass --squash --delete-branch   # we self-merge
```

- **Remotes:** `origin` = upstream `nuxsmin/sysPass` (read-only) · `opus` = our fork `OpusProjects/sysPass` (push here). Default branch: `main`.
- **Git identity** is set repo-locally (`blaipr` / `blaipr@hotmail.com`) — plain `git commit` works.
- `gh pr edit` can silently no-op on this repo (classic-Projects GraphQL field); if a body edit doesn't apply, PATCH via `gh api -X PATCH repos/OpusProjects/sysPass/pulls/<n> -F body=@file`.

## Dependencies — Composer (no npm / requirements.txt)

This is a PHP project; dependencies live in **Composer** files at the repo root:

- **`composer.json`** — declared deps. `require` (runtime) + `require-dev` (test/dev tools),
  PSR-4 autoload, and the `~8.2 || ~8.3` PHP constraint. (≈ `package.json` / `requirements.txt`.)
- **`composer.lock`** — fully-resolved pinned versions of every package + transitive dep, what
  `composer install` reproduces. (≈ `package-lock.json`.)
- **`vendor/`** — installed packages, **gitignored** (≈ `node_modules`).
- **Front-end has no npm manifest** — JS/CSS libraries are *vendored* (committed under
  `public/vendor/`, `public/js/`). Nothing to install for the front-end.

A **dependency-bump PR** edits `composer.json` (the constraint) + `composer.lock` (run
`composer update <pkg> -W` in the container), plus any code changes, validated by both suites.

## Layout (PHP 8.2 hexagonal)

| Area | Path |
|---|---|
| Domain layer (~460 classes: models, ports, services) | `lib/SP/Domain/` |
| Infrastructure (DB, file, adapters) | `lib/SP/Infrastructure/` |
| Core (bootstrap, DI definitions, ACL, crypt) | `lib/SP/Core/` (`Definitions/CoreDefinitions.php`, `DomainDefinitions.php`) |
| Web / API / CLI modules (controllers, themes, views) | `app/modules/{web,api,cli}/` |
| Theme (only one) | `app/modules/web/themes/material-blue/` |
| Front-end JS/CSS (served via a PHP `file` route) | vendored under `public/vendor/`, `public/js/` |
| Runtime-writable | `app/{config,cache,temp,backup}` |
| DB schema | `schemas/dbstructure.sql` |
| Tests (PHPUnit 11) | `tests/` (`tests/phpunit.xml`, bootstrap `tests/SP/bootstrap.php`) |
| Entry points | `index.php` (web), `api.php` (api), `cli.php` (cli) → require `lib/Base.php` |

**UI unchanged from 3.2** — same `material-blue` theme; the front-end diff is plumbing
(namespace/route updates), not a redesign (CSS essentially untouched).

## Local dev environment (Docker)

```bash
docker compose up --build -d      # PHP 8.2 + Apache app + MariaDB
# App on http://localhost:8090 ; MariaDB as host `db` (root / syspass / db `syspass`)
```

- Host **port 8090** (8080 is taken by another container on this machine).
- The image installs the PHP 8.2 extensions + the test deps (see below), prepends the Composer
  autoloader, and the entrypoint runs `composer install` and writes a dev `.env`.

## Tests

```bash
docker compose exec app composer install        # include dev deps

# Unit suite — no DB
docker compose exec -w /var/www/html app \
  vendor/bin/phpunit -c tests/phpunit.xml --group unitary --testsuite core --no-coverage

# Integration suite — needs the schema in DB `syspass`
docker compose exec -T db mariadb -uroot -psyspass syspass < schemas/dbstructure.sql
docker compose exec -e DB_SERVER=db -e DB_NAME=syspass -e DB_USER=root -e DB_PASS=syspass -e DB_PORT=3306 \
  -w /var/www/html app vendor/bin/phpunit -c tests/phpunit.xml --group integration --no-coverage
```

Both pass: **1978 unit** + **93 integration**. Test-environment gotchas (the image provides these):

- **`iproute2`** — the test bootstrap's `getRealIpAddress()` shells out to `ip a s eth0`; without
  it `shell_exec` returns `null` and `trim(null)` is a fatal `TypeError` on PHP 8.
- **Locales `en_US.UTF-8` + `es_ES.UTF-8`** — `LanguageTest` asserts against them (7 failures without).
- **Bundled font** `public/vendor/fonts/NotoSans-Regular-webfont.ttf` — `ImageTest` uses it.
- **Response language is fixed to English in tests** — the app picks the response language from the
  logged-in user's preference, so `UserDataGenerator` pins `lang` to `en` (it used a random
  `faker->languageCode()`, which made integration tests assert English strings fail intermittently
  with da/is/fo/… responses).
- Known separate flaky: `AccountPresetTest::testAddPresetPermissions` (a faker-data / consecutive-mock
  matcher issue, not language).

## Web bootstrap is WIP

The rewrite's web entry (`index.php` → `lib/Base.php`) was **never run by upstream CI** (only the
unit/integration suites, which mock the infrastructure). It still has gaps:

- `index.php` uses autoloaded `SP\` classes before loading the autoloader → the image sets
  `auto_prepend_file = vendor/autoload.php`.
- `lib/Base.php` loads a mandatory `.env` (`Dotenv::createImmutable()->load()`); the entrypoint writes one.
- **Fixed (DI definition ordering):** php-di couldn't resolve `BootstrapInterface → ConfigFileService
  → XmlFileStorageService` because `lib/Base.php` added `CoreDefinitions` *before* `DomainDefinitions`.
  `DomainDefinitions` auto-wires every `SP\Domain\*\Ports\*Service` via a `*` wildcard, and php-di
  gives **later** definition sources precedence — so the generic wildcard (`autowire(ConfigFile)`,
  which reads the unbound `XmlFileStorageService` interface) shadowed Core's explicit
  `ConfigFileService` definition. Fix: register `DomainDefinitions` **first**, then `CoreDefinitions`
  (specific overrides generic), matching the order `tests/SP/IntegrationTestCase.php` already builds
  the container with (which is why the suites passed).
- **Still WIP (fresh-install file open):** `FileHandler extends SplFileObject` and opens its file in
  the constructor, so building `ConfigFileService` when `app/config/config.xml` does not yet exist
  throws a raw `RuntimeException` before `ConfigFile::initialize()` can fall through
  `loadFromCache() ?? loadFromFile() ?? generateNewConfig()`. The fresh-install path (no config.xml)
  is still blocked on this — it needs lazy file opening (or tolerating a missing file) so
  `generateNewConfig()` runs. An already-installed instance (config.xml present) is unaffected.

## Dependency status (PHP 8.4 codebase, Symfony 8)

- **Done:** `guzzlehttp/guzzle` 6 → 7; `monolog/monolog` 1 → 3; `phpseclib/phpseclib` 2 → 3
  (RSA factory API — see `CryptPKI`); removed unused `doctrine/common`; **replaced the abandoned
  `klein/klein` router with `symfony/http-foundation` + `symfony/routing`** (the HTTP layer now goes
  through `SP\Domain\Http\Ports\ResponseService` + `SP\Core\Bootstrap\Router`).
- **Symfony 5.4 → 8.0 — done, staged one PR per major** (whole `symfony/*` suite moved together each
  step; the sanctioned exception to one-package-per-PR): `5.4 → 6.4` (#19), `6.4 → 7.4 LTS` (#20),
  PHP `8.2/8.3 → 8.4` prerequisite (#21), `7.4 → 8.0` (#22). Dev image and `config.platform` are on
  **PHP 8.4** (constraint `~8.2 || ~8.3 || ~8.4`). Notable major-version breaks fixed along the way:
  console `$defaultName` → `#[AsCommand]` (7.0); strictly-typed / `final` Request bags in tests
  (`FileBag`/`InputBag`) (7.0); default bcrypt cost 10 → 12 (PHP 8.4); `Request::get()` removed →
  `$request->query->get()` (8.0); `Environment::MAX_PHP_VERSION` raised to allow 8.4.
- The old 3.2.x line was gridlocked by `roave/security-advisories` + `fabpot/goutte`'s guzzle-6 pin —
  the reason we adopted the rewrite (which uses `symfony/dom-crawler`, not goutte).

## Conventions

- One logical change per PR; clear title (`old → new` + why) and body.
- `app/config/config.xml` holds DB creds + crypto keys — never commit it.
