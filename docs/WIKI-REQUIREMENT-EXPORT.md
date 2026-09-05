# Requirement Export — Modernized Screen

The **Requirement Export** screen (Requirements workspace → Requirement Specification Management toolbar → **Export**, or Requirement Specification Viewer toolbar → **Export** / **Export branch**) exports a whole requirement-spec project tree, a spec branch, or the requirements of a spec as TestLink XML. It replaces the legacy 1.9.20 `lib/requirements/reqExport.php` HTML page with a modern Dashio-styled page backed by a plain-PHP REST BFF.

**Path:** Requirements → requirement spec management / viewer → **Export**
**URL:** `gui/templates/requirements/reqExport.html?scope=tree|branch|items&tproject_id=<id>[&req_spec_id=<id>]`
**BFF API:** `api/reqexport/index.php` — `GET ?action=options&scope=..` · `POST ?action=export` (binary XML response)
**Rights:** `mgt_view_req` on the target test project (same check as legacy `reqExport.php`)
**Tracking issue:** [#1001](https://github.com/sebiboga/testlink-upgraded/issues/1001)

---

## Export scopes (legacy `scope` param, default `items`)

| Scope | Contents | XML root | Legacy call |
|-------|----------|----------|-------------|
| `tree` | every top-level requirement spec of the project | `<requirement-specification>` | `getFirstLevelInTestProject()` |
| `branch` | one spec + its whole sub-tree (recursive) | `<requirement-specification>` | `exportReqSpecToXML(RECURSIVE=true)` |
| `items` | direct requirement children of one spec | `<requirements>` | `exportReqSpecToXML(RECURSIVE=false)` |

## Form fields

| Field | Behaviour |
|-------|-----------|
| Test Project / Spec context | shown read-only in the header (resolved from `tproject_id` / `req_spec_id`; the scope is normalized tree/branch/items) |
| Export file name | pre-filled with the legacy default: `all-req.xml` (tree), `<title>-req-spec.xml` (branch), `<title>-child_req.xml` (items); validated non-empty on submit |
| File Type | always XML (`get_export_file_types()` = `array('XML'=>'XML')`; the dead legacy CSV branch is kept server-side for parity but not offered) |
| Include attachments | checkbox → `ATTACHMENTS` XML option (attachment contents base64-encoded) |
| Export | submits the POST to the BFF; `fetch()` + blob download; loading overlay while running |

## Export behaviour (legacy parity)

* The produced XML is byte-identical to the legacy `doExport()` output (`TL_XMLEXPORT_HEADER` from `config.inc.php`); each requirement includes doc id, title, scope, type, status, created/modified author + timestamps, and (opt-in) base64 attachments.
* Response headers: `application/xml`, `Content-Disposition: attachment` with CR/LF/quote-sanitized filename, `Pragma: public`, `Cache-Control: must-revalidate` — the legacy `downloadContentsToFile()` header injection risk is removed.

## Link switch

* `reqSpecMgmt.html` toolbar: new **Export** button (`btnExportReqs`, always visible — legacy needs only `mgt_view_req`) → `reqExport.html?tproject_id=..&scope=tree`, or `scope=branch` + `req_spec_id` once a spec is being managed.
* `reqSpecView.html` toolbar: **Export** (items → `scope=items`) and **Export branch** (→ `scope=branch`) links.

## Error handling (BFF resilience fix)

The legacy `requirement_spec_mgr::get_by_id()` aborts with broken SQL + a CWE-200 backtrace dump for a missing spec id. The BFF pre-checks the id with a flat `nodes_hierarchy` lookup before touching the legacy manager, so invalid ids return a clean **404 JSON** on both `options` and `export`.

## i18n

All labels, hints, messages, buttons and the footer use client-side `TLi18n` keys `reqexp.*` (20 keys) + `footers.reqExport`, present in every locale bundle (`en`, `de`, `es`, `fr`, `it`, `ja`, `pt`, `ro`, `ru`, `zh`).

## Related bug fix (#1002)

Testing this screen surfaced a shared defect: the other modern XML export screens (`planExport.html`, `tcExport.html`, `usersExport.html`) downloaded via `$.ajax({ xhrFields:{ responseType:'blob' } })` with no dataType; jQuery sniffed `application/xml`, applied its xml converter to a Blob, hit `parsererror` and called `.fail()` → "Export failed" on every valid export. All four screens now download via `fetch()` + `res.blob()` (JSON errors still surface as toasts; usersExport keeps its 403 handling).