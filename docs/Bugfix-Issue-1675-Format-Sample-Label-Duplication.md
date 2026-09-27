# Bugfix — Issue #1675: the "Format sample" caption duplicated on every Format switch

**Issue:** [#1675](https://github.com/sebiboga/testlink-upgraded/issues/1675) — *keywordsExport.html: 'Format sample' label duplicates on every Format switch (Export + Import panel)*
**Commit:** `addddfcf6` on `fix/issue-1675`
**Affected screen:** Keyword Export / Import (`gui/templates/keywords/keywordsExport.html`) — the popup modernized in #1615
**Regression suite:** `tmp/TLU_Test_Cases.md` → *Regression — Issue #1675* (13 cases, 13 PASS)
**Severity:** cosmetic / minor — the export and import payloads were never affected

---

## 1. Symptom

Every time the **Format** select was changed, the *"Format sample"* caption above
the sample was duplicated, in **both** the Export and the Import panel. It kept
growing: one click → two captions, two clicks → three, and so on. The sample text
itself was refreshed correctly, so the panel looked progressively broken while
remaining functionally correct.

The stray nodes disappear on the next full `render()` (i.e. a page refresh), which
makes the defect look transient and easy to dismiss as a rendering glitch.

## 2. Reproduction (pre-fix, measured on the pre-fix commit)

```js
// on http://localhost:8082/gui/templates/keywords/keywordsExport.html?mode=export&tproject_id=9001
var s = document.querySelector('#exportType');
// count the captions in this panel
Array.from(s.closest('.card-b').querySelectorAll('label'))
  .filter(function (l) { return l.textContent.trim() === 'Format sample'; }).length;
```

| `change` events on the Format select | 0 | 1 | 2 | 3 |
|---|---|---|---|---|
| Export panel (`#exportType`) | 1 | **2** | **3** | **4** |
| Import panel (`#importType`) | 1 | **2** | **3** | **4** |

`pre.sample` stayed at **1** throughout, and each switch fired **no** network
request, produced **no** console message and added **no** `events` row — a purely
client-side DOM-logic defect, invisible server-side.

## 3. Root cause

`formatSample()` produced the caption and the sample as **two sibling nodes**
(`gui/templates/keywords/keywordsExport.html:179`):

```js
return '<label style="margin-top:16px">' + esc(t('kwxml.formatSample')) + '</label>' +
  '<pre class="sample">' + esc(d) + '</pre>';
```

but both refresh paths only looked up the `<pre>` and replaced *that single node*:

```js
// renderImportSample() :241  and  exportTypeChanged() :260
var box  = $('#exportType').closest('.card-b');
var old  = box.find('pre.sample');        // finds the <pre> only, never its label
var html = formatSample(...);              // LABEL + PRE
if (old.length) { old.replaceWith(html ? $(html) : ''); }   // PRE swapped, old LABEL survives
```

`old.replaceWith($(html))` detaches the `<pre>` and inserts the new pair **in its
place**. The `<label>` that used to sit *before* `old` is not part of the
replacement, so it survives — and a brand-new `<label>` arrives with the inserted
fragment. Net effect: captions `n → n+1`, samples stay `1`.

Measured confirmation of the mechanism: `$(html)` parses into **2** elements
(`["LABEL.", "PRE.sample"]`), so a `pre.sample`-scoped lookup can never reach the
sibling caption.

The two panels duplicate **independently** — each has its own `.card-b` and
`box.find(...)` is scoped to it — so a user who used both tabs could see up to
four captions in one panel.

### History

**Not a #1007 regression.** `git log --follow` shows `formatSample()` was born with
the two-node return value *together with* the `pre.sample`-only `replaceWith()`, in
the commit that created the modernized popup (#1615). The initial render is correct
(both panels call `formatSample()` inline while building their HTML, `:208` / `:229`),
which is exactly why the defect only appears from the *first* format switch on.

The `else` fallback in both handlers — `box.find('a.doc-link').after(html)`, the
insertion anchor #1007 corrected from `#exportType` — is **unreachable** today:
`getSupportedSerializationFormatDescriptions()` always returns both XML and CSV, so
a sample block always exists. It was kept (not deleted as dead code) because it is
the correct behaviour for a format that has no description.

## 4. Blast radius

- **Files:** `gui/templates/keywords/keywordsExport.html` only.
- **Symbols:** `formatSample()` (`:179`), `renderImportSample()` (`:239`),
  `exportTypeChanged()` (`:252`). Grep over `gui/`, `api/`, `lib/` matches no other
  file — nothing else calls them.
- **Data:** none. The sample text, the `File name` ⇄ extension coupling
  (`exportTypeChanged` `:256-258`) and both export/import payloads are unaffected.

## 5. The fix

Give the caption and the sample a single wrapper so the two refresh paths have one
handle and can replace the pair as a unit.

```js
// formatSample()
return '<div class="sample-block">' +
  '<label style="margin-top:16px">' + esc(t('kwxml.formatSample')) + '</label>' +
  '<pre class="sample">' + esc(d) + '</pre></div>';

// renderImportSample() :250  and  exportTypeChanged() :272
var old = box.find('div.sample-block');    // was box.find('pre.sample')
```

Three functional lines changed, plus explanatory comments. **No CSS, no new i18n key,
no BFF/API change, no other file touched.** The `if (!d) return ''` guard is
preserved, so a format without a description still yields no block at all.

### Why a wrapper, and what was rejected

- **Add a class to the label and remove it next to the `<pre>`**
  (`.prev()` / `addBack()`): keeps the DOM flat, but couples both handlers to
  sibling *position* — it breaks the moment anything is ever inserted between
  caption and sample, and it needs two `remove()` calls at three call sites. The
  wrapper is a smaller diff with a strictly tighter invariant: "caption + sample are
  one node".
- **Update only the caption's text and leave it in place**: the cheapest diff, but
  then the two handlers treat the pair asymmetrically and the "sample disappeared"
  branch (`html === ''`) would leave a dangling caption with no sample under it.
  The wrapper keeps one code path for both.
- **Re-render the whole panel on change**: hides the bug behind a full `render()`,
  but throws away focus, scroll position and the typed `File name` value.

### The one real risk, and how it was ruled out

The new wrapper is an unstyled block, so the caption's `margin-top:16px` now
*collapses into* the wrapper's top margin — CSS could in principle have shifted the
panel. Measured by serving the **pre-fix** file side by side as a temporary copy
(`git show HEAD:…` → `gui/templates/keywords/_prefix_1675_tmp.html`, deleted
afterwards, never committed) and comparing `getBoundingClientRect()` on a fresh,
never-interacted Export panel:

| anchor | pre-fix top / height | post-fix top / height |
|---|---|---|
| `a.doc-link` | 404 / 17 | **404 / 17** |
| caption `<label>` | 437 / 17 | **437 / 17** |
| `pre.sample` | 462 / 38 | **462 / 38** |
| `label[for="exportFilename"]` | 516 / 17 | **516 / 17** |
| `#exportFilename` input | 538 / 37 | **538 / 37** |

Pixel-identical — the wrapper introduces no spacing shift.

## 6. Verification

Regression suite **13/13 PASS** (`tmp/TLU_Test_Cases.md` → *Regression — Issue #1675*):

| Area | Result |
|---|---|
| Export panel: 5 repeated changes, 10 alternating XML↔CSV | exactly **1** caption, 1 block, 1 `pre`; sample text tracks the format |
| Import panel: 5 repeated changes, 10 alternating XML↔CSV | same |
| Document-wide sweep after 10 switches | `1` caption and `1` `div.sample-block` in the whole document |
| DOM order | `a.doc-link.nextElementSibling === div.sample-block` → `true` on both panels (legacy order preserved) |
| Initial load, no interaction | 1 caption + 1 `pre` per panel (unchanged from before) |
| `File name` coupling | `my-keywords` (no known ext) left alone; `keywords.xml` → `keywords.csv`; empty → `keywords.xml` |
| Real export, both formats | `200 text/xml` with the CDATA notes; `200 text/csv` with `Keyword;Notes;Number of Test Case Linked` |
| Real CSV import round-trip | green banner *"Keywords imported. The project now has 3 keywords."*, counter `1 → 3`, panel re-renders with 1 caption |
| Locale `Română` | caption reads **"Exemplu de format"**, 1 block after 4 more switches, no raw `kwxml.formatSample` key leaks |
| `node --check` on the extracted inline `<script>` | clean |
| Browser console (`error`/`warn`) | *no console messages found* |
| Event Viewer / `events` table | 4 rows, all `log_level = 16` (AUDIT), `log_level IN (1,2)` → **0** ERROR/WARNING |

## 7. Out of scope — filed, not fixed

The CSV import used in the round-trip above created **3** keywords from a **1-row**
file: the header line `Keyword;Notes;Number of Test Case Linked` was written as a
keyword. That is the already-filed **#1616**
(`importKeywordsFromCSV()` does not skip the header row its own export writes) and
reproduces on the pre-fix file too — deliberately untouched by this diff.

## 8. Screenshots

- Before — four stacked captions in the Import panel:
  `docs/screenshots/issue-1675-format-sample-label-duplicated.png`
- After — five format switches later, still exactly one caption:
  `docs/screenshots/issue-1675-format-sample-label-fixed.png`

## 9. RESUME

```bash
git checkout fix/issue-1675
# run in the page console on the Export panel of keywordsExport.html:
#   s = document.querySelector('#exportType');
#   for (var i=0;i<9;i++) s.dispatchEvent(new Event('change',{bubbles:true}));
#   document.querySelectorAll('div.sample-block').length                    // -> 1  (pre-fix: 10 captions)
#   s.closest('.card-b').querySelectorAll('label[style*="margin-top:16px"]').length   // -> 1
# browser: admin/admin -> http://localhost:8082/gui/templates/keywords/keywordsExport.html?mode=export&tproject_id=9001
```
