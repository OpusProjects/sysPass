# sysPass — Systems Password Manager

[![sysPass CI](https://github.com/OpusProjects/sysPass/actions/workflows/ci.yaml/badge.svg)](https://github.com/OpusProjects/sysPass/actions/workflows/ci.yaml)
[![License: GPLv3](https://img.shields.io/badge/license-GPLv3-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-8.4%2B-777BB4.svg?logo=php&logoColor=white)](https://www.php.net/)

> 🛠️ **This is a fork.** This repository is a continuation of the original
> [sysPass](https://github.com/nuxsmin/sysPass) by **Rubén Domínguez ([@nuxsmin](https://github.com/nuxsmin))**,
> picked up by [OpusProjects](https://github.com/OpusProjects) after the
> original project was discontinued by its author. All original work, copyright, and the
> GNU GPLv3 license are retained and credited. See the Original project status section.

sysPass is a PHP web-based password manager for business and personal use.

---

## ✨ Features

- **AES-256 encryption**: CTR mode, all passwords encrypted at rest
- **RSA key exchange**: passwords leave the browser already encrypted
- **Two-factor authentication**: TOTP-based 2FA
- **HTML5 / Ajax UI**: single-page interface, no full-page reloads
- **Access control**: users, groups and profiles with up to 29 access levels
- **Authentication backends**: local, OpenLDAP and Active Directory
- **Rich account model**: tags, custom fields, public links, private accounts, favorites and history
- **Notifications & audit log**: activity alerts by email and in-app, plus a full event log
- **Multilanguage**: internationalised UI
- **REST API**: full programmatic access

---

## 📜 Original project status

The original sysPass was created in 2010 and maintained by **Rubén Domínguez ([@nuxsmin](https://github.com/nuxsmin))**.

Its last release, **[3.2.11](https://github.com/nuxsmin/sysPass/releases/tag/3.2.11)**, shipped on **2 July 2022**, then went dormant.

In **May 2026**, the author confirmed he had no time to continue developing it and called for a fork.

In his own words ([nuxsmin/sysPass#1954, 2026-05-05](https://github.com/nuxsmin/sysPass/issues/1954#issuecomment-4382203218)):

> _"That being said, I believe it would be better to either fork this application and continue
> its development or look for another one. I know it could be disappointing, but the reality is
> that I don't have the time to continue evolving it with the required quality standards."_

This fork exists to keep sysPass alive. It started on **20 June 2026** from the last upstream release
(**3.2.11**) as a baseline.

---

## 📜 Current project status

This fork carries **[@nuxsmin](https://github.com/nuxsmin)**'s planned [hexagonal-architecture rework](https://github.com/nuxsmin/sysPass/tree/feat/code_refactoring) forward to keep evolving the project.

Tech stack:

- **PHP 8.4+** — language runtime
- **Symfony 8** — HTTP, routing & console components
- **PHP-DI** — dependency-injection container
- **Composer** — dependency management
- **Docker** — containerised dev stack
- **MariaDB / MySQL** — database
- **PHPUnit 13** — test suite
- **npm / Node** — build & test tooling (Playwright E2E; not needed at runtime)

Architecture & design:

- **Hexagonal architecture** — ports & adapters
- **Domain-Driven Design (DDD)** — `Domain/` + `Infrastructure/` layering

---

## 📚 Documentation

| Document | What it covers |
|---|---|
| [API](docs/API.md) | REST API reference, authentication, and Swagger UI (`/api/docs/` on a live instance) |
| [Architecture](docs/ARCHITECTURE.md) | Hexagonal layer map, request lifecycle, DI container, and dependency rules |
| [CLI](docs/CLI.md) | Command-line tool (`bin/cli.php`): commands, arguments, options, and environment variables |
| [Install](docs/INSTALL.md) | Requirements and installation (Docker and manual, multi-distro) |
| [Testing](docs/TESTING.md) | Running the test suites, test layout, environment requirements, and writing new tests |

---

## 🤝 Contributing

Contributions are welcome: [CONTRIBUTING.md](CONTRIBUTING.md) covers dev setup, PR workflow, coding conventions, and tests.

Security issues: see [SECURITY.md](SECURITY.md) for private reporting.

---

## 👥 Authors

- [Blai Peidro](https://github.com/blaipr)

Part of [OpusProjects](https://github.com/OpusProjects).

---

## ⚖️ License

[GNU GPLv3](LICENSE)
