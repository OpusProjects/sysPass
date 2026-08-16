# Security model

sysPass stores credentials, so what protects them, and what an attacker or an administrator can
reach, is the property that matters most about it. This page describes the cryptography as it is
implemented: what encrypts an account password, where the master password lives, and which
behaviours that look like defects follow from the design.

Reporting a vulnerability is a different matter and is covered by [SECURITY.md](../SECURITY.md).

- [The primitives](#the-primitives)
- [How an account password is stored](#how-an-account-password-is-stored)
- [The master password](#the-master-password)
- [Why a password reset asks for the old password](#why-a-password-reset-asks-for-the-old-password)
- [Temporary master password](#temporary-master-password)
- [Public links](#public-links)
- [Sessions](#sessions)
- [In-transit protection of form fields](#in-transit-protection-of-form-fields)
- [What an administrator can and cannot do](#what-an-administrator-can-and-cannot-do)

---

## The primitives

Nothing here is hand-rolled; the two libraries below do the work, and `SP\Infrastructure\Crypt\Crypt`
is the only wrapper around them.

| Purpose | Implementation |
|---|---|
| Symmetric encryption | `defuse/php-encryption` — `Crypto::encrypt()` with a `Key`, authenticated |
| Key protected by a passphrase | `KeyProtectedByPassword` — a random key sealed with a password |
| Password hashing | `password_hash()` with `PASSWORD_BCRYPT` (`SP\Domain\Crypt\Hash`) |
| Randomness | `Core::secureRandom()`, hex-encoded (`Password::generateRandomBytes()`) |
| Asymmetric | `phpseclib3` RSA, 2048-bit (`CryptPKI`) |

Because bcrypt truncates beyond 72 bytes, `Hash` pre-hashes anything longer with SHA-256 before
hashing it. This matters for master passwords, which are routinely longer than a login password.

---

## How an account password is stored

Each account carries **its own random encryption key**, and that key — not the account password —
is what the master password protects.

```
makeSecuredKey(masterPassword)  →  a random key, sealed with the master password   → Account.key
encrypt(accountPassword, key)   →  authenticated ciphertext                        → Account.pass
```

Two columns are written per account, `pass` and `key`, and both are rejected above 1000 bytes.
Nothing anywhere stores an account password in a form that the master password alone cannot open,
and re-keying the installation means re-sealing each account's key rather than re-encrypting every
secret from scratch.

---

## The master password

The master password is never stored. What is stored is one **per-user copy of it**, sealed with a
key derived from that user's own credentials:

```php
// SP\Application\User\Services\UserMasterPass
$key = trim($userPass . $userLogin . $configData->getPasswordSalt());
```

That string secures a random key (`User.mKey`), and the master password encrypted under it becomes
`User.mPass`. Signing in reverses the process, and the result is checked against a bcrypt hash of
the real master password kept in the `Config` table — so a wrong login password produces a wrong key
and fails the check rather than silently yielding garbage.

Three consequences follow, and all three are intended:

- The master password exists in cleartext only in the session of a signed-in user.
- Two users of the same installation hold the same master password sealed differently.
- **The `passwordSalt` in `config.xml` is part of every user's key.** Losing it makes every sealed
  copy unopenable; leaking it removes one of the three inputs an attacker would otherwise need.

---

## Why a password reset asks for the old password

This is the behaviour most often reported as a bug, and it is a direct consequence of the section
above rather than a defect.

A user's copy of the master password is sealed with a key derived from their **login** password.
Change the login password without the old one and that key cannot be reconstructed, so the sealed
copy can never be opened again — the account survives but its vault does not. The sign-in form
therefore asks for the previous password so it can re-key the copy.

Somebody who used the reset flow *because they had forgotten* the password cannot supply it. That
case needs an administrator to issue a [temporary master password](#temporary-master-password),
which the sign-in form accepts in place of the old one. `PasswordResetFlowTest` records this
behaviour deliberately.

---

## Temporary master password

An administrator can issue a short-lived stand-in for the master password, which is how a user who
cannot supply their old login password gets their vault back.

`TemporaryMasterPass::create()` generates 32 random bytes, encrypts the real master password under
them, and stores a bcrypt hash of the random key plus an expiry — four hours by default. The key
itself is what the administrator hands over; the installation keeps only its hash.

It is bounded on both axes: it expires at `tempmaster_maxtime`, and `MAX_ATTEMPTS` caps verification
at 50 tries before it is discarded.

---

## Public links

A public link publishes one account's password to anyone holding the URL, without a sign-in, so its
security rests entirely on the URL being unguessable.

The link's hash is 30 bytes from `Core::secureRandom()`. The key that opens the stored copy is
`sha1(passwordSalt . hash)` — so the salt is required as well as the hash, and a leaked database
alone does not open published links. The account is serialised into a `Vault` sealed with that key
at the moment the link is created, which is why revoking access to an account does not retract a
link already issued: following it reads the vault, never the account.

Links carry an expiry and a maximum view count, both enforced on every request.

---

## Sessions

The session holds the master password in cleartext for the duration of a sign-in, so it is
encrypted at rest rather than left in the session file.

`SecureSession` generates a random key, seals it in a `Vault`, and writes it to a cache file named
by a signed UUID cookie. The passphrase for that vault is derived per request:

```php
// SP\Infrastructure\Crypt\RequestBasedPassword
hash_pbkdf2('sha1', sha1(userAgent . clientAddress), $passwordSalt, 100000, 32)
```

Binding it to the user agent and the client address means a stolen session file is useless from
another browser or address, and the `passwordSalt` is the part an attacker cannot observe. The
session cookie itself is set `HttpOnly`, `SameSite=Strict`, with `Secure` following the connection
scheme, and `use_strict_mode` and `use_only_cookies` enabled.

---

## In-transit protection of form fields

Password fields are encrypted in the browser before the form is submitted, so that a password does
not sit in a request body in cleartext even briefly.

`CryptPKI` generates a 2048-bit RSA key pair at installation, publishes the public half, and
decrypts submissions with the private half held in `config/`. A ciphertext of any length other than
exactly 256 bytes is rejected without attempting to decrypt it.

This protects the field in transit; it is not a substitute for TLS, which terminates before it and
protects everything else.

---

## What an administrator can and cannot do

The trust boundary is worth stating plainly, because a password manager is often expected to hold
one it does not.

An administrator **can** read any account they have permission for, issue a temporary master
password, change the master password for the installation, and export the database. Encrypted
values remain encrypted in an export, but an administrator who also holds the master password can
open them.

An administrator **cannot** recover a user's login password, nor open a user's sealed copy of the
master password without either that user's login password or a temporary master password.

The design protects secrets from someone who obtains the database, the backups, or a session file.
It does not protect them from an administrator who holds the master password, and it is not intended
to — that is the person the master password belongs to.
