# Security Policy

## Supported versions

Only the current `main` branch receives security fixes. No backports to the upstream 3.2.x line.

| Version | Supported |
|---|---|
| main (this fork) | Yes |
| 3.2.x (upstream) | No |

## Reporting a vulnerability

Please **do not open a public issue** for security problems.

Use GitHub's private vulnerability reporting instead:
[Report a vulnerability](https://github.com/OpusProjects/sysPass/security/advisories/new)
— it opens a private thread with the maintainers.

Include what you can: affected component or endpoint, reproduction steps, and
impact. You should hear back within a week. Once a fix ships, the advisory is
published and credited unless you prefer otherwise.

## Scope notes

- sysPass stores encrypted credentials. Vault security depends on the master
  password and the AES-256 key derived from it — protect these above all else.
- The REST API uses token-based authentication. Tokens carry account-level
  permissions; treat them like passwords and rotate them if exposed.
- LDAP bind credentials are stored in `config.xml`. Restrict read access to
  that file to the web-server user only.
- sysPass is designed to run behind a reverse proxy on a trusted network.
  Exposing it directly to the internet without TLS and hardened headers is
  outside the tested threat model.
