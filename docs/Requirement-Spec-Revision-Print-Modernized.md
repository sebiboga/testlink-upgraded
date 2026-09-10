# Requirement Spec Revision Print (reqSpecPrintRevision) — Modernized Screen

Modernization of the legacy **Requirement Specification Revision print view** —
GitHub issue
[#1354](https://github.com/sebiboga/testlink-upgraded/issues/1354).

The legacy screens open `reqSpecPrint.php?reqspec_id=<id>&reqspec_revision_id=<rev>`
from the revision viewer toolbar. In 2.0.1 that print capability was dropped:
`api/reqdoc`'s `doc` action only prints the live spec tree. This task ports the
feature back as a new Dashio screen `reqSpecPrintRevision.html` backed by a new
BFF action `revision_doc` that reuses the exact legacy `print.inc.php`
renderers against **one specific revision**.

**URL:** `gui/templates/requirements/reqSpecPrintRevision.html?revision_id=<rev_spec_revision_id>&tproject_id=<id>`
**BFF API:** `api/reqdoc/index.php?action=revision_doc&revision_id=<id>&tproject_id=<id>`
**Rights:** `mgt_view_req` enforced server-side (same gate as legacy
`reqSpecPrint.php`).

## What it does

| Feature | Legacy behavior | Modern implementation |
|---|---|---|
| Entry point | `reqSpecViewRevision.tpl` "Print view" button → `reqSpecPrint.php?reqspec_id=&reqspec_revision_id=` in a new window | toolbar **Print view** link (`#printLink`, i18n `rsvr.printView`) → new tab `reqSpecPrintRevision.html?revision_id=<rev>&tproject_id=<tp>`; the URL carries the **viewed** revision id (not the latest) |
| Historical revision | printed the exact revision passed via `reqspec_revision_id` (merged into the spec revision-type node as `revision`) | BFF `revision_doc` resolves revision by id (`getRevisionByID()`), walks up the tree node to the owning spec, prints revision-specific rows (`revision <N>`) |
| Document generation | legacy `reqSpecPrint.php` built the HTML with `print.inc.php` | BFF reuses the untouched renderers `renderHTMLHeader`, `renderReqSpecNodeForPrinting` (SINGLE_REQSPEC option set), optional child requirements, `renderEOF`; HTML returned as JSON into an iframe `srcdoc` |
| Child requirements | gated by `$tlCfg->req_cfg->show_child_reqs_on_reqspec_print_view` (`DISABLED`/`ENABLED`) | same config gate honoured server-side — enabled → `Requirement: <primary_key> : <title>` sections with version/revision/author/status/type/scope/coverage |
| Coverage | `Total count of requirements (Coverage)` row with declared vs actual | same row, e.g. `66.67% (2/3)` (declared total from the revision's `total_req`, actual = requirements under the spec at that revision) |
| Print | browser print of the standalone page | **Print** toolbar button calls the iframe's `contentWindow.print()` |
| Navigation | browser back | **Back to revision view** (`rsvp.btnBack`) returns to `reqSpecViewRevision.html?id=<spec>&tproject_id=<tp>`; **Refresh** re-issues the BFF call |
| Locale | PHP `$g_lang` | client-side `TLi18n`; keys under `rsvp.*` + `rsvr.*` |

## REST API Reference

Session-authenticated JSON; safe verb (GET) only, so no Origin guard needed.

| Method | Route | Query | Returns |
|---|---|---|---|
| GET | `?action=revision_doc` | `revision_id` (a `requirement_spec_revision` node id), `tproject_id` | `{status:'ok', html, title, tproject_name, format:FORMAT_HTML, level:'revision', id:<spec_id>, revision_id}` |

### Error conditions
- Missing/invalid session → HTTP 401.
- Missing/invalid `revision_id` → HTTP 400 `Invalid revision id`.
- Revision id does not exist → HTTP 404 `Requirement specification revision not found`.
- Revision exists but belongs to another test project → HTTP 404 `Requirement specification revision not found in this test project`.
- No `mgt_view_req` right → HTTP 403.

## Legacy parity notes

- 1:1 with `lib/requirements/reqSpecPrint.php`: `getRevisionByID()` +
  `get_node_hierarchy_info($revId)` — revision rows are real tree nodes of type
  `requirement_spec_revision`, so the node's `node_type_id` + merged `id` drive
  `renderReqSpecNodeForPrinting()` exactly like the legacy code path.
- Singleton option set matches the legacy screen: `toc=0, req_spec_scope=1,
  req_spec_author=1, req_spec_type=1, req_spec_cf=1,
  req_spec_overwritten_count_reqs=1, headerNumbering=0`, `docType=SINGLE_REQSPEC`.
- Child-requirement print options are the SINGLE_REQ set
  (`req_linked_tcs, req_cf, req_scope, req_relations, req_coverage, req_status,
   req_type, req_author, displayVersion, displayDates, displayLastEdit`).
- The document body keeps its own legacy layout; it is only embedded in the
  modern shell (same pattern as `printDocument.html`).

## i18n Keys

Keys under `rsvp.*` `(rsvp.title, rsvp.btnBack, rsvp.rendered)` and `rsvr.printView`,
plus existing `pdoc.*`, `printReq.inProject`, `common.refresh`, `rsvr.noId`,
`rsvr.revision`. Present in all 10 bundles (`en ro de es fr it ja pt ru zh`).

## Security

- Session-authenticated BFF; `mgt_view_req` re-checked server-side on every
  request.
- Generated HTML is inserted via `srcdoc`; the iframe is
  `sandbox="allow-same-origin allow-popups allow-modals allow-downloads"`
  (no `allow-scripts`) so scope/description HTML cannot run scripts against the
  parent origin while printing still works.
- `revision_id`/`tproject_id` are int-cast before any SQL use; resolution goes
  through parameter-safe recordset lookups.

## Testing

See **Task — Issue #1354** suite in `tmp/TLU_Test_Cases.md` (13/13 PASS):
toolbar link, revision-specific print (latest r2 AND historical r1), coverage
row, error paths, both child-requirement gate states, back link, events scan,
i18n bundle integrity.