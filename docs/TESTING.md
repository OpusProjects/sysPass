# Testing

sysPass uses **PHPUnit 13** with two test suites: a fast unit suite that needs no
external services, and an integration suite backed by a real MariaDB database.

Both suites pass: **2114 unit tests** and **239 integration tests**.

## Quick start (Docker)

The Docker dev stack (`docker compose up --build -d`) provides everything needed.

```bash
# Install dependencies (including dev)
docker compose exec app composer install

# Unit suite — no DB required
docker compose exec -w /var/www/html app \
  vendor/bin/phpunit -c tests/phpunit.xml --group unitary --testsuite core --no-coverage

# Integration suite — seed schema, then run
docker compose exec -T db mariadb -uroot -psyspass syspass < schemas/dbstructure.sql
docker compose exec \
  -e DB_SERVER=db -e DB_NAME=syspass -e DB_USER=root -e DB_PASS=syspass -e DB_PORT=3306 \
  -w /var/www/html app \
  vendor/bin/phpunit -c tests/phpunit.xml --group integration --no-coverage
```

## Test layout

Tests mirror the `src/` hexagonal structure:

```
tests/
  phpunit.xml          ← configuration (suites, bootstrap, coverage)
  SP/
    bootstrap.php      ← test bootstrap (constants, autoloader, env)
    Core/              ← mirrors src/Core/
    Domain/            ← mirrors src/Domain/
    Application/       ← mirrors src/Application/
    Infrastructure/    ← mirrors src/Infrastructure/
  res/                 ← test fixtures (config, datasets, images, imports)
```

The `core` testsuite covers `Core/`, `Domain/`, `Application/`, and `Infrastructure/`
(excluding the Web/Api/Cli adapter tests). The `modules` testsuite covers the adapter
integration tests under `Infrastructure/Adapter/In/`: the Web controller tests (mocked
container via `IntegrationTestCase`), the end-to-end CLI command tests (real DI
container + real database via `CliTestCase`, per-test config and runtime dirs under
`/tmp/syspass-cli-tests`), and the end-to-end REST API tests (real container + real DB
via `ApiTestCase`, driving the real Bootstrap dispatch with crypto-backed auth tokens).

## Test groups

PHPUnit groups control what runs:

| Group | What it covers |
|---|---|
| `unitary` | Pure unit tests — no DB, no filesystem side-effects |
| `integration` | Database-backed tests — need a seeded MariaDB |

Combine group and suite flags to run exactly what you need:

```bash
# Only unit tests in the core suite (fastest)
vendor/bin/phpunit -c tests/phpunit.xml --group unitary --testsuite core --no-coverage

# Only integration tests
vendor/bin/phpunit -c tests/phpunit.xml --group integration --no-coverage

# Everything
vendor/bin/phpunit -c tests/phpunit.xml --no-coverage
```

## End-to-end tests (Playwright)

Browser-level tests live in [`e2e/`](../e2e) and run with
[Playwright](https://playwright.dev/) — **on the host** (Node), driving the
Dockerised app on `http://localhost:8090`. They cover the runtime web flows the
PHPUnit suites can't reach (the install wizard, login).

```bash
npm ci                            # installs @playwright/test (dev tooling)
npx playwright install chromium   # one-time browser download (~180 MB, cached)
npm run test:e2e                  # runs everything under e2e/
```

Prerequisites: Node/npm on the host, and the Docker stack up (`docker compose up -d`).

> **The suite resets app state** — the install-wizard spec drops the `syspass`
> database and removes `config.xml` to reach a not-installed state, then installs
> fresh. Never run it against an instance whose data you want to keep. `npm` and
> `node_modules/` are dev-only; nothing here is needed to run the application.

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

- Place tests in `tests/SP/` mirroring the `src/` path of the class under test.
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
