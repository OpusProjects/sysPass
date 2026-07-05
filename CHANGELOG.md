# Changelog

All notable changes to this project are documented in this file.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the
project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Security

- Require app-admin role to grant admin flags and profile permissions — closes a privilege-escalation path where any authenticated user could self-elevate (#416)
- Key brute-force login tracking on `REMOTE_ADDR` instead of the spoofable `X-Forwarded-For` header (#409)
- Scope notification view, check, and delete to the owner — fix IDOR (#407)
- Enforce per-account authorisation on account file and account mutation endpoints — close file and account IDOR (#388, #389)
- Enforce ACL server-side on plugin enable/disable/reset and event-log clear endpoints (#403)
- Regenerate session ID on login and re-key the session vault to prevent session fixation (#385, #395)
- Fix stored XSS in public-link and user-profile views (#384), DataGrid cells (#318), account search rows (#287, #319, #320), uploaded file name and type (#356), and custom-field help text (#355)
- Harden installer pre-auth path against two injection vectors (#360)
- Restrict backup archives and intermediate dumps to the owner (#412)
- Make master-password rotation atomic — abort on partial re-key instead of committing corrupt state (#410)
- Reject the unauthenticated upgrade endpoint when no valid key is configured (#411)
- Abort encrypted import instead of silently corrupting passwords without a valid master password (#405)
- Enforce MIME and size validation on API file upload; use the authenticated user in `AccountAcl` (#413)
- Don't fall back to the service-account password on an empty LDAP bind — prevents unintended authentication (#414)
- Restrict `unserialize` allowed classes in `Serde::deserialize` (#300)
- Add `HttpOnly`, `Secure`, and `SameSite=Strict` flags to the `SYSPASS_UUID` cookie (#305)
- Prioritise server-side MIME check over client-supplied `Content-Type` on file uploads (#306)
- Increase PBKDF2 iterations from 5,000 to 100,000 (#307)
- Increase RSA key size from 1,024 to 2,048 bits (#304)
- Replace `uniqid()` with `random_bytes()` for public-link hash generation (#301)
- Use timing-safe `hash_equals()` for legacy password hash comparison (#288)
- Fix rate-limiting bypass caused by an erroneous `LIMIT 1` in the Track query (#297)
- Use CSPRNG for backup/export download hashes; stop logging the CSRF token (#387)

### Added

- Playwright end-to-end browser test suite covering the install wizard and login flow (#391)
- Full REST API with OpenAPI spec and Swagger UI at `/api/docs/` — replaces the JSON-RPC 2.0 API (#217)
- REST API endpoints for Users, Profiles, Auth Tokens, Custom Fields, Event Log, Notifications, Public Links, and Account Files (#219–#226)
- `docs/ARCHITECTURE.md` — hexagonal layer map, request lifecycle, and DI container rules (#254)
- `docs/TESTING.md` — test suite layout, environment requirements, and authoring guide (#253)
- `CONTRIBUTING.md` — dev setup, PR workflow, and coding conventions (#255)
- npm-managed front-end asset vendoring pipeline (`npm run vendor`) and esbuild CSS minification step (`npm run build:css`) (#392, #402)
- Unit test coverage for `ConfigUtil`, `CustomField`, `ItemPreset`, `ProfileData`, `UserPreferences`, and `FileHandler` (#52, #264–#267)

### Changed

- **Architecture:** restructured to textbook hexagonal layout (`Domain/` → `Application/` → `Infrastructure/`); entry points moved to `public/` (web) and `bin/` (CLI); themes moved from `src/` to `public/` (#55, #234, #241)
- **PHP:** raised minimum to 8.4, added 8.5 support; cleared all 8.4 implicitly-nullable deprecations and 8.5 runtime deprecations (#21, #25, #29, #46)
- **Symfony:** upgraded from 6.4 LTS through 7.4 LTS to 8.1 (#19–#22, #33)
- **PHPUnit** upgraded 11 → 13; **phpmailer** 6 → 7; **phpstan** 1 → 2; **phpseclib** 2 → 3; **monolog** 1 → 3; **guzzlehttp/guzzle** 6 → 7 (#9, #13, #14, #36, #38, #39)
- **jQuery** upgraded to 3.7.1; front-end libraries now npm-managed (#392); **selectize** migrated to the maintained `@selectize/selectize` 0.15.2 fork (#399)
- `SerializedModel` migrated from PHP `serialize` to JSON with dual-read fallback for existing data (#76)
- All Web controllers migrated from the legacy `JsonTrait` / `bool` return pattern to the `ActionResponse` dispatch contract (#60)
- API migrated from JSON-RPC 2.0 to REST; controllers return `ApiResponse` (#75, #217)
- `EventDispatcher` simplified — event name moved into the `Event` object (#80)
- Login page, Configuration panel, and footer status bar redesigned (#233, #238, #240)
- Modernised codebase: replaced `get_class()`, `Closure::fromCallable()`, `call_user_func()`, `strpos() !== false`, and `substr()` suffix checks with PHP 8 equivalents; translated Spanish code comments to English (#23, #259–#284)

### Fixed

- Numerous `TypeError` crashes from nullable model getters passed to non-nullable parameters across controllers, templates, services, and the API — null-coalesced for PHP 9.0 compatibility (#125–#165, #193–#200)
- Installer: DB connection test, rollback, host parsing, credentials, CLI startup, and password mangling with special characters (#359–#364)
- REST API: master-pass decryption, end-to-end tests, and backup `--path` being ignored (#353, #366, #367)
- Account search: `OR` precedence bypassing the ACL filter, client filter for all users, and tag-only chaining (#278, #317, #332, #333, #370)
- SQL `WHERE` precedence in `AccountSearch`, `User::search`, `checkDuplicatedOnAdd`, and LDAP filter (#289, #317, #332, #349)
- Erroneous `LIMIT 1` in `Track`, `AccountFile::getByAccountId`, `PluginData` delete, and `UserToUserGroup::getById` — each caused silent data truncation or security bypass (#296–#299)
- XML export silently dropping values containing `&` (#354) and import fatal on tagless accounts (#357)
- `BackupFile` SQL export producing invalid `INSERT` statements for null/empty values (#143); database backup corrupting binary values (#361)
- `updateMasterPassById` writing to wrong columns (#295); history rows missing `Account` getters during master-password change (#351, #352)
- `ItemPreset` hash computed from type only — second preset of the same type always hit the UNIQUE key (#350)
- Forced logout ~2 min after login — session vault not re-keyed on ID regeneration (#335)
- `Config → General` never saving Language and Visual Theme (#338); account edits silently wiping the password expiry date (#337)
- `Password::CHARS` alphabet missing the letter `v` (#340)
- `DataGrid` query time always showing 0 s, `getLast()` `TypeError`, and sort constants swapped (#276, #346, #347)
- Reset-email build (`getMailMessage` arity mismatch) — restored the forgot-password flow (#415)

### Removed

- Dead `JsonTrait`, `JsonResponseHandler`, and all legacy `bool` controller return wiring (#61, #62)
- Dead Task controllers referencing the non-existent `TaskServiceInterface` (#121)
- Dead `BootstrapWeb` static class (#112)
- Dead `BackupFileHelperService` port (#381)
- Non-instantiable `ApiRequestService` from the shared DI definitions — was silently breaking container compilation (#378)
- Dead `AccountRequest` DTO, dead 3.2 task-tracking residue (`EventSource`, templates), and dead DokuWiki "View at Wiki" button (#375–#377)
- Dead `ademarre/binary-to-text-php` and `doctrine/common` dependencies (#12, #65)
- Stale `phpunit.xml` coverage exclusions, dead `TODO`/`FIXME` comments, and vestigial placeholder files (#67, #244, #251, #257)

[Unreleased]: https://github.com/OpusProjects/sysPass/commits/main
