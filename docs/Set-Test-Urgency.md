# Set Test Urgency (testUrgency) — Wiki
The modernized **Set Test Urgency** screen replaces the legacy
`lib/plan/planUrgency.php` + `planUrgency.tpl` page (launched through the
`test_urgency` frmWorkArea feature from the execution tree). It assigns
urgency to the test cases linked to a test plan: per test suite with one click
or per test case version with radio buttons, computing the same execution
priority as TestLink 1.9.20 (`priority = importance × urgency`, thresholds
from `urgencyImportance` config via `priority_to_level()`).

**Path:** Test Plan Management > Set Test Urgency (visible when a test plan is active in context)
**URL:** `gui/templates/plans/testUrgency.html?tproject_id=<id>&tplan_id=<id>`
**BFF API:** `api/plans/index.php` (routes `/urgency`, `/urgency/suite`, `/urgency/tcases`)
**Prerequisite:** `testplan_planning` right enforced server-side on every route;
menu visibility gated by `testplan_set_urgent_testcases` (legacy parity).

![Set Test Urgency screen](images/testurgency-view.png)

---
## Table of Contents
1. [Screen Layout](#1-screen-layout)
2. [Urgency Model](#2-urgency-model)
3. [Rights Model](#3-rights-model)
4. [REST API Reference](#4-rest-api-reference)
5. [i18n Keys](#5-i18n-keys)
6. [Legacy Divergences](#6-legacy-divergences)
7. [Testing](#7-testing)

---
## 1. Screen Layout

* **Toolbar** — test project name, test plan selector (accessible plans),
  reload button; bare deep-links recover the first accessible project.
* **Suites pane (left)** — every suite of the plan holding DIRECTLY linked
  test cases, with TC count and a colored urgency badge (`High` / `Medium` /
  `Low` / `mixed`). Click a suite to load its table.
* **Suite urgency buttons** — `High` / `Medium` / `Low` apply the chosen
  urgency to all test case versions directly under the selected suite
  (`setSuiteUrgency()` semantics) and refresh both badges and table.
* **Test case table** — external id + name, assigned testers, importance,
  High/Medium/Low radios preselected with the current urgency and a colored
  priority tag showing the level and numeric value (e.g. `High (9)`).
* **Row links** — pen icon opens the modern Test Case viewer
  (`tcView.html`), clock icon opens Execution History (`execHistory.html`)
  in new tabs (replaces the legacy popups).
* **Save button** — “Set Urgency for Test Cases” posts every radio value
  (legacy form parity); an unsaved-changes hint appears on edits.

![Suite switching and priority tags](images/testurgency-suite-switch.png)

## 2. Urgency Model

| Value | Constant | Meaning |
|---|---|---|
| 1 | LOW | low urgency |
| 2 | MEDIUM | medium urgency |
| 3 | HIGH | high urgency |

Priority = `importance × urgency`; levels via legacy thresholds
(`>= 6` HIGH, `< 3` LOW, otherwise MEDIUM). Suite badges show `mixed`
whenever the linked test case versions of that suite carry different
urgencies.

## 3. Rights Model

Legacy `planUrgency.php` used `rightsAnd = ["testplan_planning"]` for the
page and all mutations — the BFF enforces exactly that on every route
(401 unauthenticated, 403 without the right). The aside menu entry keeps the
legacy `testplan_set_urgent_testcases` grant gate.

## 4. REST API Reference

All routes under `/api/plans/index.php`, session auth + CSRF guard:

| Route | Method | Purpose |
|---|---|---|
| `/urgency?tproject_id=&tplan_id=` | GET | context: accessible plans selector, active plan, suites w/ TC counts + urgency summary |
| `/urgency/suite?tplan_id=&tsuite_id=[&platform_id=]` | GET | test cases under one suite: ids, names, importance, urgency, computed priority, assigned testers |
| `/urgency/suite` | POST | `{tplan_id, tsuite_id, urgency}` → `setSuiteUrgency()` |
| `/urgency/tcases` | POST | `{tplan_id, items:{tcversion_id:urgency}}` → `setTestUrgency()` loop |

## 5. i18n Keys

All labels live under the `turg.*` namespace in **all ten locale bundles**
(en, ro, de, es, fr, it, ja, pt, ru, zh): header, column headers,
urgency/importance/priority level labels, feedback messages, description
text, empty states.

![Romanian locale](images/testurgency-ro.png)

## 6. Legacy Divergences

* The ExtJS execution-tree navigation is replaced by the suites pane; the
  tree-session build/platform filters are gone. Tester assignments are now
  resolved against the latest **active** build of the plan (legacy resolved
  them against the build chosen in the tree session).
* Exec-history / design popups became links to the modern standalone screens.
* Per-platform rows are merged into one row per test case version (the write
  path always updated all platform rows of a tcversion anyway).

## 7. Testing

Suite 58 in the repository test-case document — 12/12 PASS including
permission paths (401/403), deep-link recovery, locale switch, DB-level
verification of both write paths and an Event Viewer check (zero new
Error/Warning entries). Tracking issue: #605.

## 8. Bug Fixes

### Issue #1884 (2026-10-09)
- **Symptom:** Accessing `lib/plan/planUrgency.php` with missing/zero/invalid `tplan_id` answered HTTP 200 but wrote 5-7 E_WARNING rows to events (undefined keys, null array access in `init_args`, `initializeGui`/foreach path, and missing `pageTitle` in template context).
- **Root cause:** Unchecked array/session accesses (`$_SESSION['testplanName'/'testprojectID'/'testplanID']`), unchecked results from `get_node_hierarchy_info()` and `getSuiteUrgency()`, unsafe access to `$session_data` keys, and `pageTitle` not set on `$gui`.
- **Fix:** Added defensive guards in `lib/plan/planUrgency.php`:
  - `init_args()`: guard session fallback values with `isset()`; guard `$session_data` access for `testcases_to_show`, `setting_build`, `setting_platform`.
  - `initializeGui()`: guard `$ni = $treeMgr->get_node_hierarchy_info(...)` result; set `$guiObj->pageTitle = lang_get('plan_urgency')` with optional plan name suffix.
  - Main flow: treat falsy `getSuiteUrgency()` result as empty array before `foreach`.
- **Verification:** `curl -s -b <cookies> -L "http://localhost:8082/lib/plan/planUrgency.php?tplan_id=0"` produces 0 new E_WARNING events (confirmed by DB query of `events` table). Same for missing/empty/non-numeric params.
- **Regression test:** Added "Regression — Issue #1884" suite to `tmp/TLU_Test_Cases.md`; `TLU_REQUIRE_SUITE="Issue #1884" bash ai/verify_test_suites.sh` → PASS.
- **Files changed:** `lib/plan/planUrgency.php` (+14/-7)
