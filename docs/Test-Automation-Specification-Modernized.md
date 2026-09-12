# Test Automation Specification — Modernized Screen

Modernization of **Test Automation Specification**
(`lib/results/testAutomationSpec.php`) — GitHub issue
[#1485](https://github.com/sebiboga/testlink-upgraded/issues/1485).

The last standalone legacy `lib/results/*` report controller is replaced by a
standalone Dashio page (`gui/templates/results/testAutomationSpec.html`) backed
by a plain-PHP REST BFF (`api/testautomationspec/index.php`). The screen is
reached from the ASIDE menu **Test Case Design → Test Automation
Specification** (replacing the old "Test Automation Team" menu action).

**URL:** `gui/templates/results/testAutomationSpec.html?tproject_id=<id>`
**BFF API:** `api/testautomationspec/index.php`
**Rights:** `mgt_view_tc` on the REQUESTED test project (legacy parity of being
able to view the test-case design) → 401 anonymous / 400 missing project or
unknown action / 404 unknown project (answered before the rights probe) /
403 no right / 500 generation failure (JSON, no HTML leak).

The report lists every **automated** test case of the project (`exec_type`
`TESTCASE_EXECUTION_TYPE_AUTO`), grouped by test suite — the same dataset the
legacy screen produced via `genSpecViewFlat()`.



---
## Table of Contents
1. [What the screen does](#1-what-the-screen-does)
2. [REST API Reference](#2-rest-api-reference)
3. [Legacy parity notes](#3-legacy-parity-notes)
4. [i18n Keys](#4-i18n-keys)
5. [Security](#5-security)
6. [Testing](#6-testing)

## 1. What the screen does

| Feature | Legacy behavior | Modern implementation |
|---|---|---|
| Dataset | `genSpecViewFlat('testproject', ...)` filtered to `TESTCASE_EXECUTION_TYPE_AUTO`, `onlyLatestTCV` | BFF calls the identical legacy function with the identical arguments and re-shapes the result into clean JSON |
| Grouping | per test suite, caption shows the suite path verbatim | same — each suite is a caption header (`/ASD Root Suite/` style) followed by its table |
| Row | legacy template printed the automated test suite/name + latest version | Dashio table columns: **Automated Test Case** (external id + name, click → modern `tcView.html` popup), **Version**, **Importance** badge (High=red / Medium=amber / Low=green) |
| Counts | spec summary header | toolbar shows project name, automated test-case count, total suites, generation timestamp (`tas.stats`), Refresh button |
| Empty project | legacy message "Test Automation Specification is empty" | localized `tas.noAutomated` empty state panel |
| No permission | legacy denied-access redirect | localized red `403` banner, no data rendered |

## 2. REST API Reference

Single route, session-authenticated, JSON.

| Method | Route | Query | Returns |
|---|---|---|---|
| GET | `?action=spec` | `tproject_id` (optional — falls back to session `testprojectID`, legacy `initUserEnv` context) | `{status:'ok', tprojectId, tprojectName, numTc, totalSuites, generatedOn, suites:[{id, name, testcase_qty, testcases:[{id, external_id, name, version, importance}]}]}` |

### Error conditions
- Missing/invalid session → HTTP 401 `{status:'error',...}`.
- Unknown `action` or missing project → HTTP 400.
- Unknown/forged `tproject_id` → HTTP 404 (checked before the rights probe, so
  a nonexistent project never reveals right-grant errors).
- Authenticated user without `mgt_view_tc` on the requested project → HTTP 403.
- Generation exception → HTTP 500 JSON (wrapped in try/catch; the legacy call
  cannot emit a raw HTML fatal).

## 3. Legacy parity notes

- The BFF `require_once`s `lib/functions/specview.php` (function-only library)
  and calls:
  `genSpecViewFlat($db,'testproject',$tproject_id,$tproject_id,'',null,0,array('exec_type'=>TESTCASE_EXECUTION_TYPE_AUTO),array('onlyLatestTCV'=>true))`
  — byte-identical arguments to the legacy controller.
- Suite caption uses `suite['name']` verbatim (keeps the `/Root/Suite/` path
  shape TestLink users expect).
- `version` is the TC version id (int); `importance` is the TestLink scale
  (1=High, 2=Medium, 3=Low), mapped to localized badges client-side.
- The ASIDE entry replaced the legacy `testAutomationSpec` action; the old
  `lib/results/testAutomationSpec.php` controller is kept only as reference and
  is no longer reachable from the modern UI.

## 4. i18n Keys

All labels are client-side via `TLi18n`; keys under the `tas.` namespace in
**all 10 locale bundles** (`en.json`, `ro.json`, `de.json`, `es.json`,
`fr.json`, `it.json`, `ja.json`, `pt.json`, `ru.json`, `zh.json`):
`tas.caption`, `tas.inProject`, `tas.testProject`, `tas.automatedCases`,
`tas.hint`, `tas.results`, `tas.noAutomated`, `tas.noRights`, `tas.colTestCase`,
`tas.colVersion`, `tas.colImportance`, `tas.impHigh`, `tas.impMedium`,
`tas.impLow`, `tas.stats` + footer `footers.tasSpec`.

The ASIDE menu label `btn_report_test_automation` was added to all 19
`locale/*/strings.txt` files and registered in
`gui/templates/dashio/labels/labels.aside.tpl`.

## 5. Security

- Every route runs behind `bffSameOriginGuard()` (CSRF same-origin / custom
  header proof) exactly like every other BFF.
- Rights are checked with `$user->hasRight($db,'mgt_view_tc',$tproject_id)` on
  the **URL-provided** project id — never the session-only variant
  `hasRightOnProj()` (which reads `$_SESSION['testprojectID']` and would let a
  user with rights on any session project read another project's report).
- All server data goes through `esc()`/`intval`; `tcase`/`tcversions` are
  null-guarded before array access.

## 6. Testing

Regression suite **1485** (16/16 PASS) in `tmp/TLU_Test_Cases.md`, verified in
browser + API after review fixes. Fixtures: `tmp/fixtures_aside_walk.php`
(project ASD1001, TC `API health` execution_type AUTO) and
`tmp/fixtures_tas_empty.php` (project ASD1002, no test cases).
Event Viewer: no new Error/Warning entries generated.
