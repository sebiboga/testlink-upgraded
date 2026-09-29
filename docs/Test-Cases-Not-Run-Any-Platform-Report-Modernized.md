# Test Cases Not Run on Any Platform Report — Modernized Screen

The **Test Cases Not Run on Any Platform** report (ASIDE → Reports → *Test Cases
not run on any Platform*) lists every test case linked to the current test plan
that **has never been executed on any platform** of that plan. It replaces the
legacy 1.9.20 `lib/results/tcNotRunAnyPlatform.php` view — which is still in the
tree but **cannot run at all** (see *Legacy defect* below) — with a modern
Dashio-styled page backed by a plain-PHP REST BFF.

**Path:** ASIDE menu → Reports → *Test Cases not run on any Platform* (the whole
Reports block is gated on a test plan being selected)
**URL:** `gui/templates/results/tcNotRunAnyPlatform.html`
**BFF API:** `api/reports/index.php` — `GET ?action=not_run_any_platform&tproject_id=N&tplan_id=M`
**Rights:** `testplan_metrics` on the **owning** test project
**Tracking issue:** [#1717](https://github.com/sebiboga/testlink-upgraded/issues/1717)
**Legacy defect:** [#1718](https://github.com/sebiboga/testlink-upgraded/issues/1718)

---

## Table of Contents

1. [Why this screen was picked](#1-why-this-screen-was-picked)
2. [Legacy defect: the controller could never run](#2-legacy-defect-the-controller-could-never-run)
3. [Screen Layout](#3-screen-layout)
4. [Report Semantics and Data Flow](#4-report-semantics-and-data-flow)
5. [Permission Path and State Cards](#5-permission-path-and-state-cards)
6. [BFF API Reference](#6-bff-api-reference)
7. [i18n](#7-i18n)
8. [Wiring and the `enabled` trap](#8-wiring-and-the-enabled-trap)
9. [Bugs found while testing](#9-bugs-found-while-testing)
10. [Files](#10-files)

---

## 1. Why this screen was picked

The **TODO section of `docs/MODERNIZATION-STATUS.md` is EMPTY** — every ASIDE
entry maps to a modern `.html` + BFF. A sweep of the remaining
`lib/results/*` controllers turned up one screen that was never modernized and,
worse, was **not registered in `cfg/reports.cfg.php` at all** — so it was not
linked from the Reports menu and its rot went unnoticed:

* `lib/results/tcNotRunAnyPlatform.php` + `gui/templates/dashio/results/tcNotRunAnyPlatform.tpl`
* and the tl-classic `gui/templates/tc-xtp-pref/tcNotRunAnyPlatform.tpl` pair.

## 2. Legacy defect: the controller could never run

Reading the controller first (mandatory) proved it is unusable, so the BFF does
**not** `include()` it and rebuilds the semantics from surviving helpers:

| # | Legacy line | Problem |
|---|-------------|---------|
| 1 | `require_once('results.class.php')` | that class no longer exists in the 2.0.1 tree → hard fatal |
| 2 | `:50` `$re = new results($db, $tplan_id)` | **commented out**, while `:62` still calls `$re->getMapOfLastResult()` → *Call to a member function on null* |
| 3 | `:124` `$executionsMap[$suiteId]` | read, but never assigned — the map the whole aggregation depends on is missing |
| 4 | — | no `cfg/reports.cfg.php` entry → unreachable from the Reports ASIDE menu |

Filed as **bug #1718** with the `bug` label. The three legacy templates only
supplied column headers and a legend, so nothing usable was lost by not reusing
them.

## 3. Screen Layout

| **Report (rows)** | localized priority **level** (`Medium`), not the raw `urgency x importance` product |

*(Screenshots for this screen live in the GitHub Wiki page — see
`Test-Cases-Not-Run-Any-Platform-Report-Modernized`.)*

| Section | Description |
|---------|-------------|
| **Header** | Teal Dashio header "Test Cases Not Run on Any Platform" with TLi18n locale switcher |
| **Context bar** | Test project name + test plan name |
| **Summary sentence** | The legacy "*Found n of total test cases*" line — the denominator counts **every** test case in the plan, not only the never-run ones |
| **Legend** | `info_tcNotRunAnyPlatform` (About this report) explaining the rule and when a case drops out of the report |
| **DataTable** | Columns: test case external ID, test case name, test suite path, one column per platform (a colored status badge each), and **Priority** when the project has test priority enabled; a **Match count** footer |
| **Row actions** | *Design* → `tcEdit.html` popup, *Execution History* → `execNavigator.html` popup — both via `openTCEditWindow()` / `openExecHistoryWindow()` from `testlink_library.js` |
| **State cards** | No platform defined / all test cases executed / no permission / load error — each a centered Dashio message box |
| **Footer** | `footers.tcNotRunAnyPlatform` + elapsed seconds |

## 4. Report Semantics and Data Flow

The BFF rebuilds the report on the two helpers the dead controller would itself
have used — both still in the tree and already used by the sibling
`neverRun.html` report:

1. **`testPlanUrgency::getPlatforms($tplanId, ['outputFormat' => 'map'])`** —
   the plan's platform set. Empty → `platforms_active: false` → the
   *"No Platform defined for this Test Plan."* card, matching legacy.
2. **`tlTestPlanMetrics::getNeverRunByPlatform($tplanId, $platformIds)`** — one
   row per `(test suite, test case, platform)` triple that has **no execution on
   any active + open build** of the plan.
3. A test case is listed when **every platform it is linked to** (`testplan_tcversions`
   rows for that case) appears in that never-run set — i.e. it has not been run
   anywhere. A case executed on *one* platform of two drops out of the report.
4. `tnrLinkedPlatformsPerCase()` resolves the plan's platform links per case,
   `tnrLastStatusPerPlatform()` supplies the badge per cell, and
   `tnrUrgImpPerCase()` the priority (only when `testPriorityEnabled`).
5. Rows are ordered deterministically by suite path, then test case name; the
   DataTable re-sorts client side.
6. `number_of_testcases` (the denominator) is a `COUNT(DISTINCT parent_id)` over
   the plan's versions.

**Schema gotcha (already learned elsewhere in 2.0.1):** `tcversions` has **no
`tcase_id` and no `urgency` column**. The test case id is the *parent* of the
version's own `nodes_hierarchy` node, and urgency lives on
`testplan_tcversions`. The first implementation of the BFF got both wrong and
returned an empty table — see §9.

## 5. Permission Path and State Cards

| Situation | Answer |
|-----------|--------|
| Anonymous / expired session | `401` → the screen's session card links to `login.php?note=expired` |
| Logged in without `testplan_metrics` on the project | `403 no_permission` → the *No permission* card |
| `tproject_id` or `tplan_id` missing / `<= 0` | `400` |
| Unknown project or plan id | `400 invalid_*` |
| **Plan belonging to a different project** | `400` — the plan is resolved through `testplan::get_by_id(..., ['output' => 'minimun'])`, which also yields its `tproject_id`, so a plan cannot be read through a project the caller has the right on |
| Non-GET verb | rejected by the shared BFF preamble (`405`) |

The rights check runs **after** the plan→project ownership proof, so a caller
holding the right on project A can never read a plan of project B.

## 6. BFF API Reference

```
GET api/reports/index.php
      ?action=not_run_any_platform
      &tproject_id=<id>
      &tplan_id=<id>
```

Response (200):

```json
{
  "status": "ok",
  "tproject_id": 1, "tproject_name": "…", "tproject_prefix": "TNR1717",
  "tplan_id": 2,   "tplan_name": "…",
  "platforms_active": true,
  "platforms": [{ "id": 1, "name": "Linux" }],
  "priority_enabled": true,
  "number_of_testcases": 4,
  "number_of_not_run": 2,
  "has_data": true,
  "rows": [{
    "tcase_id": 4, "tsuite_id": 3, "suite_path": "Suite / Sub",
    "external_id": "TNR1717-1", "name": "…",
    "cells": [{ "platform_id": 1, "status": "not_run" }],
    "linked_qty": 1, "not_run_qty": 1,
    "urg_imp": {"urgency": "High", "importance": "Must"}
  }],
  "elapsed_time": 0.01
}
```

`cells[].status` is one of the **string** codes from `config_get('results')`:
`not_run`, `passed`, `failed`, `blocked` (plus the remaining legacy codes),
rendered through the `tnrap.status.*` keys.

Deep links from a row keep the legacy contract:
`linkto.php?tprojectPrefix=<prefix>&item=testcase&id=<external id>`.

## 7. i18n

21 keys in all 10 modern bundles (`en de es fr it ja pt ro ru zh`), validated
with `python3 -m json.tool`:

| Key | English |
|-----|---------|
| `tnrap.header` | Test Cases Not Run on Any Platform |
| `tnrap.forPlan` | for test plan |
| `tnrap.testPlan` | Test Plan |
| `tnrap.testCases` | test cases |
| `tnrap.testCasesInPlan` | test cases in this test plan |
| `tnrap.notRunOf` | Found |
| `tnrap.ofTotal` | of |
| `tnrap.matchCount` | Match count |
| `tnrap.priority` | Priority |
| `tnrap.noPlatforms` | No Platform defined for this Test Plan. |
| `tnrap.noActiveBuilds` | No active and open build was found for this Test Plan. |
| `tnrap.allExecuted` | All test cases have been executed on at least one platform. |
| `tnrap.about` | About this report |
| `tnrap.info` | This report shows test cases that have been linked to the current test plan but have not been executed on any platform at all. … |
| `tnrap.elapsedSeconds` | Elapsed seconds |
| `tnrap.noPermission` | No permission |
| `tnrap.status.not_run` / `.passed` / `.failed` / `.blocked` | status badges |
| `footers.tcNotRunAnyPlatform` | TestLink 2.0.1 - Test Cases Not Run on Any Platform |

Plus the **ASIDE menu label** `$TLS_link_report_not_run_on_any_platform` in all
**19** server locale catalogues. Those files are a **mix of encodings** (UTF-8
and ISO-8859-*), so the insert sniffs UTF-8 per file and writes atomically —
`locale/cs_CZ/strings.txt` was truncated once by a naive write and restored.

## 8. Wiring and the `enabled` trap

| File | Change |
|------|--------|
| `cfg/reports.cfg.php` | re-registers `$tlCfg->reports_list['tcNotRunAnyPlatform']` → `gui/templates/results/tcNotRunAnyPlatform.html` |
| `lib/general/asideMenu.php` | `link_report_not_run_on_any_platform` branch |
| `lib/functions/common.php` | `$actions->tcNotRunAnyPlatform` |

**Trap worth remembering:** the report must be registered with
`'enabled' => 'all'` and **not** `'testplan'`. The Reports block in
`lib/general/asideMenu.php` only accepts `all|req|bts`, and the block is already
gated on `$_SESSION['testplanID'] > 0` — an unknown `enabled` value makes the
entry *silently* invisible, which is exactly how the missing registration went
unnoticed in the first place.

## 9. Bugs found while testing

| # | Symptom | Root cause | Fix |
|---|---------|-----------|-----|
| 1 | Report table always empty (0 rows) | `tcversions` has no `tcase_id`; the case id is the version node's `nodes_hierarchy.parent_id`. Urgency is on `testplan_tcversions`, not `tcversions` | `tnrTcaseIdByTcvId()` + `tnrLinkedPlatformsPerCase()` / `tnrUrgImpPerCase()` |
| 2 | Total test-case count wrong | `COUNT(*)` on the plan's versions counted versions, not distinct cases | `COUNT(DISTINCT NH_TCV.parent_id)` |
| 3 | Test case links rendered `TNR1717-TNR1717-1` | `tcversions.full_external_id` **already** carries `<prefix>-<external_id>` (`testplan::helperConcatTCasePrefix`); the prefix was prepended a second time | use `full_external_id` as-is |
| 4 | Every cell showed *Not Run*; badges wrong | `config_get('results')` returns an **array**, so `->status_code` was silently `NULL`; the codes are single-char **strings** and were pushed through `intval()`, collapsing passed/failed/blocked into each other and turning `E.status <> 'not_run'` into `<> 0` (always true) | `$cfg['status_code']` + string comparison (commit `4d2fa6714`) |
| 5 | Report not reachable from the menu | never registered in `cfg/reports.cfg.php` (→ **#1718**) | re-registered with `enabled => all` |

## 9b. Mandatory code review (rule 16)

A subagent reviewed `2bc0024f4~1..HEAD`: **2 BLOCKER + 5 SHOULD-FIX + 8 NIT,
and no security hole in the BFF** — SQL injection (every interpolated id is
`intval()`'d, the only string is whitelist-regex'd), the IDOR guard (the plan is
resolved through `nodes_hierarchy` and compared to the requested project before
any data query), CSRF (`bffSameOriginGuard()`, not an `apikey` action), the
rights gate and output escaping all came back clean. Fixed:

| # | Finding | Fix |
|---|---------|-----|
| BLOCKER | **Stored XSS** — `cols.push({title: pname})` passed a free-text platform name to DataTables, which injects column titles with `.html()`; a platform named `<img src=x onerror=…>` executed for every viewer (the sibling `neverRun.html` escapes the same value) | `esc(pname)`; verified live by renaming a platform to the payload — the header rendered it as text with 0 injected nodes |
| BLOCKER | **Self-contradicting table** — the rows come from `getNeverRunByPlatform()` (active **and open** builds) while the badge query filtered `B.active = 1` only, so a case executed solely on a *closed* build was listed by this "Not Run" report **and** carried a `passed` badge | `AND B.is_open = 1` in `tnrLastStatusPerPlatform()`; the fixture grew a `passed`-on-a-closed-build case to pin it |
| SHOULD-FIX | `getNeverRunByPlatform()` answers **NULL** (not an empty set) for a plan with no active+open build, and the action collapsed that with the false *"all test cases have been executed"* | `builds_available` flag + new `tnrap.noActiveBuilds` key in all 10 bundles |
| SHOULD-FIX | the Priority column showed the raw `urgency x importance` integer with thresholds hardcoded in JS, ignoring the project's `urgencyImportance->threshold[]` | `priority_to_level()` + `config_get('priority')->code_label` server-side, like every sibling action |
| SHOULD-FIX | `openExecHistoryWindow(row.tcase_id)` concatenated an undeclared `tproject_id` and produced `…&tproject_id=undefined` | direct `tcView.html` / `execHistory.html` URLs with the project id |
| SHOULD-FIX | `tnrLinkedPlatformsPerCase()` scanned every `testplan_tcversions` row of the plan, behind a comment wrongly claiming the filter could not be pushed into SQL | filter pushed through the `nodes_hierarchy` parent join |
| SHOULD-FIX (documented) | the legacy controller also served `FORMAT_MSWORD` + e-mail | intentionally not reproduced: the report is `format_html` and the export gateway has no case for this report type, so a button would only produce a 500 |
| NIT | dead `$tcCfg`/`$prefix`, dead `urg_imp` key (a missing column key made DataTables log *Requested unknown parameter*), dead `platName` map, two wrong comments | removed / corrected |

NIT accepted: `$actions->tcNotRunAnyPlatform` in `lib/functions/common.php` is
unreachable — the identical dead pattern of every sibling screen, kept for
consistency.

**Known gap:** the Word and e-mail outputs of the 1.9.20 controller are not
reproduced (row 7 above).

Suite **#1717 = 40/40 PASS** (`tmp/TLU_Test_Cases.md`, harness
`tmp/test_1717.php` on fixture `tmp/fixtures_1717.php`: project 1 / plan 2 /
build 1 / platforms 1+2 / test cases 4, 7, 10, 13 / no-rights user), plus live
HTTP guards and a browser pass with a clean console and a clean Event Viewer.

## 10. Files

| File | Purpose |
|------|---------|
| `gui/templates/results/tcNotRunAnyPlatform.html` | modern Dashio screen |
| `api/reports/index.php` | `not_run_any_platform` action + `tnr*` helpers |
| `cfg/reports.cfg.php` | report registration |
| `lib/general/asideMenu.php` | Reports-menu link |
| `lib/functions/common.php` | `$actions->tcNotRunAnyPlatform` |
| `gui/templates/i18n/*.json` | 10 modern bundles |
| `locale/*/strings.txt` | 19 server catalogues (ASIDE label) |
| `lib/results/tcNotRunAnyPlatform.php` | legacy controller (broken, **#1718**) |
| `gui/templates/dashio/results/tcNotRunAnyPlatform.tpl` | legacy template (superseded) |
| `tmp/fixtures_1717.php` | two-platform, four-case fixture |
| `tmp/test_1717.php` | 34-assertion harness |
| `docs/screenshots/issue-1717-tcnotrunanyplatform-0{1,2}-*.png` | screenshots (linked from the wiki page) |
