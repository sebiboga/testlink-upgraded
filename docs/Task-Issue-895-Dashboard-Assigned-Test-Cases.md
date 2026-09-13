# My Assigned Test Cases — Dashboard Widget (Refs #895) — Docs (Wiki Mirror)

> Mirror of the GitHub Wiki page `Dashboard-Assigned-Test-Cases.md`. Image lines omitted per project convention.

## What was implemented

The TestLink dashboard (`gui/templates/mainpage/mainPage.html`) now shows a **"My
Assigned Test Cases"** card below the execution-status widget: every test case
whose execution is assigned to the logged-in user, scoped to the selected test
project (all its active test plans from the session context, or a single plan when
one is selected), on **open** builds of **active** test plans.

This ports the legacy 1.9.20 personal view `lib/testcases/tcAssignedToUser.php`
onto the Dashboard so a tester can see at a glance *"what do I work on today"* —
the exact gap described in issue #895.

## BFF API

**`api/mainpage/index.php`** — new route `GET /assigned` plus the shared
`getAssignedToMeData()` helper:

- Calls `testcase::get_assigned_to_user($userId, $tprojectID, $tplan_param,
  ['mode' => 'full_path'], ['tplan_status' => 'active', 'build_status' => 'open'])`
  — the same legacy function that drives `tcAssignedToUser.php` and the Reports
  "Assigned Test Case Overview" (Refs #684).
- Per row it resolves the **last execution status** on the row's
  build+platform (`get_last_execution()`, same pattern as the reports BFF) and
  derives `status_key` (`passed|failed|blocked|not_run`).
- Payload shape: `{ user_id, tproject_id, has_data, total, executed, pending,
  plans: [ { id, name, show_platforms, priority_enabled, has_exec_right,
  rows: [ { build_id, build_name, suite_path, tc_id, tcversion_id, prefix,
  tc_external_id, name, version, tplan_id, tplan_name, status, status_key,
  creation_ts_epoch, assigned_on_epoch, age_days, deadline_epoch,
  deadline_overdue, can_exec, platform_id, platform_name, priority,
  priority_level } ] } ] }`.
- **Parity fix (this run):** `platform_id`/`platform_name` are emitted on every
  row, not only when the plan has platforms — the legacy exec/quick-exec links
  key unconditionally on `platform_id`. Refinement over legacy: `show_platforms`
  is `true` only when the plan actually has one or more platforms
  (`getPlatforms()` returns `[]`, never null), so the widget can hide the
  Platform column when the project defines none.

## HTML screen

**`gui/templates/mainpage/mainPage.html`** — new `#secAssigned` card:

- Header "My Assigned Test Cases" + hint; right-side summary chips
  `assigned / pending / executed / overdue`.
- `DataTable` columns: **Test case** (`A895-3: Name (v.1)` + full suite path as
  sub-line), **Build**, **Platform**(hidden if no plan uses platforms),
  **Test Plan**, **Priority**(hidden if the project has priority disabled),
  **Assigned on**, **Due** (red + `overdue` badge when past deadline), **Status**
  (palette badges: passed `#4ECDC4`, failed `#e6605e`, blocked `#f0ad4e`,
  not-run `#8f8f8f`), **Actions**.
- Actions cell (gated by `can_exec`): quick **Passed/Failed/Blocked** icons
  (POST `/api/reports/index.php?action=quick_exec`, same endpoint as the Reports
  ATO screen), **Execute** link to `execSetResults.html` (`caller=mainPage`,
  build/platform pre-set), **History** link to `execHistory.html`, plus the TC
  name linking to `tcView.html`.
- Deep links use the **resolved** project id (`g_proj_id`, from the BFF
  `tproject.id`); this keeps the links correct even though the mainframe URL
  stays `tproject_id=0` when the session context drives the dashboard.
- Rows are de-duplicated across plans by `(tc_id, tcversion_id, tplan_id,
  build_id, platform_id)`.
- Empty state: the card hides entirely (`display:none`) when there is no
  project/plan or no assigned data — matching the other dashboard widgets.

## i18n

29 new keys in all 10 locale bundles (`gui/templates/i18n/*.json`), inserted
after `dash.total` keeping alphabetical order: `dash.assignedTitle`,
`dash.assignedHint`, `dash.assignedTc/Build/Platform/Priority/AssignedOn/Due/
Actions`, `dash.assignedTotal/Pending/Executed/Overdue/OverdueShort`,
`dash.assignedPriorityHigh/Medium/Low`, `dash.statusPassed/Failed/Blocked/
NotRun`, `dash.assignedQuickPassed/Failed/Blocked`, `dash.assignedExecTitle`,
`dash.assignedHistoryTitle`, `dash.assignedConfirm`, `dash.assignedQuickOk`,
`dash.assignedNoPerm`. Romanian (`ro`) carries real translations; the other
bundles follow the existing `dash.*` convention (English source values).

## Verification

Verified end-to-end in the browser on branch `task/issue-895` (fixture
`tmp/fixtures_895.php`: project ASG895, plan "ASG Dashboard Plan", open build
B895 Open + closed build B895 Closed, admin assigned to 3 TCs — 2 on the open
build, 1 on the closed build, a past deadline and a pre-inserted PASSED
execution):

- Widget shows exactly the open-build assignments (the closed-build row is
  filtered out — `build_status=open` legacy parity).
- Status/overdue/priority columns render from live BFF data.
- Quick-exec "Mark as Failed" writes an `executions` row and the widget + pie
  chart refresh; summary chips update.
- Execute link opens `execSetResults.html` pre-selecting the assigned build.
- No new Error/Warning rows in the `events` table; browser console clean.

## Files changed

| File | Purpose |
|------|---------|
| `api/mainpage/index.php` | `GET /assigned` route + `getAssignedToMeData()` + always-emit `platform_id` |
| `gui/templates/mainpage/mainPage.html` | `#secAssigned` card + render JS (`loadAssigned/renderAssigned/assignedQuick/...`) |
| `gui/templates/i18n/*.json` (×10) | 29 new `dash.assigned*` / `dash.status*` keys |
| `tmp/fixtures_895.php` | idempotent browser-test fixture (new) |
| `docs/screenshots/issue-895-assigned-widget.png` | widget normal state screenshot |
| `docs/screenshots/issue-895-quick-exec-failed.png` | widget after quick-exec interaction |