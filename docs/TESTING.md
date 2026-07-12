# Testing

sysPass uses **PHPUnit 13** with two test suites: a fast unit suite that needs no
external services, and an integration suite backed by a real MariaDB database.

Both suites pass: **2238 unit tests** and **253 integration tests**.

## Quick start (Docker)

The Docker dev stack (`docker compose up --build -d`) provides everything needed.

```bash
# Install dependencies (including dev)
docker compose exec app composer install

# Unit suite — no DB required
docker compose exec -w /var/www/html app \
  vendor/bin/phpunit -c tests/phpunit.xml --testsuite unit --no-coverage

# Integration suite — seed schema, then run
docker compose exec -T db mariadb -uroot -psyspass syspass < schemas/dbstructure.sql
docker compose exec \
  -e DB_SERVER=db -e DB_NAME=syspass -e DB_USER=root -e DB_PASS=syspass -e DB_PORT=3306 \
  -w /var/www/html app \
  vendor/bin/phpunit -c tests/phpunit.xml --testsuite integration --no-coverage
```

## Test layout

Tests are physically split by kind; `Unit/` and `Integration/` each mirror the
`src/` hexagonal structure (PSR-4: `SP\Tests\` → `tests/`):

```
tests/
  phpunit.xml          ← configuration (suites, bootstrap, coverage)
  bootstrap.php        ← test bootstrap (constants, autoloader, env)
  Unit/                ← unit tests — no DB, no external services
    Domain/            ← mirrors src/Domain/
    Application/       ← mirrors src/Application/
    Infrastructure/    ← mirrors src/Infrastructure/ (includes former Core/ tests)
  Integration/         ← database-backed tests — need a seeded MariaDB
    Infrastructure/    ← Api/Web/Cli adapter tests + DatabaseUtil
  Support/             ← shared test infrastructure (not tests)
    *.php              ← base test cases (UnitaryTestCase, IntegrationTestCase, …)
    Generators/        ← faker-backed model data generators
    Stubs/             ← hand-written stubs
  res/                 ← test fixtures (config, datasets, images, imports)
```

The `unit` testsuite is everything under `Unit/`. The `integration` testsuite is
everything under `Integration/`: the end-to-end REST API tests (real container +
real DB via `ApiTestCase`, driving the real Bootstrap dispatch with crypto-backed
auth tokens), the Web controller tests (mocked container via `IntegrationTestCase`),
and the end-to-end CLI command tests (real DI container + real database via
`CliTestCase`, per-test config and runtime dirs under `/tmp/syspass-cli-tests`).
Base test cases used by a single suite (`ApiTestCase`, `CliTestCase`) live next to
their consumers; everything shared lives in `Support/`.

Suite membership is by directory — the `#[Group('unitary')]`/`#[Group('integration')]`
attributes remain on the classes for ad-hoc `--group` filtering but no longer decide
what CI runs.

```bash
# Only unit tests (fastest)
vendor/bin/phpunit -c tests/phpunit.xml --testsuite unit --no-coverage

# Only integration tests
vendor/bin/phpunit -c tests/phpunit.xml --testsuite integration --no-coverage

# Everything
vendor/bin/phpunit -c tests/phpunit.xml --no-coverage
```

## End-to-end tests (Playwright)

Browser-level tests live in [`tests/e2e/`](../tests/e2e) and run with
[Playwright](https://playwright.dev/) — **on the host** (Node), driving the
Dockerised app on `http://localhost:8090`. They cover the runtime web flows the
PHPUnit suites can't reach (the install wizard, login).

```bash
npm ci                            # installs @playwright/test (dev tooling)
npx playwright install chromium   # one-time browser download (~180 MB, cached)
npm run test:e2e                  # runs everything under tests/e2e/
```

Prerequisites: Node/npm on the host, and the Docker stack up (`docker compose up -d`).

> **The suite resets app state** — the install-wizard spec drops the `syspass`
> database and removes `config.xml` to reach a not-installed state, then installs
> fresh. Never run it against an instance whose data you want to keep. `npm` and
> `node_modules/` are dev-only; nothing here is needed to run the application.

## Static analysis

CI's `lint` job runs [PHPStan](https://phpstan.org/) at **level 6** against `src/`.
The repo also carries **`phpstan.baseline.neon`** — a grandfathered snapshot of
findings that predate strict static analysis, replayed as ignore rules so CI
stays green without requiring every legacy error to be fixed up front.

**The baseline is shrink-only:**

- New code must pass PHPStan level 6 clean — never add a baseline entry for
  code you're writing. Baseline entries are only for old findings you're not
  touching.
- If you do need to add one anyway (e.g. a documented, deliberate false
  positive), it **must carry an explanatory comment** above the entry saying
  why it's there and why it isn't being fixed.
- If your change happens to fix an already-baselined error, its `message:`
  regex stops matching anything PHPStan reports — an **unmatched ignore**,
  which PHPStan treats as a hard failure. Prune the now-stale entry from
  `phpstan.baseline.neon` in the same PR.

Run the same check locally (CI itself runs
`phpstan analyse --level 6 --error-format=github src`, which renders as inline
PR annotations; use the commands below in a terminal):

```bash
# Clear the result cache first — a long-lived container/checkout can hold a
# stale cache that reports false all-clears (errors that no longer reflect
# the current code).
docker compose exec -w /var/www/html app vendor/bin/phpstan clear-result-cache

docker compose exec -w /var/www/html app \
  vendor/bin/phpstan analyse --level 6 src --no-progress --memory-limit=1G
```

If PHPStan reports errors (new ones, or unmatched ignores), its default table
formatter crashes instead of printing them
(`Call to undefined function ...\Symfony\Component\String\b()`). Work around it
with the raw formatter, which prints one error per line and never crashes:

```bash
docker compose exec -w /var/www/html app \
  vendor/bin/phpstan analyse --level 6 src --no-progress --error-format=raw
```

PHPStan is one of three gates in CI's `lint` job — the other two are:

- **PHPCS** (`composer phpcs`) — PSR2 code style over `src/`.
- **Vendor JS bundle drift check** — `npm run build:js && git diff --exit-code public/vendor/js/`,
  confirming the committed `vendor.bundle.min.js` and `zxcvbn.min.js` match what
  `scripts/build-js.mjs` would produce from the versions pinned in `package-lock.json`.

## Environment requirements

The Docker image provides all of these. If running tests outside Docker, ensure:

- **PHP 8.4+** with extensions: `pdo_mysql`, `gd`, `gettext`, `mbstring`, `intl`,
  `dom`/`xml`, `json`, `curl`, `fileinfo`, `zlib`.
- **`iproute2`** — the test bootstrap calls `ip a s eth0` to detect the container IP;
  without it `shell_exec` returns `null` and `trim(null)` is a fatal `TypeError` on PHP 8.
- **Locales `en_US.UTF-8` and `es_ES.UTF-8`** — `LanguageTest` asserts against them
  (7 failures without).
- **Bundled font** `public/vendor/fonts/NotoSans-Regular-webfont.ttf` — `ImageTest` uses it.

## Integration test database

The integration suite expects a MariaDB/MySQL database with these credentials
(matching the Docker stack defaults):

| Variable | Value |
|---|---|
| `DB_SERVER` | `db` (or `localhost` outside Docker) |
| `DB_NAME` | `syspass` |
| `DB_USER` | `root` |
| `DB_PASS` | `syspass` |
| `DB_PORT` | `3306` |

Seed the schema before each run:

```bash
mariadb -uroot -psyspass syspass < schemas/dbstructure.sql
```

The integration tests are **not idempotent** — they insert/update/delete rows. Always
re-seed the schema before running them again.

## Writing new tests

- Place unit tests in `tests/Unit/` and database-backed tests in `tests/Integration/`,
  mirroring the `src/` path of the class under test; shared helpers go in `tests/Support/`.
- Tag unit tests with `#[Group('unitary')]` and integration tests with `#[Group('integration')]`.
- Use PHPUnit attributes (`#[Test]`, `#[DataProvider]`, `#[Group]`) — the codebase
  uses no legacy `@test`/`@dataProvider` annotations.
- Simple model/DTO/service tests can extend `TestCase` directly.
- Use `UnitaryTestCase` as the base class for unit tests that need common mocks.
- Integration tests extend `IntegrationTestCase` or `DatabaseTestCase` which handle
  the DI container and database connection.
- End-to-end CLI command tests extend `CliTestCase`, which builds the real container
  per test (fresh not-installed config) and runs commands through Symfony's
  `CommandTester`; tests that create databases/users must drop them again.
- For classes with DI constructor dependencies, stub the interfaces
  (`$this->createStub(FooInterface::class)`) rather than instantiating real
  implementations.
- Test serialization round-trips for models that use `SerializedModel` or have
  `__wakeup()` logic — stale serialized data is a common source of runtime errors
  after class renames.

## What CI runs

Every PR against `main` runs [`.github/workflows/ci.yaml`](../.github/workflows/ci.yaml),
with these jobs:

| Job | What it does |
|---|---|
| `unit` | `--testsuite unit`, matrixed over **PHP 8.4 and 8.5** |
| `integration` | `--testsuite integration`, same PHP matrix, against a **MariaDB 11.8** service container (schema seeded from `schemas/dbstructure.sql` before the run) |
| `lint` | The three gates in ["Static analysis"](#static-analysis) above: PHPStan level 6, PHPCS (PSR2), and the vendored-assets drift check |
| `e2e` | Playwright suite (`tests/e2e/`) against the Dockerised app stack |
| `build-image` | Builds (and, on push, publishes) the Docker image; depends on `unit` and `integration` passing |

`unit`, `integration`, and `lint` install PHP dependencies via
[`ramsey/composer-install`](https://github.com/ramsey/composer-install), which
caches `vendor/` between runs keyed on `composer.lock`. All PHP jobs set up
locales through the shared [`setup-locales`](../.github/actions/setup-locales)
composite action (see ["Environment requirements"](#environment-requirements) for
why `en_US.UTF-8`/`es_ES.UTF-8` matter). A `release` job runs afterwards,
gated to version tags (`v*`), and isn't part of the PR checks.
