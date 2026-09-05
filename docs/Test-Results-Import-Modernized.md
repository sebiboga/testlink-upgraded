# Test Results Import — Modernized Screen

Modernization of the legacy import screen (`lib/results/resultsImport.php`,
`gui/templates/dashio/results/resultsImport.tpl`) — GitHub issue
[#1005](https://github.com/sebiboga/testlink-upgraded/issues/1005).

The legacy Smarty screen is replaced by a standalone Dashio page
(`gui/templates/results/resultsImport.html`) backed by a plain-PHP REST BFF API
(`api/resultsimport/index.php`). All Test Management aside links that used the
legacy controller now call `openImportResult()` in `test_automation.js`, which
opens the modern HTML page with the active testproject / testplan / build /
platform context.

**Path:** Test Management → plan details → import results toolbar button
**URL:** `gui/templates/results/resultsImport.html?tproject_id=<id>&tplan_id=<id>&build_id=<id>&platform_id=<id>`
**BFF API:** `GET /api/resultsimport/?action=info` + `POST /api/resultsimport/?action=import`
**Rights:** `testplan_execute` on the owning test project (verified 403 for guest users)
**Tracking issue:** [#1005](https://github.com/sebiboga/testlink-upgraded/issues/1005)

---

## Screen layout

| Element | Description |
|---------|-------------|
| **Heading** | Teal banner "Test Results Import" + context label `- <tproject> / <tplan>`, locale switcher |
| **Context bar** | Read-only Test Project / Test Plan values with a Back button |
| **Form card "Import XML Results"** | Build select, Test Platform select, File Type select, format documentation link, local file picker with size hint, copy-issues checkbox (only when `exec_cfg->copyLatestExecIssues->enabled` in config) |
| **Result panel** | Appears after import: section head "Import Result" + count badge; table lines with Test Case / version / tester / result / execution timestamp and Imported · Not imported · Skipped status badges |
| **Footer** | `footers.resultsImport` i18n key |

## Data flow and legacy parity

| Legacy behavior | Modernized behavior |
|-----------------|---------------------|
| `resultsImport.php?tproject_id=..&tplan_id=..&build_id=..` loads Smarty form | `resultsImport.html?...` renders standalone HTML+JS |
| PHP receives multipart upload, parses XML, loops result rows | BFF `POST ?action=import` receives the multipart file, runs the same ported loops |
| Duplicate detection by latest execution timestamp per context (`getLatestExecSingleContext`) | Same call via BFF; rows with a matching timestamp are reported as **Not imported** and skipped |
| `getInternalID()` resolves external ids (needs tproject scope for plain numeric ids) | Same helper called with `tproject_id` passed in (legacy fatal for numeric external ids is fixed) |
| `simplexml_load_file_wrapper()` dies with raw HTML on malformed XML | BFF uses `libxml_use_internal_errors` + `LIBXML_NONET`; any parse failure returns JSON `wrong_results_import_format` |
| Result code validated against `$resultsCfg['code_status']` (undefined since 1.8) | BFF validates against the authoritative `status_code` domain — a genuine modernization bug-fix (legacy rejected every import with a bogus "invalid result" message) |
| `copyIssues` checkbox gated by `exec_cfg->copyLatestExecIssues->enabled` | BFF exposes `copy_issues_enabled`; checkbox shown only when config enables it |

## BFF API reference

### GET `?action=info`

Resolves the import context. `tplan_id` falls back to the session test plan.

**Parameters:** `tproject_id`, `tplan_id`, `build_id`, `platform_id` (all optional; tplan/tproject fall back to session).

**Response (200):**
```json
{
  "status": "ok",
  "tproject": { "id": 1, "name": "ImportTProject" },
  "tplan": { "id": 2, "name": "ImportTPlan" },
  "builds": [ { "id": 1, "name": "Build1", "open": true, "active": true } ],
  "platforms": [],
  "copy_issues_enabled": false,
  "file_size_limit": 800000,
  "file_size_limit_kb": 781,
  "import_types": [ { "id": "XML", "label": "XML" } ]
}
```

**Errors:** unknown test plan → HTTP 404 `{"status":"error","message":"Test plan not found"}`; missing `testplan_execute` right → HTTP 403 `{"status":"error","message":"No permission"}`; unauthenticated → HTTP 401.

### POST `?action=import` (multipart)

**Fields:** `file` (XML), `build_id`, `platform_id`, `import_type` (`XML`), `copyIssues` (`on`).

**Response (200):**
```json
{
  "status": "ok",
  "messages": [
    "Test Case 1 - version 1 - Tester: admin - Result: Passed - Execution Timestamp: '2026-09-05 10:00:00' - Imported!",
    "Test Case 2 - version 1 - Tester: admin - Result: Not imported (duplicate execution timestamp)"
  ]
}
```

Each XML `<testcase>` row yields one message: `Imported!` / `Not imported` (duplicate timestamp) / `Not imported - is FROZEN` / `Compiled to file` / per-row error text (unknown external id, invalid result code, invalid timestamp). The whole response is localized via existing PHP `lang_get()` keys (`import_results_ok`, `import_results_skipped`, `wrong_results_import_format`, …).

## Permission path

The BFF checks `testplan_execute` on the owning test project for **both** `info` and `import`. A restricted user (guest, no execution rights) receives HTTP 403 JSON "No permission" on both. This mirrors the legacy `checkRights()` behavior — parity verified live with user `importrestricted`.

## i18n

All labels use client-side `TLi18n` keys under the `resimp.*` namespace plus `footers.resultsImport`, defined in all 10 locale bundles (`en`, `de`, `es`, `fr`, `it`, `ja`, `pt`, `ro`, `ru`, `zh`). Bundle consistency is enforced by `tools/lint_i18n.py`.

## Files

| File | Purpose |
|------|---------|
| `gui/templates/results/resultsImport.html` | Standalone HTML+JS+CSS screen |
| `api/resultsimport/index.php` | BFF API — `info` + `import` actions |
| `gui/javascript/test_automation.js` | `openImportResult()` now opens the modern screen with tproject/tplan/build/platform context |
| `gui/templates/i18n/*.json` | i18n locale bundles (`resimp.*` + `footers.resultsImport`) |
| `lib/results/resultsImport.php` | Legacy controller (never called; kept for reference) |

Screenshots: the wiki page contains current screenshots of the empty form and of an import result (see `tmp/wiki-repo/Test-Results-Import-Modernized.md`).

---

_TestLink 2.0.1 · Test Results Import · Refs #1005_