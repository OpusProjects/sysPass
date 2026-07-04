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
- No comments unless the *why* is non-obvious.
- No feature flags or backwards-compatibility shims — just change the code.
- `config/config.xml` contains DB credentials and crypto keys — never commit it.

## Running tests

```bash
# Unit (fast, no DB)
docker compose exec -w /var/www/html app \
  vendor/bin/phpunit -c tests/phpunit.xml --group unitary --testsuite core --no-coverage

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
```

All test suites must pass before merging. See [`docs/TESTING.md`](docs/TESTING.md) for
details on test layout, groups, and writing new tests.

## Commit messages

- Short title (imperative mood) — what changed and why.
- Body is optional; use it for context that isn't obvious from the diff.
- No issue/PR numbers in the title — GitHub adds the PR number on squash-merge.

## Dependencies

PHP dependencies are managed with **Composer** (`composer.json` / `composer.lock`).
Front-end libraries are vendored under `public/vendor/` (committed, no build step).
**npm** is used only for tooling — currently the Playwright end-to-end tests
(`package.json`, dev-only); it is not required to run the app.

A dependency-bump PR edits `composer.json` (the constraint) and `composer.lock`
(run `composer update <pkg> -W` in the container), plus any code changes needed.

## License

By contributing you agree that your work is licensed under the
[GNU GPLv3](LICENSE), the same license as the project.
