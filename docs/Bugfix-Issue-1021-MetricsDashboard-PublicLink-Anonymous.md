# Bugfix — Issue #1021: Metrics dashboard public link (lnl.php apikey flow) bounces to login or 500

## Problem

The Metrics Dashboard "Public link" (`gui/templates/results/metricsDashboard.html`
→ `lnl.php?type=metricsdashboard&apikey=<project-api-key>`) is broken for
anonymous viewers on PHP 8.3:

1. **No accessible test plans** (no plan, no public plan, or none active):
   HTTP **500** with an empty body — `array_keys(null)` TypeError.
2. **With accessible plans** (public/active plan + executions):
   HTTP **500** with an empty body — `hasRight()` on a null user object.
3. **Fresh session, direct hit** of
   `lib/results/metricsDashboard.php?apikey=...`:
   the anonymous session is destroyed and the viewer bounced to
   `login.php?note=logout` — a dead end, because the anonymous viewer cannot
   authenticate (no credentials in an api-key flow).

Physical symptom measured (all three scenarios, pre-fix):

```
HTTP/1.1 500 Internal Server Error → empty body (scenarios 1 & 2)
HTTP/1.1 200 → location.href='../../login.php?note=logout' (scenario 3)
```

## Root Cause

Three independent defects on the same anonymous-public-report path:

1. **`getAccessibleTestPlans()` returns `null`, not `[]`** when no plan matches
   (documented in `tlUser.class.php` docblock). `getMetrics()` fed it straight
   into `array_keys($test_plans)` → PHP 8 `TypeError: array_keys(): Argument #1
   ($array) must be of type array, null given` at `metricsDashboard.php:169`.

2. **`TLSmarty::addMenuContext()` built a menu for the anonymous session.**
   Anonymous api-key sessions have `$_SESSION['userID'] = -1` (and no
   `currentUser`). `addMenuContext() → initUserEnv() →
   tlUser::get_user_by_id(0) → null`, then `get_accessible_for_user()` calls
   `$this->hasRight()` on the null user object → PHP Fatal:
   `Call to a member function hasRight() on null` (`testproject.class.php:584`).

3. **`setUpEnvForAnonymousAccess()` razed the helpful session.**
   When `$_SESSION['basehref']` was not set (fresh anonymous viewer), it did
   `session_unset(); session_destroy();` and `redirect("../../login.php?note=logout")`
   — no exception path existed for anonymous access (the original code only
   handled the `$rightsCheck->redirect_target` branch for real users).

Plus a warning-noise defect found while verifying:
4. **`initEnv()` didn't seed `tproject_id`/`tplan_id`** into `$cerbero->args`
   for the anonymous branch, so `setUpEnvForAnonymousAccess()`
   (`common.php:1342`, `$args->tplan_id`) raised
   `E_WARNING: Undefined property: stdClass::$tplan_id` on every anonymous hit.

## Fix

Committed on branch `fix/issue-1021`, commit `237b7ec6f`:

### `lib/results/metricsDashboard.php`

1. `getMetrics()` — tolerate the documented `null` return and degrade to the
   empty state:
   ```php
   if( !is_array($test_plans) ) {
     $test_plans = array();
   }
   ```
2. `initEnv()` anonymous branch — seed the ids the env setup reads so no
   `Undefined property` warning is emitted:
   ```php
   $cerbero->args->tproject_id = $args->tproject_id;
   $cerbero->args->tplan_id = $args->tplan_id;
   ```

### `lib/functions/tlsmarty.inc.php`

3. `addMenuContext()` — anonymous sessions get the empty, crash-free grant set
   (public pages render without any menu shell — that is the correct shell for
   them):
   ```php
   $isAnonymousSession =
     isset($_SESSION['userID']) && intval($_SESSION['userID']) <= 0;
   if( !is_object($gui) || !isset($_SESSION['currentUser']) ||
       $isAnonymousSession || null == $db ) {
     $this->assign('menuGrants',self::emptyMenuGrants());
     return;
   }
   ```

### `lib/functions/common.php`

4. `setUpEnvForAnonymousAccess()` — when `basehref` is missing, rebuild the
   base path with `setPaths()` instead of destroying the session and bouncing
   to login:
   ```php
   if(!isset($_SESSION['basehref'])) {
     setPaths();
   }
   ```

**Why this approach:** all three fixes are the smallest correct change at the
crash/bounce sites; they preserve the authenticated flow byte-for-byte
(session `userID > 0` keeps the normal menu-context path and never enters the
anonymous branch of `setUpEnvForAnonymousAccess`). Rejected alternatives:
putting an api-key check into `addMenuContext` (correct but duplicated TLSMarty
knowledge), and disabling `setUpEnvForAnonymousAccess` entirely (removes
legitimate per-report param handling).

## Files Changed

- `lib/results/metricsDashboard.php` — null-array guard + args seeding
- `lib/functions/tlsmarty.inc.php` — anonymous menu-context early return
- `lib/functions/common.php` — base path rebuild instead of login bounce

## Verification

All checks on branch `fix/issue-1021`, commit `237b7ec6f`, PHP 8.3.33:

- No accessible plans → `lnl.php?type=metricsdashboard` HTTP **200**, 0% `[0/0]`
  + empty-state grid (was 500).
- With data → HTTP **200**, full dashboard (Project Progress 100% `[1/1]`,
  Passed 100%, Test Plan Progress grid with the plan row) (was 500).
- Fresh-session direct hit → HTTP **200**, dashboard renders in an isolated
  incognito context, no `login.php?note=logout` anywhere (was bounce).
- Server log: zero Fatal/Undefined entries for all three scenarios.
- Event Viewer: no new Error/Warning rows (max id before = 14, none after).
- Logged-in admin regression: modern screen renders, "Public link" button
  produces the tested URL, authenticated menu context unchanged.
- Regression suite: `tmp/TLU_Test_Cases.md` — "Regression — Issue #1021", 5/5 PASS.
- `php -l` clean on all three files.

Screenshots: `docs/screenshots/issue-1021-metrics-anonymous.png` (with data),
`docs/screenshots/issue-1021-metrics-anonymous-noplans.png` (no accessible plans).