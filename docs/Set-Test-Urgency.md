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
  build selector, platform selector (only when the plan links platforms),
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
| `/urgency?tproject_id=&tplan_id=[&build_id=][&platform_id=]` | GET | context: accessible plans selector, active plan, suites w/ TC counts + urgency summary, plan builds + default/current build, plan platforms + default/current platform |
| `/urgency/suite?tplan_id=&tsuite_id=[&platform_id=][&build_id=]` | GET | test cases under one suite: ids, names, importance, urgency, computed priority, assigned testers (for the selected build) |
| `/urgency/suite` | POST | `{tplan_id, tsuite_id, urgency}` → `setSuiteUrgency()` |
| `/urgency/tcases` | POST | `{tplan_id, items:{tcversion_id:urgency}}` → `setTestUrgency()` loop |

## 4b. Build Selector (Assigned To column) — Issue #1140

The Assigned To column is resolved from `user_assignments` for a particular
build. Legacy (`lib/plan/planUrgency.php:114` +
`tlTestCaseFilterControl::init_setting_build`) used the navigator's
`setting_build` session value; the port initially hard-coded the
alphabetically-first active build. The toolbar now has a **Build** selector
(`#buildSel`):

* all builds of the plan are listed (inactive/closed included, closed ones
  labelled `(closed)` — legacy `plan_mode` keeps them in the list);
* choosing a build re-fetches the current suite with `&build_id=` and the
  column reflects that build's assignments; the choice is persisted in the URL
  (`build_id`);
* server default order: navigator-stored `<tplan_id>_stored_setting_build`
  session value → alphabetically-first active+open build (pre-#1140
  behaviour) → first build; an invalid `build_id` falls back to the default;
* a plan with no builds shows the `-` placeholder and an empty Assigned To
  column.

## 4c. Platform Selector (urgency grid scope) — Issue #1141

The legacy urgency grid was scoped by the navigator's platform setting
(`lib/plan/planUrgency.php:46,112-115` → `$context->platform_id`;
`lib/functions/testPlanUrgency.class.php:163-166` appends
`AND TPTCV.platform_id=<n>` when set). The port merged every platform of a
test case version into a single row with no way to isolate one. The toolbar now
has a **Platform** selector (`#platformSel`):

* options are the plan's linked platforms (`testplan::getPlatforms()`) plus an
  **All platforms** entry (`0`);
* choosing a platform re-fetches the current suite with `&platform_id=` and the
  grid shows only that platform's urgency/assignment rows (e.g. a version linked
  to Linux at urgency 3 and Windows at 1 shows `High` for *All/Linux* and `Low`
  for *Windows*); the choice is persisted in the URL (`platform_id`);
* server default order: navigator-stored `<tplan_id>_stored_setting_platform`
  session value → `0` (all platforms); an explicit `platform_id=0` selects all,
  an unlinked positive id falls back to the default;
* a plan that links no platforms hides the selector (`#platformCtx`), matching
  legacy `init_setting_platform()` when `getLinkedToTestplanAsMap()` is empty;
* like legacy, the **write** paths (`setSuiteUrgency()` / `setTestUrgency()`)
  are NOT platform-scoped — saving a per-TC urgency updates that tcversion's
  rows on every platform. Only the read/display path is filtered.

## 5. i18n Keys

All labels live under the `turg.*` namespace in **all ten locale bundles**
(en, ro, de, es, fr, it, ja, pt, ru, zh): header, column headers,
urgency/importance/priority level labels, feedback messages, description
text, empty states, and the Build / Platform selector labels
(`turg.build`, `turg.buildTitle`, `turg.platform`, `turg.platformAll`,
`turg.platformTitle`).

![Romanian locale](images/testurgency-ro.png)

## 6. Legacy Divergences

* The ExtJS execution-tree navigation is replaced by the suites pane; the
  tree-session build/platform filters are gone. Dedicated **Build** (Issue
  #1140) and **Platform** (Issue #1141) selectors now reproduce the legacy
  `setting_build` (Assigned To column) and `setting_platform` (urgency grid
  scope) semantics that lived in the tree session.
* Exec-history / design popups became links to the modern standalone screens.
* Per-platform rows are merged into one row per test case version when *All
  platforms* is selected (the write path always updated all platform rows of a
  tcversion anyway, exactly like legacy).

## 7. Testing

Suite 58 in the repository test-case document — 12/12 PASS including
permission paths (401/403), deep-link recovery, locale switch, DB-level
verification of both write paths and an Event Viewer check (zero new
Error/Warning entries). Tracking issue: #605.

## 8. Bug Fixes

### Issue #1141 (2026-10-16) — Platform filter
- **Gap:** legacy scoped the urgency grid to the navigator's platform setting
  (`setting_platform`); the modern screen had no platform control and merged all
  platforms into one row, so urgency/assignment could not be isolated per
  platform.
- **Fix:** `GET /urgency` now returns `platforms`, `default_platform_id` and
  `platform_id` (new `urgencyPlatforms()` helper, `api/plans/index.php`, honours
  the legacy `<tplan_id>_stored_setting_platform` session key); the toolbar got a
  **Platform** selector (`gui/templates/plans/testUrgency.html`) that passes
  `platform_id` to `GET /urgency/suite`, persists it in the URL and resets on
  plan change; the selector hides for plans with no linked platforms. The BFF
  `platform_id` filter on `GET /urgency/suite` already existed.
- **Testing:** fixture `tmp/fixtures_1141.sql` (plan 2010, Linux/Windows, PLT-1
  urgency 3 vs 1). Verified in-browser: All/Linux → `High (6)`, Windows →
  `Low (2)`; write path stays global (both platform rows updated), matching
  legacy. Added test suite `Task — Issue #1141` to `tmp/TLU_Test_Cases.md`.

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
