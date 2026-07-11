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

## Dependencies — Composer (PHP); npm for dev tooling only

This is a PHP project; runtime dependencies live in **Composer** files at the repo root:

- **`composer.json`** — declared deps. `require` (runtime) + `require-dev` (test/dev tools),
  PSR-4 autoload, and the `~8.4 || ~8.5` PHP constraint. (≈ `package.json` / `requirements.txt`.)
- **`composer.lock`** — fully-resolved pinned versions of every package + transitive dep, what
  `composer install` reproduces. (≈ `package-lock.json`.)
- **`vendor/`** — installed packages, **gitignored** (≈ `node_modules`).
- **Front-end libraries are *vendored*** (committed `*.min.js` under `public/vendor/`, `public/js/`;
  no build step, nothing to install to run the app). A root **`package.json`** (dev/build-only,
  `node_modules/` gitignored) manages them: bump a version there → `npm install` → `npm run vendor`
  (recopies dist `*.min.js` into `public/vendor/js/` per `scripts/vendor-assets.mjs`) → commit. The
  served bundle order lives in `JsController::JS_MIN_FILES`. Same `package.json` also holds the
  Playwright E2E suite (`npm run test:e2e`, host-run against the Docker app).
- **Theme CSS is served *pre-minified***: the `resource/css` route (`CssController` + the
  `Minify` service) only *concatenates* the committed `*.min.css` — it does **not** minify at
  runtime (`MinifyCss::minify()` just joins files with a `/* FILE */` header). So after editing a
  `*.css` source under `public/themes/material-blue/css/`, run `npm run build:css` (esbuild,
  `scripts/build-css.mjs`) to regenerate its `*.min.css`, or it ships stale/unminified. The app's
  own JS (`public/js/app-*.min.js`) is authored directly — no `*.js` source, no JS build step.

A **dependency-bump PR** edits `composer.json` (the constraint) + `composer.lock` (run
`composer update <pkg> -W` in the container), plus any code changes, validated by both suites.

## Layout (PHP 8 hexagonal)

| Area | Path |
|---|---|
| Domain layer (models, driven ports, pure services) | `src/Domain/<Ctx>/` (`Models/`, `Dtos/`, `Ports/`, `Services/`) |
| Application layer (use-cases, driving ports) | `src/Application/<Ctx>/` (`Ports/`, `Services/`) |
| Infrastructure — driven adapters (repositories) | `src/Infrastructure/Adapter/Out/<Ctx>/Repositories/` |
| Infrastructure — driving adapters (controllers) | `src/Infrastructure/Adapter/In/{Web,Api,Cli}/` |
| Infrastructure — shared (DB, file, common repos) | `src/Infrastructure/{Database,File}/` |
| Core (bootstrap, DI definitions, ACL, crypt) | `src/Core/` (`Definitions/CoreDefinitions.php`, `DomainDefinitions.php`) |
| Theme (only one) | `public/themes/material-blue/` |
| Front-end JS/CSS (served via a PHP `file` route) | vendored under `public/vendor/`, `public/js/` |
| Runtime config | `config/` (runtime; gitignored `config.xml` holds DB creds + crypto keys) |
| Runtime-writable | `var/{cache,temp,backup}` |
| Resources (locales, templates) | `resources/` |
| DB schema | `schemas/dbstructure.sql` |
| Tests (PHPUnit 13) — Unit/Integration each mirror src; shared helpers in Support | `tests/{Unit,Integration,Support}/` |
| Entry points | `public/index.php` (web), `public/api.php` (api), `bin/cli.php` (cli) → require `src/Base.php` |

**UI unchanged from 3.2** — same `material-blue` theme; the front-end diff is plumbing
(namespace/route updates), not a redesign (CSS essentially untouched).

## Local dev environment (Docker)

```bash
docker compose up --build -d      # PHP 8.5 + Apache app + MariaDB
# App on http://localhost:8090 ; MariaDB as host `db` (root / syspass / db `syspass`)
```

- Host **port 8090** (8080 is taken by another container on this machine).
- The image installs the PHP extensions + the test deps (see below), prepends the Composer
  autoloader, and the entrypoint runs `composer install` and writes a dev `.env`.

## Tests

```bash
docker compose exec app composer install        # include dev deps

# Unit suite — no DB
docker compose exec -w /var/www/html app \
  vendor/bin/phpunit -c tests/phpunit.xml --testsuite unit --no-coverage

# Integration suite — needs the schema in DB `syspass`
docker compose exec -T db mariadb -uroot -psyspass syspass < schemas/dbstructure.sql
docker compose exec -e DB_SERVER=db -e DB_NAME=syspass -e DB_USER=root -e DB_PASS=syspass -e DB_PORT=3306 \
  -w /var/www/html app vendor/bin/phpunit -c tests/phpunit.xml --testsuite integration --no-coverage
```

Both pass: **2239 unit** + **253 integration**. The integration suite includes the
end-to-end CLI command tests (`tests/Integration/Infrastructure/Adapter/In/Cli/`, real DI container +
real DB via `CliTestCase`, per-test config under `/tmp/syspass-cli-tests`). Test-environment
gotchas (the image provides these):

- **`iproute2`** — the test bootstrap's `getRealIpAddress()` shells out to `ip a s eth0`; without
  it `shell_exec` returns `null` and `trim(null)` is a fatal `TypeError` on PHP 8.
- **Locales `en_US.UTF-8` + `es_ES.UTF-8`** — `LanguageTest` asserts against them (7 failures without).
- **Bundled font** `public/vendor/fonts/NotoSans-Regular-webfont.ttf` — `ImageTest` uses it.
- **Response language is fixed to English in tests** — the app picks the response language from the
  logged-in user's preference, so `UserDataGenerator` pins `lang` to `en` (it used a random
  `faker->languageCode()`, which made integration tests assert English strings fail intermittently
  with da/is/fo/… responses).
- `AccountPresetTest::testAddPresetPermissions` was flaky (faker-data collisions with the
  logged-in user's id/userGroupId); fixed by applying the same `array_diff` exclusion in test
  expectations that the production code uses.

## Web request flow & DI container

`public/index.php` (or `public/api.php`/`bin/cli.php`) → `src/Base.php` builds the **php-di** container and runs
`Bootstrap::run($dic->get(BootstrapInterface), $dic->get(ModuleInterface))`. Per request:
`Bootstrap::handleRequest()` → `Router::dispatch()` (Symfony Routing catch-all) →
`manageWebRequest()` resolves the controller from the **`r` query param** and invokes the action.
The rewrite's web entry was **never run by upstream CI** (only the mocked unit/integration suites),
so these runtime contracts are easy to break:

- **Routing:** `?r=<controller>/<action>/<p1>/<p2>` → `<Controller>Controller::<action>Action(...)`;
  empty action → `index` (`src/Core/Bootstrap/RouteContext.php`). Leaf code reads ids from these
  route params, not the path.
- **DI definition order** (`src/Base.php`): `DomainDefinitions` → `CoreDefinitions` → module
  `module.php`, and **php-di gives later sources precedence** — the specific entry overrides the
  `SP\Domain\*\Ports\*Service` wildcard auto-wiring; the module overrides Core. Keep this order.
- **Compilation:** when `!DEBUG` the container is **compiled and lazy proxies are written**
  (`enableCompilation`/`writeProxiesToFile`); when `DEBUG` it's built live. So (1) every definition
  must be **compilable** — never bind a literal object; use `create()`/`autowire()`/`factory()`
  (e.g. a `new SymfonyResponse()` constructor *default* is not compilable → inject it explicitly via
  `->constructor(create(...))`); (2) a **circular dependency** is only broken by a lazy proxy → mark
  the entry `->lazy()` (only `create()`/`autowire()` can be lazy, not `factory()`).
- **`.env`** is loaded with `Dotenv::createImmutable()` → values land in **`$_ENV`/`$_SERVER` only,
  not `getenv()`**; `SP\getFromEnv()` reads `$_ENV`/`$_SERVER` first. `DEBUG` defaults false.
- `public/index.php` uses `SP\` classes before the autoloader loads → the image sets
  `auto_prepend_file = vendor/autoload.php` (so the entrypoint's first `composer install` must run
  with that prepend disabled). `src/Base.php` requires a `.env` to exist (entrypoint writes one).

## Controllers (hexagonal dispatch contract)

Every action `Bootstrap` invokes **must** return `SP\Domain\Common\Dtos\ActionResponse` and carry
`#[Action(ResponseType::JSON|PLAIN_TEXT|...)]` — `Bootstrap::getMethod()` rejects anything else with
*"Incorrect method return type"*. Build with `ActionResponse::ok()/error()/warning()`.

- **All Web controllers are migrated.** The legacy `JsonTrait` pattern (`fooAction(): bool` +
  `returnJsonResponse*()`) is gone — `JsonTrait` and `JsonResponseHandler` have been deleted.
  API controllers use a separate dispatch (`ApiResponse` return via REST) and
  don't go through the `ActionResponse` contract.
- `SP\` global functions (`__`, `__u`, `logger`, `processException`, `getFromEnv`) are in namespace
  `SP` — **`use function SP\...`** them (PHP's bare-call fallback only reaches the global namespace).
- `ControllerBase` exposes `$this->view` (`TemplateInterface`); render a view and wrap the HTML in
  `ActionResponse::ok($html)` with `#[Action(ResponseType::PLAIN_TEXT)]`.
- **`Init::PARTIAL_INIT`** lists controllers that skip the not-installed / DB / session checks
  (Install, Css, Js, Upgrade…). When not installed, `Init` redirects everything else to the install
  route.

## Persistence (models + repositories)

- Repos build SQL with **Aura.SqlQuery** via `$this->queryFactory`. `->set($col, $rawExpr)` injects a
  **RAW, unquoted** expression (`'NOW()'`, `0`, `"''"` for an empty string — *not* `''`, which yields
  invalid SQL).
- **`Model::toArray()` includes relation/non-column properties** (e.g. `UserGroup::$users`) — exclude
  them from insert `cols` or you get *"Unknown column"*.
- A model property left **null** is inserted as `NULL` and **overrides a column's schema DEFAULT** —
  `array_filter(..., fn($v) => $v !== null)` the cols so `NOT NULL DEFAULT` columns use their default.
- **`SPException` + `error()/critical()/warning()/info()` accept `int|string $code`** (cast to int) —
  PDO SQLSTATEs are strings; a string reaching `\Exception` TypeErrors and **masks the real DB error**.
  `processException()` accepts `Throwable` (PHP `Error`s like `TypeError` are not `Exception`).

## Model patterns

- **Models are immutable** — `Model::__set()` throws `Error('Dynamic properties not allowed')`.
  Use `$model->mutate(['prop' => $value])` to get a new instance with changed properties.
  Constructor accepts `?array $properties`. Exception: `ProfileData` and `ConfigData` still have
  setters.
- **`Model::__get()`/`__isset()`** — `__get()` proxies both declared (protected) and outer/bag
  property reads (`null` for anything unknown), and `__isset()` is defined to match, so
  `$model->prop` and `isset($model->prop)` are safe; only `__set()` throws.
- **Dtos** (`src/Domain/*/Dtos/`) extend `Dto` — `public readonly` constructor properties, use
  `mutate(array)` for copies.
- **`SerializedModel` trait** — models with a serialized blob column (e.g. `ItemPreset.data`,
  `UserProfile.profile`) use `#[Hydratable('prop', [TargetClass::class])]` +
  `hydrate(string $class): ?object` (deserialize) / `dehydrate(object): static` (serialize via
  `Serde::serializeObjectToJson`). Call `hydrate()` before passing to templates that call methods
  on the deserialized object.
- **Nullable model getters + `declare(strict_types=1)`** — many model getters return `?string`,
  `?int`. Code in strict-types files that passes these to functions expecting non-nullable params
  (e.g. `Html::truncate(string $text, ...)`, `preg_match(string $pattern, string $subject)`) must
  null-coalesce: `$model->getFoo() ?? ''`.
- **`ValidationException`** — constructor is `__construct(string $message, ...)`. Do **not** pass
  `SPException::ERROR` as the first argument (that's the `$type` param of `SPException`, not
  `ValidationException`'s `$message`).
- **`BootstrapWeb` is removed** — use `UriContextInterface` (injected via DI) for
  `getWebUri()`/`getSubUri()` instead of the deleted static `BootstrapWeb::$WEBURI`/`$SUBURI`.

## The installer

`InstallController::installAction()` → `Installer::run(InstallData)` → schema → admin user →
`config.xml <installed>1`. Two modes:

- **Hosting** (`hostingmode=1`): DB already exists, use the given creds directly. The Docker
  MariaDB auto-creates `syspass` DB, so hosting mode "just works."
- **Normal** (`hostingmode=0`): installer creates the DB + restricted user.

Key constraints:
- `InstallData` is a **shared DI singleton** — the controller and `MysqlSetup` must use the same
  instance (host detection mutates it).
- Install connection **must not select the DB** (it may not exist yet).
- Password fields are PKI-encrypted client-side; falls back to raw value for scripted installs.
- `FileHandler extends SplFileObject` — opens its file in the constructor (eagerly); open
  config-like files `c+` so they're created.

## Current stack

- **PHP 8.5** — `config.platform`, Docker image, and CI. Constraint `~8.4 || ~8.5`; `Environment`
  allows `>= 8.4 < 8.6`.
- **Symfony 8.1** — HTTP foundation, routing, console, DomCrawler.
- **Key libraries:** `guzzlehttp/guzzle` 7, `monolog/monolog` 3, `phpseclib/phpseclib` 3
  (RSA factory API — see `CryptPKI`), `symfony/http-foundation` + `symfony/routing` (replaced the
  abandoned `klein/klein` router — the HTTP layer goes through
  `SP\Domain\Http\Ports\ResponseService` + `SP\Core\Bootstrap\Router`).
- Faker 1.24 emits `trigger_deprecation()` notices (provider API deprecated for Faker 2) —
  these are library-internal (not PHP-level), suppressed by `display_errors=0` in `phpunit.xml`.
  `session.sid_bits_per_character` was removed from `SessionLifecycleHandler::SESSION_OPTIONS`.

## Known non-issues — audited, do NOT "fix"

- **`SP\Domain\Plugin\Ports\PluginDataStorage` has no implementation in `src/` — intentional.**
  It is the `#[Hydratable]` target for `PluginData.data`; the concrete classes ship with the
  plugins themselves (core cannot know plugin data shapes). Do not implement it in core and do
  not delete it — either breaks the plugin contract.
- **The CLI module binds no `BootstrapInterface` — the absence is correct.** `bin/cli.php` only
  requests `ModuleInterface`. Do not add an unused binding "for consistency": every explicit DI
  definition must stay compilable forever, and an unused-but-broken binding is exactly what once
  fatally broke prod container compilation (the phantom `ApiRequestService` entry).
- **The API `config/export` / `config/backup` `path` parameter is a deliberate feature, not an
  arbitrary-write flaw.** A caller-chosen export/backup location is documented for the CLI
  (`sp:backup --path`) and covered by tests (`ConfigControllerTest::testExportActionCustomPath`,
  `testBackupActionCustomPath`); it is gated behind the privileged `CONFIG_EXPORT_RUN` /
  `CONFIG_BACKUP_RUN` tokens. Do not "harden" it by confining the path to `Path::BACKUP` — that
  breaks the documented, tested feature. (That an admin could target a web-accessible directory is
  operational guidance, not a code bug.)
- **`jquery-ui` is in `package-lock.json` but not in `package.json` or the vendor MAP — not
  drift.** It is an `optionalDependencies` entry of `@selectize/selectize` (drag_drop plugin
  support), locked like any transitive dep (`npm ls jquery-ui` shows the chain; a fresh
  `npm install` keeps it). It is never vendored into `public/vendor/js/` and never served.
  Likewise, a locked version ahead of a `^` constraint (e.g. `jsencrypt` `^3.3.2` → lock 3.5.4)
  is normal semver resolution, and vendored-copy currency is enforced by CI's drift check.

## Conventions

- One logical change per PR; clear title (`old → new` + why) and body.
- `config/config.xml` holds DB creds + crypto keys — never commit it.
