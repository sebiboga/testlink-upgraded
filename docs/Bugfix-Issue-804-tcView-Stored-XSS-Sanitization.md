# Bugfix — Issue #804: Stored XSS in Test Case Viewer (tcView.html)

## Symptom

The Test Case Viewer (`gui/templates/testcases/tcView.html`) inserted the WYSIWYG
rich-text test-case fields directly as HTML into the DOM via `.html()`. Any user able
to create/edit a test case could store an HTML/JS payload (e.g. an `<img src=x onerror=...>`
or a `<script>` block) in the summary, preconditions, or step action/expected-result
fields. That payload would then execute in the browser of **every** user who opened the
affected test case — a stored XSS vector (severity: medium, per the original report).

## Root cause

`gui/templates/testcases/tcView.html` builds each version card as an HTML string and
inserts it with `.html()` (`render()` → `wrap.append(renderVersion(v))`). Four rich-text
fields were concatenated **raw**:

- `v.summary` (line ~310)
- `v.preconditions` (line ~312)
- `st.actions` (line ~324) — step action
- `st.expected_results` (line ~325) — step expected result

The BFF `api/testcases/index.php` (action `view`) returns these fields as stored WYSIWYG
HTML. Because they are rich text, they legitimately contain HTML — so a plain `.esc()`
(which escapes everything) would have stripped all formatting and broken the display.
The correct fix is **sanitization** (allow safe HTML, strip scripts/event-handlers).

The codebase already established this exact convention for the same class of field
(rich-text notes): `gui/templates/plans/buildsView.html:317` and
`gui/templates/plans/planView.html:284` both sanitize with DOMPurify 3.0.9 from cdnjs.

## Fix (commit `ba91dea06`)

Changed files: `gui/templates/testcases/tcView.html` (+9 / −4).

1. Loaded DOMPurify 3.0.9 from cdnjs (same URL/version as the existing modernized
   screens): `<script src="https://cdnjs.cloudflare.com/ajax/libs/dompurify/3.0.9/purify.min.js">`.
2. Added a small helper:
   ```js
   function sanitizeRich(s) {
     return DOMPurify.sanitize(String(s == null ? '' : s));
   }
   ```
3. Wrapped the four rich-text insertions with `sanitizeRich(...)`:
   `sanitizeRich(v.summary)`, `sanitizeRich(v.preconditions)`,
   `sanitizeRich(st.actions)`, `sanitizeRich(st.expected_results)`.

### Why this method (and what was rejected)

- **Rejected `esc()` on the rich fields** — would escape all WYSIWYG formatting
  (bold, lists, links) that the rich editor legitimately produces; breaks the display.
- **Chosen DOMPurify** — the established repo convention for rich-text notes
  (`buildsView.html`, `planView.html`). It strips active content (`<script>`,
  `on*` handlers, `javascript:` URLs) while preserving safe markup.

### Files changed

| File | Purpose |
|---|---|
| `gui/templates/testcases/tcView.html` | Add DOMPurify + `sanitizeRich()`; sanitize summary/preconditions/step fields |

## Verification

Fixture `tmp/fixtures_804.php` created test project `RPT804` → suite `Suite 1` → test
case `TC-XSS` (tcase node 3, tcversion node 4) whose fields carry both an XSS payload
(`<img src=x onerror=alert(1)><script>window.__xss804=1</script>` in summary, and the
equivalent in step 1) and benign rich text (bold + list in preconditions).

Opening `tcView.html?tcase_id=3&tcversion_id=4`:

- Summary rendered as `<img src="x">XSS summary` — the `<script>` and `onerror` were
  stripped by DOMPurify; `window.__xss804` stayed **undefined** (no script executed).
- Benign rich text preserved: preconditions still render `<p>`, `<b>bold</b>`,
  `<ul><li>` list; step-1 expected result keeps `<b>expected bold</b>`.
- DOM scan of `#versionsWrap`: no `<script>` tags, no event-handler attributes
  (`onerror/onclick/onload/...`), no `<img ... onerror>`.
- DOMPurify 3.0.9 loads with HTTP 200. The sanitized payload's now-harmless `src=x`
  produces one expected broken-image 404 (`/gui/templates/testcases/x`) with no
  handler attached, so it cannot trigger anything.
- Event Viewer (`events` table): only normal LOGIN/CREATE audit entries; no new
  Error/Warning from viewing the sanitized test case.

Code-review subagent: 5/5 PASS (correct DOMPurify loading, null handling, all four
injection points wrapped, repo convention followed, rich-text rendering preserved).

## Regression test

`tmp/TLU_Test_Cases.md` → **Suite 804 — Regression — Issue #804**. Result: PASS.
