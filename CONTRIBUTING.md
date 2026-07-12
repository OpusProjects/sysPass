# Contributing

## Development setup

The fastest way to get a working environment:

```bash
git clone https://github.com/OpusProjects/sysPass.git
cd sysPass
docker compose up --build -d
```

See [`docs/INSTALL.md`](docs/INSTALL.md) for full requirements and manual setup.

## Branch and PR workflow

Every change — a feature, a fix, a doc edit — goes through its own PR, squash-merged
into `main`. Never commit directly to `main`.

```bash
git checkout main && git pull origin main
git checkout -b <type>/<short-name>
# make exactly one logical change
git commit -am "Short title — what & why"
git push -u origin <type>/<short-name>
# open PR, squash-merge, delete branch
```

**Branch prefixes:** `fix/`, `refactor/`, `docs/`, `cleanup/`, `feat/`, `test/`.

**One logical change per PR** — independently reviewable, bisectable, revertable.

## Coding conventions

- **PHP 8.4+** with `declare(strict_types=1)`.
- Follow the existing hexagonal structure — see [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md).
  Domain code must not depend on Infrastructure.
- **PSR2** code style, enforced by PHPCS (`composer phpcs`); **PHPStan level 6**
  static analysis, shrink-only against `phpstan.baseline.neon` — see
  ["Static analysis"](docs/TESTING.md#static-analysis) in `docs/TESTING.md`.
- No comments unless the *why* is non-obvious.
- No feature flags or backwards-compatibility shims — just change the code.
- `config/config.xml` contains DB credentials and crypto keys — never commit it.

## Running tests

```bash
# Unit (fast, no DB)
docker compose exec -w /var/www/html app \
  vendor/bin/phpunit -c tests/phpunit.xml --testsuite unit --no-coverage

# Integration (needs seeded DB)
docker compose exec -T db mariadb -uroot -psyspass syspass < schemas/dbstructure.sql
docker compose exec \
  -e DB_SERVER=db -e DB_NAME=syspass -e DB_USER=root -e DB_PASS=syspass -e DB_PORT=3306 \
  -w /var/www/html app \
  vendor/bin/phpunit -c tests/phpunit.xml --group integration --no-coverage

# End-to-end (Playwright, headless browser — runs on the host, drives the Docker
# app on :8090 and RESETS its DB/config, so don't run it against an instance you
# want to keep)
npm ci
npx playwright install chromium
npm run test:e2e

# Lint (also required before merging — see docs/TESTING.md#static-analysis)
docker compose exec -w /var/www/html app vendor/bin/phpstan clear-result-cache
docker compose exec -w /var/www/html app vendor/bin/phpstan analyse --level 6 src --no-progress
docker compose exec -w /var/www/html app composer phpcs
npm run build:js && git diff --exit-code public/vendor/js/
```

All test suites must pass before merging. CI's `lint` job runs three gates:
**PHPStan** (level 6, shrink-only against `phpstan.baseline.neon`), **PHPCS**
(PSR2), and the **vendored-assets drift check**. See
[`docs/TESTING.md`](docs/TESTING.md) for details on test layout, groups, and
writing new tests, and its ["What CI runs"](docs/TESTING.md#what-ci-runs)
section for the full CI job map (unit/integration matrix, lint gates, e2e,
image build).

## Commit messages

- Short title (imperative mood) — what changed and why.
- Body is optional; use it for context that isn't obvious from the diff.
- No issue/PR numbers in the title — GitHub adds the PR number on squash-merge.

## Dependencies

PHP dependencies are managed with **Composer** (`composer.json` / `composer.lock`).
The root **`package.json`** (dev/build-only; `node_modules/` gitignored, not needed
at runtime) covers the front-end libraries and the Playwright E2E suite.

A PHP dependency-bump PR edits `composer.json` (the constraint) and `composer.lock`
(run `composer update <pkg> -W` in the container), plus any code changes needed.

Front-end libraries are **bundled** into `public/vendor/js/vendor.bundle.min.js`
(committed). To update one: bump its version in `package.json`, then

```bash
npm install
npm run build:js  # rebuilds the esbuild bundle into public/vendor/js/
npm run test:e2e  # validate the browser flows still work
```

**Theme CSS** is served pre-minified: the `resource/css` route concatenates the
committed `*.min.css` files without minifying. After editing a `*.css` source
under `public/themes/material-blue/css/`, regenerate its `*.min.css` so they don't
drift out of sync:

```bash
npm run build:css   # minifies each theme *.css -> *.min.css via esbuild
```

The app's own JavaScript (`public/js/app-*.min.js`) is authored directly — there
is no `*.js` source and no JS build step.

## License

By contributing you agree that your work is licensed under the
[GNU GPLv3](LICENSE), the same license as the project.
