# Bugfix — Issue #622: planView.tpl E_WARNING spam on Test Plan Management screens

**Issue:** [#622](https://github.com/sebiboga/testlink-upgraded/issues/622)
**Branch:** `fix/issue-622-plan-warnings` · **Commits:** `a3fc7d8d9` (fix), `437024b54` (regression suite)
**Status:** FIXED & VERIFIED (2026-08-29)

## Symptom

Every load of the Test Plan Management screen (`lib/plan/planEdit.php?tproject_id=<n>`,
default `do_action=list`, which renders `gui/templates/dashio/plan/planView.tpl`) emitted
four `E_WARNING` (level-2) events per rendered plan row into the `events` table:

```
Undefined array key "tcase_qty"   ... *_0.file.planView.tpl.php:213
Undefined array key "build_qty"   ... *_0.file.planView.tpl.php:217
Undefined array key "rights"      ... *_0.file.planView.tpl.php:279
Trying to access array offset on null ... *_0.file.planView.tpl.php:279
```

Reproduced against a fresh DB: created test project `B421:Bug421 Project` (id=1) and test
plan `Billable Resources` (id=2) via the UI, then loaded `planEdit.php?tproject_id=1` →
events #9–#12 were exactly the four lines above.

## Root cause chain

1. `lib/plan/planEdit.php` **is** the Test Plan Management screen. Its `do_action` switch
   ("list" default) sets the template to `plan/planView.tpl` (`planEdit.php:210`) and
   enriches the `$gui->tplans` rows with `tcase_qty`, `build_qty` and per-plan
   `rights` (`planEdit.php:230-265`).
2. But immediately after the switch, `$gui = initializeGui(...)` **replaces** the object
   (`planEdit.php:282`). The fresh `$gui->tplans` is rebuilt as a plain
   `getAccessibleTestPlans()` fetch (`planEdit.php:470-474`) — no enrichment.
3. The template is then rendered via `planViewGUIInit()` (`lib/plan/planViewUtils.php:22-35`),
   which only computes `drawPlatformQtyColumn` and re-fetches the same plain rows — it never
   adds the aggregates the template reads at `planView.tpl:129/132/171`.
4. `plan/tpl/js/planView.js` renders the DataTable from those keys; the compiled template
   (`gui/templates_c/..._0.file.planView.tpl.php`) accesses them unconditionally at lines
   213/217/279 → the four warnings.

The standalone `lib/plan/planView.php` was clean because its controller enriches the rows
inline before `initializeGui()` (`lib/plan/planView.php:94-130`); `planEdit.php`'s flow
skips that inline path. (Historic side-note: the `layout`/`cellContent`/`cellLabel`
warnings mentioned in the report were already fixed by commit `6f66ffb4e`; only the
`planView.tpl`-through-`planEdit.php` path was still broken.)

## Approach — enrich in planViewGUIInit

The fix moves the row-enrichment so it always survives `planEdit.php`'s `initializeGui()`
re-init, mirroring `lib/plan/planView.php:94-130`:

`lib/plan/planViewUtils.php` — `planViewGUIInit()` now, when `$guiObj->tplans` is non-empty:

- computes `tcase_qty` per plan via `$tplanMgr->count_testcases($tplanSet, null, array('output' => 'groupByTestPlan'))`, guarded with `isset(...['qty'])` → 0;
- computes `build_qty` via `$tplanMgr->get_builds($tplanSet, null, null, array('getCount' => true))`, guarded with `isset(...['build_qty'])` → 0;
- computes `drawPlatformQtyColumn` + per-plan `platform_qty` via
  `$tplanMgr->platform_mgr->testProjectCount()` and `$tplanMgr->getPlatforms($idk)`
  (guarded, `isset` checks);
- computes `rights['testplan_user_role_assignment']` via the
  `tplanRoles → tprojectRoles → globalRole` fallback chain used in `planView.php:106-130`.

Rejected alternatives:
- Treating the template: adding `{if isset(...)}` guards would hide missing data instead
  of fixing the data path, and would diverge from the standalone `planView.php` which
  already provides the keys.
- Fixing `planEdit.php`'s `initializeGui()` ordering: larger blast radius (every testplan
  mode that uses it) and the enrichment code already exists duplicated in `planView.php`;
  centralising in `planViewGUIInit()` fixes the shared template path for both controllers.

## Files changed

| File | Change |
|---|---|
| `lib/plan/planViewUtils.php` | +50: `planViewGUIInit()` now enriches rows with `tcase_qty`, `build_qty`, `platform_qty`, `drawPlatformQtyColumn` and per-plan `rights` |
| `tmp/TLU_Test_Cases.md` | +Suite 622 (8 test cases, 8/8 PASS) |

## Verification

- `php -l lib/plan/planViewUtils.php` → "No syntax errors detected".
- Cleared `events` + `gui/templates_c/*.php`, then loaded `planEdit.php?tproject_id=1`:
  **0** new level-2 events (pre-fix: 4 per render). Both plans listed with counts 0/0 and
  per-plan Assign Roles links present (rights populated).
- Created 2nd test plan "Second Plan" via UI → only `audit_testplan_created` /
  `audit_users_roles_added_testplan` (level-16 INFO) rows; no warnings.
- Standalone `planView.php` still renders clean (no regressions).
- Edit form `planEdit.php?do_action=edit&itemID=2` clean (planEdit.tpl path).
- Inserted `platforms` + `testplan_platforms` → Platform # column appears, `platform_qty=1`
  per plan, events still empty (platform path exercised).
- Event Viewer scan across all runs: no new Error/Warning entries.

## Regression suite

Suite 622 in `tmp/TLU_Test_Cases.md` — 8/8 PASS (pre-fix repro, post-fix clean events,
rights links presence, create flow, standalone planView, edit form, platform column path,
Event Viewer scan).