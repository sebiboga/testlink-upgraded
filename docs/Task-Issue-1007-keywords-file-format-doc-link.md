# Task 1007 — "View File Format Documentation" link in keyword import/export

**Issue:** [#1007](https://github.com/sebiboga/testlink-upgraded/issues/1007)
**Status:** IMPLEMENTED & VERIFIED (2026-09-27) — branch `task/issue-1007`

## The gap

Legacy 1.9.20 told the user **what the file must look like** before asking them to
upload or diff one. Both keyword exchange screens rendered an anchor to the static
format specification, sitting inside the *file-type* cell — i.e. right under the
XML/CSV select:

| legacy template | line | anchor |
|---|---|---|
| `gui/templates/dashio/keywords/keywordsImport.tpl` | 31 | `<a href={$basehref}{$smarty.const.PARTIAL_URL_TL_FILE_FORMATS_DOCUMENT}>{$lbl.view_file_format_doc}</a>` |
| `gui/templates/dashio/keywords/keywordsExport.tpl` | 63 | same anchor, in the `exportType` `<td>` |
| `cfg/const.inc.php` | 919 | `define('PARTIAL_URL_TL_FILE_FORMATS_DOCUMENT','docs/tl-file-formats.pdf')` |

It is a **house-wide convention**, not a keywords quirk — 15+ legacy screens carry
the same anchor:

```
tcImport.tpl:36          tcExport.tpl:55          cfieldsImport.tpl:76
cfieldsExport.tpl:60     platformsImport.tpl:78   platformsExport.tpl:57
planImport.tpl:37        planExport.tpl:65         execExport.tpl:69
resultsImport.tpl:28     reqExport.tpl:77          reqCreateFromIssueMantisXML.tpl:37
usermanagement/usersExport.tpl:54                 include/inc_gui_import_file.tpl:16
```

The modernized exchange popup had **no anchor at all**. Measured in the browser
before the fix:

| panel | anchors in DOM | anchors to `tl-file-formats.pdf` |
|---|---|---|
| Export (`?mode=export`) | **0** | **0** |
| Import | **0** | **0** |

`document.querySelectorAll('a').length === 0` in both — the link was not
restyled-away, it was never ported. Meanwhile **9 already-modernized screens do
honour the convention** (`platformsExport.html:102`, `plans/planImport.html:74`,
`cfields/cfieldsExchange.html:76,97`, `testcases/tcExport.html`,
`results/resultsImport.html`, `requirements/req{Import,Export}.html`,
`execute/execExport.html`), so keywords was the odd one out.

## What the issue body got slightly wrong

The issue says the link is missing from the *modals of `keywordsView.html`*. The
modern flow is not inline modals: `keywordsView.html:394-410` opens a **popup**
via `openImport()` / `openExport()` → `openExchange(mode)` serving
`gui/templates/keywords/keywordsExport.html`, which builds **both** panels
client-side. The gap itself is exactly as reported; only the location moved.

## The change

No BFF change was required — `api/keywordsxml/index.php?action=init` already
returns everything both panels render (`exportTypes`, `importTypes`,
`formatDescriptions`, `limits`, `rights`, `tproject_name`, `keyword_count`,
`default_filename`). The PDF is a static asset under the served docroot.

### `gui/templates/keywords/keywordsExport.html` (+16 lines)

```css
.doc-link { display: inline-block; color: #0f6862; font-size: 12px; margin-top: 6px; text-decoration: none; }
.doc-link:hover { text-decoration: underline; }
```

```js
// Legacy keywordsImport.tpl:31 / keywordsExport.tpl:63 render a "view file
// format documentation" anchor right below the file-type select, pointing at
// PARTIAL_URL_TL_FILE_FORMATS_DOCUMENT (cfg/const.inc.php:919 ->
// docs/tl-file-formats.pdf). Same anchor, same spot, in both exchange panels -
// the PDF is a static asset under the served docroot, so no BFF call is needed.
var FILE_FORMATS_DOC = '/docs/tl-file-formats.pdf';

function docLink() {
  return '<a class="doc-link" href="' + FILE_FORMATS_DOC + '" target="_blank" rel="noopener">' +
    '<i class="fa fa-file-pdf-o"></i> ' + esc(t('kwxml.viewDocs')) + '</a>';
}
```

rendered in **both** panels, immediately after the format select — the legacy
position:

* `exportPanel()` — after `'<select id="exportType" …>'`
* `importPanel()` — after `'<select id="importType" …>'`

`docLink()` is a **string helper, not a static `data-i18n` node**, on purpose: both
panels are re-rendered as an HTML string on every `render()`, so a static node
would be thrown away on the first format switch or locale change. The label is
resolved with `t('kwxml.viewDocs')` at render time, like the rest of the file.

`rel="noopener"` matters: the link opens a new tab, and without it the PDF page
would keep a `window.opener` handle back into the app.

### i18n — `kwxml.viewDocs` in all 10 bundles

`de, en, es, fr, it, ja, pt, ro, ru, zh` — inserted right after
`kwxml.fileNameHint`, **+1 line / 0 deletions per bundle** (ordering preserved,
no mass reformat). The wording reuses the already-translated sibling keys
`pexp.viewDocs` / `cfx.formatDoc` so the 10 bundles stay consistent:

| locale | `kwxml.viewDocs` |
|---|---|
| en | View File Format Documentation |
| de | Dokumentation zum Dateiformat anzeigen |
| es | Ver documentación del formato de archivo |
| fr | Voir la documentation du format de fichier |
| it | Visualizza la documentazione sul formato del file |
| ja | ファイル形式のドキュメンテーションを表示 |
| pt | Ver documentação do formato de arquivo |
| ro | Vezi documentația formatului de fișier |
| ru | Посмотреть документацию по формату файла |
| zh | 查看文件格式文档 |

## Verification

`tmp/TLU_Test_Cases.md` → **Suite 1007, 13/13 PASS** (case 1007-01 is the
deliberately reproduced pre-fix baseline).

| check | measurement |
|---|---|
| Export panel | `a.doc-link` found, `href="/docs/tl-file-formats.pdf"`, `target=_blank`, `rel=noopener`, `i.fa-file-pdf-o` present, **inside `#exportType`'s parent**, `color=rgb(15,104,98)` |
| Import panel | same anchor, **inside `#importType`'s parent** |
| position | a11y order (zh page): 格式 select → 查看文件格式文档 → 格式示例 — same as `keywordsExport.tpl:63` |
| link resolves | click → new tab `http://localhost:8082/docs/tl-file-formats.pdf`; `200 OK`, `Content-Type: application/pdf`, `Content-Length: 570890` |
| format switch | `exportTypeChanged()` to CSV → `linkStillThere: true`, href unchanged |
| locale `ro` | *„Vezi documentația formatului de fișier"* in **both** panels |
| locale `zh` | *„查看文件格式文档"*, `rawKeyLeak: false` |
| i18n bundles | 10/10 `python3 -m json.tool` OK, 10/10 non-empty and distinct |
| syntax gate | `node --check` on the extracted inline `<script>` (14281 bytes) → OK |
| console | `list_console_messages(error, warn)` → `<no console messages found>` |
| Event Viewer | `select log_level, count(*) from events group by log_level` → `16 | 1` (audit INFO only); `where log_level in ('ERROR','WARNING')` → **empty** |
| regression | exchange untouched: format select, format sample, file name / file picker and their buttons all still render; no BFF change |

### Fixture (fresh DB)

This fork has **no `testprojects.name` column** — `testProjectName()` reads
`nodes_hierarchy.name`, so a project fixture needs rows in *both* tables or the
screen shows the "Test project not found" card:

```sql
INSERT INTO testprojects (id,prefix,color,active,tc_counter,is_public)
  VALUES (9001,'KWFIX','#4ECDC4',1,0,1);
INSERT INTO nodes_hierarchy (id,parent_id,node_type_id,name,node_order)
  VALUES (9001,0,1,'Keyword Fixture Project',0);
```

Entry point: `http://localhost:8082/gui/templates/keywords/keywordsExport.html?mode=export&tproject_id=9001`

## Files

| file | purpose |
|---|---|
| `gui/templates/keywords/keywordsExport.html` | `.doc-link` CSS, `FILE_FORMATS_DOC` + `docLink()`, rendered in `exportPanel()` and `importPanel()` |
| `gui/templates/i18n/{de,en,es,fr,it,ja,pt,ro,ru,zh}.json` | `kwxml.viewDocs` |
| `CHANGELOG` | `[FEATURE GAP] - #1007` line |
| `tmp/TLU_Test_Cases.md` | Suite 1007 |

Commit: `b3d41cfb1`.
