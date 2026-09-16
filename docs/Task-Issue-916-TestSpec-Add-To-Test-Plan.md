# Task 916 — Add to Test Plan action in the Test Specification editor (gap vs legacy)

**Issue:** [#916](https://github.com/sebiboga/testlink-upgraded/issues/916)
**Status:** IMPLEMENTED (2026-09-16)

## The gap

Legacy `testcases` (`lib/testcases/tcEdit.php:80` `case "doAdd2testplan"` →
`lib/testcases/testcaseCommands.class.php:476` `doAdd2testplan`, plus the
`tcAssign2Tplan.php` popup) lets a user **add a test case version to a Test
Plan directly from the test-case editor**, choosing the target test plan; the
TC then appears in that plan's tree.

The modern `testSpec.html` editor dropped this capability: there was no
add-to-test-plan action and the modern BFF (`api/testcases/index.php`) had no
such endpoint (verified through git history — both landed in commit 8ff73e50c,
01:22Z).

## Legacy source of truth

- `lib/testcases/tcEdit.php:80` — `doAction=doAdd2testplan` dispatch.
- `lib/testcases/testcaseCommands.class.php:476-511` — `doAdd2testplan`:
  reads `request['add2tplanid']` = `tplan_id → array(platform_id)`,
  builds `item2link` (`tcversion`, `platform`, `items`) and calls
  `testplan::link_tcversions(tplan_id, item2link, user_id)` per checked
  (plan, platform) pair.
- `lib/testcases/tcAssign2Tplan.php` + `tcAssign2Tplan.tpl` — grid of ACTIVE
  plans with per-platform checkboxes; already-linked pairs rendered read-only.
- `lib/functions/testcase.class.php:7527` — the button is shown only with
  `testplan_planning` right + the project having test plans.

## Modern implementation (port) — landed in commit 8ff73e50c, verified here

**Backend `api/testcases/index.php`**

- `GET ?action=add_plan_options` (line 1598): resolves the owning project
  (`owningProjectOf`), right-checks `testplan_planning`, loads the target
  tcversion (+ version number, name and identity `PREFIX-<extid>:<name>`),
  and builds the plan × platform grid via the helper `addToplanGrid()`
  (line 1138).
- Helper `addToplanGrid()`: mirrors the legacy tcAssign2Tplan decision logic —
  for every ACTIVE test plan (`testproject::get_all_testplans`, plan_status 1)
  it reads `testcase::get_linked_versions()` + `testplan::getPlatforms()`
  (`addIfNull` → platform 0 "No Platform" row), flags already-linked
  (plan, platform) pairs as read-only, and hides platforms when the plan is
  bound to a **different** version (TestLink allows only one version per plan).
- `POST ?action=add_to_plan` (line 1921): right-checks `testplan_planning`,
  validates the tcversion really belongs to the tcase, dedups existing
  `(testplan_id, tcversion_id, platform_id)` rows (unique key), then calls
  `testplan::link_tcversions` per checked pair — the exact `doAdd2testplan`
  semantics (`added`, `added_by_plan` counters in the response).

**Frontend `gui/templates/testcases/testSpec.html`**

- **Add to Test Plan** action in the TC view panel (line 858), shown when
  `grants['testplan_planning']` AND `ctx.hasTestPlans` (legacy gating).
- Modal `#addToPlanModal` (line 260): grid Ver. / Test Plans / Platform with a
  checkbox per (plan, platform) pair, already-linked rows checked + disabled
  with an "(Already linked)" note, submit button disabled until a free pair is
  selected.
- JS `openAddToPlan()` / `renderAddToPlanModal()` / `submitAddToPlan()`
  (lines 458–539): loads `add_plan_options`, posts `add2tplanid:{plan:{platform:true}}`,
  toast on success/cancel on error, closes the modal.
- i18n keys `tspec.addToTestPlan`, `tspec.loadingPlanOptions`,
  `tspec.alreadyLinked`, `tspec.noPlatform`, `tspec.addedToPlan`,
  `tspec.errNoPlanSelected`, `tspec.testPlans`, `tspec.versionShort`,
  `tspec.platform`, `tspec.noTestPlans` in all 10 bundles (de,en,es,fr,it,
  ja,pt,ro,ru,zh).

## Verification (this run, fresh DB)

Fixtures created through the modern UI: Project **TP916** (id 1), platforms
**Linux** (1) / **Windows** (2), plans **Release 1.0** (2) / **Release 2.0** (3)
with both platforms assigned, suite **Login Suite**, TC **TC Login Valid**
(tcase 5, tcversion 6). Browser E2E:

1. TC panel shows **Add to Test Plan** button (screenshot
   `docs/screenshots/issue-916-tcspec-add2tplan-button.png`).
2. Modal lists all 4 (plan, platform) pairs + identity `TP916-1:TC Login Valid`
   (screenshot `docs/screenshots/issue-916-tcspec-add2tplan-modal.png`).
3. Checked Release 1.0 × Linux and Release 2.0 × Windows → toast
   "Test case added to test plan(s) (2)"; DB `testplan_tcversions` gains rows
   `(2,6,1)` and `(3,6,2)`; audit events `audit_tc_added_to_testplan` for both
   plans (ASSIGN, source GUI).
4. Reopened modal: linked pairs render checked + disabled + "(Already linked)",
   the other pairs stay selectable.
5. POSTing an already-linked pair + a fresh pair returned `added:1` with
   `added_by_plan:{3:[1]}` — duplicate row correctly skipped (dedup).
6. Plan tree effect: `planUpdateTC.html` for Release 1.0 shows suite Login
   Suite → `TP916-1: TC Login Valid` (version 1, Latest); plan management shows
   Test Cases: 1 for both plans.
7. Error paths: missing params → 400, nonexistent tcase → 404, empty
   `add2tplanid` → 400 "No test plan selected". No JS console errors; no new
   Error/Warning rows in the `events` table.

Test suite: see `tmp/TLU_Test_Cases.md` **"Task — Issue #916"**.