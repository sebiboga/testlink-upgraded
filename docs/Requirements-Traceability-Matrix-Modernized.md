# Requirements Traceability Matrix — Modernized Screen

Enhancement screen for GitHub issue [#1065](https://github.com/sebiboga/testlink-upgraded/issues/1065):
a Requirements Traceability Matrix (RTM) that traces every requirement of a
test project to the test cases that cover it and to their latest execution
result on a selected build/platform.

Unlike the **Requirements Coverage** report
(`lib/results/resultsReqs.php` → `gui/templates/results/resultsRequirements.html`),
which aggregates *how well* a plan meets its requirements, the RTM is a
requirement-first, bidirectional view: it includes **orphan requirements**
(linked to nothing) and lists each requirement's **individual linked test
cases** together with their in-plan status and latest result, plus the plan's
**orphan test cases** (assigned to the plan but linked to no requirement).

**Path:** ASIDE menu → Reports → *Requirements Traceability Matrix*
**URL:** `/gui/templates/results/rtm.html?tproject_id=<id>&tplan_id=<id>`
**BFF API:** `/api/rtm/index.php?action=matrix&tplan_id=<id>&build_id=<id>&platform_id=<id>` (`build_id`/`platform_id` `0` = any)
**BFF context:** `/api/rtm/index.php?action=context&tplan_id=<id>`
**Rights:** `testplan_metrics`, enforced server-side against the **owning**
project of the plan (proved from the plan, not trusted from the client).
**Tracking issue:** [#1065](https://github.com/sebiboga/testlink-upgraded/issues/1065)
**Files:** `gui/templates/results/rtm.html`, `api/rtm/index.php`, `tmp/fixtures_1065.php`

Shortcut: the same URL is exposed client-side as `$actions->rtm` in
`lib/functions/common.php`.

## Overview

| Column | Meaning |
|---|---|
| Req ID | `requirements.req_doc_id` (e.g. `R6-REQ-1`) |
| Requirement | Requirement title, from `nodes_hierarchy`; an `orphan` chip is shown for requirements with no traceability link |
| Req Spec | Spec doc-id (`RS-R6`) · spec title |
| Linked TCs | Expandable count; the detail table lists each `req_coverage`-linked test case with external id, name, version, suite, **In plan** and **Latest result** |
| Executed | In-plan linked test cases that have an execution on the build/platform filter (`n/max`) |
| Passed | In-plan linked test cases whose latest filtered execution is `p` |
| Defects | Distinct bugs (`execution_bugs`) raised by executions of the requirement's linked test cases on the filter |
| Coverage | `covered` / `partial` / `uncovered` (see below) |

### Coverage semantics

* **uncovered** — the requirement has no plan-assigned linked test case, or none of them was executed on the filter.
* **covered** — every plan-assigned linked test case has been executed on the filter and the latest result of each is `p`.
* **partial** — anything in between (e.g. some executed, some failing/blocked/not run).
* **orphan requirement** — zero rows in `req_coverage` for the requirement (`link_status`/`is_active`).
* **orphan test case** — a `testplan_tcversions` row whose `tcversion_id` has no active `req_coverage` row.

"Latest result" uses `MAX(executions.id)` per (`testplan_id`, `tcversion_id`)
on the build/platform filter, matching the project's latest-wins convention:
on **Any build** the newest execution row decides, so a test run in a later
build shadows an earlier one.

## Toolbar actions

* **Platform / Build** selects (`[Any]` = `0`) — re-fetch the matrix on **Apply**.
* **Coverage** select — client-side filter (`All` / `covered` / `partial` / `uncovered` / `orphan`)
  applied through a hidden DataTables column, so it combines with the search box and does not re-fetch.
* **Export CSV** — client-side CSV of the current matrix rows (Req ID, Requirement, Spec, Linked TCs, Executed, Passed, Defects, Coverage, orphan flag), correctly quoted, UTF-8 **with BOM**, and spreadsheet-formula characters (`= + - @`, tab/CR) neutralised.

## States

* Loading spinner, then summary badges + DataTable.
* **Requirements disabled** for the project → info message "Requirements are not enabled for this test project.", no table (BFF returns `requirements_enabled: false`).
* **No requirements** → empty panel.
* **403** (user lacks `testplan_metrics`) → error card "Missing rights: testplan_metrics" with machine code chip; **401** for anonymous. Both are JSON from the BFF surfaced by the screen's Ajax error handler.
* **401 / `session_expired`** → the screen redirects to `/login.php?note=expired` (same as the other BFF-backed screens) instead of leaving a dead tab.

## BFF notes & schema drift

* 2.0.1 schema drift (lesson from [#1767](https://github.com/sebiboga/testlink-upgraded/issues/1767)): `testplans` and `testprojects` have **no `name` column** — plan/project names are read from `nodes_hierarchy`.
* `tcversions` nodes are children of the testcase node in `nodes_hierarchy`, so test-case names need the two-hop join (`tvversions` → testcase node → suite node).
* Rights are proved on the plan's owning project; a supplied `tproject_id` that does not match the plan → `404 project_mismatch` (client-side assertion only).
* Machine codes: 400 (missing/unknown action or missing plan), 401 (anonymous), 403 (no rights / same-origin guard), 404 (unknown plan / project mismatch), 405 semantics guarded by `bffSameOriginGuard` (foreign or absent Origin → 403), session-based auth + `bffSameOriginGuard` + `bffEnforceSession`.

## Hardening pass (2026-10-30)

A verification pass over the screen found and fixed four defects:

* **Orphan test-case names were empty and the suite column showed the test case.**
  The orphan query joined `nodes_hierarchy` on `NTC.id = TV.id` (the tcversion
  node) and took `NTC.parent_id` (the test case) as the suite. It now walks
  version → test case → suite (`TVN.parent_id` then `NTC.parent_id`), matching
  the requirement-links query. Regression fixture `tmp/fixtures_1065_edges.php`
  (project `RTM1065E`) proves `tc_name` / `suite_name` are populated.
* **DataTables rows were corrupted after a second render when a detail row had
  been expanded.** The per-requirement detail used a nested `<table><tr>`;
  DataTables walks `tbody tr` and ingested those nested rows as data rows after
  re-initialisation, nesting the whole matrix inside the first detail cell.
  The detail is now rendered with `div` rows (`.tc-detail-grid`), so it is
  invisible to DataTables.
* **Session timeout was not enforced.** The BFF now calls
  `bffEnforceSession($db)` (401 `session_expired`), sends
  `Cache-Control: no-store`, supports `HEAD`, and returns machine codes on
  every error (`not_authenticated`, `wrong_method`, `no_testplan`,
  `unknown_testplan`, `unknown_action`).
* **CSV hardening.** UTF-8 BOM for Excel and formula-injection neutralisation.

The same pass added the **Coverage** filter and removed ten duplicated
`setTimeout` blocks that re-applied the DataTables search-input accessibility
attributes.

## Verification

See the screenshot set `docs/1065-rtm-*.png` and the test suites
"Modernize — Issue #1065" and "Regression — Issue #1065" in
`tmp/TLU_Test_Cases.md`.
Fixture `tmp/fixtures_1065.php` rebuilds a deterministic project (RTM1065/R6)
with two builds, an orphan requirement, an orphan test case, a defect and a
failing login test, so the build/config flips are observable end to end.
Fixture `tmp/fixtures_1065_edges.php` (project `RTM1065E`) adds edge cases:
an inactive coverage link, a second requirement version, a linked test case
outside the plan, a real platform, and a second project/plan for the
`project_mismatch` 404.
