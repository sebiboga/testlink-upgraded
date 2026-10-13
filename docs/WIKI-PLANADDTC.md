# Add / Remove Test Cases to Test Plan (planAddTC) — Wiki
The modernized **Add / Remove Test Cases** screen replaces the legacy
`lib/plan/planAddTC.php` tree-based linker. It links test case versions into a
test plan (optionally per platform), unlinks them, sets execution order and can
assign a tester + build for freshly added cases — all with the same server-side
semantics as TestLink 1.9.20 (`testplan::link_tcversions()`,
`unlink_tcversions()`, `setExecutionOrder()`).

**Path:** Test Plan > Add / Remove Test Cases (requires an active test plan)
**URL:** `gui/templates/plans/planAddTCView.html?tproject_id=<id>&tplan_id=<id>`
**BFF API:** `api/plans/index.php` (REST: GET `/planaddtc/init`, `/planaddtc/suites`,
`/planaddtc/container`; POST `/planaddtc/save`, `/planaddtc/reorder`)
**Prerequisite:** `testplan_planning` right on the test project; removing links
that already have executions additionally requires `testplan_unlink_executed_testcases`.

![Screen](images/planaddtc-view.png)

---
## Table of Contents
1. [Screen Layout](#1-screen-layout)
2. [Linking Model](#2-linking-model)
3. [Executed-Link Guard](#3-executed-link-guard)
4. [Rights Model](#4-rights-model)
5. [REST API Reference](#5-rest-api-reference)
6. [i18n Keys](#6-i18n-keys)
7. [Testing](#7-testing)

---
## 1. Screen Layout
* Teal Dashio header: title, subtitle, locale switcher.
* Toolbar: test project picker, test plan picker (re-inits the whole screen),
  keyword filter, Refresh button.
* Left panel — suite tree with **linked/total** deep badges per suite.
* Right panel — per-suite table:
  * *ADD*: checkbox; version dropdown (active versions only, newest active
    preselected; inactive versions disabled); platform multi-select when the
    plan has platforms (nothing preselected, legacy default); execution order input.
  * Linked rows show a remove chip `v<n> #<order>` (red dot when executed) and
    the add controls collapse into the linked state.
  * Linked rows additionally render a full-width **custom-fields row** per link
    when the project has custom fields with `show_on_testplan_design`/
    `enable_on_testplan_design` (scope `testplan_design`): tag
    *"Custom fields for v<n> · <platform>"* followed by the legacy CF inputs
    (`custom_field_<type>_<cfid>_<link_id>` names, pre-filled with the values
    stored in `cfield_testplan_design_values`).
* Footer: **Save changes** (links) / **Save execution order** (reorder) /
  **Save custom fields** (CF values — visible only when at least one linked TC
  in the suite has testplan-design CFs and the user holds `testplan_planning`).
* Tester assignment block appears when `exec_assign_testcases` right is present
  and the plan has active builds: tester + build dropdowns applied to newly
  created features.

## 2. Linking Model
Payloads mirror the legacy structures exactly:
`{items:{tcId:{platId:versionId}}, tcversion:{tcId:versionId}, platform:{platId:platId}}`.
* Platform-less plans link version id under platform key `0`.
* Already-linked pairs are silently deduplicated server-side (legacy
  `getFilteredLinkedVersions` behavior).
* Reorder sends `{order:{tcversion_id: node_order}}` → `setExecutionOrder()`.

![Custom fields + save action](screenshots/1126-planaddtc-custom-fields.png)

## 2a. Testplan-design custom fields (Issue #1126)
Legacy parity with `planAddTC.php doSaveCustomFields()` (lib/plan/planAddTC.php
:715-778): the BFF renders the ready-to-write inputs server-side with the exact
legacy helper (`testcase::html_table_of_custom_field_inputs` scope
`testplan_design`), so every CF type (string, combo, multi-select, checkbox,
radio, date, datetime) keeps its native input/name/value semantics. The POST
endpoint groups the submitted `custom_field_*_*_<link_id>` names by link_id
(≈ `testplan_tcversions.id`, `explode('_')` position 4, same split as legacy),
rejects links not owned by the plan, and persists each subset through
`cfield_mgr::testplan_design_values_to_db($cfvalues, null, $link_id)`.

## 3. Executed-Link Guard
When removing links whose `executed_qty > 0` and the user lacks
`testplan_unlink_executed_testcases`, the request is rejected (403). Users that
have the right see a confirmation modal listing affected test cases; confirming
removes links **and cascades execution deletion** (same as 1.9.20).

## 4. Rights Model
| Right | Effect |
|-------|--------|
| `testplan_planning` | required on every endpoint |
| `testplan_unlink_executed_testcases` | remove executed links |
| `exec_assign_testcases` | enables tester/build assignment |

Hardening (code review round): `/planaddtc/init` validates tplan ownership,
`suites` rejects foreign recursion roots, `save` verifies every test case lives
inside the target project (parent-walk to project root), CSRF same-origin proof
required on POSTs.

## 5. REST API Reference
| Method & Route | Purpose |
|----------------|---------|
| GET `/api/plans/index.php/planaddtc/init?tproject_id=&tplan_id=` | plans, rights, keywords, platforms, builds, testers |
| GET `/planaddtc/suites?tproject_id=&tplan_id=[&keyword_id=][&container_id=]` | suite tree w/ deep totals + linked counts |
| GET `/planaddtc/container?container_id=` | test cases of one suite: versions, links, executed_qty, per-link `custom_fields_html`, `has_custom_fields` |
| POST `/planaddtc/save` | link/unlink pairs + optional tester/build assignment |
| POST `/planaddtc/reorder` | save execution orders |
| POST `/planaddtc/savecustomfields` | persist testplan-design CF values (grouped by link_id, owned-link check, `testplan_design_values_to_db`) |

Errors are JSON `{status:"error",message}` with proper HTTP codes
(400 invalid input, 401 no session, 403 rights/ownership, 404 not found,
422 NO_SELECTION / INACTIVE_VERSION).

## 6. i18n Keys
36 `paddtc.*` keys in all 10 bundles (`en, ro, de, es, fr, it, ja, pt, ru, zh`),
plus reuse of `pv.testProject`, `common.*`. The five testplan-design-CF keys
(`paddtc.saveCustomFields`, `paddtc.cfFor`, `paddtc.cfHelp`,
`paddtc.cfNothingSaved`, `paddtc.msg.cfSaved`) were added for Issue #1126.

## 7. Testing
Regression suites in `tmp/TLU_Test_Cases.md`: **56** (original load/rights/i18n/
navigation/add-reorder-remove/executed-guard/keyword-filter) and **Task — Issue
#1126** (9/9 PASS): CF row rendering, `custom_field_*_<link_id>` naming, save
round-trip persistence, Event Viewer clean, i18n coverage gate.
