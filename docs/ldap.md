# LDAP authentication

sysPass can authenticate users against an LDAP directory instead of, or alongside, its own user
table. This page covers how a sign-in is resolved against the directory, what each setting does, and
the filters that are built for you unless you override them.

The settings themselves are listed in [configuration](configuration.md); what follows is how they
combine.

- [How a sign-in is resolved](#how-a-sign-in-is-resolved)
- [Directory types](#directory-types)
- [Settings](#settings)
- [Filters](#filters)
- [Group membership](#group-membership)
- [What happens on first sign-in](#what-happens-on-first-sign-in)
- [Checking a configuration](#checking-a-configuration)
- [Things that catch people out](#things-that-catch-people-out)

---

## How a sign-in is resolved

Authentication is a **two-bind** sequence, and understanding it explains most failures.

1. Bind as the configured **bind user** — a service account, not the person signing in.
2. Search for the user's entry with the user filter, and read their attributes and group
   memberships.
3. Reject early if the account is **expired**, or if it is **not in the required group**.
4. Bind a second time, this time as the **user's own DN** with the password they typed. Success here
   is the actual authentication.

The password is never compared by sysPass; the directory decides, by accepting or refusing the
second bind. That also means a user whose entry cannot be found fails at step 2, which reports
differently from a wrong password failing at step 4.

---

## Directory types

Three types are recognised, and the type selects which filters and membership strategy are used.

| Type | Value | Provider |
|---|---|---|
| Standard LDAP | `1` | `LdapStd` — OpenLDAP and other RFC-conformant directories |
| Active Directory | `2` | `LdapMsAds` |
| Azure | `3` | Recognised by the enum |

The practical difference is the default filters, which differ because the schemas do. Active
Directory also picks a server from the SRV records it discovers when several are advertised.

---

## Settings

Fifteen settings drive it, and only the first five are needed for a working configuration.

| Setting | Purpose |
|---|---|
| `ldapEnabled` | Turns LDAP authentication on |
| `ldapType` | `1` standard, `2` Active Directory, `3` Azure |
| `ldapServer` | Directory host, optionally with scheme and port |
| `ldapBase` | Base DN the searches start from |
| `ldapBindUser` / `ldapBindPass` | The service account used for the first bind |
| `ldapGroup` | Group a user must belong to; empty means any user in the base DN |
| `ldapTlsEnabled` | Negotiates TLS on the connection |
| `ldapDefaultGroup` | sysPass group assigned to users created from the directory |
| `ldapDefaultProfile` | sysPass profile assigned to those users |
| `ldapDatabaseEnabled` | Falls back to the local user table when the directory refuses |
| `ldapFilterUserObject` | Overrides the user object-class filter |
| `ldapFilterGroupObject` | Overrides the group object-class filter |
| `ldapFilterUserAttributes` | Attributes matched against the typed login |
| `ldapFilterGroupAttributes` | Attributes examined for group membership |

`ldapDefaultGroup` and `ldapDefaultProfile` are what a directory user's [permissions](permissions.md)
come from, since the directory has no notion of sysPass profiles.

---

## Filters

Each provider ships defaults, so the four filter settings are overrides rather than requirements.
Leave them empty unless a search is not matching.

**Standard LDAP**

```
user object   (|(objectClass=inetOrgPerson)(objectClass=person)(objectClass=simpleSecurityObject))
group object  (|(objectClass=groupOfNames)(objectClass=groupOfUniqueNames)(objectClass=group))
```

**Active Directory**

```
user object   (&(!(UserAccountControl:1.2.840.113556.1.4.804:=32))(|(objectCategory=person)(objectClass=user)))
group object  (objectCategory=group)
```

The Active Directory user filter excludes disabled accounts through the `UserAccountControl` bit
mask, which is why a disabled account fails to be found rather than failing to authenticate.

Both types match a typed login against `samaccountname`, `cn`, `uid` and `userPrincipalName` by
default, so a user may sign in with any of them.

The value is composed into the filter as `(&(|<attribute matches>)<object filter>)`, and the typed
login passes through `ldap_escape($value, '', LDAP_ESCAPE_FILTER)` first — a login containing
filter metacharacters is escaped rather than interpreted.

---

## Group membership

Group membership is checked in two ways, because directories express it in two ways.

Direct membership reads the attributes in `ldapFilterGroupAttributes`, defaulting to `memberOf` and
`groupMembership`. Active Directory additionally uses `memberof:1.2.840.113556.1.4.1941:`, the
matching rule that walks **nested** groups — so a user in a group that is itself a member of the
required group is recognised.

If `ldapGroup` is empty, this check passes for anybody the base DN search finds.

---

## What happens on first sign-in

A user authenticating against the directory for the first time has no local record, so one is
created.

They are given `ldapDefaultGroup` and `ldapDefaultProfile`, and their name and email are taken from
the directory. They still need a master password before they can open any account, which follows the
ordinary flow described in [security](security.md) — LDAP authenticates the person, it does not
unlock the vault.

Users can also be imported ahead of time from the configuration manager, which walks the directory
and creates the accounts in bulk rather than waiting for each person to sign in.

---

## Checking a configuration

The configuration manager has a check action that connects, runs the filters and reports what it
found, without changing anything.

Use it before saving: it distinguishes a connection or bind failure from a filter that matches
nothing, which is the distinction most worth having when a configuration does not work. There is a
separate check for the import, reporting the users and groups that would be created.

---

## Things that catch people out

Four failure modes account for most of the trouble.

**A wrong bind user fails for everybody**, at step 1, before any user filter runs. If nobody can
sign in, check this before the filters.

**A filter that matches nothing looks like a wrong password.** The search at step 2 returning no
entry and the bind at step 4 being refused are different failures; the check action distinguishes
them.

**`ldapDatabaseEnabled` changes what a directory failure means.** It sets whether the LDAP provider
is *authoritative*: with it off, a refusal is final and is counted against the brute-force tracking.
With it on, the refusal is not final — the event log records `Non authoritative auth` and the local
user table is tried next. That is useful while migrating, and it is also how a directory account
that has been disabled can keep access through a local password.

**Filters used to be corrupted on save.** An earlier version escaped HTML on input, so
`(&(objectClass=user))` was stored as `(&amp;(objectClass=user))`, where `&amp;` is not an operator
and the search matches nothing. Text is now stored exactly as typed, and the migration described in
[upgrade](upgrade.md) repairs filters saved by an affected version — but a filter that was worked
around while it was broken is worth re-checking after upgrading.
