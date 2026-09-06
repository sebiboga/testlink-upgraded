# Assign Keywords to Test Cases (keywordsAssign) — Wiki
The modernized **Assign Keywords to Test Cases** screen replaces the legacy
`lib/keywords/keywordsAssign.php` option-transfer page. It attaches or detaches
keywords on the **latest active version** of a single test case (replace
semantics, exactly like 1.9.20 `setKeywords`) or applies add / remove /
remove-all operations to every test case inside a test suite subtree.
**Path:** Test Specification > Assign Keywords
**URL:** `gui/templates/keywords/keywordsAssign.html?tproject_id=<id>`
**BFF API:** `api/keywords/assign.php` (REST: `/context`, `/suites`, `/tcases`,
`/keywords`, POST `/case`, POST `/suite`)
**Prerequisite:** `keyword_assignment` right on the test project
---
## Table of Contents
1. [Screen Layout](#1-screen-layout)
2. [Test Case Mode](#2-test-case-mode)
3. [Test Suite Mode](#3-test-suite-mode)
4. [Executed-Version Guard](#4-executed-version-guard)
5. [Rights Model](#5-rights-model)
6. [REST API Reference](#6-rest-api-reference)
7. [i18n Keys](#7-i18n-keys)
8. [Testing](#8-testing)
---
## 1. Screen Layout
* Teal Dashio header with title + subtitle and the locale switcher.
* Dark toolbar with the project context, the **Target level** selector
  (Test Case / Test Suite) and the action buttons for the active level.
* Picker row: Test Suite dropdown (+ scope radio in suite mode) or Test Case
  cascading dropdown; subtitle mirrors the legacy
  `keyword_assignment_subtitle` (`Test Case :: <name>`).
* Dual listbox (option transfer): *Available Keywords* on the left,
  *Assigned/Selected Keywords* on the right with `> >> << <` buttons.
## 2. Test Case Mode
1. Pick a suite, then a case — cases are listed deep (nested suites included).
2. The right box loads the keywords assigned to the **latest active version**
   (legacy `get_keywords_map` + `ORDER BY keyword`).
3. Move keywords and press **Save**: the full right-box set replaces the
   assignment (`testcase::setKeywords`, same contract as legacy).
4. A badge marks executed versions; Save is disabled when blocked (see §4).
## 3. Test Suite Mode
Choose a suite and one scope:
* **entire subtree** — legacy `get_testcases_deep`
* **only direct children** — legacy `get_children_testcases`
The right box is the operation payload (legacy option-transfer semantics):
| Button | Effect |
|--------|--------|
| Add to Test Cases | `addKeywords(right box)` on every case in scope |
| Remove from Test Cases | `deleteKeywords(right box)` on every case in scope |
| Remove All | clears every keyword from every case in scope, ignores the right box |
Cases whose latest active version has been executed are skipped unless your
role allows editing them; the toast reports how many were skipped.
## 4. Executed-Version Guard
Same rule as 1.9.20: when the latest active version has execution rows, keyword
changes are blocked unless the user holds
`testproject_add_remove_keywords_executed_tcversions` **or**
`testproject_edit_executed_testcases`. Blocked state shows an *executed*
badge plus an explanatory note and disables Save.
## 5. Rights Model
* Screen access / all write endpoints require `keyword_assignment`; without it
  the screen renders an explanatory note and hides pickers, boxes and buttons.
* Executed-version editing requires one of the two rights above (admins have it).
## 6. REST API Reference
| Route | Method | Purpose |
|-------|--------|---------|
| `/api/keywords/assign.php/context` | GET | project info + `canAssign` + `canEditExecuted` |
| `/api/keywords/assign.php/suites` | GET | flat dotted-path suite list |
| `/api/keywords/assign.php/tcases?tsuite_id&scope=deep\|direct` | GET | case id/name list |
| `/api/keywords/assign.php/keywords?tcase_id=` | GET | available + assigned maps + `hasBeenExecuted` |
| `/api/keywords/assign.php/case` | POST `{tcase_id, keywords[]}` | replace set (executed-guard enforced) |
| `/api/keywords/assign.php/suite` | POST `{tsuite_id, scope, mode:add\|remove\|removeall, keywords[]}` | bulk op with executed skip counters |
All routes are session-authenticated and return JSON
(`{status:"ok"|...}`); insufficient rights yield HTTP 403.
## 7. i18n Keys
29 keys under `kwa.*` exist in **all ten locale bundles**
(`en de es fr it ja pt ro ru zh`). No hardcoded strings remain on screen;
toasts use parameterized messages (`%d` counts).
## 8. Testing
Suite 43 (Suite ID: 50) in `TLU_Test_Cases.md` covers both levels, replace vs
bulk semantics, deep/direct scope, the executed guard for a restricted role,
the no-rights path and i18n validity across bundles.

SCREEN-COMPARE regression suite **KWA-1105** (18 TCs, `TLU_Test_Cases.md`,
Refs #1105) re-verifies the parity pass on seeded fixtures (project 1 "KWA Demo
Project", users `kwanr` no-rights / `kwadesign` restricted, plan+build+execution
on Case A2): deep case listing, replace-save, executed badge + blocked Save
(POST /case → `{status:"blocked", reason:"executed"}`), suite bulk add/remove/
remove-all with executed skip counters, no-rights 403 + UI note, all 400/404
error contracts, empty-keyword project card, Română locale switch, Event Viewer
cleanliness, `php -l` + console. Gaps found: **#1106** `useFilteredSet`
assign-to-filtered-set dropped · **#1107** legacy `id`/`edit` deep-link params
dropped · **#1108** cleanup (delete legacy keywordsAssign).
