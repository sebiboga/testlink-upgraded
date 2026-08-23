# Update Linked Test Case Versions (planUpdateTC) — Wiki

The modernized **Update Linked Test Case Versions** screen replaces the legacy
`lib/plan/planUpdateTC.php`. It re-links the test case versions attached to a
test plan to newer (or any user-chosen) active versions, remapping
executions and custom-field execution values exactly like TestLink 1.9.20
(`doUpdate()` / `doBulkUpdateToLatest()` → `testplan::get_linked_and_newest_tcversions()`).

**Path:** Test Plan > Update Linked Test Case Versions (requires an active test plan)
**URL:** `gui/templates/plans/planUpdateTC.html?tproject_id=<id>&tplan_id=<id>`
**BFF API:** `api/plans/index.php` (REST: GET `/update-linked`;
POST `/update-linked/selected`, `/update-linked/latest`)
**Prerequisite:** menu visibility needs `testplan_update_linked_testcase_versions`;
every mutation requires `testplan_planning` (same as legacy `rightsAnd` check).



---
## Table of Contents
1. [Screen Layout](#1-screen-layout)
2. [Row States & Legacy Parity](#2-row-states--legacy-parity)
3. [Three-Table Remap](#2-three-table-remap)
4. [Rights Model](#3-rights-model)
5. [REST API Reference](#4-rest-api-reference)
6. [i18n Keys](#5-i18n-keys)
7. [Testing](#6-testing)

---
## 1. Screen Layout
* Teal Dashio header: title, subtitle, locale switcher.
* Toolbar: test project name, test plan picker, linked-count chip, reload,
  **Update ALL to latest version**, locale switcher.
* Left panel — suite list with updatable counts as badges; clicking a suite
  switches the table.
* Right panel — per-suite table with columns:
  * *tick* checkbox + *Target Version* dropdown (`--` default; every active
    sibling version offered, newest tagged).
  * Test Case (external id + name) with links to **Test Specification**
    (`tcView.html`) and **Execution History** (`execHistory.html`).
  * Linked Version, Newest Active Version, Status badge (*Latest / Updatable /
    linked version inactive*), Executed counter.
* Footer: selected count + hint line.
* Confirm modals for both bulk actions list each old → new pair before applying.

## 2. Row States & Legacy Parity
* Rows flagged *Latest* still expose checkbox + target select whenever another
  active sibling exists — legacy `tideUpForGUI()` allowed re-linking an
  up-to-date case to any other active version (downgrades included). Only rows
  whose linked version is inactive or has no active siblings are read-only.
* The green "already newest" banner shows when nothing is updatable.

## 3. Three-Table Remap
Both mutations re-link using the LIVE link row read from the database and remap,
in one transaction-style sequence, exactly like legacy `doUpdate()`:
1. `testplan_tcversions` — link moved to target tcversion id;
2. `executions` — execution history follows the new version;
3. `cfield_execution_values` — custom field values follow too.
The plan↔project binding is validated server-side on every request (400 when
the supplied `tproject_id` does not match the plan's parent), preventing
cross-project manipulation.



## 4. Rights Model
| Right | Effect |
|---|---|
| `testplan_update_linked_testcase_versions` | ASIDE menu entry visible |
| `testplan_planning` | required by all three BFF routes (403 otherwise) |

No-rights users get a persistent localized error state in the screen and a 403
JSON from the API.

## 5. REST API Reference
| Method & route | Purpose |
|---|---|
| GET `/api/plans/index.php/update-linked?tproject_id=&tplan_id=` | Context payload: project/plan info, suites with updatable counts, per-item linked/newest/target versions + executed qty + state (`ok` / `empty`) |
| POST `/update-linked/selected` `{updates:{tcId:tcversionId}}` | Re-links each listed case to the chosen active version (3-table remap); returns updated count |
| POST `/update-linked/latest` | Bulk move of every non-latest link to its newest active version (legacy `get_linked_and_newest_tcversions()`) |

Errors are JSON with `error_code`: `NO_TPLAN`, `NO_TPROJECT`,
`NO_RIGHTS` (403), plus per-pair validation failures.

## 6. i18n Keys
All UI strings use the client-side `TLi18n` module with `uptc.*` keys present
in **all ten** locale bundles (en, ro, de, es, fr, it, ja, pt, ru, zh),
including `uptc.noPermission`, `uptc.description`, `uptc.feedbackUpdated`
(`{n}` placeholder).

## 7. Testing
Full suite (15 cases) in `tmp/TLU_Test_Cases.md` — Suite 619, 15/15 PASS:
rendering, suite switching, update-selected with non-newest target, executions
remap proof, bulk-to-latest, no-selection guard, cancel path, empty plan,
permission paths (BFF/screen/menu) and cross-project IDOR guard.


