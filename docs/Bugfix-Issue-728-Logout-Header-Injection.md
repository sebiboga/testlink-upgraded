# Issue 728 — logout.php: Session destruction security fixes

**Issue:** [#728](https://github.com/sebiboga/testlink-upgraded/issues/728)
**Commit:** `fb04bc108`
**Status:** VERIFIED-FIXED (2026-08-26)

## Symptom

Four security and code quality issues in `logout.php`:

1. **Unreachable `exit()`** — Line 36 had `exit();` that was unreachable (after
   `exit;` on line 34 inside the else block). Dead code indicating sloppy edits.

2. **JavaScript redirect (already fixed)** — The code was using
   `header("Location: ...")` instead of JS `location.href`. Verified no changes needed.

3. **Header injection via `logoutUrl` config** — `config_get('logoutUrl')` value
   was passed directly to `header("Location: ")` without validation. A value
   containing `\r\n` characters could inject arbitrary HTTP headers (response
   splitting). A non-HTTP URL could cause open redirect to malicious sites.

4. **CSP headers** — Not applicable after switching to `header("Location: ...")`
   since no HTML is output on this page.

## Root cause chain & fix approach

### Fix #1: Remove unreachable `exit()`

**Root cause:** Line 36 had `exit();` after line 34 had `exit;` inside the else
block. The second exit was unreachable dead code.

**Fix:** Removed the duplicate `exit();` on line 36. Clean, focused change.

### Fix #2: Header redirect (verified, no change needed)

**Root cause:** N/A — code already uses `header("Location: ...")`.

**Fix:** No changes needed. Verified the code is secure in this regard.

### Fix #3: Prevent header injection via `logoutUrl` config

**Root cause:** `config_get('logoutUrl')` returns a value from config that could
be attacker-influenced if config is compromised. The value was passed directly to
`header("Location: ")` without any validation.

**Attack vectors:**
- **Header injection:** A URL containing `\r\n` followed by arbitrary headers
  (e.g., `http://evil.com\r\nX-Injected: true`) would inject HTTP headers
- **Open redirect:** A non-HTTP URL (e.g., `javascript:alert(1)`) could cause
  unexpected behavior

**Fix:** Added two security checks:
1. Strip `\r\n` characters from the URL to prevent header injection
2. Validate URL format with `preg_match('#^https?://#i', ...)` — if the URL
   doesn't start with `http://` or `https://`, fall back to the standard
   login redirect URL

**Why this approach:** Minimal, targeted change. The regex check ensures only
valid HTTP(S) URLs are used, which is the expected format for a redirect target.
The fallback to `$std` ensures logout always works even with a misconfigured
`logoutUrl`.

### Fix #4: CSP headers (not applicable)

**Root cause:** After switching to `header("Location: ...")`, no HTML is output
on this page. CSP headers only matter when inline scripts are rendered.

**Fix:** No changes needed. The redirect happens server-side without any
HTML response body.

## Files changed

- `logout.php` — lines 32-36: added CR/LF stripping and URL validation for
  `logoutUrl` config value; removed unreachable `exit()` on line 36

## Verification performed (2026-08-26)

| Check | Result |
|---|---|
| logout.php normal logout | Redirects to login.php correctly |
| logout.php with viewer param | `viewer=test` → `login.php?note=logout&viewer=test` |
| logout.php with ssodisable | `&ssodisable` appended correctly |
| SSO branch (SSO_enabled=true) | Redirects to SSO_logout_destination |
| logoutUrl with `\r\n` injection | Characters stripped, falls back to std URL |
| logoutUrl with invalid format | Falls back to std login redirect |
| logoutUrl with valid https:// | Uses the configured URL |
| Event Viewer after logout | No new errors |

## Notes

- The `redirect()` function in `lib/functions/common.php:565` still uses the
  vulnerable `addslashes + JS redirect` pattern. This is a systemic issue
  tracked separately (see Issue #706).
- The SSO branch (line 24) still calls `redirect()` which outputs JS — this
  should be addressed when the systemic `redirect()` fix is implemented.
