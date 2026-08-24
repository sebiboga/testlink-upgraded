# Issue 488 — Zero-test-project warnings: umbrella verified fixed + grant-builder handler cleanup

**Issue:** [#488](https://github.com/sebiboga/testlink-upgraded/issues/488)
**Branch:** `fix/issue-488` · **Commits:** `1357fc21e` (fix), `35b563844` (regression suite), docs (this page)
**Status:** FIXED & VERIFIED (2026-08-24)

## Symptom (as reported, 2026-08-18)

286 PHP 8 warnings in the Event Viewer when **no test projects exist**, grouped in five
sites: missing platform grants in `getAccess()` (common.php:2003/2004), aside.tpl missing
`showMenu` keys (`search`, `project_inventory_*`, `countPlans`, `plugin_management`),
projectEdit.tpl create-case properties (`actions`, `itemID`, `tplan_id`, `tproject_id`,
`mgt_view_events`), mainPage.php null access (`testprojectOptions`, `inventoryEnabled`)
and tlRole.class.php:264 array-offset-on-null.

## Investigation outcome — symptom already eliminated piecemeal

Reproduction against current HEAD (fresh DB, zero testprojects, admin login + full
authenticated sweep of every zero-project-reachable screen) produced **0 WARNING/ERROR
events** while the logging pipeline was proven live (AUDIT/INFO rows written during the
same window). Each original group is now hardened by fixes that landed under later refs:

| Original group | Current guard |
|---|---|
| getAccess() platform grants | `property_exists()` checks — common.php:2142-2155; full grant shape incl. `platform_management/platform_view` — common.php:2053-2092 |
| aside.tpl showMenu keys | `'search' => false` default — common.php:2248; `countPlans = 0` init — common.php:1663; hardened `$menuGrants` from `TLSmarty::addMenuContext()` — lib/functions/tlsmarty.inc.php:568-612 |
| projectEdit create case | `initializeGui()` sets `actions/itemID/tplan_id/tproject_id/mgt_view_events` — lib/project/projectEdit.php:723-745 |
| mainPage.php null access | guarded reads — lib/general/mainPage.php:187-189 and live-options read at :533-541 |
| tlRole.class.php:264 | site no longer exists after refactors; no array-offset access remains |

The refs responsible include #483 (zero-project aside), #487, the #609 session-hardening
series, #537 and #674. Issue #488 itself stayed open as a stale umbrella.

## Remaining live defect found inside group 1's own function

`getGrantSetWithExit(&$dbHandler,...)` dereferenced **undefined locals** on its normal path:

```php
// common.php:2131/2133 (pre-fix)
$argsObj->user->hasRight($dbH,"testproject_user_role_assignment", ...)
$argsObj->user->hasRight($db,"user_role_assignment",null,-1)
```

Neither `$dbH` nor `$db` exists in function scope (the parameter is `$dbHandler`; no
`global`). It worked silently because `tlUser::hasRight(&$db,...)` takes the handler **by
reference** (PHP auto-vivifies the undefined variable to `null` with no warning) and never
dereferences it when `$getAccess=false` (tlUser.class.php:794-882). Any future call path
that passes `$getAccess=true` would fatal with "call to a member function on null".
This exact latent fragility was pre-flagged in the [issue #674 wiki page](Bugfix-Issue-674-MainPage-Restricted-Login-Grants-EWarning.md)
"Notes for future work" section. Blast radius: both lines run on **every authenticated page
load** (grant builder serves the normal, blind-folded and zero-project paths).

## Approach — pass the real handler

Minimal fix: replace `$dbH`/`$db` with the function's parameter `$dbHandler` (+ explanatory
comment). No behavior change for existing callers (verified by the regression matrix below);
removes reliance on by-ref autovivification and makes the code correct for any future
handler-using call. Rejected alternatives: `global $db;` (wrong layering — the handler is
already an argument) and leave-as-is (latent fatal).

## Files changed

| File | Change |
|---|---|
| `lib/functions/common.php` | `getGrantSetWithExit()`: undefined `$dbH`/`$db` → `$dbHandler` (+ comment) |
| `tmp/TLU_Test_Cases.md` | Suite 488 regression entry (6-case matrix) |

## Verification (Suite 488 — 6/6 PASS)

| Case | Result |
|---|---|
| Original symptom absent (browser login, zero projects → shell renders) | PASS — 0 WARNING/ERROR events |
| Authenticated sweep of all zero-project screens (13 URLs) | PASS — full content everywhere, events delta 0 |
| Logging health canary | PASS — AUDIT/INFO rows present ⇒ zero-warning result meaningful |
| Grant builder fix live (both changed lines run per page load) | PASS — no fatal, grants unchanged |
| Non-zero-project branch regression (create project via UI → full menus → delete) | PASS |
| Syntax gate (`php -l`) | PASS |


Method note recorded on the issue: cURL logins must use field names
`tl_login/tl_password/tl_login_btn` — a first sweep with wrong names silently re-rendered
the login form and was discarded.
