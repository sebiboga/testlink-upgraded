# Results by Test Suite — Modernized Screen

The **Results by Test Suite** report shows execution status metrics aggregated by
Top-Level : Child (Level 1 : Level 2) test suites of the active test plan, one
table per platform, with the First/Latest execution time span above each table.
It replaces the legacy 1.9.20 `lib/results/resultsByTSuite.php` HTML view with a
modern Dashio-styled page backed by a plain-PHP REST BFF. The legacy "send by
e-mail" and "export as spreadsheet" actions are preserved as-is: their buttons
POST to the same legacy controller endpoints (mail/XLS generation stays in
`lib/results/resultsByTSuite.php`).


**Path:** ASIDE menu → Reports → *Metrics by Level 1 & Level 2 Test Suites*
**URL:** `gui/templates/results/resultsByTSuite.html?tproject_id=<id>&tplan_id=<id>` (loaded inside the main frame)
**BFF API:** `api/reports/index.php` — `GET ?action=metrics_by_tsuite&tproject_id=&tplan_id=`
**Rights:** `testplan_metrics` (same right the legacy controller enforces via `checkRights()`; BFF returns 403 otherwise)
**Tracking issue:** #671

---

## Table of Contents

1. [Screen Layout](#1-screen-layout)
2. [Data Flow and Parity](#2-data-flow-and-parity)
3. [Toolbar Actions (legacy parity)](#3-toolbar-actions-legacy-parity)
4. [i18n](#4-i18n)
5. [Permission Path and Empty State](#5-permission-path-and-empty-state)
6. [BFF API Reference](#6-bff-api-reference)
7. [Bugs Fixed Along the Way](#7-bugs-fixed-along-the-way)
8. [Files](#8-files)

---

## 1. Screen Layout

| Section | Description |
|---------|-------------|
| **Header** | Teal Dashio header "Metrics by Level 1 & Level 2 Test Suites — for test plan X" with locale switcher |
| **Toolbar** | Dark bar: test project context, *Send by email to myself* (legacy POST), *Export data as spreadsheet* (legacy POST), *Refresh* |
| **Notice** | "Important Notice" platform-relationship box shown only when the plan has platforms (legacy `important_notice` + `report_tcase_platorm_relationship`) |
| **Report sections** | One white card per platform titled "Results on Platform: \<name\>" (no title for platform-less plans), each rendering the First/Latest execution span line plus the classic first-column / TOTAL / per-status qty + [%] table ending in **Completed [%]** |
| **Footer** | Legacy `info_gen_test_rep` description line + "Generated on \<timestamp\> · Processing time (seconds): n" |

## 2. Data Flow and Parity

The BFF calls exactly the same engine methods as the legacy controller:

1. `tlTestPlanMetrics::getStatusTotalsTSuiteDepth2ForRender($tplanId, null,
   ['groupByPlatform' => 1])` — produces the Level 2 suite breakdown grouped by
   platform (`infoL2[platform][l2suite]`) plus `idNameMap`.
2. NULL / empty `infoL2` ⇒ `hasData:false` ⇒ screen shows the empty state — this
   covers BOTH a plan with no linked test cases AND a flat specification whose
   test cases sit directly under level 1 suites (same contract as legacy).
3. Column headers are built from the FIRST data row's `details` keys, labels
   resolved server-side through `lang_get($tlCfg->results['status_label'][…])`.
4. Execution time span via `getExecTimeSpan($tplanId,['testplan_id'])`, adding
   `'platform_id'` when the plan has platforms; plans/platforms without any
   executions simply omit the span line.
5. Rows are re-ordered following `natcasesort(idNameMap)` — the natural
   case-insensitive SUITE NAME order is this report's defining feature. The BFF
   emits ordered LISTS (not id-keyed maps) because JS re-sorts integer-like
   object keys numerically, which would destroy that order.
6. Platform sections follow `natsort(platform names)`; platform-less plans get
   the implicit fake key `0` (legacy `array('')`).

## 3. Toolbar Actions (legacy parity)

| Button | Behaviour |
|--------|-----------|
| Send by email to myself | POSTs `sendByEmail=1` to `/lib/results/resultsByTSuite.php?format=<MAIL_HTML>&tplan_id=&tproject_id=` (legacy mail flow untouched) |
| Export data as spreadsheet | POSTs to `/lib/results/resultsByTSuite.php?format=<XLS>&spreadsheet=1…` → downloads `TestLink_GTMP_<proj>_<plan>.xls` |

## 4. i18n

All labels come from the client-side TLi18n module: 17 `rbs.*` keys added to ALL
10 locale bundles (`en de es fr it ja pt ro ru zh`), plus reused `common.*`
keys. `rbs.header` mirrors the legacy page title
(`metrics_by_l1l2_testsuite`); span/notice/footer wording mirrors
`firstExec`, `latestExec`, `l1l2`, `section_link_report_by_tsuite_on_plat`,
`important_notice`, `report_tcase_platorm_relationship`, `info_gen_test_rep`,
`elapsed_seconds`.

## 5. Permission Path and Empty State

* No session ⇒ HTTP 401 from the BFF.
* User without `testplan_metrics` (global role AND project context) ⇒ HTTP 403;
  the screen shows an "Insufficient rights" warning box. The gate matches the
  legacy `checkRights()` semantics: users holding the right ONLY through a
  per-project role pass, exactly like `hasRightOnProj()`.
* Plan without linked test cases or flat spec ⇒ friendly empty state message.

## 6. BFF API Reference

`GET /api/reports/index.php?action=metrics_by_tsuite&tproject_id=<id>&tplan_id=<id>`

```json
{
  "status": "ok",
  "hasData": true,
  "tproject_id": 1, "tplan_id": 2,
  "tproject_name": "RBS Demo", "tplan_name": "RBS Plan",
  "show_platforms": true,
  "platform_set": {"1": "Linux", "2": "Windows"},
  "platform_order": [{"id": "1", "name": "Linux"}, {"id": "2", "name": "Windows"}],
  "columns": {"not_run": {"qty": "Not Run", "percentage": "[%]"}, "...": {}},
  "span": {"1": {"begin": "raw datetime", "end": "raw datetime"},
           "2": {"...": "..."}},
  "suites": {"1": [ {"id": 4, "name": "Top Alpha:Alpha One",
                     "total_tc": 3, "percentage_completed": "66.67",
                     "details": {"passed": {"qty": 2, "percentage": "66.67"},
                                  "...": {}} } ] },
  "send_mail_url": "/lib/results/resultsByTSuite.php?format=6&tplan_id=...",
  "export_xls_url": "/lib/results/resultsByTSuite.php?format=4&spreadsheet=1...",
  "elapsed_time": 0.15
}
```

Notes:
* `hasData:false` responses carry only the context fields.
* Span timestamps travel RAW and are formatted client-side with
  `toLocaleString()` — the server-side strftime path renders placeholders
  literally under PHP 8 (see issue #563).
* Suite rows are ordered lists so JS preserves the natcasesort name order.

## 7. Bugs Fixed Along the Way

* Exec-span dates rendered literally as `%24/%51/%2026` (legacy
  `localize_dateOrTimeStamp`/strftime path broken under PHP 8, same family as
  #563) → raw timestamps + client-side formatting.
* 4x E_WARNING `Undefined variable $dummy` fired by the removed
  `localize_dateOrTimeStamp()` calls → calls deleted together with the date fix.
* Review fixes: JSON integer-key resort destroying suite/platform name order
  (ordered lists emitted instead); rights gate now admits per-project-role
  holders (legacy parity, fail-closed preserved); subtitle markup nesting.

## 8. Files

| File | Purpose |
|------|---------|
| `gui/templates/results/resultsByTSuite.html` | Modern Dashio screen |
| `api/reports/index.php` | `metrics_by_tsuite` action (BFF) |
| `lib/general/asideMenu.php` | Reports menu href switch (`link_report_by_tsuite`) |
| `gui/templates/i18n/*.json` | `rbs.*` keys in all 10 bundles |
| `tmp/seed_rbs.php` | CLI fixture seeder used for browser testing |
