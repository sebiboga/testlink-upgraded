# Bugfix: array-shaped `doAction` on `planMilestonesEdit.php` raised a `TypeError` → HTTP 500 (Issue #1886)

**Issue**: `GET /lib/plan/planMilestonesEdit.php?doAction[]=x` answered **HTTP 500 with a 0-byte
body** and wrote nothing to `events`. This is the array-shaped twin of #1731
(`reqMgrSystemEdit.php`); #1726 had explicitly scoped itself to the scalar/bare-`doAction`
case and left the array case open.

## Root cause

Chain on the pre-fix revision (`git show d61807355^:lib/plan/planMilestonesEdit.php`):

1. `lib/plan/planMilestonesEdit.php:80` — `$args->doAction = isset($_REQUEST['doAction']) ? $_REQUEST['doAction'] : null;`
   No `is_scalar()` check, so `?doAction[]=x` stores a PHP **array** in `$args->doAction`.
2. `lib/plan/planMilestonesEdit.php:28` — `$pFn = $args->doAction;` (array).
3. `lib/plan/planMilestonesEdit.php:30` — `if(method_exists($commandMgr,$pFn))`.
   `method_exists(object|string $object_or_class, string $method)` requires a **string** for
   argument #2; an array throws `TypeError`.
4. `TypeError` is uncaught → HTTP 500 with an empty body and **no** `events` row (the failure
   happens before `tLog()`), exactly as the issue observed.

## Fix (approach)

The file was **already replaced** on the default branch by the modernized redirect shim in
commit `d61807355` (`feat(#1894): Test Milestone Create/Edit standalone screen`), whose message
states it "kills #1739 … and #1886 (array doAction TypeError 500)". The shim:

- never dispatches through `method_exists()` (the whole legacy dispatcher is gone);
- scalar-checks the action at `lib/plan/planMilestonesEdit.php:66-67`:
  ```php
  $doAction = isset($_REQUEST['doAction']) && is_scalar($_REQUEST['doAction'])
            ? (string)$_REQUEST['doAction'] : '';
  ```
  A non-scalar action is simply ignored and the redirect target is computed from scalar inputs
  only — no attacker-controlled string is written to the log;
- is GET/HEAD-only (405 otherwise);
- the replacement BFF `api/milestoneedit/index.php` answers `400 invalid_parameter` for any
  array-shaped parameter.

No additional source change was required for #1886; this run **verified** the guard is present
and effective, then closed the stale tracker.

## Files changed

- None (the fixing code already lives on the default branch in `d61807355`). This run adds:
  - `docs/Bugfix-Issue-1886-PlanMilestonesEdit-Array-DoAction-TypeError.md` (this mirror),
  - the `Regression — Issue #1886` suite in `tmp/TLU_Test_Cases.md`.

## Verification

- Pre-fix reproduction (pre-`#1894` revision temporarily served as a docroot probe, then removed):
  `?doAction[]=x` → **HTTP 500, 0 bytes**; scalar control `?doAction=x` → **HTTP 302**.
- Current default branch (`19dc496f1`):
  - `lib/plan/planMilestonesEdit.php?doAction[]=x` → HTTP 200 (162 B, JS redirect to
    `gui/templates/plans/planMilestoneEdit.html?mode=create`); `?doAction[]=x&id[]=1` → 200;
    `?doAction=edit&id[]=1` → 200; `?doAction[foo][bar]=x` → 200. No 500.
  - `api/milestoneedit/index.php?action[]=x` → HTTP 400 `invalid_parameter`;
    `?action=init&id[]=1` / `?action=init&tplan_id[]=1` → HTTP 400 `invalid_parameter`.
  - `events` `log_level=2`: no rows attributable to the target file.
- Regression suite `Regression — Issue #1886` (10 cases) 10/10 PASS; suite gate 7/7
  (`TLU_REQUIRE_SUITE="Issue #1886" bash ai/verify_test_suites.sh`).
- No user-facing string added → no i18n bundle touched.

## Related / follow-ups

- #1731 (`reqMgrSystemEdit.php`), #1726 (`planMilestonesEdit.php` scalar case), #1738
  (array-typed input parameter root cause) — same shape, fixed separately.
- **#1897** — a live twin of this defect found during this validation:
  `lib/keywords/keywordsEdit.php:66` calls `trim($_REQUEST['doAction'])` without an `is_scalar()`
  guard, so `?doAction[]=x` still 500s. Filed as a separate bug (out of scope for #1886).
