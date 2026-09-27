# Test Plan Milestones (milestonesView) — Modernized Screen

Mirror of the wiki page [Test-Plan-Milestones](https://github.com/sebiboga/testlink-upgraded/wiki/Test-Plan-Milestones).

Modernization of **Test Plan Milestones** (`lib/plan/planMilestonesView.php` +
`lib/plan/planMilestonesEdit.php` + `planMilestonesCommands.class.php`) —
GitHub issue [#647](https://github.com/sebiboga/testlink-upgraded/issues/647).

The legacy Smarty screens are replaced by a standalone Dashio page
(`gui/templates/plans/planMilestones.html`) backed by a plain-PHP REST BFF
(`api/milestones/index.php`), following the same pattern as the other
modernized screens. The aside menu entry *Test Case Execution → Milestones*
now points to the new HTML screen (switched in `lib/functions/common.php`,
both the `milestonesView` link and the old `mileView` launcher entry).

## What the screen does (legacy parity, nothing dropped)

| Feature | Legacy behavior | Modern implementation |
|---|---|---|
| List milestones of current test plan (ordered by target date) | `testplan_planning` required (`checkGUISecurityClearance` rightsAnd) | `GET /api/milestones/index.php/list?tplan_id=N` (403 otherwise); aside visibility still gated by `testplan_milestone_overview` |
| Create milestone (name, target date, optional start date, priority %) | edit form → `do_create`; duplicate name per plan; valid dates; target not in past; target ≥ start; % 0–100 | `POST …/create?tplan_id=N` returning 400 + stable error codes for every validation branch |
| Edit milestone in modal | name click → `edit` / `do_update` | row-name button opens prefilled modal → `PUT …/update/{id}`; past-target check only when date changed (BUGID parity) |
| Delete milestone with confirm dialog | delete icon → `delete_confirmation()` → `doDelete` | trash icon → Bootstrap confirm modal → `DELETE …/delete/{id}` |
| Priority columns High/Medium/Low vs single % column | depends on project option `testPriorityEnabled`; when disabled only medium is editable (hidden 0 fields) | same layout switch driven by BFF-provided flag; modal swaps 3 inputs for one input |
| Live "Status of Milestones" report | `tlTestPlanMetrics::getMilestonesMetrics()` table: achieved % per priority vs expected %, red/green coloring, overall % | identical computation reused server-side; client renders green/red badges + `(done/total)` counters + expected % |
| Execution window semantics | only executions within `[start_date, target_date]` count | inherited from the shared metrics class (verified live: execution before start date is excluded) |
| Audit trail | `audit_milestone_created/saved/deleted` | identical events written by the BFF |

## Rights

* Everything: `testplan_planning` — exactly what the legacy controller
  enforces on `planMilestonesView.php` / `planMilestonesEdit.php`.
* The aside menu shows the entry only for users with
  `testplan_milestone_overview`, but the screen/BFF additionally require
  `testplan_planning` (a read-only user without it gets HTTP 403 on every
  route).

## Notes & decisions

* Error messages are stable codes (`milestone_name_already_exists`,
  `warning_invalid_date`, `warning_milestone_date`,
  `warning_target_before_start`, `warning_empty_milestone_name`,
  `warning_invalid_percentage`) localized client-side via the `ms.*` i18n
  keys present in all 10 locale bundles.
* **Percentage mapping quirk preserved:** in legacy the edit-form field labeled
  *High priority %* posts as `low_priority_tcases`, while reads alias DB
  column `a` as `high_percentage`. The modern BFF maps request values so the
  user-visible round-trip matches legacy exactly.
* Dates are stored ISO `YYYY-MM-DD`; empty start date persists as
  `0000-00-00` (BUGID 3907 parity).
* Plans without builds render no metrics section (`getMilestonesMetrics`
  returns `[]`, cf. issue #634 fix).
* Test suite: `tmp/TLU_Test_Cases.md` **Suite 647 — 18/18 PASS**.
* **Event history (added 2026-09-07, Refs #1149):** the edit modal now shows a
  *Show event history* button (hidden while creating a new milestone) for users
  holding `mgt_view_events`. It mirrors the legacy `planMilestonesEdit.tpl`
  `showEventHistoryFor(id,'milestones')` icon and the `platformsView.html`
  pattern: hidden `#eventhistory` form posts `object_id`/`object_type=milestones`
  to `/gui/templates/eventviewer/eventviewer.html`. The BFF `/list` exposes
  `rights.canViewEvents`; new `ms.showEventHistory` key added to all 10 locale
  bundles. See GitHub issue #1148/#1149.
* **SCREEN-COMPARE (2026-09-07, Refs #1148):** re-verified against legacy
  `lib/plan/planMilestonesView.php`/`planMilestonesEdit.php` on seeded project
  `MS Demo Project`. Confirmed full parity (list + metrics report, create/edit/
  delete + audit events, duplicate-name/past-date/% validation, `testplan_planning`
  403). Only gap was the dropped event-history icon → fixed (#1149). Test suite:
  `tmp/TLU_Test_Cases.md` **Suite 1148 — 11/11 PASS**.

* **Legacy grid DataTables contract (added 2026-09-27, Fixes #1650):** the legacy
  screen `lib/plan/planMilestonesView.php` →
  `gui/templates/dashio/plan/planMilestonesView.tpl` already *included*
  `DataTables.inc.tpl` with `DataTablesSelector="#item_view"` (tpl:36), but its table
  was `<table class="common" width="100%">` with **no id** and no `<thead>` — so
  `$('#item_view').DataTable(config)` was a silent no-op on an empty jQuery set (no
  console error). Measured before the fix: `#item_view` = 0, no
  `.dataTables_length` / `.dataTables_filter` / `.dataTables_info`, `thead` count 0,
  while the `lengthMenu`/`stateSave` config *was* emitted into the page. The
  `[planMilestonesView]` config added by #1594 (`item_view_table="table table-bordered"`,
  `item_view_thead="thead-dark"`, `pagination_length=20`) had no consumer.
  Fixed by porting the grid to the same contract the sibling Dashio list screens use
  (`buildView.tpl:86`, `planView.tpl:96`, `rolesView.tpl:73`):
  `<table class="{#item_view_table#}" id="item_view">`, header row in
  `<thead class="{#item_view_thead#}">`, data rows in `<tbody>`, and the Delete action
  column marked `data-orderable="false" class="icon_cell"` so it stays out of sorting
  and out of the global search index. The separate "Milestones Report"
  (`simple_tableruler`) table is deliberately untouched.
  After the fix (both project variants, `testPriorityEnabled` 1 → 7 columns and
  0 → 5 columns): `#item_view` = 1 with class
  `table table-bordered dataTable no-footer`, thead `thead-dark`,
  `#item_view_wrapper` present, page-length menu `20/40/60/All`,
  `.dataTables_info` "Showing 1 to 3 of 3 entries", search filters, Name sorting
  asc/desc, `stateSave` persists the page length across reloads, delete
  `delete_confirmation()` handlers and `planMilestonesEdit.php` links unchanged.
  The modernized screen `gui/templates/plans/planMilestones.html` was **not** affected —
  it already initialises DataTables on `#msTable` / `#reportTable`; this gap was
  confined to the legacy template that is still shipped (cf. the open
  "Delete legacy …" cleanup tasks). Test suite:
  `tmp/TLU_Test_Cases.md` **Suite 1650 — 22/22 PASS**; Event Viewer 0 new
  ERROR/WARNING. See GitHub issue #1650.

