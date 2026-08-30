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
git checkout main && git pull origin main
git checkout -b <type>/<short-name>
# ... make exactly one change, test it ...
git commit -am "<type>: short imperative summary"
git push -u origin <type>/<short-name>
gh pr create --repo OpusProjects/sysPass --base main --head <type>/<short-name> --title "..." --body "..."
gh pr merge <n> --repo OpusProjects/sysPass --squash --delete-branch   # we self-merge
```

- **Types** (branch prefix and commit/PR title alike): `feat`, `fix`, `perf`, `refactor`, `docs`, `test`, `build`, `chore` — as in every OpusProjects repo.
- **Remote:** `origin` = `OpusProjects/sysPass`, the only one — the discontinued `nuxsmin/sysPass` shares no history with our squashed `main`, so nothing merges or rebases from it. Default branch: `main`.
- **Git identity** is set repo-locally (`blaipr` / `blaipr@hotmail.com`) — plain `git commit` works.
- `gh pr edit` can silently no-op on this repo (classic-Projects GraphQL field); if a body edit doesn't apply, PATCH via `gh api -X PATCH repos/OpusProjects/sysPass/pulls/<n> -F body=@file`.

## Dependencies — Composer (PHP); npm for dev tooling only

This is a PHP project; runtime dependencies live in **Composer** files at the repo root:

- **`composer.json`** — declared deps. `require` (runtime) + `require-dev` (test/dev tools),
  PSR-4 autoload, and the `~8.4 || ~8.5` PHP constraint. (≈ `package.json` / `requirements.txt`.)
- **`composer.lock`** — fully-resolved pinned versions of every package + transitive dep, what
  `composer install` reproduces. (≈ `package-lock.json`.)
- **`vendor/`** — installed packages, **gitignored** (≈ `node_modules`).
- **Front-end libraries are bundled with esbuild** into a single committed
  `public/vendor/js/vendor.bundle.min.js`. A root **`package.json`** (dev/build-only,
  `node_modules/` gitignored) declares them: bump a version there → `npm install` →
  `npm run build:js` (esbuild, `scripts/build-js.mjs` + entry `scripts/vendor-entry.mjs`) →
  commit. `zxcvbn.min.js` is kept separate (lazy-loaded at runtime). Same `package.json` also
  holds the Playwright E2E suite (`npm run test:e2e`, host-run against the Docker app).
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
| Infrastructure — shared (DB, file, HTTP, HTML, log, crypt, bootstrap, DI) | `src/Infrastructure/{Database,File,Http,Html,Log,Crypt,Bootstrap,Definitions,...}/` |
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

Both pass: **3973 unit** + **972 integration**. The integration suite includes the
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
- **A killed `docker compose exec` keeps running inside the container.** Interrupting the host-side
  client does not signal the process in the container: it carries on, and a CLI-command test that
  was mid-run keeps Symfony's `LockableTrait` lock, so every later run of
  `UpdateMasterPasswordCommandTest` fails with *"The command is already running in another
  process"* — eight failures with nothing wrong in the code. Kill it inside the container
  (`docker compose exec app sh -c 'ps -eo pid,cmd | grep phpunit'`, then `kill -9`) and remove
  `/tmp/sf.*.lock`. The same applies to any interactive prompt: `CommandTester` with fewer inputs
  than the command has questions blocks forever rather than failing.
- `AccountPresetTest::testAddPresetPermissions` was flaky (faker-data collisions with the
  logged-in user's id/userGroupId); fixed by applying the same `array_diff` exclusion in test
  expectations that the production code uses.

### Coverage: what is covered, and what deliberately is not

Line coverage is **97.20%** (`22268/22910`). Measure it by installing pcov in the container
(`pecl install pcov && docker-php-ext-enable pcov` — the enable step is separate, and a previous
session's `pecl install` leaves it *installed but disabled*, so a `pecl list | grep pcov` guard
skips it and every file reports zero), running both suites with `--coverage-clover`, and removing
the ini again — the image does not ship a coverage driver, and leaving one enabled slows every
later run. When merging two clover files, read `//file` by xpath: clover nests files inside
`<package>` elements, so `$xml->project->file` only ever finds the namespace-less ones. The two
runs also disagree about which lines *are* statements in a handful of files (8 of 749, 16 lines,
worst 2 — the Web `Forms/`), so a merge that treats "absent from one report" as uncovered
overstates those files. Confirm a per-file gap with a
focused `--coverage-clover` run before sending anyone to fix it: that file is at 100%.

The remaining 642 statements are not a backlog to burn down uniformly. What is left:

- **Bootstrap (72 statements).** `Base.php`, `Definitions/CoreDefinitions.php`, `Adapter/In/Cli/Init.php`,
  `Bootstrap/BootstrapBase.php`. These run *before* the container the tests build — `Base.php` is
  what constructs it — or are wiring the entry point exercises live. The containers themselves are
  compiled in the suite (`CompilableContainerTest`), which is what catches the failure mode that
  once broke production.
- **LDAP providers.** Only the parts that actually open a connection. `LdapAuth` takes its
  directory as an injected interface and is unit-tested against a double — do not assume a branch
  here needs a server without checking what it depends on.
- **Permission denials, in the integration suite.** `IntegrationTestCase` stubs `AclInterface`
  with `checkUserAccess()` always returning `true`, so no integration test *through that harness*
  can exercise a refusal, and one that appears to is passing for another reason. Cover a refusal in
  a **unit** test instead, with the ACL mocked closed — that is how `AccountPasswordHelper`'s are
  pinned. The mapping itself is unit-tested too: `Infrastructure/Acl/Acl.php` is at 100%. The
  account ACL *is* exercised for real, in `tests/Integration/Application/Account/AccountAccessTest.php`,
  by building a container by hand (below) and switching who is signed in.

The session and the request guards are **no longer** in that list: `Infrastructure/Context/Session.php`
is covered against a real PHP session under `#[RunClassInSeparateProcess]` (the technique
`SessionLifecycleHandlerTest` already used), and `Adapter/In/Web/Init.php`'s guards are covered as
plain unit tests. Neither needed a different test architecture — reach for a separate process
before assuming something is unreachable.

### Which harness to write against

Five, and picking the wrong one is how a test ends up asserting nothing:

| harness | database | use it for |
|---|---|---|
| `UnitaryTestCase` | none | a class in isolation |
| `IntegrationTestCase` | **mocked** | a dispatched web request — controllers, forms, views |
| `ApiTestCase` | real | the REST dispatch, with a real auth token |
| `CliTestCase` | real | a console command, installed per test |
| a container built by hand | real | anything that has to **persist and be read back** |

That last one is not a class: `tests/Integration/Application/**` (the export/import round trip, the
importers, history restore, brute-force tracking, the password reset, account access) each build
`DomainDefinitions` + `CoreDefinitions('cli')` + the Cli `module.php` with a real `DbStorageHandler`.
`IntegrationTestCase` mocks the database away, so a test that creates something and then looks for
it there is asserting against a mock that answers whatever it was told to. Copy one of those files
rather than inventing a sixth way.

A few harness details bite when writing an integration test against a real branch:

- The stubbed ACL answers `getRouteFor()` with the **action id**, not the route, so any production
  code comparing a request's `r` against a route (e.g. `AccountSearchHelper`'s "was I reached from
  the menu?") never matches. Override the `AclInterface` definition for that one action.
- `$this->databaseQueryResolver` must **not** be a static closure — the harness binds it with
  `Closure::call()`, which a static one cannot accept, and it then silently returns `null`.
- The vfs filesystem is built **once per process** and shared by every test, so a test that writes
  into `Path::BACKUP` (or any other runtime dir) has to key its files on something unique to it —
  otherwise it either finds another test's leftovers or leaves its own for the next one.
- **`EventDispatcher` cannot be doubled.** `SimpleControllerBase::$eventDispatcher` is typed as the
  concrete `final` class, so injecting a mock of `EventDispatcherInterface` fails at controller
  construction with a property-type `TypeError` — which surfaces as an `ERROR` JSON body and masks
  whatever the test meant to assert. To spy on events, build a real `EventDispatcher` and `attach()`
  an `EventReceiver` double whose `getEvents()` returns `'*'`.
- **Stubbing `ConfigDataInterface` cannot show a save.** A `createConfiguredStub()` does not keep
  what its setters were handed, so a test asserting a controller stored something must pass a real
  `ConfigData` through a stubbed `ConfigFileService` and read it back afterwards.
- **The harness mints a fresh random user every time it is asked for one.** `getUserDataDto()` calls
  the generator on each invocation, so a test that has to know who is signed in — anything asserting
  an ownership check — must override it to memoize, or it will compare against a different user than
  the one in the container's context.
- **`isShow()` is false for every account in the integration harness.** `IntegrationTestCase` stubs
  `AccountAclService`, and its `getAcl()` returns an `AccountPermission` with `resultView`/`resultEdit`
  set but **none of the `setShow*()` flags** — which are the only thing `isShow()` reads. Everything
  in a search row past the account's name sits behind that, so a test asserting any of it renders
  must override `AccountAclService` for itself (see `SearchFiltersTest`). Owning the fixture account
  does nothing; two attempts were lost to that before the cause was found.
- **Every request the harness builds carries a CSRF token**, because `Csrf::check()` only enforces
  anything when the session holds one and answering `null` switched the guard off for the whole
  suite. A test that needs a request *without* one passes `csrfToken: null` to `buildRequest()`.
- **`glob()` cannot see vfsStream.** Anything exercising file cleanup (the export's and the backup's
  "delete the previous one" passes) must use a real directory, or it passes while proving nothing.
- **An arrow function captures by value when it is created.** A mock callback that has to see a
  value assigned later needs `function () use (&$x)`, not `fn() => $x`.
- **Two test processes sharing the fixture database produce failures that are not yours.** A red
  integration run while an agent or another shell is mid-run is contention until proven otherwise —
  re-run it alone before believing it.
- **Editing `src` while a suite is running invalidates the run, and it comes back green.** PHP loads
  each class the first time something asks for it, so tests that ran before the edit used the old
  code and everything after it used the new: the result describes neither version. Unlike the
  contention above, there is no red to notice — an unrelated change can be "verified" by a run that
  never executed it. Let the run finish, or kill it (inside the container — see the gotcha above)
  and start again. `git stash push -- <the other change's files>` is how to get one change verified
  on its own when two of them are in the tree.
- **A model factory that stamps `time()` cannot be compared against one the test built.**
  `AccountUseCases::create()` and `::updatePassword()` stamp `passDate` with `time()`, and five
  expectations in `AccountTest` built theirs by calling the same factory — so whenever the second
  ticked between the two calls the models differed by one and CI went red in whichever pull request
  happened to be open. It is not reproducible on demand, which is what made it read as a mystery
  rather than a bug. `anAccountStampedNow()` compares everything else exactly and takes only
  `passDate` from the actual, after checking it is a timestamp from the last few seconds. Injecting
  `sleep(1)` before the write is how to reproduce it, and how to show a fix works.
- **Faker's `randomNumber($n)` includes zero**, and forms read a zero id as "not given". A fixture
  drawing a group or profile id that way fails about one run in a hundred, on CI, in whichever pull
  request happened to be open. Use `numberBetween(1, …)`.

The rest is mostly, but not entirely, a long tail: of the 642 uncovered statements across **262**
files, **303 sit in 200 files missing three statements or fewer** — individual error branches,
rarely-hit conditionals and unused accessors, worth picking off when touching the surrounding code
and not worth a campaign.

The exception is worth knowing, because "it is all a long tail" was wrong: the **API `Help`
classes** were the largest non-bootstrap block at ~107 statements across 8 files, several of them
untouched entirely. They are pure static parameter definitions, and `ApiHelpMatchesControllersTest`
now exercises every one through `getHelpFor()` while asserting the thing that actually matters —
that each declared parameter is one its controller reads, with the same required flag. Look for a
block like that before assuming the remainder is uniform.

It holds for **forms** as much as endpoints. Four of them had no test at all —
`ClientForm`, `TagForm`, `PublicLinkForm`, `CustomFieldDefForm` — and writing the first one for each
turned up three defects in two of them: a model key that did not match the declared property, so
`isGlobal` never persisted and the "global client" switch did nothing; `0 === $x` against a value
`analyzeInt()` answers as **null** when the field is absent, letting a definition with no type or
module reach two NOT NULL columns; and a typed property with no `= null`, which every other form has,
making `getItemData()` a fatal rather than the null its return type promises. Compare a form against
its siblings before reading it closely — all three showed up as the one that differed.

`NotificationForm` had the same `0 ===` mistake against `analyzeInt()`, and it is worth knowing what
it cost, because the obvious reading was wrong. The form's "Select User" option posts an **empty
string**, which `analyzeInt()` reads as null, so `$userId === 0` was false and an administrator's
`onlyAdmin`/`sticky` were dropped. That looks like a disclosure — a broadcast with `onlyAdmin = 0` is
visible to everyone — but the target check two methods below refuses a notification with no user and
neither flag, so the submission came back as **"A target is needed"**, naming the one thing the
administrator had supplied. The feature was not leaky, it was **unreachable**: no broadcast could be
created through the web form at all. Follow the value to the end before describing the damage.

Writing the first test for an endpoint has been the most reliable way to find a real defect here:
the REST user, notification and auth-token endpoints each had one — a credential leak, an
authorization gap and a create that answered with nothing usable — and none of them had a test
before. Prefer an untested surface over an uncovered branch in a tested one.

## Web request flow & DI container

`public/index.php` (or `public/api.php`/`bin/cli.php`) → `src/Base.php` builds the **php-di** container and runs
`Bootstrap::run($dic->get(BootstrapInterface), $dic->get(ModuleInterface))`. Per request:
`Bootstrap::handleRequest()` → `Router::dispatch()` (Symfony Routing catch-all) →
`manageWebRequest()` resolves the controller from the **`r` query param** and invokes the action.
The rewrite's web entry was **never run by upstream CI** (only the mocked unit/integration suites),
so these runtime contracts are easy to break:

- **Routing:** `?r=<controller>/<action>/<p1>/<p2>` → `<Controller>Controller::<action>Action(...)`;
  empty action → `index` (`src/Infrastructure/Bootstrap/RouteContext.php`). Leaf code reads ids from these
  route params, not the path.
- **DI definition order** (`src/Base.php`): `DomainDefinitions` → `CoreDefinitions` → module
  `module.php`, and **php-di gives later sources precedence** — the specific entry overrides the
  `SP\Domain\*\Ports\*Service` wildcard auto-wiring; the module overrides CoreDefinitions. Keep this order.
- **Optional constructor params are never auto-wired.** php-di skips any parameter that has a
  default value, *even when the container has a binding for its type* — `?FooService $foo = null`
  stays **null** unless a definition passes it explicitly
  (`autowire(X::class)->constructorParameter('foo', get(FooService::class))`, or a `factory()`).
  This fails silently: no error, no log, and mocked tests that inject the dependency by hand still
  pass, so a whole feature can be dead in production while looking wired and tested (this is exactly
  how `AccountAcl`'s per-account ACL file cache never ran). `Application` and `ProvidersHelper` in
  `CoreDefinitions` show the explicit form. Prefer a required parameter for anything that must be
  present; to check an existing one, probe the real container
  (`$dic = require 'src/Base.php';`) rather than reading the class.
- **The compiled container is never revalidated.** php-di writes it once and reuses whatever file
  it finds under that class name, without looking at the definitions behind it — so the *name* is
  the only invalidation there is, and `compiledContainerName()` builds it from the module **and the
  application version**. The module part stops one entry point's bindings being served to another;
  the version part stops the previous release's container being served to new code. `var/cache` is
  runtime state that survives a deployment, and nothing in the upgrade clears it, so without the
  version an in-place upgrade fatals on every request with a `TypeError` about a constructor
  argument that changed — and cannot be recovered from through the UI, because the container is
  built before `Init` runs and the upgrade page is therefore unreachable. **A stale compiled
  container is also the first thing to suspect when a local instance fatals on a constructor
  signature you have just changed** — it is not a bug in the change; clear `var/cache`.
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
- **"Raw" still goes past Aura's identifier quoter, which quotes whatever follows `AS`.**
  `CAST(COALESCE(\`value\`, '0') AS UNSIGNED) + 1` is emitted as
  ``CAST(COALESCE(`value`, '0') AS `UNSIGNED) + 1` `` — the rest of the expression is swallowed into
  a quoted identifier and the statement will not parse. The SQL is valid when run by hand, so it
  reads as a database problem rather than a builder one; dump `$query->getStatement()` before
  believing either. Write the expression without `AS`: `COALESCE(\`value\`, '0') + 1` casts just as
  well for arithmetic.
- **A numeric comparison against a varchar column needs the column side forced numeric.** `Config`
  stores everything as text, so `value < :limit` compares as text when both sides are strings, and
  `'10' < '3'` is true — a counter would pass a limit of 3 forever once it reached 10. `Database`
  binds an int as `PDO::PARAM_INT`, which settles it, but `+ 0` on the column makes it independent
  of how the value happens to arrive.
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
  Constructor accepts `?array $properties`. Exception: `ConfigData` still has setters (it extends
  `DataCollection`, not `Model`, and is a mutable DI-singleton config store by design).
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
  `SP\Infrastructure\Http\Ports\ResponseService` + `SP\Infrastructure\Bootstrap\Router`).
- Faker 1.24 emits `trigger_deprecation()` notices (provider API deprecated for Faker 2) —
  these are library-internal (not PHP-level), suppressed by `display_errors=0` in `phpunit.xml`.
  `session.sid_bits_per_character` was removed from `SessionLifecycleHandler::SESSION_OPTIONS`.

## Where the defects have actually been

A record of what repeatedly turned out to be broken, because the pattern predicts the next one
better than any coverage number does.

**A guard that is not where the change happens.** The commonest shape here, and the one that reads
as correct in review. A public link's view limit and the temporary master password's fifty-attempt
cap were both tested in PHP against a row that had already been read, so two requests arriving
together both passed — and the attempt counter was written back as `$attempts + 1`, an absolute
value worked out from that same stale read, so guesses in parallel advanced it by one between them.
The master password's rotation re-encrypted every secret inside a transaction and then stored the
hash describing them outside it, leaving a vault nobody could open if those last two writes failed.
`40024210101.sql` made two commits out of one logical change, and DDL commits as it goes, so a
refused second statement left an upgrade that could be neither finished nor repeated.
The same shape appears as a guard that stops *half* of an operation: demo mode skipped every account
in the master-password rotation and reported them all done, while the custom fields were re-encrypted
and the new hash was written — so a demo instance ended up believing a password that opened none of
its accounts. A guard that lets an operation continue has to stop all of it or none.

Where the codebase gets this right it is always the same move — the guard and the change are one
statement: `UserPassRecover::toggleUsedByHash()` consumes a reset token with `used = 0` in its
`WHERE` and throws when it affects nothing, `InstallThrottle` holds an exclusive `flock` across its
whole read-modify-write, `countViews + 1` is arithmetic the server does. **Ask where the decision is
taken and where the change lands; if they are not the same statement, work out what fits between
them.**

**The same rule, asked at the other door.** Every rule here has two ways in — the web and the API,
and often a create path and an edit path — and it is reliably enforced at the one that was written
first. A rule that is *in a form* is the strongest tell, because the API has no forms: `UserForm`
refused to touch the demo account and to change a password past its policy lifetime, and the REST
user and account endpoints did neither. Custom-field values were masked for a caller without
`CUSTOMFIELD_VIEW_PASS` in the web's view and handed over in full by the API's. Demo mode was
enforced in five web config actions and mentioned nowhere on the API at all — the sharpest case,
because a demo publishes its administrator's credentials, so the ACL stops nobody and that guard is
the whole boundary. Search paging clamped a negative offset in one of the two DTOs that carry one.
It runs the other way too, and that is the more useful half: the API re-reads the user on every
request and refuses a disabled one, while the web trusted what login had put in the session — so
disabling an account stopped its token at once and left its browser session working, and since the
timeout is measured from the last request, the session being actively used is the one that never
expires.

**Take a rule you can see being enforced and go looking for its other door.** When you find the gap,
put the check somewhere both doors reach — a shared base method, or the service under them — rather
than a second copy that will drift; and move the constant it compares against out of whichever one
owned it privately.

**A paged query with a non-total order.** `LIMIT`/`OFFSET` over an `ORDER BY` that does not
determine a unique order lets the database return tied rows differently for each page, so one row
arrives on two pages and another on none. Asked of this schema directly — `ORDER BY countView DESC`
over 104 accounts in pages of ten — **63 accounts appeared on no page at all and 34 on two**. The
account search sorted on one non-unique column for every sort key it offers, and view count is the
worst of them because most accounts sit at zero and therefore all tie.

The same applies to a `LIMIT 1` **selector**, which is not paging at all but has the identical
defect: `ItemPreset::getByFilter()` chose a preset with `ORDER BY score DESC LIMIT 1`, where `score`
is `priority + 3 / + 2 / + 1` by specificity — so two group presets at the same priority tie, which
a user in two groups reaches from an ordinary configuration, and which password policy or default
permissions applied to them was decided by nothing. When picking the direction of a tie-break, check
what the database is *already* doing: here it returned the lower id, so `id ASC` settles the
question without moving anybody's effective policy, where `id DESC` would silently have changed it.

Pin this on the **statement**, not by paging a real table: whether a given plan happens to be stable
is the database's business and moves with volume, statistics and version — the joined search query
was stable at 150 rows while the direct query was wildly not. What the application controls, and
what has to hold whatever the plan, is that the ordering it asks for is total.

Every table here has `id` for a primary key, so the rule is one rule with no exceptions list to
rot: **the last thing a paged search orders by is the primary key**. It is applied even where the
leading column is already unique (`UserGroup.name`, `UserProfile.name`, `Plugin.name`), because it
costs nothing there and survives somebody dropping that index later.
`PagedSearchesAreTotallyOrderedTest` holds every paged repository to it, so a new search cannot be
added without one. Worth knowing while reading the schema: `Category.name`, `Client.name` and
`Tag.name` are **not** unique — only their `hash` is — so those three needed it despite looking
like natural keys.

**A file we write, at whatever umask happens to be set.** The backup archives are deliberately
`0600`, with a comment saying why: they hold the DB dump and `config.xml`, and the process umask is
typically `0644`, which on a shared host is every local user. The XML export holds the same
installation — every account's encrypted secret and its key, and when no export password was given
the name, login, URL and notes of every account **in the clear** — and was left at whatever the
umask gave it, measured as `0644`. The uncompressed archive had the same gap for as long as
compressing took, though `database.sql` beside it was restricted the moment it was opened, for
exactly that reason.

**Anything this application writes that holds account data is restricted to its owner**, and the
place to check is not only the finished artefact but every intermediate on the way to it — and what
is left behind when the run fails, since an interrupted backup leaves its intermediate at the umask
indefinitely. `config/config.xml` is the exception that is fine: it is `0644` itself, but
`ConfigUtil` holds its *directory* at `0750`, and that is the control.

**A guard on the read but not on the write.** `Notification` has the rule written down and named —
`checkUserAccess()`, admins may reach any notification and regular users only their own, answering
"not found" so ids cannot be enumerated by the difference. It was called from `getById()` and
`setCheckedById()`, and not from `delete()` or `deleteByIdBatch()`, where the repository's only
condition is `sticky = 0`. Both delete paths now read the row first.

**Correcting the record on that one (#841 overstated it).** The PR said any signed-in user could
delete another user's notification, on the grounds that `Acl` returns `true` for the notification
actions unconditionally. `NOTIFICATION_VIEW`, `NOTIFICATION_SEARCH` and `NOTIFICATION_CHECK` are in
that list — **`NOTIFICATION_DELETE` is not in the switch at all**, so it falls through to the deny
at the end, and only `isAdminApp` (which short-circuits at the top of `checkUserAccess()`) ever
reached those methods. The ownership check is still right, as defence in depth and for consistency
with `setCheckedById()`, but it was not the reachable hole it was described as. **Read the ACL arm
for the exact action, not for its neighbours** — three of the four notification actions being
unconditional says nothing about the fourth.

**An update that matched nothing, reported as saved.** Eight of the thirteen services check what
their `update()` affected and throw when it is zero; `PublicLink` and `CustomFieldDefinition` threw
the count away, so editing something another session had already deleted came back as saved. The
repositories were already counting — only the services ignored it.

Before adding such a check, know that **the connection sets `Pdo\Mysql::ATTR_FOUND_ROWS`**, so
affected means *matched*, not *changed*: a save that alters no field still returns 1 and is not
mistaken for a missing row. Without that attribute this check would turn every unchanged save into
"not found", which is worth measuring through the application's own PDO options rather than the
`mariadb` client, since the client does not set it and answers 0 for the same statement.

**A static factory a subclass cannot use.** `SPException` offers `error()`, `info()`, `critical()`,
`warning()` and `from()`, each doing `new static($message, …)`. Four subclasses fix their own message
and take `int $type` first instead — `AccountPermissionException`, `UnauthorizedActionException`,
`UnauthorizedPageException`, `UpdatedMasterPassException` — so
`UnauthorizedActionException::error('…')` hands a string to a parameter declared `int` and dies with
*"Argument #1 ($type) must be of type int, string given"*. The factory idiom is used everywhere else
(`ServiceException::error()`, `ValidationException::error()`, which are plain subclasses and inherit
the constructor unchanged), which is exactly why reaching for it on these four is a natural mistake.

**Raise those four with `new` and a type.** Pinned in `UnauthorizedActionExceptionTest` rather than
fixed: they are constructed at some twenty sites that all pass a type first, so changing the
signature is wide, behaviour-neutral churn to close a trap nobody has yet stepped in.

**One of the siblings already gets it right.** Where a small family of near-identical methods does
the same job for different types, the correct one is usually already there, and reading it settles
the design before you invent one. The API's four parameter readers are the case: `getParamArray()`
checked the type it had been sent and answered `Wrong parameters` with a 400, while `getParamInt()`,
`getParamString()` and `getParamRaw()` each handed the value straight to something typed — so
`{"name": 123}` was a `TypeError` escaping as a 500 with the class, the method and the server's
absolute path in the body, on every string and integer parameter of every endpoint. The same value
in a query string was fine, since everything arrives as a string there.

**Find the sibling that is right before deciding what right means** — and once the rule is shared,
have the one that already had it defer to the shared copy, or there are two definitions again.

**The wiring, not the code.** php-di skips a constructor parameter that has a default *even when the
container has a binding for its type*, silently. `Init::$sessionKeyService` was null that way, so
`reKey()` — and the `session_regenerate_id()` inside it — never ran, and session identifiers were
never rotated for logged-in users. `AccountAcl`'s per-account cache died the same way earlier.
Neither is visible in the unit suite, which mocks the container, nor in `CompilableContainerTest`,
which asserts a container *compiles*: a definition compiles perfectly well and still throws, or
silently under-fills, when something asks for it. `OptionalDependenciesAreWiredTest` builds each
module and fails on any defaulted service-typed parameter left null. **Probe a real container
before believing a dependency arrived.**

**A refusal that costs a different amount.** The reply can be identical and still tell the caller
what it was meant to withhold. `DatabaseAuth::authUser()` answered `Wrong login` for a name that
does not exist and for a wrong password alike — but the first returned as soon as `getByLogin()`
threw, while the second ran a bcrypt verify. Measured on this installation, which hashes at cost
12: **277ms against 0.7ms**. One request per candidate name told an unauthenticated caller which
logins were real, without their ever guessing a password. The fix is to spend the same time on the
way to the same answer — verify against a fixed hash nothing can match — and the test asserts the
two paths against *each other* rather than against a number of milliseconds, so it calibrates
itself to whatever machine it runs on.

Watch for the same shape wherever a lookup precedes a check: the public link's "never issued"
answered differently from "expired" until it was made to answer the same, and the password reset's
two refusals are deliberately one message. **Ask what a refusal costs, not only what it says.** Note
that a fixture is easy to get wrong here — `UserDataGenerator` puts a plain string in `pass`, which
`password_verify()` rejects as malformed and returns from at once, so a timing comparison against an
unmodified generated user is two fast paths agreeing and proves nothing.

**A comparison done in the wrong domain.** These behave correctly in the common case, which is why
they survive review. `Hash::getKey()` measured bcrypt's 72-**byte** limit with `mb_strlen()`, so a
40-character CJK password (120 bytes) skipped the SHA-256 pre-hash and bcrypt truncated it —
Latin-script passwords were never affected. `Address::check()` compared IPv6 addresses through
`ip2long()`, which answers `false` for them, so every IPv6 client matched every network. When one
of these turns up, sweep the class rather than fixing the instance.

**A parser told to ignore its own limits.** `LIBXML_PARSEHUGE` on the XML import relaxed the entity
expansion limit: 542 bytes expanded to 3,000,000 characters, where libxml refuses the same file by
default. `Serde::deserialize()` allows *every* class when it is not told which to expect, which is
the wrong way round for a default — cache files were built into arbitrary objects.

**Tests that assert nothing.** Five times a green test was pinning nothing: mocking a method the
code no longer called, asserting an element count that survived the field being blank, matching a
string that appeared elsewhere in the page. **Break the fix and re-run.** A test that does not fail
without the change has not been shown to test it.

**Only the integration suite sees the wiring.** Restricting `FileCache::load()` broke `Action[]` and
`MimeType[]` — both read as plain arrays from their variable names, and neither is what the docblock
says. The unit suite mocks the cache and passed twice while the application was broken.

## Swept and clean — where the defects are not

The other half of the record above. Each of these was swept exhaustively and came back clean, so a
later session can start somewhere else instead of re-deriving them. A lens listed here was checked
against the code as it stood, not reasoned about: where a claim needed a fact, the fact was measured.

- **Injection.** Every `where()` clause carrying a variable also carries a `:placeholder` — none
  interpolate. `ORDER BY` is a `match` over constants with a `default`, so a caller's sort key can
  only be a fixed literal. An array bound to `IN (:ids)` is expanded into one placeholder per
  element, word-boundary guarded so `:id` cannot corrupt `:idOwner`. The only raw-SQL sites are
  literal expressions (`->set()`) and the installer's `GRANT`, which `quote()`s every value and
  escapes the LIKE wildcards in the database name.
- **Path traversal on the `resource/css|js` route**, which takes a caller-supplied base directory
  and file list and answers unauthenticated. `ResourceBase`'s constructor verifies an HMAC over
  every query parameter, keyed by the password salt, before any action runs — so `b` and `f` cannot
  be forged. `getSecureAppPath()` is the second layer, not the only one; on its own it would allow
  `b=/config`, since `APP_ROOT` contains it.
- **LDAP.** Every value reaching a filter goes through `ldap_escape(…, LDAP_ESCAPE_FILTER)`, in
  `LdapUtil::getAttributesForFilter()` and in the one hand-built filter in
  `LdapActions::searchGroupsDn()`. The unauthenticated-bind bypass is closed three times over:
  `Login` rejects an empty password before any provider runs, `LdapConnection::connect()` uses `??`
  so a provided-but-empty password is passed verbatim rather than falling back to the service
  account's, and Laminas throws on an empty password with a username (`allowEmptyPassword` defaults
  false and is never enabled here).
- **Object-level authorisation.** Every API endpoint acting on an existing account calls
  `checkAccountAccess()`; create has no account to check and search is scoped by
  `AccountFilterUser` in the query. On the web, both `getPasswordView()` and `getPasswordClear()`
  call `checkActionAccess()`, and `getPasswordForId()` / `getPasswordHistoryForId()` build through
  `AccountFilterUser` and throw when the row is not visible. History metadata is the exception that
  proves it: its query *cannot* be filtered, because history has to outlive the account it
  describes, so `AccountHistoryHelper::checkAccess()` enforces the ACL instead.
- **Deserialization.** Every `unserialize()` in `src` passes `allowed_classes`, and every
  `Serde::deserialize()` names the class it expects — bar `ConfigBackup::configToJson()`, recorded
  below with the reason.
- **Foreign keys.** Nine tables have `*Id` columns without one, and each is explained: polymorphic
  targets (`moduleId`/`itemId`), ids from `actions.yaml` rather than a table (`AuthToken.actionId`),
  history that outlives its account, audit rows that must survive a user's deletion
  (`EventLog.userId`, `Track.userId` — an FK there would make a user undeletable), and `parentId`'s
  `0` sentinel, which no FK can represent.
- **Import.** The whole import runs inside `transactionAware`, so a failure rolls all of it back.
  Accounts are created through `AccountService::create()`, so presets, permissions and history
  apply; ownership comes from the import parameters. CSV refuses a row whose field count is wrong
  rather than building a partial account.
- **Deployment layout.** `DocumentRoot` is `public/`, so `config/config.xml` and `var/backup` are
  not reachable: a request for them falls through the rewrite to the login redirect while genuine
  assets under `public/` are served directly.
- **Also checked and sound:** the privacy and permission presets are applied on both create and
  update (and bulk edit cannot write `isPrivate`); the config cache invalidates on the config file's
  mtime and is rewritten at save; a custom field's value is read as encrypted or not according to
  **the row**, not the definition's current flag, so toggling the flag cannot corrupt existing
  values; and every date comparison found compares epoch against epoch.

## Known non-issues — audited, do NOT "fix"

- **The session-timeout preset matches on the *forwarded* address, unlike everything else that
  makes a security decision.** `Init::getSessionTimeoutForUser()` reads `getClientAddress()`, which
  prefers the client-supplied `Forwarded` / `X-Forwarded-For` header, while
  `Track::buildTrackRequest()` and `InstallThrottle::check()` both key on `REMOTE_ADDR` because
  that header is spoofable. The difference is deliberate: behind a reverse proxy `REMOTE_ADDR` is
  the proxy, so an address-based preset would match every user or none and the feature would stop
  working for the deployments that configure it. The limiters accept that cost because rotating a
  header defeats a brute-force limit outright; here it only extends an already-authenticated
  session and grants no access. The consequence — **the preset is a convenience, not a boundary**,
  and anyone can claim an address to get the timeout set for it — is recorded at the call site.

- **A password reset leaves the next sign-in asking for the previous password.** Not a broken
  reset: the user's master password is sealed with a key derived from their login password, so
  changing it without the old one leaves the vault unopenable and the login asks for the old one to
  re-key it. Somebody who used the flow *because they forgot* cannot supply it and needs an
  administrator to issue a temporary master password, which the login form takes in its place. This
  is the crypto, not a defect — `PasswordResetFlowTest` records it with the reason.

- **`sp:backup` has no demo-mode guard, and should not get one.** The web and the API refuse a
  backup on a demo instance because both are remote doors and a demo publishes its administrator's
  credentials, so any visitor can reach them. The CLI is not that: running it needs shell access on
  the server, and anyone with that already has `config/config.xml` — the database credentials and
  the crypto keys — and can dump the database directly. A guard there would protect nothing while
  stopping an operator from backing up their own demo. `sp:updateMasterPassword` **is** refused on a
  demo, but for a different reason: that rotation corrupts the instance rather than merely copying
  it, and the refusal lives in `MasterPass::changeMasterPassword()`, which every door reaches.

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
- **`ConfigBackup::configToJson()` calls `Serde::deserialize()` without naming a class, and that is
  where it stays.** Restricting it to `ConfigData::class` was tried and reverted: a sysPass 3.2
  backup holds `O:20:"SP\Config\ConfigData"`, a class this rewrite does not have, and the `is_a()`
  arm throws before the `__PHP_Incomplete_Class` arm can deal with it — so the restriction turns
  reading an old backup into a fatal error. The path that actually applies a backup, `restore()`,
  **is** restricted, and this one only deserializes in order to re-serialize to JSON, over the
  `config_backup` row the application itself wrote. Every other `unserialize()` in `src` passes
  `allowed_classes`, and every other `Serde::deserialize()` names what it expects; this is the one
  exception and it is deliberate.
- **`jquery-ui` is in `package-lock.json` but not in `package.json` — not drift.** It is an
  `optionalDependencies` entry of `@selectize/selectize` (drag_drop plugin support), locked like
  any transitive dep (`npm ls jquery-ui` shows the chain; a fresh `npm install` keeps it). It is
  never imported in the vendor bundle entry and never served. Likewise, a locked version ahead of
  a `^` constraint (e.g. `jsencrypt` `^3.3.2` → lock 3.5.4) is normal semver resolution, and
  bundle currency is enforced by CI's drift check.
- **47 `route:` entries in `resources/actions.yaml` resolve to no controller — audited, and left
  alone.** Every route the application *hands out* does resolve; those 47 belong to ACL action ids
  nothing ever passes to `getRouteFor()` (`CATEGORY`, `WIKI_*`, `FILE_*`, the `CONFIG_*` section
  ids, …), left over from before the rewrite split one controller per action. They exist so
  permissions can be named, so deleting the actions would drop those permissions, and inventing a
  controller for each would be inventing a feature. `RoutesAreDispatchableTest` lists them and
  asserts each is *still* unrouted and *still* never handed out — so the list cannot silently
  excuse a new one. Everything not on it must resolve **and** satisfy the dispatch contract.

## A token that carries a vault, and what that costs

Some actions need the master password, so a token issued for one carries a **vault**: the master
password sealed with the token's own password *and* the token itself. `AuthToken::needsSecureToken()`
is the single definition of which actions those are — `SECURED_ACTIONS` plus
`CAN_USE_SECURE_TOKEN_ACTIONS` — and both the web form and the API ask it.

`POST /api/v1/auth-tokens` used to answer **500 "Error while retrieving master password from
context"** for every one of them, with or without a password, while the web created the same tokens
without difficulty: sealing a vault needs the master password on the context, and the API only ever
loads that from the *calling* token's own vault, which `AUTHTOKEN_CREATE` did not have.

It works now, and the way it was made to work is a decision worth knowing rather than a detail:
**`AUTHTOKEN_CREATE` and `AUTHTOKEN_EDIT` are themselves on `CAN_USE_SECURE_TOKEN_ACTIONS`, so a
token that can mint tokens also carries the master password.** That is the authority the web already
grants — an administrator who can reach the tokens page has unlocked the vault with their own
password — but on the API it is a bearer credential sitting in somebody's script, so it is worth as
much as the vault. Two things follow, and both are enforced in
`AuthTokenBase::prepareSecureToken()`:

- the password on such a token is **required**, not optional. Without it the vault is sealed with
  the empty string and nothing can open it: `Api::getMasterPassFromVault()` reads `tokenPass` as a
  required parameter, and required refuses the empty string, so the one password that would work
  cannot be presented. Do not "fix" that by letting the empty key through;
- creating one requires a calling token that already carries a vault, so an `AUTHTOKEN_CREATE`
  token minted before this existed has none and gets a 401 until it is re-issued. The web can
  always issue the first one.

## Escaping: on output, never on input

**Text is stored exactly as it was typed, and escaped where it is rendered.** This was the other
way round until recently — `Filter::getString()` ran `htmlspecialchars()` over every web form field
and every REST parameter — and the four changes that turned it around are worth knowing about
before touching any of it:

- **The view escapes.** `Html::escape()` is the one definition (`ENT_QUOTES | ENT_SUBSTITUTE`,
  UTF-8, null-accepting), bound into template scope as `$_e` beside `$_getvar` — a closure and not
  a class, because an included file does not inherit the including file's namespace. Inside a
  `<script>` element the rule is different and `$_e` is wrong: a script element is raw text, so
  entities arrive as the literal characters `&quot;`, and what ends the element is `</script>`
  appearing anywhere in it. That is `Html::jsValue()`, bound as `$_j`.
  `ThemeEscapesWhatItRendersTest` holds both rules per template, exempting by *shape* — a
  translated literal, an icon class, a cast, arithmetic, a ternary between literals — plus the two
  methods that compose markup on purpose (`AccountSearchItem::getAccesses()`, `getShortNotes()`),
  which would be just as broken if escaped. Do not add a file to an exemption list; there isn't one.
- **Messages escape too.** `HtmlFormatter` composes the HTML for notification mails and the
  notices panel out of event details, which are account names, file names and logins.
- **A URL is not text.** `Html::escape()` does nothing about `javascript:`, so `Html::isSafeUrl()`
  is what decides whether a stored address may become an `href` — a denylist (`javascript`, `data`,
  `vbscript`), because the addresses in a password manager are `ssh://`, `rdp://`, `sftp://`.
- **Nothing rewrites a value on the way in.** `Filter::getString()` trims and scrubs invalid UTF-8
  (the latter used to be a side effect of `ENT_SUBSTITUTE`) and does nothing else.

The escaping was never the guard it looked like: `Filter::getString()` used `ENT_NOQUOTES`, so it
left both quote characters alone and did nothing for the 274 places the theme interpolates into an
attribute. What it did do was corrupt: a category created as `Q&A <b>notes</b>` was stored, and
answered by the API, as `Q&amp;A &lt;b&gt;notes&lt;/b&gt;`; the UI escaped it again and showed
`Q&amp;A`; and an LDAP filter `(&(objectClass=user))` was handed to the directory as
`(&amp;(objectClass=user))`, where `&amp;` is not an operator and the search matches nothing.

`TextIsStoredAsTypedTest` is the round trip that pins it, and `schemas/40024240101.sql` plus
`UpgradeConfigText` are what bring rows and settings written before the change into line.
**That migration is not idempotent and no decode of stored text could be** — run twice,
`&amp;amp;` goes from `&amp;` to `&`, and nothing in the value says whether it has been decoded.
What keeps it to one run is the database version, and the file is wrapped in a transaction so an
interrupted run leaves the rows alone rather than half-decoded with no version written. Encrypted
custom field values are **not** migrated: reading them needs the master password, which an upgrade
does not have.

## Conventions

- One logical change per PR; clear title (`old → new` + why) and body.
- `config/config.xml` holds DB creds + crypto keys — never commit it.
