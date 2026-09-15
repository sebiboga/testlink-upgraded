# Custom Fields XML Exchange — Modernized Screen

Modernization of the legacy Custom Fields export/import screens
(`lib/cfields/cfieldsExport.php` + `lib/cfields/cfieldsImport.php`,
`gui/templates/dashio/cfields/cfieldsExport.tpl` + `cfieldsImport.tpl`) —
GitHub issue
[#1411](https://github.com/sebiboga/testlink-upgraded/issues/1411).

The two legacy Smarty screens are replaced by a single standalone Dashio page
(`gui/templates/cfields/cfieldsExchange.html`) backed by a plain-PHP REST BFF
API (`api/cfieldsx/index.php`). The Custom Fields screen (`cfieldsView.html`)
toolbar now has **Export** and **Upload File** buttons pointing at the modern
screen, and `$actions->cfieldsExchange` was added in
`lib/functions/common.php`.

**Path:** Custom Fields → toolbar **Export** / **Upload File**
**URL:** `gui/templates/cfields/cfieldsExchange.html`
**BFF API:** `GET /api/cfieldsx/` (info) · `POST /api/cfieldsx/export` (download)
· `POST /api/cfieldsx/import` (multipart upload)
**Rights:** export — `cfield_view` (read); import — `cfield_management` (write);
parity with the legacy controllers. Users without the right see the card
greyed out and receive HTTP 403 from the BFF.
**Tracking issue:** [#1411](https://github.com/sebiboga/testlink-upgraded/issues/1411)

---

## Screen layout

| Section | Description |
|---------|-------------|
| **Context strip** | Existing custom field count + current user's export/import rights + max upload size |
| **Export card** | Filename input (default `customFields.xml`), Format select (**XML** only), *View File Format Document* link, **Export** button (downloads the XML attachment) |
| **Import card** | Format select (**XML** only), file input (`targetFilename`), *View File Format Document* link, max-size hint, **Upload File** button |
| **Result box** | After import: green list of **Imported** names and red list of **Not imported** (already-existing) names with per-field reasons, plus a totals summary |

## Export behavior (parity with legacy `cfieldsExport.php`)

The BFF replicates the legacy `ADODB_XML` output exactly:

- root element `<custom_fields>`, one `<custom_field>` row per
  `custom_fields ⋈ cfield_node_types` row (the same joined query, so a field
  bound to several node types appears once per binding, as in 1.9.20);
- per-row columns: `name, label, type, possible_values, default_value,
  valid_regexp, length_min, length_max, show_on_design, enable_on_design,
  show_on_execution, enable_on_execution, show_on_testplan_design,
  enable_on_testplan_design, node_type_id`;
- streamed with `Content-Disposition: attachment`, default filename
  `customFields.xml`;
- right checked: `cfield_view`.

## Import behavior (parity with legacy `cfieldsImport.php`)

- root/`<custom_field>` rows read with `simplexml_load_string` after
  `libxml_disable_entity_loader(true)`, `LIBXML_NONET` — XXE-safe;
- for each field, if `cfield_mgr->get_by_name()` finds no field with that name,
  `cfield_mgr->create()` inserts it (custom_fields + cfield_node_types rows),
  otherwise it is skipped and reported as "not imported";
- upload size limited by `import_file_max_size_bytes` (default 800 KB, shown in
  the UI as the max-size hint);
- right checked: `cfield_management`.

## Error states

| Case | Client message | BFF response |
|------|----------------|--------------|
| Empty export filename | Export name can not be empty! | — (blocked client-side) |
| No file selected | Please choose a file to import | 422 `need_file` |
| File over the size limit | The file exceeds the maximum allowed size of {kb} KB | 422 `file_too_big` |
| Malformed/empty XML | Could not load XML content… (+ libxml detail) | 422 `parse_failed` |
| Upload plumbing failure | Upload failed | 500 `upload_failed` |
| Missing right | Cards greyed out / BFF 403 No permission | 403 |

## Security

- session auth + per-route rights;
- `X-Requested-With: XMLHttpRequest`/Origin proof required on all POSTs
  (`api/_guard.php` `bffSameOriginGuard`), same as every other BFF;
- XXE protection (entity loader disabled + `LIBXML_NONET`);
- exported filename sanitized via `basename()` and a `[a-zA-Z0-9_.]` allow-list
  before being echoed in the `Content-Disposition` header.

## i18n

All labels, tooltips and messages use the client-side `TLi18n` module; 32
`cfx.*` keys were added to all 10 locale bundles (`en, de, es, fr, it, ja, pt,
ro, ru, zh`), each validated with `python3 -m json.tool`.

## Screenshots

See the GitHub wiki page *Custom Fields XML Exchange* for current screenshots
(normal state + import results).