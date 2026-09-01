# Bugfix: Issue #790 — Execute Tests: missing "Assign execution task to me" + assignee display/warning (regression)

## Summary

The modernized **Execute Tests** screen (`gui/templates/execute/execTest.html` +
`api/execute/index.php`) dropped the legacy test-case **tester assignment**
feature entirely. On an unassigned test case the tester could previously:

- see a **"No tester assigned"** warning in the test-case header
  (`execTCaseHeader.inc.tpl:58-66`),
- tick an **"Assign task to me"** checkbox
  (`inc_exec_controls.tpl:55-60`), which on save created a
  `user_assignments` row (`execSetResults.php:208-219`) with
  `type=testcase_execution`, `status=open`,
- later see **"Assigned to: <user>"** in the header.

The 2.0.1 rewrite rendered none of that: `execTest.html` contained zero
`assign`/`assigned` references, the BFF `tcDetails` response had no
`assigned_user`, and `save` never called `assignment_mgr`.

## Legacy Behavior

- `gui/templates/tl-classic/execute/inc_exec_controls.tpl:55-60` — renders the
  `assignTask` checkbox when `$tc_exec.assigned_user == ''` (single-save mode).
- `gui/templates/tl-classic/execute/execTCaseHeader.inc.tpl:58-66` — renders
  warning icon `has_no_assignment`, or the `assigned_to` label + assignee name.
- `lib/execute/execSetResults.php:208-219` — on save with `$args->assignTask`:
  `$fid = $tplan_mgr->getFeatureID($tplan_id, $platform_id, $version_id)`
  (testplan.class.php:825 → the `testplan_tcversions.id` row) then
  `assignment_mgr->assign([$fid => [user_id, assigner_id, build_id,
  type=testcase_execution, status=open]])`.
- Assignment read back via `testcase::get_version_exec_assignment($tcversion_id,
  $tplan_id, $build_id)` (testcase.class.php:4623) joining `user_assignments`
  on `feature_id = testplan_tcversions.id`.

## Root Cause

1. `api/execute/index.php` `?action=tcDetails` (lines 1021-1040) built its
   response with keys `status, tcase, linked_bugs, version, steps,
   prior_execution, prior_step_results, exec_history` — no assignment query, no
   `assigned_user`/`assigned_user_id`. Verified live: the response had neither
   field.
2. `?action=save` (lines 1047-1315) mapped the payload onto `write_execution()`
   but had no `assign_task` handling and no `assignment_mgr` usage anywhere in
   the file.
3. `gui/templates/execute/execTest.html` `renderForm()` (lines 699-864) never
   rendered an assignee line or checkbox; `saveExecution()` never sent the flag.

Blast radius: only this screen consumes assignment data on the Execute path;
`tcExecAssignment.html` / `tcAssignments.html` have their own API routes and
were unaffected. No other modernized screen touches `assignment_mgr`.

## Fix

**Backend — `api/execute/index.php`:**
- `?action=tcDetails`: resolves the assignment of the current version in the
  plan+build+platform context through the same lookup legacy uses
  (`testcase::get_version_exec_assignment()`), builds comma-joined display
  names and ids (via `tlUser::getByID()` + `getDisplayName()`), and returns
  `assigned_user` + `assigned_user_id` (both empty strings when unassigned).
  Wrapped in try/catch so assignment lookup can never break the details load.
- `?action=save`: new optional `assign_task` payload (`1`/`true`/`on`/`yes`).
  When set, computes `$fid = $tplanMgr->getFeatureID($tplanId, platformNorm,
  $tcversionId)` and creates the row via `assignment_mgr->assign()` with
  `type=testcase_execution`, `status=open`, `build_id`, `user_id=assigner_id=
  $userId` — replicating execSetResults.php:208-219. `assignment_mgr->assign()`
  is idempotent (skips present rows), so re-checking on a later save cannot
  duplicate. Platform normalization: the frontend sends `platform_id=-1` for
  no-platform plans while assignments store `platform_id=0` → normalize before
  `getFeatureID()`. Assignment creation is wrapped in try/catch (best-effort
  side effect, never fails the execution write).

**Frontend — `gui/templates/execute/execTest.html`:**
- `renderForm()`: right under the form title/meta, if `current.assigned_user`
  is empty renders the warning box "⚠ No tester assigned" plus (writable mode
  only) an "Assign task to me" checkbox; otherwise renders "👤 Assigned to:
  <display name>". Mirrors `execTCaseHeader.inc.tpl:58-66`.
- `saveExecution()`: appends `assign_task=1` to the FormData payload when the
  box is checked (same pattern as `copyAttFromLEXEC`).

**i18n:** added `exe.assignedTo` ("Assigned to"), `exe.assignTaskToMe`
("Assign task to me") and `exe.noTesterAssigned` ("No tester assigned") to all
10 locale bundles (`de,en,es,fr,it,ja,pt,ro,ru,zh`); every bundle validated
with `python3 -m json.tool`.

## Why This Approach

- **Minimal / legacy-parity**: reuse the exact legacy read and write helpers
  (`get_version_exec_assignment`, `getFeatureID`, `assignment_mgr->assign`) —
  no schema change, no new endpoint, no new classes.
- **Idempotent by construction**: `assignment_mgr->assign()` already skips
  existing rows, matching legacy's expectations.
- **Alternatives rejected**: adding assignment management UI (edit/remove
  assignees) belongs to the dedicated `tcExecAssignment` screen; the Execute
  form only needs the claim-or-display behavior legacy had.

## Files Changed

- `api/execute/index.php` — `tcDetails` (assignment fields), `save`
  (`assign_task` handling).
- `gui/templates/execute/execTest.html` — assignee/warning display, checkbox,
  payload append.
- `gui/templates/i18n/{de,en,es,fr,it,ja,pt,ro,ru,zh}.json` — 3 new keys each.
- `docs/screenshots/issue-790-before.png` (unassigned, pre-fix), 
  `docs/screenshots/issue-790-unassigned.png` (warning + checkbox),
  `docs/screenshots/issue-790-assigned.png` ("Assigned to: Testlink Administrator").
- `tmp/TLU_Test_Cases.md` — TC-790.1–790.9 (9/9 PASS).

## Commits

- `946dde82b` — `fix(execute): restore assign-execution-task in tcDetails and save (Refs #790)`
- `5ee0b8d4e` — `fix(execute): assign-task checkbox, assignee display and i18n keys (Refs #790)`
- `80821e171` — `test(execute): add regression suite TC-790.1-9 for assign-execution-task (Refs #790)`

## Regression Matrix

| # | Case | Result |
|---|------|--------|
| 1 | `tcDetails` returns `assigned_user`/`assigned_user_id` (empty when unassigned) | PASS |
| 2 | Unassigned case: "No tester assigned" warning + "Assign task to me" checkbox render | PASS |
| 3 | Check box + Passed + Save → one `user_assignments` row (user 1, build 1, type 1, status 1, feature_id = plan link) | PASS |
| 4 | Reopen → "Assigned to: Testlink Administrator", checkbox gone, API returns the names/ids | PASS |
| 5 | Save WITHOUT the box → no `user_assignments` row | PASS |
| 6 | Re-check and save twice → still exactly one row (idempotent) | PASS |
| 7 | No-platform plans (`platform_id=-1`) normalize to storage `0` for lookup + creation | PASS |
| 8 | All 10 i18n bundles valid JSON and contain the 3 new keys | PASS |
| 9 | Event Viewer `events` table: no new Error/Warning from the fix | PASS |

Verified 2026-09-01 with fresh fixtures (project Demo Project id=1, plan Demo
Plan id=2, case Login Test tcversion 5, build Build A id=1,
`testplan_tcversions.id=1`), headless Chrome at http://localhost:8082,
admin/admin. Browser console clean (only pre-existing a11y notices), events
table only INFO audit rows.