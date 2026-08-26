# Issue 706 — XSS: addslashes bypass + raw output + weak filter

**Issue:** [#706](https://github.com/sebiboga/testlink-upgraded/issues/706)
**Branch:** `fix/issue-706-xss`
**Status:** VERIFIED-FIXED (2026-08-25)

## Symptom

Three Cross-Site Scripting (XSS) vulnerabilities:

1. **logout.php:34-36** — `addslashes()` used to sanitize a URL placed inside a
   `<script>` block. `addslashes` escapes `'`, `"`, `\`, NUL but NOT `<`, `>`, `/`.
   An attacker injects `</script><script>alert(1)</script>` via the `viewer` GET
   parameter to break out of the script block and execute arbitrary JS.

2. **lnl.php:137** — `$args->type` (user-controlled from `$_REQUEST['type']`)
   is concatenated directly into an `echo` statement with zero escaping:
   `echo 'ABORTING - UNKNOWN TYPE:' . $args->type;`

3. **index.php:124** — The `reqURI` filter only blocklists `javascript` (via
   `stripos`), missing `data:`, `vbscript:`, whitespace-split variants like
   `java\tscript:`, and URL-encoded variants. The value is placed unsanitized
   into an iframe `src` attribute.

## Root cause chain & fix approach (the HOW)

### XSS 1 — logout.php

**Root cause:** The code used a JS redirect (`location.href='...'`) with
`addslashes()` for sanitization. `addslashes` is a SQL-quoting function, not
an XSS mitigation. The `</script>` sequence passes through `addslashes` unchanged.

**Fix:** Replaced the entire JS redirect block with a PHP `header("Location: ...")`
call. This eliminates the HTML/JS injection surface entirely — the browser follows
the `Location` header without rendering any response body.

**Why this approach:** PHP header redirects are the standard, secure way to perform
server-side redirects. The `redirect()` helper in `common.php` also exists but uses
the same vulnerable `addslashes+JS` pattern (a systemic issue for a separate fix).
The inline `header()` approach was chosen for minimal, targeted change.

### XSS 2 — lnl.php

**Root cause:** Line 137 uses raw string concatenation into `echo`:
`echo 'ABORTING - UNKNOWN TYPE:' . $args->type;` The `type` parameter goes through
`tlInputParameter::STRING_N` which only validates max length (30 chars), not
HTML safety.

**Fix:** Wrapped `$args->type` in `htmlspecialchars($args->type, ENT_QUOTES, 'UTF-8')`
at line 137. This is the standard PHP approach for outputting user data into HTML.

### XSS 3 — index.php

**Root cause:** The blocklist check `stripos($args->reqURI,'javascript')` only
catches the exact substring `javascript`. It misses:
- `data:` URIs (can execute inline HTML/JS)
- `vbscript:` (IE/Edge legacy)
- Whitespace-split variants: `java\tscript:`, `java\nscript:`
- URL-encoded: `javascript%3A` (stripos matches, but others don't)

**Fix:** Replaced blocklist with allowlist: only accept `reqURI` values starting
with `lib/` or `gui/` (all valid TestLink page paths). Additionally:
- Reject any URI containing `:`, `@`, or `\` (catches absolute URLs, scheme URIs)
- Added `htmlspecialchars()` on `$gui->mainframe` assignment (defense-in-depth for
  the iframe `src` attribute)

**Why allowlist over blocklist:** Blocklists are inherently incomplete — every new
attack vector requires a new filter entry. Allowlists are closed by definition:
anything not explicitly permitted is rejected. All legitimate TestLink pages live
under `lib/` or `gui/`.

## Files changed

- `logout.php` — lines 33-36: removed addslashes+JS echo, replaced with `header("Location: ...")`
- `lnl.php` — line 137: wrapped `$args->type` in `htmlspecialchars()`
- `index.php` — lines 122-126: replaced blocklist with allowlist check
- `index.php` — line 144: added `htmlspecialchars()` to `$gui->mainframe` assignment

## Verification performed (2026-08-25)

| Check | Result |
|---|---|
| logout.php `</script>` injection | HTTP 302 redirect, no JS body output |
| logout.php normal logout | Redirects to login.php correctly |
| logout.php viewer param passthrough | `viewer=test` → `login.php?note=logout&viewer=test` |
| lnl.php `<script>` in type param | Output: `&lt;script&gt;alert(1)&lt;/script&gt;` |
| lnl.php unknown type error message | Displays escaped type string |
| index.php `data:` URI | Falls back to mainPage.php |
| index.php `vbscript:` URI | Falls back to mainPage.php |
| index.php `java[TAB]script:` | Falls back to mainPage.php |
| index.php `javascript%3A` | Falls back to mainPage.php |
| index.php absolute URL | Falls back to mainPage.php |
| index.php normal path (`lib/...`) | Renders correctly |
| index.php `returnFeature` parameter | Resolves to frmWorkArea.php |
| index.php no reqURI | Shows default mainPage.php |
| Event Viewer after all tests | No new errors (pre-existing `$key` warning only) |

Regression suite: `tmp/TLU_Test_Cases.md` → "Suite 38: Regression — Issue #706" (**14/14 PASS**).

## Note: systemic issue in `redirect()` helper

The `redirect()` function in `lib/functions/common.php:565` uses the same
vulnerable pattern (`addslashes` + JS redirect). Four callers require
`top.location` (frame-breaking redirects), which cannot be replaced with PHP
`header("Location:")`. This should be tracked as a separate issue for a
comprehensive fix across all 27+ call sites.
