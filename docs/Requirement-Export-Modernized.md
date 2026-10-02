# Requirement Export — Modernized

Modernization of the legacy **Requirement Export** screen
(`lib/requirements/reqExport.php`,
`gui/templates/dashio/requirements/reqExport.tpl`) — tracked in
[#1001](https://github.com/sebiboga/testlink-upgraded/issues/1001).

The legacy Smarty page is replaced by a standalone Dashio page
(`gui/templates/requirements/reqExport.html`) backed by a plain-PHP REST BFF
(`api/reqexport/index.php`). Every legacy entry point now opens the modern
screen instead of `reqExport.php`.

**Path:** Requirements → Requirement Specification Management → toolbar **Export**
(whole project), or Requirement Specification Viewer → toolbar **Export** /
**Export branch**
**URL:** `gui/templates/requirements/reqExport.html?scope=tree|branch|items&tproject_id=..[&req_spec_id=..]`
**BFF API:** `GET ?action=options` + `POST ?action=export` on `/api/reqexport/`
**Rights:** `mgt_view_req` on the target test project (same rightsAnd check as
legacy `reqExport.php`).

![Requirement Export form](reqExport-normal.png)

## Behavior

The BFF reproduces `lib/requirements/reqExport.php` `doExport()` so the
produced XML is byte-identical to the legacy download:

- **Scopes** (legacy `scope` param, default `items`):
  - `tree` — every top-level requirement spec of the project
    (`getFirstLevelInTestProject`), root `<requirement-specification>`
  - `branch` — one spec + its whole sub-tree (recursive)
    (`exportReqSpecToXML(RECURSIVE=true)`), root `<requirement-specification>`
  - `items` — direct requirement children of one spec (non-recursive), root
    `<requirements>`
- **File type:** XML always (`get_export_file_types()` returns only XML); the
  legacy CSV branch is kept server-side for parity but not offered in the UI.
- **Export attachments** checkbox → `ATTACHMENTS` option; attachment contents
  are base64-encoded inside `<attachments>`.
- **Default export file name** (legacy `initializeGui()`):
  - `tree` → `all-req.xml`
  - `branch` → `<spec title>-req-spec.xml`
  - `items` → `<spec title>-child_req.xml`

## Flow

1. `GET ?action=options` resolves the scope context → spec title, default
   filename, current `mgt_view_req` right (404 on unknown spec; 400 on bad id).
2. User edits the file name, toggles attachments, clicks **Export**.
3. `POST ?action=export` streams the XML as `application/xml` +
   `Content-Disposition: attachment` (filename CR/LF/quote-sanitized),
   `Pragma: public`, `Cache-Control: must-revalidate`.
4. Browser saves the file via `fetch()` + blob download; JSON errors surface
   as a toast.

## Link switch

- `reqSpecMgmt.html` toolbar: new **Export** button (`btnExportReqs`) →
  `reqExport.html?tproject_id=..&scope=tree`; when a spec is being managed it
  passes `req_spec_id` + `scope=branch`. Always visible (legacy only needs
  `mgt_view_req`, unlike Import which needs modify rights).
- `reqSpecView.html` toolbar: **Export** (items) and **Export branch** links →
  `reqExport.html?req_spec_id=..&tproject_id=..&scope=items|branch`.

![Requirement Export button in spec management](reqSpecMgmt-export-btn.png)
![Export links in the spec viewer](reqSpecView-export-links.png)

## Error handling

- Invalid / unknown `req_spec_id` → clean 404 JSON. Before the fix the legacy
  `requirement_spec_mgr::get_by_id()` produced broken SQL for missing ids and
  the DB layer aborted with a CWE-200 backtrace dump; the BFF now pre-checks
  the node via a flat `nodes_hierarchy` lookup.
- Invalid scope → falls back to `items`; empty file name → client-side toast.
- Unauthenticated → 401, missing right → 403.

## Security

- **Header injection:** `Content-Disposition` filename strips `CR`/`LF`/`"` and
  `basename()` (spaces → `_`)
- **XSS:** all DOM values escaped via `esc()`/`.text()`/`.val()`
- **SQL injection:** ids `intval()`-cast, scope/type whitelisted
- **CSRF / same-origin:** `bffSameOriginGuard()` + `X-Requested-With`

## Related fix (#1002)

While testing this screen a shared defect was found on the other modern XML
export screens (`planExport.html`, `tcExport.html`, `usersExport.html`): their
`$.ajax({ xhrFields: { responseType: 'blob' } })` download reported
**"Export failed"** on every valid export — jQuery sniffed the
`application/xml` response, ran its xml converter over a Blob and routed to
`.fail()`. All four screens now download via `fetch()` + `res.blob()`.

## i18n

All strings use `reqexp.*` keys present in all ten locale bundles.

## Test coverage

**Suite 1001** in `tmp/TLU_Test_Cases.md` — see the suite for the executed
matrix (scopes × attachments × filename validation × right gate).
---

# Export file name budget (legacy `FILENAME_MAXLEN`) — issue #1295

## What legacy did

`gui/templates/dashio/requirements/reqExport.tpl:65-69` loaded
`gui/templates/conf/input_dimensions.conf` and rendered the file-name field
with the budget configured there:

```
{config_load file="input_dimensions.conf" section=$cfg_section}
...
<input type="text" name="export_filename" id="export_filename"
       maxlength="{#FILENAME_MAXLEN#}" ... size="{#FILENAME_SIZE#}"/>
```

`gui/templates/conf/input_dimensions.conf:22-23`:

```
FILENAME_MAXLEN=50
FILENAME_SIZE=50
```

That `maxlength` was the **only** enforcement point: both
`lib/requirements/reqExport.php::doExport()` and the BFF pass
`export_filename` straight into the `Content-Disposition` header, so any HTTP
client bypassing the browser could put an unbounded name into the header.

## What the modern screen did (the gap)

No limit anywhere in the modern stack — `reqExport.html` rendered
`<input id="exportFilename" required>` with no `maxlength`/`size`, and the BFF
did not cap the value either. Measured pre-fix with a 204-char name
(`'A'*200 + '.xml'`): both the legacy page and the BFF emitted all 204
characters into the response header.

## What it does now

| Layer | Behaviour |
|---|---|
| BFF `filenameDimensions()` | reads `FILENAME_MAXLEN` / `FILENAME_SIZE` from `gui/templates/conf/input_dimensions.conf` (safe default 50/50) — same pattern as `api/reqtcassign` `scopeShortTruncate()` → `SCOPE_SHORT_TRUNCATE` |
| BFF `?action=options` | returns `filename_maxlen` and `filename_size` |
| BFF `clampExportFilename()` | caps the name before the header is built; counts **characters** (like the browser attribute), UTF-8 safe, with a byte-loop fallback if mbstring is missing |
| Screen `updateLimitHint()` | applies `maxlength`/`size` from the API response (never hardcoded) and renders the live budget hint |
| Screen `trimToMaxlen()` | paste / drop / autofill guard — the HTML `maxlength` never fires on those paths — plus a red toast |

New i18n keys `reqexp.filenameLimit` and `reqexp.errFilenameTooLong` in all
ten locale bundles (e.g. Romanian: `50 din 50 caractere`,
`Numele fișierului de export este limitat la {max} caractere`).

## Parity re-verification done with the fix

The audit that opened #1295 was right that the screen is at parity, but its
fixture had no attachments, so `exportAttachments=1` "changing nothing" was
never actually exercised. `tmp/fixtures_1295.php` adds real attachments to a
req spec and to a requirement; with them, legacy and modern output are
byte-identical in all three scopes, with and without the flag:

| Scope | attachments off | attachments on |
|---|---|---|
| `tree` | 1022 B, 0 `<attachment>` | 1390 B, 1 `<attachment>` |
| `branch` | 1022 B, 0 `<attachment>` | 1390 B, 1 `<attachment>` |
| `items` | 471 B, 0 `<attachment>` | 839 B, 1 `<attachment>` |

The CSV dropdown is **not** a gap: `requirement_spec_mgr::$export_file_types`
is `array("XML" => "XML")`, so the legacy UI offered XML only and its
`case 'csv'` branch of `doExport()` was unreachable there too.

**Suite 1295** in `tmp/TLU_Test_Cases.md` — 16/16 PASS (options payload, browser
typing, autofill bypass, exact-50, whitespace-only, end-to-end download,
Romanian locale, 6-combination byte-diff matrix vs legacy, clamped-content
matrix, header-name edge cases, multi-byte budget, 403 rights gate for a
`<no rights>` user, Event Viewer clean).

![export file name budget](issue-1295-reqexport-filename-limit.png)
