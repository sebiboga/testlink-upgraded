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
| **Format** | Type select, populated dynamically from the BFF `types` map — **XML** and **Markdown**. The default filename extension follows the selection (`.xml` / `.md`) |
| **Options** | Checkboxes: external ID, with project prefix, summary, preconditions, steps, requirements, custom fields, keywords, attachments |
| **Action** | **Export** (downloads the selected format) and **Cancel** |

**Prefix mirroring:** unchecking *Include external ID* disables and unchecks
*With project prefix* (external prefix only applies when external ID is on).

## Markdown format (MD)

The **Markdown** type produces a structured document that round-trips through the
modern MD importer (`api/testcasesimport/?action=import_md`):

```
# <project name>
**Project:** <project name>
**Prefix:** <prefix>

## <suite name> (Suite ID: N)
### TC-<tcase-node-id>: <title>
- **ExternalID:** TLU-190          (prefix + glue + tc_external_id)
- **Importance:** High | Medium | Low
- **Preconditions:** ...           (single bullet line, newlines flattened)
- **Steps:**
     1. Do something
        *Expected:* something happened
- **Steps:**_none_                 (when a TC has no steps)
```

Format rules (kept symmetric with the MD parser):

- **Importance labels** use the TestLink canonical mapping High=3 / Medium=2 / Low=1.
- The exported block always reflects the **latest test case version** (the ExternalID,
  title, preconditions and steps all come from the same latest version).
- `ExternalID` is omitted when the case has no external id.
- `TC-<N>` in the heading is the test case **node id** — used only for display and for
  the `internalID` hit-criteria on re-import; duplicate detection prefers the
  `ExternalID` line.

Re-importing the exported document with `hit_criteria=externalid` +
`action_on_hit=update_last_version` updates each existing test case **in place** by
its ExternalID and never creates duplicates.

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

## Suite-level export launchers (#1325)

Legacy 1.9.20 reached the suite export from `containerView.tpl` (test project
control panel, `btn_export_all_testsuites`) and from the test-suite control
panel (`include/containerViewTestSuiteTextButtons.inc.tpl` —
`btn_export_testsuite` → deep suite export + `btn_export_tc` → children test
cases). Modern parity was completed with two suite-context launch points:

| Launch point | Location | Deep (recursion) | Children (suite_tc) |
|--------------|----------|------------------|---------------------|
| **Test Specification** suite card | `testSpec.html` `showSuiteView()` | **Export Test Suite** button | **Export Test Cases** button |
| **Test Suite Viewer** toolbar | `suiteView.html` | **Export Test Suite** button | **Export Test Cases** button |

Both open `tcExport.html?tproject_id=<project>&containerID=<suite_id>[&useRecursion=1]`
in a popup; the BFF resolves the mode (`testsuite` vs `suite_tc`), default
filename (`<suite>.testsuite-deep.xml` vs `<suite>.testsuite-children-testcases.xml`)
and export options exactly as the legacy screen did. Verified with the `EXP1325`
fixture (`tmp/fixtures_1325.php`, project 1, root suite 2 + sub-suite 3): the
children export streams the 2 direct cases (`EXP Case A/B`, not the sub-suite's
`EXP Case C`), the deep export streams all 3 cases plus the sub-suite, and the
Markdown variant round-trips too. No BFF change was needed — the export API
already handled both suite modes. New i18n keys: `tspec.exportSuite`,
`tspec.exportSuiteCases`, `suvw.exportSuite`, `suvw.exportSuiteCases` (all
locales).

## Backend notes

- The BFF reuses the exact legacy export methods — `testcase::exportTestCaseDataToXML()`
  and `testsuite::exportTestSuiteDataToXML()` — so the generated XML is byte-for-byte
  identical to the legacy screen.
- The MD export path uses the new `markdownTcExport` class
  (`lib/functions/markdown_tc_export.class.php`), streaming a `.md` attachment with
  `Content-Type: text/markdown; charset=utf-8`.
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
| POST | `/api/testcasesexport/?action=export` | stream the file as a download (XML or Markdown, per `exportType`) |

All routes require a valid session; `POST` is protected by the shared same-origin
CSRF guard (`_guard.php`).

## i18n

All labels, titles, placeholders and messages use client-side `TLi18n` keys under
the `tcx.*` namespace (32 keys) present in all 10 locale bundles
(`gui/templates/i18n/{en,ro,de,es,fr,it,ja,pt,ru,zh}.json`). Bundle consistency is
enforced by `tools/lint_i18n.py`.

The **File type** row shows a *view file format documentation* hint link
(`tcx.fileFormatsDoc` → `docs/tl-file-formats.pdf`, same `reqimp.fileFormatsDoc`/
`resimp.fileFormatsDoc` pattern). The type dropdown labels use `tcx.type.xml` and
`tcx.type.md`, which fall back to the backend label when a bundle lacks the key.

---

## SCREEN-COMPARE parity verification (Refs #1323)

Parity run `testcases/tcExport.html` vs legacy `lib/testcases/tcExport.php`
(2026-09-09, tracked as issue [#1323](https://github.com/sebiboga/testlink-upgraded/issues/1323)):

- All four export modes resolve the same default filenames as legacy
  (`<name>.version<N>.testcase.xml`, `<project>.testproject-deep.xml`,
  `<suite>.testsuite-deep.xml`, `<suite>.testsuite-children-testcases.xml`).
- Default checkbox states are identical to legacy (external ID, summary,
  preconditions, steps, requirements and custom fields on; prefix, keywords and
  attachments off). Prefix mirroring differs only cosmetically (legacy
  `mirrorCheckbox` re-checks the prefix when re-enabling external ID; modern
  re-enables it unchecked).
- **XML output is byte-for-byte identical** to legacy for the single test-case
  (835 B) and the project-deep export (1061 B) — both call the same legacy
  exporter methods.
- Markdown export works in every mode (legacy recursion was XML-only because the
  `testsuite` class exposes a single export type — modern superset from #853).
- Empty-suite/project state shows the localized warn box (`tcx.noTestcasesToExport`
  / `tcx.noTestsuitesToExport`) while legacy hides the form entirely (superset).
- Permission parity: any authenticated session — same as legacy.
- **Gaps found and fixed in-run:** file-format documentation link restored +
  `tcx.type.*` keys added to the 8 bundles that had silently fallen back to the
  backend label.
- **Open gaps:** suite-level export has no modern launcher — legacy
  `containerView.tpl` targeted `tcExport.php?containerID=<suite>`; modern reaches
  only the testcase mode (tcView toolbar) and the project-deep mode
  (projectInfoView *Export all test suites*). See #1325. Skeleton export has no
  modern UI button (tl-classic `tcExport.tpl:130` has an `exportSkel` submit
  button, the Dashio form had none; the BFF `export` already honors the
  `exportSkel` param and produces byte-identical output to legacy). See #1326.
- Cleanup: #1324 (delete legacy `tcExport.php` + tcExport.tpl templates once the
  reachability gap is resolved).

---

_TestLink 2.0.1 · Test Case / Suite / Project Export · Refs #803 · MD round-trip #842 / #852 / #853 · SCREEN-COMPARE #1323 (gaps #1324 #1325)_
