# Issue #985 — Guest login HTTP 500 (getDisplayName() on null in common.php:1787)

**Issue:** [#985](https://github.com/sebiboga/testlink-upgraded/issues/985)
**Commit:** `087ace530` (fix) + `9d00f9201` (regression suite)
**Status:** VERIFIED-FIXED (2026-09-05)

## Symptom

Logging in with a user that has only the GUEST role (role id 5), or any user without
a test-project role assignment, redirects to `index.php?caller=login` which crashes
with **HTTP 500**:

```
PHP Fatal error:  Uncaught Error: Call to a member function getDisplayName() on null
in lib/functions/common.php:1787
Stack trace:
#0 lib/functions/tlsmarty.inc.php(597): initUserEnv()
#1 lib/functions/tlsmarty.inc.php(650): TLSmarty->addMenuContext()
#2 index.php(71): TLSmarty->display()
```

## Investigation

Reproduced on a fresh DB with a test project (`testprojects.id=1`, **GTP**), a user
`pts_guest`/`ptsguest` (`users.id=2`, `role_id=0` — **no global role**) and a project
GUEST role (id 5) on project 1. Both the browser (login → `chrome-error://chromewebdata/`
HTTP 500) and a CLI harness replicating `addMenuContext()` → `initUserEnv()` produced the
exact stack above.

## Root cause chain

1. `lib/functions/tlUser.class.php:302-305` — `readFromDB()` builds
   `$this->globalRole` only when `globalRoleID` (i.e. `users.role_id`) is truthy.
   For `role_id = 0` (guest accounts), `$this->globalRole` stays **null**.
2. `lib/functions/tlUser.class.php:670-682` — `getEffectiveRole()` sets
   `$effective_role = $this->globalRole;` and returns it unchanged when the current
   `tplan_id`/`tproject_id` matches no `user_testplan_roles`/`user_testproject_roles`
   row (right after login `tproject_id = 0` ⇒ no match). Result: returns **null**.
3. `lib/functions/common.php:1779-1791` — the Refs #609 guard checks `$args->user !== null`
   but then unconditionally calls `$eRoleObj->getDisplayName()` on the **effective role**
   at line **1787**, with no guard on `$eRoleObj`. Result: fatal.
4. `lib/functions/tlsmarty.inc.php:597 → 650` — `addMenuContext()` runs `initUserEnv()`
   on **every** render of the modern Dashio frameset, so the guest login redirect to
   `index.php?caller=login` 500s immediately.

Two further null-role crashes surfaced on the same guest session once the main fatal was
cleared:

- `lib/general/navBar.php:198` — `$args->user->globalRole->getDisplayName()` fatal building
  the navbar whoami when no project role was selected.
- `lib/functions/tlUser.class.php:830` — `$this->globalRole->rights` E_WARNING inside
  `hasRight()` when `globalRole` is null (recorded in the `events` table, `log_level=2`).

## Fix (minimal, three guards)

1. **`lib/functions/common.php:1787`** — guard the effective-role object:
   ```php
   $gui->whoamiRole = is_object($eRoleObj) ? $eRoleObj->getDisplayName() : '';
   ```
2. **`lib/general/navBar.php:196-201`** — guard the global role with `is_object()` and
   fall back to `''` when it is null:
   ```php
   if ($gui->tproject_id && isset($args->user->tprojectRoles[$gui->tproject_id])) {
     $role = $args->user->tprojectRoles[$gui->tprojectID];
     $testprojectRole = $role->getDisplayName();
   } else if (is_object($args->user->globalRole)) {
     $testprojectRole = $args->user->globalRole->getDisplayName();
   } else {
     $testprojectRole = '';   // guest account with users.role_id = 0
   }
   ```
3. **`lib/functions/tlUser.class.php:832`** — treat a null global role as an empty right
   set in `hasRight()`:
   ```php
   $userGlobalRights = (array)($this->globalRole ? $this->globalRole->rights : array());
   ```

All consumers of `$gui->whoamiRole` use `|escape` on the value, so the `''` fallback
renders as an empty label with no further error.

## Why this method (alternatives rejected)

The bug has two layers: the user object is fine but the *role* it resolves is null.
Fixing it by hard-coding a fallback role (e.g. forcing GUEST) would silently grant an
account semantics it did not ask for and could diverge from `hasRight()` results. The
chosen approach mirrors the existing Refs #609 anonymous-identity fallback (`''`), keeping
guests as a genuine zero-right identity while rendering the shell. No refactor of role
resolution was attempted — this is a targeted null-guard.

## Verification

- **Guest login** (`pts_guest`/`ptsguest`): Dashio frameset renders, no HTTP 500;
  `navBar` / `asideMenu` / `mainPage` all HTTP 200; whoami shows "Guest User".
- **Guest → project 1** (project-role GUEST path): renders, no error.
- **Admin regression** (`admin`/`admin`): full frameset 200, whoami "Testlink
  Administrator / admin", full aside — unchanged.
- **Event Viewer / `events` table**: guest + admin sessions produced **zero** new
  WARNING (log_level=2) rows; only AUDIT (log_level=16) login-success rows. The 13
  pre-existing WARNING rows are all from the *before*-fix guest session.
- `php -l` passes on all three touched files.
- Full 7-case regression suite appended to `tmp/TLU_Test_Cases.md` (**7/7 PASS**).

## Screenshots

- Before: `docs/screenshots/issue-985-guest-login-500.png`
- After: `docs/screenshots/issue-985-guest-login-fixed.png`

## Files changed

- `lib/functions/common.php` (+3/−1) — effective-role null guard (`whoamiRole`).
- `lib/general/navBar.php` (+3/−0) — global-role null guard (`whoamiRole`).
- `lib/functions/tlUser.class.php` (+2/−1) — empty right set for null global role.
- `tmp/TLU_Test_Cases.md` — regression suite `TC-985.*` (7/7 PASS).
- `docs/screenshots/issue-985-guest-login-{500,fixed}.png` — evidence.
