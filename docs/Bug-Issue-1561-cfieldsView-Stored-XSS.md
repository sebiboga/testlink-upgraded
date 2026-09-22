# Bug fix (security) — Issue #1561: stored XSS in cfieldsView (custom-field name/label + delete action)

## Symptom
The modern Custom Fields screen (`gui/templates/cfields/cfieldsView.html`) rendered
custom-field **Name** and **Label** column cells with DataTables, which writes cell
data via `html()`. A custom field whose `name`/`label` contains markup (e.g.
`"> <img src=x onerror=alert(73)>`) executed the handler on load. The row Actions
cell's inline `onclick="deleteCf(id,'name')"` only escaped single quotes, so a `"`
in the name broke out of the attribute (stored XSS on the same screen, any user
with `cfield_management` can seed it — every reader of the list is affected).

## Reproduction (this run, fresh DB)
1. `POST /api/cfields/index.php` with a JSON body carrying
   `name = "> <img src=x onerror=alert(73)>`, `label = evil <b>bold</b>`
   → HTTP 200, stored raw (server does NOT sanitize; same as legacy, which only
   TRIMs).
2. Load `cfieldsView.html` in Chrome as admin.
3. Pre-fix behaviour: `alert(73)` fires on load. Post-fix behaviour: cell renders
   the HTML-escaped literal, no element is created, no alert.

## Root cause chain
- **Primary sink — DataTables cells.** `renderTable()` (cfieldsView.html:198-230)
  pushed `cf.label`/`cf.name` unescaped into DataTables row data; DataTables
  writes cells via `html()`. Legacy template
  `gui/templates/dashio/cfields/cfieldsView.tpl:58-59` escaped with Smarty
  `|escape` (htmlspecialchars) — the modernization port dropped it.
- **Secondary sink — delete action attribute.** The row trash icon used an inline
  `onclick="deleteCf(id,'<name>')"` with only single-quote escaping → a `"` broke
  out of the attribute and into an HTML/JS context.
- **Server accepts markup by design.** `api/cfields/index.php:134-135` only
  `trim()`s name/label (legacy parity). XSS must be prevented at render time, not
  by corrupting stored data (labels legitimately contain `&`/`<`).

## Fix approach
1. **Cell + delete-action hardening (landed with task #950, commit `b7aaee02e`):**
   new `escAttr()` helper (amp/lt/gt/quote/aps) applied to the label/name/type
   cells (cfieldsView.html:213-215) and the delete icon switched to
   `data-delete-id` / `data-delete-name` (escaped) with a delegated
   `$(document).on('click', '.action-btn.danger[data-delete-id]', ...)` handler
   (lines 209-210, 318-320). `jQuery.data()` HTML-decodes the attribute back to
   the raw name for `confirm()` — a text-only sink, safe by design.
2. **This run:** closed the LAST two unescaped interpolations in the same file
   that funnel field-derived strings into an append()/html() sink — the type and
   node `<option>` builders in `loadMeta()`:
   - line 159 `t.name` → `escAttr(t.name)` (type option text; value keeps the int id);
   - line 169 `n.name` → `escAttr(n.name)` in both value and text slots (node value
     IS the name) — same pattern as sibling `cfieldsAssignView.html:200,207`.
   Sources feed `get_available_types()` (system-fixed array,
   `cfield_mgr.class.php:80`, extensible via `custom/cf_*.php` config `+=`) and
   `get_allowed_nodes()` — not attacker-controlled today, but the inconsistency
   was exactly the fragile style bug #1561 was filed against.
3. **Deliberately NOT changed:** server-side write sanitization — it would corrupt
   legitimate labels (`&`/`<` in text), legacy stores raw and escapes at output,
   and every modern render path for this data now escapes before its sink. 

## Verification
Browser regression matrix on `http://localhost:8082` (admin), hostile fixture
created via the BFF then exercised:
- Hostile name/label render inert (`"&gt; &lt;img src=x onerror=alert(73)&gt;`,
  `evil &lt;b&gt;bold&lt;/b&gt;`), `td.querySelector('img') === null`, no alert.
- Delete via delegated handler works; confirm shows the raw name as plain text;
  DELETE 200; row gone from table + DB.
- Edit modal title uses `.text()` (inert), name/label inputs raw via `.val()`.
- Type/Node selects populate identically (string/numeric/float/... and
  build/testsuite/testplan/testcase/...).
- Create-via-modal flow works.
- Event Viewer clean: only log_level 16 (NOTICE) audit rows in the hour;
  zero Error/Warning.

## Files changed
- `gui/templates/cfields/cfieldsView.html` — 2 one-line escapes (fix run; the
  cell/actions hardening was already in `b7aaee02e`).
- `tmp/TLU_Test_Cases.md` — Regression — Issue #1561 suite (5/5 PASS).
- `CHANGELOG` — KEY BUGFIX (security) entry.
- This doc + wiki mirror.

## Result
Issue #1561 closed as fixed: primary vector (cells + delete attr) landed in
`b7aaee02e`, residual meta-select gap closed on branch `fix/issue-1561`; the five
regression checks pass error-free.