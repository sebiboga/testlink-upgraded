# Modernize: Project Build Inventory (Issue #2061)

**Screen:** `gui/templates/plans/buildInventory.html`
**BFF:** `api/buildinventory/index.php`
**Status:** DONE — one new standalone Dashio screen, no Smarty, no legacy controller.

## Why this screen

Legacy 1.9.20 offered one build list per test plan
(`lib/plan/buildView.php` / `gui/templates/plan/buildView.tpl`), and 2.0.1 also
has a modern per-plan `buildsView.html`, but neither offers a project-wide
answer to *which builds exist, which are active/open, and where the execution
evidence actually is?* Builds are a project-scoped entity
(`builds.testproject_id`; there is no `builds.testplan_id`), so the per-plan
screen cannot answer that question without stitching plans together by hand.
2.0.1 now has a dedicated read-only build registry report for one test project.

## Screen

- Header toolbar: Refresh, Export CSV, Project / Plan / State / Availability
  filters and Apply / Close. Deep links accept `tproject_id`, `plan_id` and
  (for compatibility with `buildsView.html`) `tplan_id`, plus `state` / `open`.
- Context panel with project, plan scope and the number of assigned versions.
- 11 KPI tiles: builds, active, open, with executions, executions, passed,
  failed, blocked, pass rate, distinct defects and last activity.
- Project verdict banner (`no_data`, `not_started`, `at_risk`, `watch`, `ready`,
  `in_progress`) with the failed-execution count supplied to the localized
  `at_risk` message.
- 18-column build DataTable: name/notes, active, availability, plans with
  evidence, creation/release/closure dates, executions, cases, pass/fail/block,
  pass rate, testers, distinct defects, last execution, verdict and Detail.
- Per-build Detail modal listing the latest execution events (test case, plan,
  status, tester, duration), capped at 200 newest-first even when a report
  filter hides that build. Distinct defect IDs are rolled up per build in the
  table and KPI columns.
- UTF-8 CSV export with BOM, `;` separator, quoted fields and a formula
  injection guard; verdict text comes from the active locale bundle.
- Localized 400/401/403/404/405 state cards. 87 `binv.*` keys plus
  `footers.buildInventory` exist in all 10 client bundles; `bv.buildInventory`
  localizes the toolbar entry; `ai/verify_i18n_coverage.sh` reports 9/9 PASS
  (13262 keys each). EN and DE checked in the browser.

## BFF contract

| Route | Method | Right | Result |
|---|---|---|---|
| `?action=projects` | GET/HEAD | `testplan_metrics` per project | readable projects |
| `?action=init&tproject_id=P[&plan_id=N]` | GET/HEAD | `testplan_metrics` on P | context, plan/state/open options |
| `?action=report&tproject_id=P[&plan_id=N][&state=all\|active\|inactive][&open=all\|open\|closed]` | GET/HEAD | `testplan_metrics` on P | KPIs, rows, project verdict |
| `?action=detail&tproject_id=P&build_id=B[&plan_id=N]` | GET/HEAD | `testplan_metrics` on P | capped execution evidence for one build |

All routes call `bffSameOriginGuard()` and `bffEnforceSession()`. The
`testplan_metrics` right is checked on the **addressed** project before the
project exists lookup, so an unentitled caller cannot probe project existence;
denials are written to the Event Viewer as AUDIT rows. Validation returns
machine codes `invalid_request`, `not_authenticated`, `no_right`,
`tproject_not_found`, `plan_not_found`, `build_not_found` and
`method_not_allowed`.

Project/plan display names are resolved through `nodes_hierarchy` because the
`testprojects` table has no `name` column; this was the root cause of bug
#2062. Executions are attributed to plans from their own `testplan_id`
(builds have no owning plan column), assignment counts come from
`testplan_tcversions`, and defect IDs are deduplicated per build across
execution rows and steps.

## Link switch

`lib/functions/common.php` gained `$actions->buildInventory` at project scope
(valid with `tplan_id=0`). `gui/templates/plans/buildsView.html` gained a
`Build Inventory` toolbar button that carries `tplan_id` and `tproject_id`
into the new screen. No legacy controller was reused.

## Issues found while testing

- #2062 — `GET ?action=projects` returned an empty HTTP 500 because the BFF
  selected `testprojects.name`, a column that does not exist in 2.0.1. Fixed in
  `81b50229b` by querying IDs and resolving names via
  `testproject::getName()`; verified and closed.

Suite `Issue #2061` is appended to `tmp/TLU_Test_Cases.md` and executed:
25 PASS, 1 code-reviewed permission path, 0 open bugs. Event Viewer has no new
Error/Warning entries after the fix. Screenshots: normal state, detail modal
and German locale mirrored to the wiki (filtered-state capture was skipped after
two Chrome MCP timeouts).
