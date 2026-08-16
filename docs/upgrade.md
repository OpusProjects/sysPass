# Upgrading

An upgrade brings an existing installation's database and configuration up to the version of the
code now deployed. This page covers what triggers one, what each migration does, and the two
properties of the text migration that decide whether an interrupted run is safe.

Installing for the first time is a different operation, covered by [install](install.md).

- [What triggers an upgrade](#what-triggers-an-upgrade)
- [The upgrade key](#the-upgrade-key)
- [How a migration is selected](#how-a-migration-is-selected)
- [The migrations](#the-migrations)
- [Why the text migration runs exactly once](#why-the-text-migration-runs-exactly-once)
- [What is deliberately not migrated](#what-is-deliberately-not-migrated)
- [Before upgrading](#before-upgrading)

---

## What triggers an upgrade

Every request checks two recorded versions against the running code, and either one being behind is
enough to divert the request.

```php
// SP\Infrastructure\ModuleBase
$this->isVersionOutdated($this->configData->getDatabaseVersion(), $currentVersion)
|| $this->isVersionOutdated($this->configData->getAppVersion(), $currentVersion)
```

`databaseVersion` is stamped by the database migration that last ran; `appVersion` is stamped once
the whole upgrade completes. Both live in `config.xml`. When either is outdated the web entry point
redirects to the upgrade route, and the API refuses the request rather than serving against a schema
it does not match.

Both are read because they answer different questions. Stamping only the database version left an
installation that had upgraded successfully being sent back to the upgrade page on its next request,
with its one-time key already spent.

---

## The upgrade key

The upgrade page is reachable before anyone has signed in, so it is gated by a single-use key rather
than by a permission.

`ConfigFile` generates 16 random bytes when it detects that an upgrade is needed, and writes them to
`config.xml` as `upgradeKey`. The administrator reads it from that file and supplies it to the
upgrade page; the controller compares with `hash_equals()` and clears the key once the upgrade
succeeds.

Practically this means the key is in the one place an attacker cannot read without already having
the database credentials, and it stops working the moment it has been used.

---

## How a migration is selected

Migrations declare the version they belong to with a repeatable attribute, so one handler can cover
several versions.

```php
#[UpgradeVersion('400.24210101')]
#[UpgradeVersion('400.24240101')]
final class UpgradeDatabase implements UpgradeHandlerService
```

`Upgrade::upgrade()` collects every registered handler whose declared version is ahead of the
installation's recorded one, applies them in order, and saves the configuration after each. A
handler returning anything other than success aborts the run with `UpgradeException`, leaving the
version it failed on unstamped so the next attempt retries it.

`UpgradeDatabase` derives its SQL filename from the version, so `400.24240101` runs
`schemas/40024240101.sql`. A version with no matching file, or a file with no statements, is an
error rather than a silent no-op.

---

## The migrations

Two schema migrations ship today, plus one that rewrites configuration rather than rows.

| Version | Handler | What it does |
|---|---|---|
| `400.24210101` | `UpgradeDatabase` → `schemas/40024210101.sql` | Schema changes for the 4.0 rewrite |
| `400.24240101` | `UpgradeDatabase` → `schemas/40024240101.sql` | Decodes HTML entities in 28 columns across 12 tables |
| `400.24240101` | `UpgradeConfigText` | The same decode for `config.xml`, which SQL cannot reach |

The pair at `400.24240101` exist because escaping moved from input to output. Text used to be run
through `htmlspecialchars()` on the way in, so a category typed as `Q&A <b>notes</b>` was *stored*
as `Q&amp;A &lt;b&gt;notes&lt;/b&gt;` and answered that way by the API. `UpgradeConfigText` covers
the settings file because that is where the LDAP filter lives, and a filter written `(&(objectClass=user))`
had been stored as `(&amp;(objectClass=user))`, where `&amp;` is not an operator and the search
matches nothing.

Within each value the ampersand is decoded **last**. Somebody who typed `&lt;` had it stored as
`&amp;lt;`, and decoding the ampersand first would take that through `&lt;` to `<`, quietly changing
what they wrote.

---

## Why the text migration runs exactly once

This is the property worth understanding before running it, because it cannot be made otherwise.

**The decode is not idempotent, and no decode of stored text could be.** Run twice, `&amp;amp;` goes
from `&amp;` to `&`; nothing in a value says whether it has already been decoded. What keeps it to a
single run is the recorded database version, which `UpgradeDatabase` stamps only after the file has
been applied.

**The file is therefore wrapped in a transaction.** Every statement in it is DML, so an interrupted
run rolls back and leaves the rows exactly as they were, rather than half decoded with no version
recorded — which is the state no second run could repair. `UpgradeDatabase::apply()` has no rollback
of its own; it rethrows as `UpgradeException`, and what unwinds the work is PDO tearing down the
connection.

---

## What is deliberately not migrated

Two categories are left alone, and both would be worse to touch.

- **`EventLog`** is a record of what happened. Rewriting it would be rewriting history.
- **Encrypted custom field values.** Reading them requires the master password, which an upgrade
  does not have and should not ask for. The exclusion is enforced in the statement itself, which
  joins `CustomFieldData` to its definition on `isEncrypted = 0` — unencrypted values are decoded
  like any other text, encrypted ones keep whatever form they were stored in.

---

## Before upgrading

Nothing here is unusual for a schema migration, but the text migration's single-run property makes
the first item matter more than it usually does.

- **Take a backup.** `sp:backup` covers the database and the application; see [CLI](cli.md).
- **Copy `config.xml`.** It holds `passwordSalt` and the crypto keys, and `UpgradeConfigText`
  rewrites it in place. Losing it makes every user's sealed copy of the master password unopenable —
  see [security](security.md).
- **Read `upgradeKey` from `config.xml`** once the upgrade page appears; it is generated at that
  point, not before.
- **Check the event log if a run fails.** Handlers report through it, and `UpgradeDatabase` logs the
  failing statement.
