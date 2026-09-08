# Test Cases with Custom Fields — Modernized Screen

The **Test Cases with Custom Fields** report (ASIDE → Reports → *Test Cases with Custom Fields*) lists every execution whose test case has **any custom field set on execution**, plus the execution notes for the applicable rows: one row per execution with the test suite, test case (with history / execute / edit icons), version, optional platform, build, tester, date, status, execution notes, and one column per execution-time custom field linked to the project. It replaces the legacy 1.9.20 `lib/results/testCasesWithCF.php` ext-table screen with a modern Dashio-styled page backed by a plain-PHP REST BFF.

**Path:** ASIDE menu → Reports → *Test Cases with Custom Fields*
**URL:** `gui/templates/results/tcasesWithCF.html?tproject_id=<id>&tplan_id=<id>`
**BFF API:** `api/reports/index.php` — `GET ?action=tcases_with_cf&tproject_id=&tplan_id=`
**Rights:** `testplan_metrics`
**Tracking issue:** [#1204](https://github.com/sebiboga/testlink-upgraded/issues/1204) (parity analysis)

---

## Overview

* Header carries the test plan context; toolbar shows the test project.
* One row per execution with any of: an execution-note value **or** any execution-time custom-field value — rows where both are empty are dropped (legacy `$hasValue` filter, byte-identical behavior).
* Columns (in order): Test Suite (path) · Test Case (external id `PREFIX-n : name` + history / execute / edit icons) · Version · Platform (only when the plan has linked platforms) · Build · Tester · Date · Status (localized badge) · Execution Notes · one column per execution-time CF.
* The custom-field column set is derived at runtime from `cfield_mgr::get_linked_cfields_at_execution(tproj,1,'testcase',null,null,null,'name')` — the exact same call the legacy controller makes.

## BFF API

`GET /api/reports/index.php?action=tcases_with_cf&tproject_id=<id>&tplan_id=<id>`

Key payload fields: `hasData`, `show_platforms`, `cf_columns` (`[{id,label,name}]`), `rows` (`[{tcase_id, tcversion_id, tc_external_id, external_id, tcase_name, suite_path, tcversion_number, platform_name/-id, build_name/-id, tester, execution_ts, exec_status, exec_status_label, exec_notes, cfields:{<name>:<value>}}]`), `warning_msg`, `elapsed_time`. Images/enrichment all reuse legacy helpers (`buildExternalIdString`, `getPathLayered`, `string_custom_field_value`).

Permission handling mirrors legacy `checkRights()`: no session ⇒ 401; missing `testplan_metrics` ⇒ 403.

## Empty states

* Plan with no linked test cases (or a plan with executions all empty of notes+CF values) → *There are no test cases with a custom field set on execution* (legacy `no_linked_tc_cf`), shown in an info box with an elapsed footer.

## i18n

14 `tcwcf.*` keys in all 10 locale bundles (en de es fr it ja pt ro ru zh); status labels and some column headers resolved server-side via `lang_get()` so they follow the session locale.

## Parity verification (issue #1204)

Verified 2026-09-08 against a purpose-built fixture (`tmp/fixtures_tcwcf.php`: project TCWCF #45, plan `PlanTCWCF` #66 + empty plan `TCWCF-Empty` #67, suites One/Two, 6 TCs TC1–TC6, open build 5 + closed build 6, platforms TCWCF Linux #3 / TCWCF Windows #4, execution-time custom field `TCWCF_exec_note` linked at the TESTCASE node with values on some executions / notes-only / both / neither, tester + `tcwcf_norg` role-3 no-rights user). Browser+API verified modern vs legacy side by side (admin + no-rights):

* **Row set byte-identical** — both generations call the same `get_linked_cfields_at_execution()` and apply the same hasValue filter, so exactly `TC1/TC3/TC5` render (TC2 notes-only dropped because empty; TC4/TC6 neither → dropped). Confirmed 3 rows on both.
* CF column header (`TCWCF Exec Field`) + values (`CF value Alpha/Gamma/Epsilon`), status badges (Passed/Blocked/Passed), external ids (`TCW-1 : TC1` …), per-row history/execute/edit icons, platform column (plan has linked platforms), build column, tester, date all match.
* `testplan_metrics` enforced BFF-side (role-3 `tcwcf_norg` → HTTP 403; modern shows a generic "Error loading data" toast — the legacy `checkRights` also refuses, so the rights contract is intact).
* Empty-plan state: modern shows the `no_linked_tc_cf` info box + elapsed (legacy shows only the info note + generated timestamp with no warning) — modern is a helpful superset.
* Event Viewer: no new Error/Warning rows attributable to browser testing (the stdClass `enable_on_*`/`color` E_WARNINGs at seed time are fixture-creation artifacts, not screen behavior).

### Gaps filed (all task, OPEN)

| Issue | Gap |
|---|---|
| [#1205](https://github.com/sebiboga/testlink-upgraded/issues/1205) | ExtTable **group-by-Build** + toolbar (Expand/Collapse Groups, Show all Columns, Reset to Default State, Refresh, Reset Filters, MultiSort) + Date-DESC default sort dropped — legacy `tlExtTable` `setGroupByColumnName('build')`/`setSortByColumnName('date')`; modern flat DataTable |
| [#1206](https://github.com/sebiboga/testlink-upgraded/issues/1206) | Per-column **Platform list filter** dropped — legacy `getColumnsDefinition()['platform']` `filter='list', filterOptions=$platforms` |
| [#1207](https://github.com/sebiboga/testlink-upgraded/issues/1207) | **Execute icon drops build/platform context** — legacy `openExecutionWindow(tcase_id,tcversion_id,builds_id,tplan_id,platform_id)` passes `setting_build`/`setting_platform` to execSetResults; modern `openExecute(tcId,tcverId)` forwards only tcase/tcversion/tplan |
| [#1208](https://github.com/sebiboga/testlink-upgraded/issues/1208) | **Info note + "Generated by TestLink on" footer** dropped — legacy tpl renders `info_testCasesWithCF` + generated timestamp; modern footer only `tcwcf.elapsed` |

Cleanup: [#1209](https://github.com/sebiboga/testlink-upgraded/issues/1209) Delete legacy `testCasesWithCF.php` + `testCasesWithCF.tpl`.

Screenshots: `docs/screenshots/tcwcf1204_modern.png`, `docs/screenshots/tcwcf1204_legacy.png`, `docs/screenshots/tcwcf1204_empty.png`.
