# Execution Export — Modernized Screen

Modernization of the legacy export screen (`lib/execute/execExport.php`,
`gui/templates/dashio/execute/execExport.tpl`) — GitHub issue
[#1024](https://github.com/sebiboga/testlink-upgraded/issues/1024).

The legacy Smarty popup is replaced by a standalone Dashio page
(`gui/templates/execute/execExport.html`) backed by a plain-PHP REST BFF API
(`api/executeexport/index.php`). The Export button on the bulk-execution window
(`execTest.html` toolbar) now opens `openExport()`, which passes the current
visible test-case set (`tcversionSet`) plus the testproject / testplan / build /
platform context to the modern screen.

**Path:** Execute Tests → toolbar **Export** button (BUGID 3421: "Export All
Test Case in TEST SUITE")
**URL:** `gui/templates/execute/execExport.html?tproject_id=<id>&tplan_id=<id>&build_id=<id>&platform_id=<id>&tsuite_id=<id>&tcversionSet=<csv>`
**BFF API:** `GET /api/executeexport/?action=info` + `POST /api/executeexport/?action=export`
**Rights:** `testplan_execute` OR `exec_ro_access` on the owning test project (verified 403 for guest users)
**Tracking issue:** [#1024](https://github.com/sebiboga/testlink-upgraded/issues/1024)

---

## Screen layout

| Element | Description |
|---------|-------------|
| **Heading** | Teal banner "Export Test Case Set" + context label `- <tplan>`, locale switcher |
| **Context bar** | Test plan name, "Test cases in set" count, Back/Cancel button |
| **Context grid card** | Read-only cells: Test project / Test plan (+ Build, and Platform only when one is set in the context) |
| **Export file name** | Text input prefilled `export_execution_set.xml`; client-side empty check (toast `Export name can not be empty!`) |
| **File type** | Select with `XML` only, plus "view file formats documentation" link (`/docs/tl-file-formats.pdf`) |
| **Actions** | Export (teal) + Cancel (dark); Enter in the filename triggers export; export runs a fetch → Blob download |
| **Footer** | `execx.footer` i18n key |

## Data flow and legacy parity

| Legacy behavior | Modernized behavior |
|-----------------|---------------------|
| Smarty form posts to `execExport.php` (`export` submit button) | Popup calls BFF `GET ?action=info`, then `POST ?action=export` via `fetch()` → object-URL download |
| `contentAsXML()` = `TL_XMLEXPORT_HEADER` + `<executionSet>` → `<context>` + `<testcases>` | Identical composition in the BFF; templates/whitespace byte-identical |
| `contextAsXML()` reads testproject/testplan/build(+optional platform), project prefix, renders `<testproject>`/`<testplan>`/`<platform>`/`<build>` elements | `contextAsXMLBFF()` ports the same template + `exportDataToXML()` mapping |
| `tcaseSetAsXML()` loops the comma-separated `tcversionSet`, calling `testcase::exportTestCaseDataToXML(0,id,tproject,true)` | `tcaseSetAsXMLBFF()` calls the exact same legacy method |
| `downloadContentsToFile()` streams `text/plain` | BFF streams `application/xml` + `Content-Disposition: attachment` (matches modern export screens); filename sanitized via `basename()` + header-injection strip |
| Default filename `export_execution_set.xml` | Same default; user override kept |
| No explicit rights check beyond authentication | BFF gates `info` + `export` on `testplan_execute` OR `exec_ro_access` on the **owning** test project (resolved from the test plan) — 403 for restricted users; empty/garbage context → 400/404 JSON |

## BFF API reference

### GET `?action=info`

**Parameters:** `tplan_id` (required), `tproject_id`, `build_id`, `platform_id`, `tsuite_id`, `tcversionSet` (comma-separated tcv ids).

**Response (200):**
```json
{
  "status": "ok",
  "tplan": { "id": 9, "name": "WALK Plan" },
  "tproject": { "id": 4, "name": "WALK DEMO" },
  "platform": { "id": 0, "name": "" },
  "build": { "id": 1, "name": "Build Walk" },
  "tsuite_id": 0,
  "tc_count": 1,
  "default_filename": "export_execution_set.xml",
  "types": { "XML": "XML" },
  "rights": { "testplan_execute": 1, "exec_ro_access": 0 }
}
```

**Errors:** missing `tplan_id` → HTTP 400 `{"status":"error","message":"Invalid test plan id"}`; unknown plan → 404 `Test plan not found`; no `testplan_execute`/`exec_ro_access` → 403 `No permission`; unauthenticated → 401.

### POST `?action=export` (form-encoded, `X-Requested-With: XMLHttpRequest`)

**Fields:** `tplan_id`, `tproject_id`, `build_id`, `platform_id`, `tsuite_id`, `tcversionSet`, `export_filename`.

**Response (200):** streams `application/xml` with the byte-faithful legacy document and `Content-Disposition: attachment; filename="<export_filename>"`.

**Errors:** empty `tcversionSet` → 400 `No test case versions to export`; the same rights/404/401 matrix as `info` applies.

## Screenshots

![Execution Export screen](images/execx_screen.png)

![Execution Export with custom filename](images/execx_filename.png)

## Permission path

The BFF checks `testplan_execute` OR `exec_ro_access` on the **owning** test project (plan →
`testproject_id`) for both `info` and `export`. A restricted user (guest role, no execution
rights on the project) receives HTTP 403 JSON "No permission" on both — parity verified live
with a dedicated `noexec` user (role `guest`).

## i18n

All labels use client-side `TLi18n` keys under the `execx.*` namespace plus `exe.export` /
`exe.errExportNoData` on `execTest.html`, defined in all 10 locale bundles (`en`, `de`, `es`,
`fr`, `it`, `ja`, `pt`, `ro`, `ru`, `zh`).

## Files

| File | Purpose |
|------|---------|
| `gui/templates/execute/execExport.html` | Standalone HTML+JS+CSS popup screen |
| `api/executeexport/index.php` | BFF API — `info` + streaming `export` actions |
| `gui/templates/execute/execTest.html` | Toolbar Export button + `openExport()` context builder |
| `lib/functions/common.php` | `$actions->execExport` mapping → modern screen |
| `gui/templates/i18n/*.json` | i18n locale bundles (`execx.*`, `exe.export`, `exe.errExportNoData`) |
| `lib/execute/execExport.php` | Legacy controller (never called; kept for reference) |

---

_TestLink 2.0.1 · Execution Export · Refs #1024_