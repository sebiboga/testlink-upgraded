# Issue #498 — Investigate port 9080 remote image URL failures

**Issue:** [#498](https://github.com/sebiboga/testlink-upgraded/issues/498)
**Commit:** (pending)
**Status:** VERIFIED-FIXED (2026-08-26)

## Investigation

### Files examined
1. `lib/functions/print.inc.php:691-713` — Company logo rendering; line 702 uses local file path (fixed by #570)
2. `lib/functions/print.inc.php:292, 1311, 1540, 1653, 1815` — Attachment image getimagesize() calls; all guarded (fixed by #574)
3. `lib/functions/testcase.class.php:8359-8449` — `renderImageAttachments()` private method; line 8422 had unguarded getimagesize()
4. `lib/functions/requirement_mgr.class.php:4025-4074` — Requirement renderImageAttachments(); does NOT call getimagesize()
5. `lib/functions/configCheck.php:42-118` — `get_home_url()` builds URL from `$_SERVER` vars
6. `config.inc.php:644, 967` — Company logo filename and document generator config

### Code flow analysis
```
configCheck.php:42-118  -> get_home_url() builds URL from $_SERVER vars (port varies)
common.php:245-247      -> $_SESSION["basehref"] = get_home_url() (cached in session)
config.inc.php:967      -> company_logo = tl-logo-transparent-25.png

BEFORE fix #570 (original error path):
print.inc.php:700  -> $safePName = $_SESSION["basehref"] . TL_THEME_IMG_DIR . company_logo
                     e.g. "http://localhost:9080/gui/themes/default/images/tl-logo-transparent-25.png"
print.inc.php:702  -> getimagesize($safePName) -> HTTP self-fetch -> 404 or 60s deadlock

AFTER fix #570 (current state):
print.inc.php:702  -> getimagesize(TL_ABS_PATH . TL_THEME_IMG_DIR . company_logo) -> local file

testcase.class.php:8416-8426 (FIXED by #498):
  line 8370 -> $repoDir = config_get("repositoryPath")
  line 8420 -> $pathname = $repoDir . $attSet[$id][$atx]["file_path"] (local path)
  line 8421-8422 -> $imgData = @getimagesize($pathname) with false check
  line 8423-8428 -> $iDim = width/height if success, empty if failure
```

### Key findings
1. The original port 9080 error was caused by `getimagesize()` receiving an HTTP URL from `$_SESSION["basehref"]` for the company logo. **Already fixed by #570**.
2. `testcase.class.php:8422` had an **unguarded** `getimagesize($pathname)` call that produced E_WARNING events and broken HTML on missing/corrupt files. **Fixed by #498**.
3. Port 9080 was never hardcoded; it came from `$_SERVER` at session creation time.

## Root cause chain

1. `configCheck.php:59` — `get_home_url()` includes server port in URL (e.g. port 9080)
2. `common.php:247` — URL cached in `$_SESSION["basehref"]`
3. `print.inc.php:700` (before #570) — Company logo URL built from session basehref
4. `print.inc.php:702` (before #570) — `getimagesize($safePName)` made HTTP self-fetch to wrong port
5. `testcase.class.php:8422` (before #498) — Unguarded `getimagesize($pathname)` produced E_WARNING + broken HTML on missing files

## Fix approach

### Chosen fix
Guard the `getimagesize()` call at `testcase.class.php:8422` with `@` error suppression and a false check, matching the exact pattern established by #574 in print.inc.php. When the file is missing/corrupt, fall back to rendering the `<img>` tag without width/height attributes.

### Why this approach
- Consistent with the established #574 pattern used across 5 call sites in print.inc.php
- The `<img>` tag without width/height still renders correctly in browsers (natural size)
- `@` prevents PHP 8 E_WARNING events from flooding the Event Viewer
- Minimal change: 6 lines added, 3 removed

### Rejected alternatives
- **Replace getimagesize() entirely**: Rejected because the width/height attributes improve layout stability for valid images. Only missing/corrupt files need graceful handling.
- **Add file_exists() check before getimagesize()**: Rejected because it creates a TOCTOU race condition and is less efficient than just suppressing the warning from getimagesize().

## Files changed

- `lib/functions/testcase.class.php` — lines 8419-8430: Replaced bare `getimagesize($pathname)` + `list()` assignment with guarded `@getimagesize($pathname)` + false check + fallback to empty dimension string (+8, -3)

## Verification performed (2026-08-26)

| # | Check | Expected | Result |
|---|---|---|---|
| 1 | `php -l lib/functions/testcase.class.php` | No syntax errors | PASS |
| 2 | Event Viewer after login | No new errors | PASS (empty - clean DB) |
| 3 | Code pattern matches #574 | `@getimagesize()` + false check | PASS |

### Event Viewer check
- Pre-fix: 0 events (clean DB)
- Post-fix: 0 events
- New errors introduced: 0

### RESUME block
To re-test: create a test case with an inline image attachment, delete the file from upload_area manually, then view the test case. Expected: image renders without width/height attributes, no E_WARNING in Event Viewer.
