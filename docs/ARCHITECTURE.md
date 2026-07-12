# Architecture

sysPass follows a **hexagonal (ports & adapters) architecture** organised around
**Domain-Driven Design (DDD)** principles, with three layers under `src/`. Bounded
contexts (`Account`, `User`, `Auth`, `Config`, …) live in `Domain/` and
`Application/`; concrete implementations live in `Infrastructure/`. Each layer has
a strict dependency direction: inner layers never depend on outer layers.

```
Domain  ←──  Application  ←──  Infrastructure
```

## Layers

### Domain (`src/Domain/`)

Pure business logic with no framework dependencies. Organised by bounded context
(Account, User, Auth, Config, etc.):

```
src/Domain/<Context>/
  Models/       ← immutable value objects (mutate() for copies)
  Dtos/         ← data transfer objects (public readonly constructor props)
  Ports/        ← interfaces (driven ports — repositories, services)
  Services/     ← domain services (pure logic, no I/O)
  Adapters/     ← adapter interfaces for external representation
```

`Domain/Core/` is the **shared kernel** — cross-cutting domain contracts available
to all layers: ACL definitions, bootstrap interfaces, context ports, cryptography
ports, event interfaces, exception hierarchy, HTML helpers, message builders, and
UI icon abstractions.

`Domain/Common/` holds shared base classes: `Model`, `Dto`, `SerializedModel` trait,
enums, and the `ActionResponse` return type used by all controllers.

### Application (`src/Application/`)

Use cases that orchestrate domain services. Also organised by bounded context:

```
src/Application/<Context>/
  Ports/        ← driving port interfaces (what the outside world calls)
  Services/     ← use-case implementations
```

Application services depend on Domain ports (injected via DI) and contain no
infrastructure details.

### Infrastructure (`src/Infrastructure/`)

Concrete implementations of ports and all framework/library integration:

```
src/Infrastructure/
  Adapter/
    In/                          ← driving adapters (receive requests)
      Web/                       ← web controllers, views, DataGrid, forms
        Controllers/<Context>/   ← one controller per action
        DataGrid/                ← table/grid presentation components
        View/                    ← template engine (TemplateInterface)
      Api/                       ← REST API controllers
        Controllers/<Context>/
      Cli/                       ← Symfony Console commands
        Commands/
    Out/                         ← driven adapters (call external systems)
      <Context>/Repositories/    ← database repository implementations
```

Infrastructure also houses the **shared kernel implementations** — the concrete
classes that satisfy `Domain/Core/` interfaces:

```
src/Infrastructure/
  Acl/          ← action registry and permission checks
  Bootstrap/    ← router, path resolution, request lifecycle
  Context/      ← session and application state
  Crypt/        ← encryption implementations (AES, RSA/PKI)
  Database/     ← PDO/query-builder helpers
  Definitions/  ← PHP-DI container definitions
  Events/       ← event dispatcher
  File/         ← filesystem utilities
  Html/         ← HTML rendering helpers
  Http/         ← HTTP layer (ResponseService, middleware)
  Log/          ← Monolog logging setup
  Storage/      ← file-based storage (XML config)
  UI/           ← ThemeIcons implementation
  Util/         ← shared utility classes
```

`src/Base.php` is the bootstrap entry point that builds the DI container and
dispatches to the appropriate module.

## Entry points

| Entry point | Route | Purpose |
|---|---|---|
| `public/index.php` | Web UI | Loads `src/Base.php` with `APP_MODULE = 'web'` |
| `public/api.php` | REST API | Loads `src/Base.php` with `APP_MODULE = 'api'` |
| `bin/cli.php` | CLI | Loads `src/Base.php` with `APP_MODULE = 'cli'` |

Each module has its own DI definitions in
`src/Infrastructure/Adapter/In/{Web,Api,Cli}/module.php`.

## Request lifecycle (web)

```
index.php → Base.php (build DI container)
  → Bootstrap::run()
    → Bootstrap::handleRequest()
      → Router::dispatch() (Symfony Routing)
        → manageWebRequest()
          → resolve controller from ?r=<controller>/<action>
            → <Controller>::<action>Action()
              → returns ActionResponse
```

The `r` query parameter drives routing: `?r=account/view/42` resolves to
`AccountController::viewAction(42)`. Empty action defaults to `index`.

## DI container

The container is built by [PHP-DI](https://php-di.org/) with definitions loaded in
this order (later sources override earlier ones):

1. **DomainDefinitions** — wildcard auto-wiring for domain ports
2. **CoreDefinitions** — concrete bindings, path resolution, caches
3. **Module definitions** (`module.php`) — module-specific overrides

In production (`DEBUG=false`), the container is compiled and lazy proxies are
written to disk for performance. In development (`DEBUG=true`), it's built fresh
on each request.

## Other key directories

| Path | Purpose |
|---|---|
| `public/themes/material-blue/` | The single UI theme (MDL-based) |
| `public/vendor/js/` | Third-party libraries bundled by esbuild (`npm run build:js` via `scripts/build-js.mjs` + entry `scripts/vendor-entry.mjs`); committed, nothing to install to run the app |
| `public/js/` | Hand-authored app code — `app-*.min.js`, `toasts.min.js`, `zxcvbn-async.min.js`, `selectize-plugins.min.js` — no `*.js` source, no build step; served via `JsController::JS_APP_MIN_FILES` |
| `resources/` | Locale `.po`/`.mo` files, action/mimetype YAML |
| `schemas/` | Database schema (`dbstructure.sql`) and XML config schema |
| `config/` | Runtime config (`config.xml`, keys) — gitignored |
| `var/` | Runtime-writable (`cache/`, `temp/`, `backup/`) |
| `tests/` | PHPUnit test suites — mirrors `src/` structure |

## Dependency rules

- **Domain** depends on nothing outside `Domain/` (and PHP built-ins).
  `Domain/Core/` is the shared kernel — all layers may depend on it.
- **Application** depends on Domain ports, never on Infrastructure.
- **Infrastructure** depends on Domain, Application, and external libraries.
- **Templates** (`.inc` files in `public/themes/`) import Domain classes
  (e.g. `SP\Domain\Core\Html\Html`) — the dependency direction is inward
  (Infrastructure → Domain), which is correct hexagonal.
