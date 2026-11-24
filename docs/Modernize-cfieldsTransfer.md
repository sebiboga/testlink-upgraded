# Modernize: Custom Fields Usage & Transfer Hub (Issue #2048)

**Screen:** `gui/templates/cfields/cfieldsTransfer.html`
**BFF:** `api/cfieldstransfer/index.php`
**Status:** DONE — one new screen, two legacy controllers retired as shims.

## Why this screen

TestLink 1.9.20 exposed Custom Field definition Export and Import only through
two standalone Smarty controllers (`lib/cfields/cfieldsExport.php` and
`lib/cfields/cfieldsImport.php`), reachable by typing their URLs. 2.0.1 already
had `cfieldsExchange.html` for same-project assignment transfer, but neither the
definition XML round-trip nor a cross-definition usage answer had a Dashio home:
*which custom fields are actually linked to projects and which carry values?*

`cfieldsTransfer` is that hub. It reimplements the full legacy definition
export/import behaviour and adds a net-new inventory tab over the `cfield_*`
value tables.

## Screen

- **Usage inventory** — seven KPI tiles (definitions, linked projects, design /
  execution / plan / build values, unused definitions), a verdict banner, a
  searchable 11-column DataTable and an exact `Used` / `Unused` / `All` verdict
  filter. Exports the filtered rows as UTF-8 CSV with BOM, quoted fields,
  `;` separator and formula-injection guard.
- **Export XML** — configurable file name and a guarded `fetch` download of the
  legacy ADODB_XML document (`<custom_fields>` / `<custom_field>`), definitions
  only, never stored values.
- **Import XML** — multipart upload of the same format; only names that do not
  already exist are created, duplicates are reported as skipped, and malformed
  files get a localized state card. The sample XML is shown on the tab.

Tabs are deep-linkable with `#usage`, `#export` and `#import`, and the URL hash
is kept in sync when the user switches tabs. All labels, hints, state messages
and DataTables strings come from `TLi18n`; `ctrf.*` and `footers.cfieldsTransfer`
keys exist in all 10 locale bundles, with Romanian fully translated.

## BFF contract

| Route | Method | Right | Result |
|---|---|---|---|
| `?action=info` | GET/HEAD | `cfield_view` | context, grants, limits, export field names, authoritative type map |
| `?action=usage` | GET/HEAD | `cfield_view` | per-definition counts and verdicts, plus totals |
| `?action=export[&filename=…]` | GET/HEAD | `cfield_view` | ISO-8859-1 XML attachment; filename whitelist `[A-Za-z0-9 _.-]+` |
| `?action=import` | POST (multipart `targetFilename`) | `cfield_management` | `{created, skipped, failed, total}` JSON |

All routes run `bffSameOriginGuard()` and `bffEnforceSession()`; anonymous
answers are 401 (`not_authenticated` / `session_expired`), insufficient rights
403 `no_right`, bad methods 405 and bad actions 400. Import parses with
`LIBXML_NONET`, no entity substitution, a 1 MiB upload cap and a 500-field cap;
only whitelisted definition columns are passed to `cfield_mgr::create()` and
`node_type_id` is validated against `get_allowed_nodes()`.

## Link switch and retired controllers

`lib/functions/common.php` gained `$actions->cfieldsTransfer`. The Custom Fields
list (`gui/templates/cfields/cfieldsView.html`) now offers **Export**, **Upload
File** and **Usage inventory** buttons pointing at the new screen. The two old
PHP controllers are GET/HEAD-only, session-guarded 302 shims to `#export` and
`#import`; every other method returns 405, so the old unauthenticated
render-and-write paths are gone.

## Issues found while testing

- #2049 usage route HTTP 500 — `get_recordset()` returns an array, not an
  ADORecordSet.
- #2050 XML `node_type_id` string rejected by a strict int comparison.
- #2051 verdict filter options showed raw i18n keys.
- #2052 `Used` smart-matched `Unused` in the hidden DataTables column.
- #2053 tab clicks left a stale URL hash.
- #2054 DataTables search control failed two accessibility checks.
- #2055 Romanian bundle fell back to English for most new labels.
- Final code review: session-expired state, filename-injection whitelist,
  `is_uploaded_file()` proof, authoritative type map, DataTables label handling,
  raw CSV values, fetch-based export with error handling and localized node
  types (commit `37a849ecf`).

All were fixed, verified and closed before this screen was declared done.

## Verification

Suite `Issue #2048` (2048.1–2048.25) covers the fixture, BFF contract, rights
matrix, shims, all three tabs, filters, CSV, XML round-trip, deep links, locale
switch, permission state, console and gates. i18n gate: 9/9 bundles pass.
Event Viewer: only AUDIT login rows, no new Error/Warning.
