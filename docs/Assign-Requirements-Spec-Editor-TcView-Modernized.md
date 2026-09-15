# Assign Requirements — Test Specification Editor + TC Viewer — Modernized

**Path:** ASIDE → Test Specification → *Test Specification* (per-test-case
"Assign Requirements" button + modal) and **Test Case Viewer** toolbar button.
**URLs:** `gui/templates/testcases/testSpec.html?tproject_id=<id>[&tcase_id=<id>]`
and `gui/templates/testcases/tcView.html?tcase_id=<id>`
**BFF API:** `api/requirements/index.php` — `GET /assign-reqspecs?tproject_id=N`
(req spec combo), `GET /assign-reqs?req_spec_id=N&tcase_id=X` (free/assigned
split), `POST /assign-reqs {tcase_id, req_ids[]}` (legacy
`requirement_mgr::assign_to_tcase()`), `POST /unassign-reqs {link_ids[]}`
(legacy `delReqVersionTCVersionLinkByID()`). Session auth, JSON I/O.
**Replaces:** legacy per-test-case requirement assignment reached from
`tcEdit.php` / `tcView.php` (the old `reqTcAssign.php?edit=testcase` popup and
the "Assign directly" workflow) for the two modern screens — the standalone
`assignReqs.html` bulk screen remains for the Requirements ASIDE section.
**Refs:** #912.

---

## What it does

Restores the per-test-case *Assign Requirements* workflow that was missing from
the modernized Test Specification editor and Test Case Viewer.

* **Test Specification (`testSpec.html`)** — when a test case is selected, an
  **Assign Requirements** button is rendered in the action bar (below Delete
  Test Case). Clicking it opens a modal:
  * **Requirement Specification** combo, pre-populated via
    `GET /assign-reqspecs` (dotted `[RS-912] - name` naming, legacy
    `genComboReqSpec()` parity).
  * **Free Requirements** vs **Assigned Requirements** two-list layout, fed by
    `GET /assign-reqs` for the selected spec + current test case.
  * `>> Assign` links each selected free requirement's **latest version** to
    the test case's **latest version** (`assign_to_tcase()`); `<< Unassign`
    deletes coverage links by link id.
  * Live counts per column; empty-state notices ("No requirements assigned
    yet.", "No requirements found on this specification."); toast feedback on
    assign/unassign/failure; no req specs → notice + empty dropdown.
* **Test Case Viewer (`tcView.html`)** — toolbar **Assign Requirements** button
  (visible only on the **latest** version). Same modal + same BFF endpoints.
  After every assign/unassign the main *Requirements* section re-fetches the
  view payload (`action=view`) and re-renders **without a page reload**, so the
  displayed coverage list always matches the modal state.

## Visibility gating (rights + options)

| Condition | Behaviour |
|-----------|-----------|
| project option `requirementsEnabled` off | button hidden on both screens |
| missing grant `req_tcase_link_management` | button hidden on both screens |
| tcView on a non-latest version | button hidden (legacy links only the latest TC version) |
| all satisfied | button shown; BFF also enforces the grant (403) |

The project options flags are read through `api/testcases/index.php`
`tprojectOpt()` helper which tolerates **both** the object and array shapes
returned by `testproject::getOptions()` (options blobs may decode to either;
previously array blobs made `requirementsEnabled` silently false).

## i18n

New keys in all 10 locale bundles (`en/ro/de/es/fr/it/ja/pt/ru/zh`):
`tspec.assignRequirements`, `tcview.assignRequirements`. The modal reuses the
existing `reqAssign.*` keys (`reqSpec`, `noReqSpecs`, `unassigned`, `assigned`,
`assignBtn`, `unassignBtn`, `noReqsOnSpec`, `emptyAssigned`, `versionShort`,
`selectOneReq`, `selectOneLink`, `assignDone`, `unassignDone`,
`assignFailed`, `unassignFailed`, `loadError`) plus `tspec.cancel` / `rpt.close`
for the Cancel/Close footer buttons.

## Permissions

| State | Behaviour |
|-------|-----------|
| user has `req_tcase_link_management` on the project | full access |
| missing right | button hidden; BFF returns 403 on assign/unassign routes |

## Regressions guarded

* list **updates live** in tcView after assign/unassign (no stale REQUIREMENTS
  section);
* **empty assigned list** notices restore correctly after removing the last
  link;
* button visibility respects requirementsEnabled + grant + latest version;
* assignment targets the latest versions on both sides (legacy semantics,
  `assign_to_tcase()` handles inactive-latest rejection).

## Test Cases

See suite **#912** in `tmp/TLU_Test_Cases.md` (10 cases, PASS).