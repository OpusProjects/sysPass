# GitHub configuration

This document describes how the `OpusProjects/sysPass` repository itself is configured: the branch
rules that gate a merge, what continuous integration runs and when, how dependency updates arrive,
and how a release is cut. It covers repository settings rather than the application.

- [Repository settings](#repository-settings)
- [Branch protection](#branch-protection)
- [Continuous integration](#continuous-integration)
- [Dependency updates](#dependency-updates)
- [Releases and the container image](#releases-and-the-container-image)
- [Issue and pull request templates](#issue-and-pull-request-templates)
- [Contribution workflow](#contribution-workflow)

---

## Repository settings

The repository is public, GPL-3.0 licensed, and carries discovery topics so it can be found by
subject rather than by name alone.

| Setting | Value | Why |
|---|---|---|
| Default branch | `main` | Every pull request targets it |
| Squash merging | enabled | The history is one commit per change |
| Merge commits | enabled at repository level | Blocked into `main` regardless, by the linear-history rule below |
| Rebase merging | enabled | Permitted, but squash is what is used |
| Auto-merge | disabled | Pull requests are merged deliberately, by a person |
| Delete branch on merge | disabled | Branches are deleted per merge with `gh pr merge --delete-branch` |
| Issues | enabled | Bug reports, feature requests and questions |
| Discussions | disabled | — |
| Topics | `password-manager`, `credentials`, `security`, `self-hosted`, `php`, `syspass` | Discovery |

`main` has a **flat history**: a squashed `sysPass 3.2.11` root — upstream's commit history is
deliberately omitted — followed by one squash-merged pull request per change. A squash commit takes
its body from the branch's commit messages, and its title from the single commit where a branch has
only one, or from the pull request otherwise. GitHub appends the pull request number to that title,
which is why titles should not carry one themselves.

---

## Branch protection

`main` is protected, and the rules apply to administrators too. Nothing reaches it except through a
pull request whose checks have passed.

| Rule | Value |
|---|---|
| Require a pull request | yes, with **0** required approvals |
| Required status checks | the seven that run on a pull request (below) |
| Require branches to be up to date | no |
| Require linear history | yes |
| Require conversation resolution | yes |
| Force pushes | blocked |
| Branch deletion | blocked |
| Enforce for administrators | yes |

Three of these are less obvious than they look, and each is deliberate:

**Zero required approvals.** The rule still forces every change through a pull request, which is
what stops direct commits to `main`. Requiring even one approval would deadlock the repository,
because GitHub does not let you approve your own pull request and there is a single maintainer.

**The `Release` job is not a required check.** It is conditional on a `v*` tag and therefore reports
*skipped* on every pull request. A skipped check never turns green, so requiring it would block
every merge permanently.

**Branches need not be up to date.** Requiring it would force a rebase and a full seven-job re-run on
every open pull request each time anything merged — a batch of dependency updates would mean one
round per update. The trade-off is that a pull request validated against a slightly older `main` can
still merge.

Because administrators are not exempt, turning protection off is the only way to push to `main`
directly or to merge a pull request whose checks are red.

---

## Continuous integration

One workflow, `.github/workflows/ci.yaml`, covers testing, linting, the container image and
releases. It is triggered in exactly two situations:

```yaml
on:
  push:
    tags: ['v*']
  pull_request:
    branches: [main]
```

**Nothing runs on `main` itself.** A merge into `main` triggers no workflow — the pull request that
preceded it is where the code was proven, and the next run against that code is the tag build. This
is why the branch protection above matters: the checks on the pull request are the only gate.

These seven jobs run on a pull request, and all seven are required to merge:

| Job | What it does |
|---|---|
| `Unit tests (8.4)` / `(8.5)` | The unit suite on both supported PHP versions; no database |
| `Integration tests (8.4)` / `(8.5)` | The integration suite against a MariaDB 11.8 service, schema loaded from `schemas/dbstructure.sql` |
| `Lint` | PHPStan (level 6, `src`), PHPCS (PSR-2), and a check that the committed vendor JS bundle is current |
| `E2E tests (Playwright)` | Starts the Docker stack, waits for the app to respond, and runs the browser suite against it |
| `Build image` | Builds `docker/Dockerfile`; **does not push** on a pull request |

Both PHP jobs use a matrix with `fail-fast: false`, so a failure on 8.4 does not cancel 8.5 — when a
change breaks only one version, the run tells you which. Every job installs the same extensions
(`pdo_mysql`, `gd`, `ldap`, `gettext`, `zip`, `intl`, `mbstring`) and runs with `zend.assertions=1`.

Locale setup is shared through a composite action at `.github/actions/setup-locales`, because
`LanguageTest` asserts against `en_US.UTF-8` and `es_ES.UTF-8` and fails without them.

---

## Dependency updates

Dependabot watches three ecosystems, defined in `.github/dependabot.yml`, and opens a pull request
per update on a weekly schedule.

| Ecosystem | Covers |
|---|---|
| `composer` | PHP runtime and development dependencies (`composer.json`, `composer.lock`) |
| `npm` | Front-end build tooling and the Playwright suite (`package.json`, `package-lock.json`) |
| `github-actions` | Action versions pinned in the workflow |

Its pull requests go through the same seven required checks as any other, and are merged manually
rather than automatically. That is a deliberate choice: auto-merge would land dependency changes
that no person ever looked at, and two of these ecosystems update the tooling that decides whether
everything else is green.

---

## Releases and the container image

A release is cut by pushing a SemVer tag prefixed with `v`, which is the only push event the
workflow reacts to.

```bash
git tag v4.0.1
git push origin v4.0.1
```

The tag runs the full pipeline, and then two jobs behave differently than they do on a pull request:

- **`Build image`** pushes to `ghcr.io/opusprojects/syspass`. Three tag rules are configured: the
  commit SHA, the bare version from the git tag, and `latest` gated on the metadata action's
  `is_default_branch` expression. On a pull request the image is built but never pushed.
- **`Release`** extracts this version's section from `CHANGELOG.md` and creates the GitHub release
  with it as the body, falling back to a pointer to the changelog when no matching section exists.

The release title always equals the tag name. Because the notes come from `CHANGELOG.md`, the
changelog entry has to be written and merged *before* the tag is pushed.

---

## Issue and pull request templates

Templates live under `.github/` and are offered automatically when somebody opens an issue or a
pull request.

| File | Used for |
|---|---|
| `ISSUE_TEMPLATE/bug_report.md` | Reproducible defects |
| `ISSUE_TEMPLATE/feature_request.md` | Proposed functionality |
| `ISSUE_TEMPLATE/question.md` | Usage questions |
| `PULL_REQUEST_TEMPLATE.md` | The description every pull request starts from |

Security problems do not belong in an issue: [SECURITY.md](../SECURITY.md) describes private
reporting.

---

## Contribution workflow

Every change is its own pull request, squash-merged, and independently reviewable, bisectable and
revertable — a dependency bump, a fix and a documentation edit each get their own.

```bash
git checkout main && git pull origin main
git checkout -b <type>/<short-name>
# ... make exactly one change, and test it ...
git commit -am "<type>: short imperative summary"
git push -u origin <type>/<short-name>
gh pr create --repo OpusProjects/sysPass --base main --head <type>/<short-name> --title "..." --body "..."
gh pr merge <n> --repo OpusProjects/sysPass --squash --delete-branch
```

Branch names are `<type>/<short-name>` and titles are `<type>: short imperative summary`, drawn from
`feat`, `fix`, `perf`, `refactor`, `docs`, `test`, `build` and `chore`. The only remote is `origin`,
this repository — the discontinued `nuxsmin/sysPass` shares no history with the squashed `main`.

[CONTRIBUTING.md](../CONTRIBUTING.md) covers development setup and coding conventions in full.
