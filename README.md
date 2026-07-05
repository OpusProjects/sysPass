# sysPass — Systems Password Manager

[![sysPass CI](https://github.com/OpusProjects/sysPass/actions/workflows/ci.yaml/badge.svg)](https://github.com/OpusProjects/sysPass/actions/workflows/ci.yaml)
[![License: GPLv3](https://img.shields.io/badge/license-GPLv3-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-8.4%2B-777BB4.svg?logo=php&logoColor=white)](https://www.php.net/)

sysPass is a self-hosted, open-source web password manager for business and personal use.

The original sysPass was created and maintained by **Rubén Domínguez
([@nuxsmin](https://github.com/nuxsmin))**, who released the last upstream version
(**[3.2.11](https://github.com/nuxsmin/sysPass/releases/tag/3.2.11)**) in July 2022.
In May 2026, he confirmed he no longer had the time to keep evolving it
([nuxsmin/sysPass#1954](https://github.com/nuxsmin/sysPass/issues/1954#issuecomment-4382203218))
and called for someone to fork it.

This repository is that fork — started by [OpusProjects](https://github.com/OpusProjects)
in June 2026, picking up [@nuxsmin](https://github.com/nuxsmin)'s own planned
[hexagonal-architecture rework](https://github.com/nuxsmin/sysPass/tree/feat/code_refactoring)
as the new baseline, built on PHP 8, Symfony, and a full REST API.
All original copyright and the GNU GPLv3 license are retained.

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
