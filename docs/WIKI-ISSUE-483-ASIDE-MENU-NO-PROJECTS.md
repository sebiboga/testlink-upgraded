# Issue 483 — Aside menu empty when no test project exists (+ missing create-project page title)

**Issue:** [#483](https://github.com/sebiboga/testlink-upgraded/issues/483)
**Branch:** `fix/issue-483` · **Fix commit:** `88360663a` (real-grants hardening; the original
symptom repair predates this run and was verified end-to-end here)
**Status:** FIXED & VERIFIED (2026-08-24)

## Symptom

On a TestLink instance with **zero test projects** (fresh install or all projects deleted):

1. The aside sidebar rendered **completely empty** — no System, no Projects sections at all,
   so nobody could reach "Test Project Management" to create the first project through
   navigation.
2. The main frame correctly redirected to `projectEdit.php?doAction=create`, but its
   `<h1>` heading was empty (the blue bar collapsed).

## Root cause chain

1. `lib/functions/tlsmarty.inc.php:596` — the aside menu context calls `initUserEnv()` on
   every render of `asideMenu.php`.
2. `lib/functions/common.php:1691` — `$doInitUX = ($args->tproject_id > 0) ||
   $options['forceCreateProj']`. With zero projects and no `forceCreateProj` option (aside /
   navBar callers) grants/access/showMenu stay null.
3. Pre-fix, nothing populated them → `aside.tpl` rendered nothing. An earlier fix (present on
   the default branch) added a rescue branch:
   - `common.php:1714` — `elseif ($gui->zeroTestProjects)` builds showMenu and forces the
     `projects` + `system` sections true;
   - `getMenuVisibility()` (`common.php:2189/2198`) keeps those two sections reachable via
     `|| $gui->tproject_id == 0` fallbacks;
   - `projectEdit.php:51/57` sets `$gui->pageTitle = $ui->caption` in the create/edit cases
     (fixes the missing `<h1>`).
4. **Residual defect fixed by this run:** that rescue branch hardcoded an **admin-level grant
   set for every logged-in user** (`common.php:1723-1738` old code), while callers going
   through the `doInitUX` branch computed real rights via `getGrantSetWithExit()`
   (`common.php:1705`). A guest-role user therefore saw User Management / Role Management /
   Event Viewer / Issue & Code Tracker Management links in the zero-project state (server-side
   rights still enforced — UI over-exposure only, but inconsistent between frames and
   misleading).

## Fix approach

Replace the hardcoded grant object with a real computation:

```php
$gui->grants = getGrantSetWithExit($dbH,$args,$tprjMgr,
                                   array('forceCreateProj' => false));
```

*Why this method:* it is exactly the machinery the rest of the shell already uses, so admin
rendering is unchanged (admin holds every right) while limited roles get their real global
rights. `forceCreateProj => false` matters: inside the zero-project branch we must NOT trigger
the create-project redirect (`common.php:2026-2035`) — redirecting is the mainframe's job;
the aside must always render.

Alternatives rejected: per-right allow-listing of fake grants (still fake data, drifts from
role edits); hiding System/Projects sections entirely for low-rights users (breaks the anchor
that keeps Test Project Management reachable).

Diff: `lib/functions/common.php` +6/−18 (commit `88360663a`).

## Verification (live, http://localhost:8082)

| # | Case | Result |
|---|------|--------|
| R1 | admin, zero projects | aside = System(9 sub-items)/Projects(5)/Plugins/Documentation; redirect to create form; h1 "Create a new project" — PASS |
| R2 | admin creates first project | project id=1 created, navBar updated, full menu after reload — PASS |
| R3 | guest role, zero projects | NO admin sub-items; Projects shows only real-rights entries (Keyword Management) — PASS |
| R4 | guest role with one project | normal per-rights menu, no redirect loop — PASS |
| R5 | Event Viewer across R1–R4 | zero new Error/Warning events — PASS |





## Fixture gotchas for future testers

1. **SQL-inserted users need `cookie_string`.** `index.php:29-39` compares the browser auth
   cookie against the DB column; an empty value bounces the user back to login.php even right
   after a successful POST /login.php. Generate one:
   `UPDATE users SET cookie_string=MD5(CONCAT(login,RAND())) WHERE login='...';`
2. Use locale `en_GB` for fixtures unless you want unrelated "not localized ... using en_GB"
   WARNING events (pre-existing i18n fallback logging, unrelated to this issue).
3. Deleting projects directly needs FK checks disabled:
   `SET FOREIGN_KEY_CHECKS=0; DELETE FROM nodes_hierarchy WHERE id IN (SELECT id FROM testprojects);
   DELETE FROM testprojects; SET FOREIGN_KEY_CHECKS=1;`

Regression suite: "Regression — Issue #483" in `tmp/TLU_Test_Cases.md`.
