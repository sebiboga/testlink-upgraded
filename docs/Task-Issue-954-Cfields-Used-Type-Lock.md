# Task 954 — Forbid type/node-type change of custom fields already holding values (cfieldsView + cfieldsAssignView)

**Issue:** [#954](https://github.com/sebiboga/testlink-upgraded/issues/954)
**Status:** IMPLEMENTED & VERIFIED (2026-09-22) — branch `task/issue-954-cfields-used-type-lock`

## The gap

Legacy edit of a custom field that already **holds values** locks both
"Available for" (node type) and "Type" as read-only static text:

- `gui/templates/dashio/cfields/cfieldsEdit.tpl:105-141` — when
  `$gui->cfield_is_used` the template renders the selected node-type/type labels
  as plain text plus hidden inputs `cf_node_type_id` / `cf_type` that carry the
  original values, so a `do_update` can never change them;
- `lib/cfields/cfieldsEdit.php:37,280` computes `cf_is_used` via
  `cfieldMgr->is_used()` (`lib/functions/cfield_mgr.class.php:1486`), which
  returns 1 as soon as the field has a row in any of the four value tables
  (`cfield_design_values`, `cfield_build_design_values`,
  `cfield_testplan_design_values`, `cfield_execution_values`).

The modern screens dropped this: the shared edit modal
(`cfieldsView.html` and `cfieldsAssignView.html`) always rendered **editable**
Type + Node Type combos for used fields, and `PUT /api/cfields/{id}`
(`api/cfields/index.php:191-204` at fix time) wrote the submitted values
straight into the DB — a used field could be silently re-typed.

**Measured repro** (fixture: `Tier` cf with one `cfield_design_values` row,
`is_used=1`):

- `GET /api/cfields/index.php/1` → `{... type:0, node_type_id:3}` — no `is_used`
  exposed;
- `PUT /api/cfields/index.php/1 {type:8, node_type:'testcase'}` → **HTTP 200**,
  re-GET shows `type=8` — silent re-type.

## Implementation

### BFF — `api/cfields/index.php` (Refs #954)

- **`usedFieldIds()`** (helper, `index.php:76-90`): resolves once per request the
  set of field ids that appear in any of the four value tables — a single UNION
  query replacing one `is_used()` SQL per row on list endpoints. Table names come
  from `tlObject::getDBTables([...])` (`cfield_mgr->tables` is `protected`,
  `lib/functions/object.class.php:78`).
- **`cfToJSON($cf, $isUsed)`** now emits `'is_used' => 1|0` on every payload:
  GET list (`:135`), GET /{id} (`:149`), POST create result (`:223`), PUT result
  (`:309`), and GET /assignment linked + available rows (`:408,:423`).
- **PUT /{id} guard** (`:269-288`): when `is_used`, the submitted `type` /
  `node_type` are normalised to int (`intval` — the node map returns DB strings
  like `'3'`, which would have tripped the strict `!==` compare) and compared
  against the stored values. If either genuinely differs → **HTTP 400**
  `{status:'error', code:'warning_no_type_change', message: lang_get('warning_no_type_change', assignLocale())}`
  (the legacy localized warning).
  If unchanged (the normal UI save always sends the preserved values) the
  attributes are force-preserved from `$existing` while every other attribute
  (label, possible values, flags) saves normally.

### Screens — `cfieldsView.html` + `cfieldsAssignView.html`

- Modal adds `#editTypeText` / `#editNodeTypeText` static-text elements and a
  `#typeLockWarning` banner (`cf.msg.warningNoTypeChange`, hidden by default).
- New `setTypeEditable(editable)` toggles selects ↔ static text + warning.
- `editCf()` fills the static text (`typeMap[cf.type]`, resolved node name) and
  calls `setTypeEditable(!cf.is_used)`; `showCreateModal()` (list screen) resets
  to editable mode.
- `saveCf()` error mapping: BFF response `code === 'warning_no_type_change'` →
  the localized bundle text `cf.msg.warningNoTypeChange`.
- The assign screen's name links in **both** the Assigned and Available tables
  use the same `editCf()`, so the lock applies everywhere the field is editable.

### i18n

`cf.msg.warningNoTypeChange` added to all 10 bundles
(`gui/templates/i18n/{en,ro,de,fr,es,it,pt,ja,zh,ru}.json`), wording adapted
from the legacy `$TLS_warning_no_type_change`
(`locale/en_US/strings.txt:816`, `de_DE:722`, `fr_FR`, `es_ES:775`,
`pt_PT:845`, `ja_JP:884`, `zh_CN`); `ro`/`it` had no legacy translation and get
a fresh faithful translation. All bundles validated with `python3 -m json.tool`.

## Verification evidence (browser + API, fixture `tmp/fixtures_954.php`)

- **cfieldsView, used field (Tier)**: modal shows Type `string` + Node Type
  `testcase` as static text (selects `display:none`) + warning banner.
- **cfieldsView, value-less field (TierEmpty)**: selects editable, no banner.
- **Normal save on used field** (label change): toast "Custom field saved",
  label persisted, `type`/`node_type_id` untouched, no modal error.
- **BFF backstop**: `PUT {type:4}` and `PUT {node_type:'build'}` → HTTP 400
  `code:warning_no_type_change`; stored values unchanged.
- **cfieldsAssignView**: used field locked from the name link in the Assigned
  table and, after an unlink, from the Available table (verified, then re-linked).
- **Non-used field re-type still allowed** (no scope regression).
- **German locale** (`?locale=de`): banner localised
  `Dieses benutzerdefinierte Feld enthält bereits Werte; …`.
- Event Viewer/`events`: 18 rows, all `log_level=16` (audit INFO), **0 rows > 16**
  (no Error/Warning).
- Browser console clean (only pre-existing a11y hints); inline JS `node --check`
  clean; all 10 bundles valid.

Suite 954 in `tmp/TLU_Test_Cases.md` — **9/9 PASS**.

Screenshot: `docs/screenshots/issue-954-used-field-locked-modal.png` (mirrored to wiki).

Refs #954.