# Test Case Editor — Modernized Screen

Modernization of the legacy "test case version editor" screen
(`lib/testcases/tcEdit.php`, `gui/templates/dashio/testcases/tcEdit.tpl`) —
GitHub issue [#812](https://github.com/sebiboga/testlink-upgraded/issues/812).

The legacy Smarty editor is replaced by a standalone Dashio page
(`gui/templates/testcases/tcEdit.html`) backed by a plain-PHP REST BFF API
(`api/testcasesedit/index.php`). The test case viewer toolbar **Edit** button
(`openTcEdit`) now opens the modern screen instead of the legacy `tcEdit.php`
redirect — this was the **last remaining HIGH-priority legacy redirect** from
issue #753 (`tcExport.php`, `tcCompareVersions.php`, `tcAssign2Tplan.php` were
already converted in #803/#809/#810).

**Path:** Test Spec → Test Case Viewer → toolbar **Edit**
**URL:** `gui/templates/testcases/tcEdit.html?tcase_id=..&tcversion_id=..&tproject_id=..`
**BFF API:** `GET /api/testcasesedit/?action=edit` + `POST /api/testcasesedit/?action=update` + `POST /api/testcasesedit/?action=create_version`
**Rights:** `mgt_modify_tc` for edit/update; editing an *executed* version is
gated by `testproject_edit_executed_testcases` (parity with the legacy
controller's edit-permission logic).
**Tracking issue:** [#812](https://github.com/sebiboga/testlink-upgraded/issues/812)

---

## Behavioral parity with the legacy controller

The BFF mirrors `lib/testcases/tcEdit.php` + `testcaseCommands`:

- **Load (`action=edit`)** resolves the test case version through its position
  in the version chain, validates it belongs to the project, and returns its
  full content: name, summary, preconditions, ordered steps (actions +
  expected results), importance, status, execution type, estimated execution
  duration, and the project's keywords (both assigned and all project
  keywords). It also computes `keywords` (assigned) and `all_keywords`.
- **Rights** (`mgt_modify_tc`) are enforced on every route; a user lacking the
  right receives HTTP 403 `No permission`.
- **Executed-version gate**: when the current version has already been
  executed, editing is only permitted for users carrying
  `testproject_edit_executed_testcases`; otherwise the BFF refuses the edit.
- **Save (`action=update`)** persists via `testcase::update()` (name on the
  `nodes_hierarchy` node, summary/preconditions/steps/expected_results on the
  `tcversions` row) plus `$attr` for `status` and `estimated_exec_duration`,
  and syncs the keywords. Empty titles are rejected (HTTP 400
  `Title must not be empty`); bad durations rejected with a client-side guard.
- **Create New Version (`action=create_version`)** clones the current version
  as a new Draft version (`version = max+1`) with steps copied, then returns
  the new version identity so the screen can switch to editing it.

## Screen layout

| Section | Description |
|---------|-------------|
| **Heading** | "Edit Test Case" + test-case identity (`PREFIX-<id>:<name> (#<id> v<version>)`) |
| **Toolbar** | Name/title input, Importance / Status / Execution Type / Estimated duration selects, locale switcher |
| **Description** | Summary textarea |
| **Preconditions** | Preconditions textarea |
| **Steps** | Data-list of (actions, expected results) rows with **Add step** / remove buttons |
| **Keywords** | Assigned-project-keyword pills |
| **Actions** | **Save**, **Cancel**, **Create New Version** |
| **Overlay/toast** | Loading overlay + Bootstrap-toast messages for save/errors/confirms |

---

## Flow

1. On load the page calls `GET /api/testcasesedit/?action=edit` with
   `tcase_id`, `tcversion_id`, `tproject_id`. The BFF resolves and validates the
   version, checks rights, and returns the version content.
2. The screen renders the form with all fields prefilled.
3. **Save** POSTs `?action=update` with the full payload (name, summary,
   preconditions, steps, importance, status, exec type, duration, keyword ids).
   On success a toast shows "Test case saved." and the form reloads the saved
   version.
4. **Add step** appends a new (actions, expected results) row client-side;
   **remove** deletes a row; at least one step may be kept.
5. **Create New Version** confirms, then POSTs `?action=create_version`; on
   success the screen switches to editing the freshly created Draft version.
6. **Cancel** closes the editor.

---

## API surface (`api/testcasesedit/index.php`)

- `GET ?action=edit&tcase_id=..&tcversion_id=..&tproject_id=..` → `200` with the
  version content (`tcase.name`, `summary`, `preconditions`, `steps[]`,
  `importance`, `status`, `exec_type`, `estimated_duration`, `keywords`,
  `all_keywords`, `grants`, `has_been_executed`, identity). `403` when the user
  lacks `mgt_modify_tc`; `404` when context invalid.
- `POST ?action=update` (JSON) → `200 {status:ok}`; `400` on empty title;
  `403` no right.
- `POST ?action=create_version` (JSON) → `200` with new version identity;
  `403` no right.
- Any other action → `404`; no session → `401`.

## i18n

All strings use the `TLi18n` module (`gui/templates/i18n/i18n.js`) with the
`tcedit.*` key prefix, present in all 10 locale bundles
(`en/ro/de/fr/es/it/pt/ja/ru/zh.json`).

## Verification

Executed as regression **Suite 812** — **17/17 PASS**
(`tmp/TLU_Test_Cases.md`), covering load, render, save (fields/name/attrs),
add-step, create-version, empty-title/bad-duration guards, the three rights-deny
paths (edit/update/create_version HTTP 403), unknown-action 404,
unauthenticated 401, bad-context 400, i18n in all bundles, the tcView link
switch, and a clean Event Viewer (0 new Error/Warning rows).
