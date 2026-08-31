# Test Case / Suite / Project Export — Modernized Screen

Modernization of the legacy export screen (`lib/testcases/tcExport.php`,
`gui/templates/dashio/testcases/tcExport.tpl`) — GitHub issue
[#803](https://github.com/sebiboga/testlink-upgraded/issues/803).

The legacy Smarty screen is replaced by a standalone Dashio page
(`gui/templates/testcases/tcExport.html`) backed by a plain-PHP REST BFF API
(`api/testcasesexport/index.php`). The Test Case Viewer toolbar **Export** button
now opens the modern screen instead of submitting the legacy form.

**Path:** Test Spec → Test Case Viewer → toolbar **Export**
**URL:** `gui/templates/testcases/tcExport.html?tproject_id=..&testcase_id=..&tcversion_id=..`
**BFF API:** `GET /api/testcasesexport/?action=info` + `POST /api/testcasesexport/?action=export`
**Rights:** any authenticated session (parity with legacy export, which is read-only).
**Tracking issue:** [#803](https://github.com/sebiboga/testlink-upgraded/issues/803)

---

## Export modes

The screen mirrors the three export contexts of the legacy `tcExport.php`:

| Mode | Trigger | Filename pattern |
|------|---------|------------------|
| **Test case** | `testcase_id` + `tcversion_id` set | `<name>.version<v>.testcase.xml` |
| **Test suite (children)** | `containerID` set, no recursion | `<suite>.testsuite-children-testcases.xml` |
| **Test suite (deep)** | `containerID` set + `useRecursion=1` | `<suite>.testsuite-deep.xml` |
| **Test project (deep)** | `useRecursion=1`, root container | `<project>.testproject-deep.xml` |

The mode/title/default filename are resolved by the BFF `info` route using the
same logic as the legacy `initializeGui()` and `init_args()`.

## Screen layout

| Section | Description |
|---------|-------------|
| **Heading** | Export title + breadcrumb context (test project name) |
| **File name** | Text input, pre-filled with the default filename; editable |
| **Format** | Type select (XML — the only legacy export format) |
| **Options** | Checkboxes: external ID, with project prefix, summary, preconditions, steps, requirements, custom fields, keywords, attachments |
| **Action** | **Export** (downloads XML) and **Cancel** |

**Prefix mirroring:** unchecking *Include external ID* disables and unchecks
*With project prefix* (external prefix only applies when external ID is on).

## Export flow

1. On load the page calls `GET /api/testcasesexport/?action=info` to resolve the
   context (mode, project name, default filename, supported types, grants).
2. The user edits options and clicks **Export**.
3. The page POSTs `action=export` with the option flags and reads the response
   as a **blob** (`XMLHttpRequest` `responseType='blob'`), then triggers a
   browser download via an object URL.
4. Any JSON error payload (unauthenticated, nothing to export, invalid input) is
   detected from the blob content-type and shown as a localized toast.

## Error & edge states

| Case | Result |
|------|--------|
| Empty file name | local toast `tcx.errEmptyFilename`, no request sent |
| Nothing to export (empty suite) | warn box `tcx.noTestcasesToExport` shown, Export still active |
| Project with no suites | warn box `tcx.noTestsuitesToExport` |
| Not authenticated | HTTP 401 `{"status":"error","message":"Not authenticated"}` |
| Nothing generated (0 bytes) | HTTP 400 "Nothing to export for the given selection" |

## Backend notes

- The BFF reuses the exact legacy export methods — `testcase::exportTestCaseDataToXML()`
  and `testsuite::exportTestSuiteDataToXML()` — so the generated XML is byte-for-byte
  identical to the legacy screen.
- `nothingTodo` is computed with a `countExportChildren()` helper that mirrors the
  legacy `$check_children` logic (excludes testplan/requirement/build/platform nodes).
- Two pre-existing legacy `E_WARNING`s in `testsuite.class.php::exportTestSuiteDataToXML()`
  (`$$tsuiteData` typo leaving `$tsuiteData` undefined at the project root, and an
  uninitialized `$attachXML`) were fixed (`lib/functions/testsuite.class.php`) so the
  recursive export no longer pollutes the Event Viewer.

## API endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/testcasesexport/?action=info` | export context: mode, names, default filename, types, grants |
| POST | `/api/testcasesexport/?action=export` | stream the XML as a file download |

All routes require a valid session; `POST` is protected by the shared same-origin
CSRF guard (`_guard.php`).

## i18n

All labels, titles, placeholders and messages use client-side `TLi18n` keys under
the `tcx.*` namespace (29 keys) present in all 10 locale bundles
(`gui/templates/i18n/{en,ro,de,es,fr,it,ja,pt,ru,zh}.json`). Bundle consistency is
enforced by `tools/lint_i18n.py`.

---

_TestLink 2.0.1 · Test Case / Suite / Project Export · Refs #803_
