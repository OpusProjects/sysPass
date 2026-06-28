# 🔐 sysPass — Systems Password Manager

> 🛠️ **This is a fork.** This repository is a continuation of the original
> [sysPass](https://github.com/nuxsmin/sysPass) by **Rubén Domínguez ([@nuxsmin](https://github.com/nuxsmin))**,
> picked up by [OpusProjects](https://github.com/OpusProjects) after the
> original project was discontinued by its author. All original work, copyright, and the
> GNU GPLv3 license are retained and credited. See the Original project status section.

PHP web-based password manager for business and personal use.

- 🔒 AES-256 encryption in CTR mode
- 🔑 RSA for sending passwords from forms
- 🛡️ Two-factor authentication
- 💻 HTML5 and Ajax interface
- 👥 Users, groups and profiles management with up to 29 access levels
- 🗄️ MySQL, OpenLDAP and Active Directory authentication
- 🏷️ Tags, custom fields, public links, private accounts, favorites, history, etc.
- 🔔 Activity notifications by email and in-app, plus an event log
- 🌐 Multilanguage
- 🔌 REST API

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

Current stack:

- **PHP 8.4+** — language runtime
- **Symfony 8** — HTTP, routing & console components
- **Hexagonal architecture** — ports & adapters
- **Domain-Driven Design (DDD)** — `Domain/` + `Infrastructure/` layering
- **PHP-DI** — dependency-injection container
- **Composer** — dependency management
- **Docker** — containerised dev stack
- **MariaDB / MySQL** — database
- **PHPUnit 13** — test suite

---

## 📚 Documentation

Project documentation lives in the [`docs/`](docs) folder:

- [`docs/API.md`](docs/API.md) — REST API reference, authentication, and [Swagger UI](/api/docs/) interactive docs.
- [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) — hexagonal layer map, request lifecycle, DI container, and dependency rules.
- [`docs/INSTALL.md`](docs/INSTALL.md) — requirements and installation (Docker and manual, multi-distro).
- [`docs/TESTING.md`](docs/TESTING.md) — running the test suites, test layout, environment requirements, and writing new tests.

---

## ⚖️ License

This software is published under the **[GNU GPLv3](LICENSE)** license, unchanged from the original project.
