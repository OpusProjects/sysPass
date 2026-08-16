# Permissions

Two different questions decide whether somebody may do something: *are they allowed to perform this
kind of action at all*, and *may they reach this particular account*. They are answered by two
separate mechanisms, and most confusion about sysPass permissions comes from conflating them.

How secrets are protected once access is granted is a separate matter, covered by
[security](security.md).

- [Two questions, two mechanisms](#two-questions-two-mechanisms)
- [Profile permissions](#profile-permissions)
- [The two administrator flags](#the-two-administrator-flags)
- [Per-account access](#per-account-access)
- [Private accounts](#private-accounts)
- [Full group access](#full-group-access)
- [Global search](#global-search)
- [The rule is enforced twice](#the-rule-is-enforced-twice)
- [Granting permissions](#granting-permissions)

---

## Two questions, two mechanisms

Every guarded operation passes through both, in this order, and being allowed by one says nothing
about the other.

| Question | Mechanism | Answered by |
|---|---|---|
| May this user perform this kind of action? | The user's **profile** | `Acl::checkUserAccess($actionId)` |
| May this user reach *this* account? | The account's **permissions** | `AccountAcl::getAcl()` |

A user with `accView` may view accounts *in general*; whether they may view account 42 depends
entirely on the second. Conversely, being an account's owner grants nothing if the profile withholds
the action.

---

## Profile permissions

A profile is 30 independent booleans. They are not hierarchical and none implies another, except
where a check below reads more than one.

| Group | Permissions |
|---|---|
| Accounts | `accView`, `accViewPass`, `accViewHistory`, `accEdit`, `accEditPass`, `accAdd`, `accDelete`, `accFiles`, `accPermission`, `accPublicLinks`, `accPrivate`, `accPrivateGroup`, `accGlobalSearch` |
| Configuration | `configGeneral`, `configEncryption`, `configBackup`, `configImport` |
| Management | `mgmUsers`, `mgmGroups`, `mgmProfiles`, `mgmCategories`, `mgmCustomers`, `mgmApiTokens`, `mgmPublicLinks`, `mgmAccounts`, `mgmTags`, `mgmFiles`, `mgmItemsPreset`, `mgmCustomFields` |
| Other | `evl` (event log) |

`Acl::checkUserAccess()` maps an action id onto them. A few actions read more than one flag, and
those combinations are where surprises live:

```php
case self::ACCOUNT_VIEW:        // view OR edit — editing implies seeing
    return $userDto->isAdminAcc || $userProfile->isAccView() || $userProfile->isAccEdit();
case self::ACCOUNT_COPY:        // both — copying is creating something you could already see
    return $userDto->isAdminAcc || ($userProfile->isAccAdd() && $userProfile->isAccView());
case self::PUBLICLINK_CREATE:   // either — one is administrative, one is not
    return $userProfile->isMgmPublicLinks() || $userProfile->isAccPublicLinks();
```

That last one is worth knowing: `accPublicLinks` is the ordinary account-level right granted so
users can publish links for **their own** accounts. It is not administrative, and publishing a link
is still subject to per-account access.

---

## The two administrator flags

These live on the user, not the profile, and they short-circuit different things.

**`isAdminApp`** returns `true` from `checkUserAccess()` before the action is even examined. It is
total: every profile permission is granted.

**`isAdminAcc`** is checked per action, alongside the profile flag, and additionally exempts the
user from the per-account rules — an account administrator reaches every account without being
owner, group member or named party.

Both also lift the account listing's SQL filter entirely, which is what makes them administrators of
data rather than merely of settings.

---

## Per-account access

`AccountAcl::getAcl()` resolves view and edit for one account by walking these in order and stopping
at the first that matches.

1. **Withheld as private** → no access at all, whatever follows. See below.
2. **`isAdminApp`, `isAdminAcc`, the owner, or the account's main group** → view and edit.
3. **Listed among the account's secondary users** → view; edit only if that entry's `isEdit` is set.
4. **A group the user belongs to is the account's main group** → view and edit.
5. **A group the user belongs to is among the account's secondary groups** → view; edit follows that
   entry's `isEdit`.

The order is the design. A private account is withheld *before* the administrator branch is reached,
so privacy is not something an account administrator overrides — and step 3 granting only what
`isEdit` says is why "shared with me" and "editable by me" are different states.

Separately, `compileShowAccess()` fills in which *actions* the interface offers for that account, by
asking `checkUserAccess()` for each. This is the point where the two mechanisms meet: an account you
can reach still shows no delete button unless your profile has `accDelete`.

---

## Private accounts

Two independent flags withhold an account from everyone except one party, and each is checked
against the thing it names.

| Flag | Reachable only by |
|---|---|
| `isPrivate` | the account's **owner** (`userId`) |
| `isPrivateGroup` | the account's **main group** (`userGroupId`) |

They are independent: an account may set either, both or neither, and either one being set and
unmatched withholds the account. Marking an account private overrides every share on it —
secondary users and groups included — and, as above, is not lifted by either administrator flag.

Marking an account private is offered by the interface only to a user whose profile carries
`accPrivate` (or `accPrivateGroup`) **and** who owns the account — or to an `isAdminApp`
administrator, for whom both are always offered.

---

## Full group access

`accountFullGroupAccess` changes what "the user's groups" means when matching an account's secondary
groups, and it is the setting most often behind "why can my colleague see this and I cannot".

Off (the default), a secondary group grants access only when it is the user's **main** group. On,
any group the user belongs to counts. The setting widens access across the whole installation at
once, so it is worth deciding deliberately rather than switching on to solve one case.

---

## Global search

Global search lets a user search beyond what they can normally reach, and it takes three things
being true at once.

The installation's `globalSearch` must be on, the user's profile must have `accGlobalSearch`, and
the search itself must request it. Only then is the listing's ownership filter lifted — the private
flags are still applied, so global search widens reach without exposing accounts marked private.

---

## The rule is enforced twice

This is the part worth internalising before changing anything here. Account access is decided in two
places, and they must agree:

- **`AccountFilter::buildFilter()`** builds the SQL `WHERE` that decides which accounts appear in a
  listing.
- **`AccountAcl`** decides, in PHP, whether a single account fetched by id may be viewed or edited.

A rule implemented in one and not the other is not a cosmetic inconsistency: an account the listing
correctly withholds is still handed over when asked for by id, or the reverse. Both therefore apply
the same privacy conditions, written the same way round, and `AccountFilter` has a matching
`buildFilterHistory()` so history rows obey the rules their accounts do.

Any code path reading an account for a signed-in user should go through the filter rather than
querying the table directly. Reads that bypassed it are exactly how accounts have leaked here
before.

---

## Granting permissions

A user who can edit profiles could otherwise grant permissions they do not hold themselves, which
would make `mgmProfiles` equivalent to full administrator.

`ProfileData::constrainedTo()` prevents it: when a profile is saved, every boolean is intersected
with the granting user's own profile, so a permission the actor lacks cannot be set. Both entry
points apply it — the web form and the REST controller — because a constraint enforced on only one
of them is not enforced at all.
