# Configuration

Configuration lives in three places, each with a different owner and a different lifetime: the
environment tells the application where things are, `config.xml` holds what an administrator has
set, and a handful of values live in the database. This page describes all three and the settings
worth knowing before you call a behaviour a bug.

- [The three places](#the-three-places)
- [Environment variables](#environment-variables)
- [config.xml](#configxml)
- [Settings by area](#settings-by-area)
- [Defaults worth knowing](#defaults-worth-knowing)
- [Settings that change behaviour](#settings-that-change-behaviour)

---

## The three places

Which one holds a value follows from when it has to be known and who is allowed to change it.

| Where | Holds | Changed by |
|---|---|---|
| Environment / `.env` | Paths and database connection — everything needed *before* the config file can be read | An operator, at deploy time |
| `config/config.xml` | The ~96 application settings, the crypto salt, and version stamps | The configuration manager in the UI, or the installer |
| `Config` table | The master password hash, temporary master password state | The application itself |

The ordering matters: the environment is read first because `config.xml`'s own location comes from
it, and the database connection has to work before anything in the `Config` table can be read.

---

## Environment variables

Thirteen variables are read, all through `SP\getFromEnv()`, and every one has a working default
except the database credentials the installer writes.

| Variable | Default | Purpose |
|---|---|---|
| `CONFIG_PATH` | `<app>/config` | Directory holding `config.xml`; may sit outside the web root |
| `CONFIG_FILE` | `<CONFIG_PATH>/config.xml` | The configuration file itself |
| `CACHE_PATH` | `<app>/var/cache` | Cache directory; stores many small files |
| `LOG_FILE` | `<CONFIG_PATH>/syspass.log` | Application log |
| `ACTIONS_FILE` | `<resources>/actions.yaml` | ACL action definitions |
| `DEBUG` | `false` | Builds the DI container live instead of compiling it |
| `DB_SERVER` | — | Database host |
| `DB_NAME` | — | Database name |
| `DB_USER` | — | Database user |
| `DB_PASS` | — | Database password |
| `DB_PORT` | `3306` | Database port |
| `DB_SOCKET` | — | Unix socket, as an alternative to host and port |
| `SYSPASS_DIR` | — | Set by the official Docker image; the installer uses its presence to detect a container |

Two details bite in practice:

**`.env` is loaded with `Dotenv::createImmutable()`**, which populates `$_ENV` and `$_SERVER` but
**not** `getenv()`. `getFromEnv()` reads those first and falls back to a real environment variable,
so both work — but code calling `getenv()` directly would see nothing.

**A boolean default is parsed, not cast.** A dotenv value is always a string and `(bool)"false"` is
`true` in PHP, so `DEBUG=false` is understood rather than silently enabling debug mode.

`.env.example` is out of step with this list: it documents `BACKUP_PATH`, `TMP_PATH` and
`MIMETYPES_FILE`, none of which the code reads, and gives `actions.xml` where the default is
`actions.yaml`. Treat the table above as authoritative.

---

## config.xml

This is the file to protect and the file to back up. It is written by the installer and then
maintained through the configuration manager in the UI, so it is not normally hand-edited.

It holds three things that are not ordinary settings:

- **`passwordSalt`** — an input to every user's key derivation and to the public-link key. Losing it
  makes every sealed copy of the master password unopenable; see [security](security.md).
- **`dbPass`** and the rest of the database credentials.
- **`upgradeKey`** — a single-use key generated when an upgrade is pending; see [upgrade](upgrade.md).

It is **gitignored and must never be committed.** `configVersion`, `databaseVersion` and `appVersion`
also live here, and the last two are what decide whether a request is diverted to the upgrade page.

---

## Settings by area

The ~96 settings group into the areas below. All are managed from the configuration manager; the
names are the element names inside `config.xml`, which is what you would grep for.

| Area | Settings |
|---|---|
| Site | `siteLang`, `siteTheme`, `applicationUrl`, `resultsAsCards`, `demoEnabled`, `maintenance` |
| Database | `dbHost`, `dbPort`, `dbName`, `dbUser`, `dbPass`, `dbSocket` |
| Security | `passwordSalt`, `httpsEnabled`, `encryptSession`, `sessionTimeout`, `authBasicEnabled`, `authBasicDomain`, `authBasicAutoLoginEnabled`, `ssoDefaultGroup`, `ssoDefaultProfile` |
| Accounts | `accountCount`, `accountLink`, `accountFullGroupAccess`, `accountPassToImage`, `accountExpireEnabled`, `accountExpireTime`, `globalSearch` |
| Public links | `publinksEnabled`, `publinksMaxViews`, `publinksMaxTime`, `publinksImageEnabled` |
| Files | `filesEnabled`, `filesAllowedExts`, `filesAllowedMime`, `filesAllowedSize` |
| Mail | `mailEnabled`, `mailServer`, `mailPort`, `mailUser`, `mailPass`, `mailFrom`, `mailSecurity`, `mailAuthenabled`, `mailRequestsEnabled`, `mailRecipients`, `mailEvents` |
| LDAP | `ldapEnabled`, `ldapType`, `ldapServer`, `ldapBase`, `ldapGroup`, `ldapBindUser`, `ldapBindPass`, `ldapTlsEnabled`, `ldapDefaultGroup`, `ldapDefaultProfile`, `ldapDatabaseEnabled`, `ldapFilterUserObject`, `ldapFilterGroupObject`, `ldapFilterUserAttributes`, `ldapFilterGroupAttributes` |
| Logging | `logEnabled`, `logEvents`, `syslogEnabled`, `syslogRemoteEnabled`, `syslogServer`, `syslogPort` |
| Wiki | `wikiEnabled`, `wikiFilter`, `wikiPageurl`, `wikiSearchurl`, `dokuwikiEnabled`, `dokuwikiUrl`, `dokuwikiUrlBase`, `dokuwikiUser`, `dokuwikiPass`, `dokuwikiNamespace` |
| Proxy | `proxyEnabled`, `proxyServer`, `proxyPort`, `proxyUser`, `proxyPass` |
| Internal | `installed`, `configVersion`, `databaseVersion`, `appVersion`, `configDate`, `configHash`, `configSaver`, `upgradeKey`, `backup_hash`, `export_hash`, `checkUpdates`, `checkNotices`, `debug` |

---

## Defaults worth knowing

These are the defaults defined in `ConfigData`, and each one is a value somebody eventually asks
about.

| Setting | Default | Meaning |
|---|---|---|
| `accountCount` | `12` | Results per page in the account listing |
| `sessionTimeout` | `300` | Seconds of inactivity before the session ends |
| `filesAllowedSize` | `1024` | Maximum attachment size, in KB |
| `publinksMaxViews` | `3` | Views a public link allows before it stops working |
| `publinksMaxTime` | `600` | Seconds a public link stays valid |
| `accountExpireTime` | `10368000` | Password expiry, in seconds — 120 days |
| `siteLang` | `en_US` | Interface language |
| `siteTheme` | `material-blue` | The only theme that ships |
| `dbPort` | `3306` | Database port |
| `mailPort` | `587` | SMTP port |
| `syslogPort` | `514` | Remote syslog port |
| `proxyPort` | `8080` | Proxy port |

---

## Settings that change behaviour

Three settings change what the application does in ways that are easy to mistake for defects.

**`accountFullGroupAccess`** widens secondary-group access: with it on, a user reaches an account
through *any* group they belong to, not only their main group. An account visible to a colleague and
not to you is often this rather than a permissions error.

**`accountCount`** is entered as a free number with no upper bound. It sets how many accounts a page
renders, and every row costs work, so a large value makes the listing slow for reasons that look
like a performance bug.

**`maintenance`** closes the application to everyone except the one user holding the application
lock — the person who enabled it — and only for their AJAX requests; every ordinary page request is
refused outright, theirs included. An installation that suddenly refuses everyone is worth checking
here before anywhere else, and note that clearing the flag alone is not enough if the lock outlives
it.
