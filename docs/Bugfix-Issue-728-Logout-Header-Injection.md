# Issue 728 — logout.php: Session destruction security fixes

**Issue:** [#728](https://github.com/sebiboga/testlink-upgraded/issues/728)
**Commit:** `fb04bc108`
**Status:** VERIFIED-FIXED (2026-08-26)

## Investigation

### Files examined

1. **`logout.php`** (52 lines) — The logout handler. Destroys session, logs audit
   event, redirects to login page or SSO logout destination.

2. **`lib/functions/common.php:565-575`** — The `redirect()` helper function.
   Uses `addslashes()` + JavaScript `location.href` redirect pattern. Called from
   27+ locations across the codebase.

3. **`index.php:153`**, **`lib/general/navBar.php:233`** — Generate logout links
   that point to `logout.php`.

4. **`lib/functions/doAuthorize.php:176,236,442`** — Generate logout links in
   authentication flow.

5. **`lib/usermanagement/usersEdit.php:209`** — Redirects to `logout.php` after
   user deletion.

6. **`config.inc.php`** — Provides `logoutUrl` configuration value used in
   `logout.php:29`.

### Code flow analysis

```
logout.php:10-12  → Load config, init DB
logout.php:14-17  → Log audit event if user was logged in
logout.php:18-19  → session_unset() + session_destroy()
logout.php:21-24  → SSO branch: redirect($authCfg['SSO_logout_destination'])
logout.php:25-39  → Non-SSO branch:
  logout.php:26-27 → Build standard login URL with viewer param
  logout.php:29-30 → Get logoutUrl from config, fallback to standard URL
  logout.php:32-36 → Security fixes applied here (THIS FIX)
  logout.php:38-39 → header("Location: " . $lo) + exit
logout.php:42-56  → init_args() function
```

### Key findings from investigation

1. **Line 36 `exit();` was unreachable** — The else block (lines 25-35) ends with
   `exit;` on line 34. Line 36's `exit();` was outside the else block but after
   the if-else structure, making it dead code.

2. **JavaScript redirect was already fixed** — The code already uses
   `header("Location: ...")` instead of JS `location.href`. The issue's
   recommendation #1 was already implemented.

3. **`logoutUrl` config value was unvalidated** — Line 29-30:
   ```php
   $xx = config_get('logoutUrl');
   $lo = is_null($xx) || trim($xx) == '' ? $std : $xx;
   ```
   The `$lo` variable was passed directly to `header("Location: " . $lo)` on
   line 33 without any sanitization.

4. **`redirect()` function uses vulnerable pattern** — `common.php:565-575`:
   ```php
   function redirect($url, $level = 'location') {
     $safeUrl = addslashes($url);
     echo "<html><head></head><body>";
     echo "<script type='text/javascript'>";
     echo "$level.href='$safeUrl';";
     echo "</script></body></html>";
     exit;
   }
   ```
   This is a systemic issue (tracked in Issue #706), not specific to logout.php.

## Root cause chain & fix approach (the HOW)

### Fix #1: Remove unreachable `exit()`

**Root cause:** The code structure was:
```php
if (SSO_enabled) {
  redirect($authCfg['SSO_logout_destination']);
} else {
  // ... build URL ...
  header("Location: " . $lo);
  exit;        // ← line 34: exits here
}
exit();        // ← line 36: UNREACHABLE
```

The second `exit();` on line 36 was unreachable because:
- If SSO is enabled: `redirect()` calls `exit` internally
- If SSO is not enabled: the else block exits on line 34

**Fix:** Removed the duplicate `exit();` on line 36. This is dead code that
indicates sloppy editing and can confuse future maintainers.

**Why this approach:** Simple removal. No behavior change — the code was already
working correctly. The dead `exit()` was just noise.

### Fix #2: Header redirect (verified, no change needed)

**Root cause:** N/A — the code already uses `header("Location: ...")` instead
of JavaScript redirect. This was likely fixed in a prior commit.

**Fix:** No changes needed. Verified the implementation is correct:
- `header("Location: " . $lo)` sends a proper HTTP 302 redirect
- `exit;` ensures no further output
- No HTML/JS response body that could be exploited

### Fix #3: Prevent header injection via `logoutUrl` config

**Root cause:** `config_get('logoutUrl')` returns a value from the application
configuration. If an attacker gains write access to the config (e.g., through
file inclusion, compromised admin panel, or exposed config file), they could
set `logoutUrl` to a malicious value.

**Attack vector 1 — Header injection:**
```
logoutUrl = "http://testlink.example.com\r\nX-Injected: true\r\n\r\n<script>alert(1)</script>"
```
The `\r\n` characters would terminate the Location header and inject arbitrary
HTTP headers or response body.

**Attack vector 2 — Open redirect:**
```
logoutUrl = "javascript:alert(document.cookie)"
```
While `header("Location: javascript:...")` is not a standard redirect, some
browsers may handle it differently. More importantly, any non-HTTP URL is
unexpected behavior.

**Attack vector 3 — Protocol-relative URL:**
```
logoutUrl = "//attacker.example.com/steal-cookies"
```
This would redirect to `attacker.example.com` using the current protocol.

**Fix:** Added two security checks before the redirect:
```php
// Security: strip CR/LF to prevent header injection, validate URL
$lo = str_replace(array("\r", "\n"), '', $lo);
if (!preg_match('#^https?://#i', $lo) && $lo !== $std) {
  $lo = $std;
}
```

1. **Strip CR/LF:** `str_replace(array("\r", "\n"), '', $lo)` removes any
   carriage return or line feed characters that could inject headers.

2. **Validate URL format:** `preg_match('#^https?://#i', $lo)` ensures the URL
   starts with `http://` or `https://`. If not, falls back to the standard
   login redirect URL (`$std`).

**Why this approach:**
- **Minimal change:** Only adds 4 lines, doesn't restructure the code
- **Defense in depth:** Both checks together prevent all three attack vectors
- **Graceful fallback:** If the URL is invalid, logout still works (redirects to
  standard login page)
- **No false positives:** Legitimate `logoutUrl` values (which must be HTTP/HTTPS
  URLs) pass the check unchanged

**Alternative approaches considered:**
1. **`filter_var($lo, FILTER_VALIDATE_URL)`** — More comprehensive URL validation,
   but overkill for this use case. The regex check is sufficient and more readable.
2. **Use `urlencode()`** — Would break the URL (encodes `://`, `/`, etc.)
3. **Use `addslashes()`** — SQL-quoting function, not appropriate for HTTP headers

### Fix #4: CSP headers (not applicable)

**Root cause:** After switching to `header("Location: ...")`, no HTML is output
on this page. The browser receives a 302 redirect and follows it immediately.

**Fix:** No changes needed. CSP headers protect against inline script injection
in HTML responses. Since `logout.php` produces no HTML body, CSP provides no
benefit here.

**Note:** CSP would be relevant if the code still used the `redirect()` function
from `common.php` (which outputs `<script>` tags). However, that's a systemic
issue tracked in Issue #706.

## Files changed

- **`logout.php`** — lines 32-36:
  - Added CR/LF stripping: `$lo = str_replace(array("\r", "\n"), '', $lo);`
  - Added URL validation: `if (!preg_match('#^https?://#i', $lo) && $lo !== $std)`
  - Added fallback: `$lo = $std;` if URL is invalid
  - Removed unreachable `exit();` on line 36

## Verification performed (2026-08-26)

### Test case 1: Normal logout (no logoutUrl configured)

**Steps:**
1. Set `logoutUrl` to empty/null in config
2. Login as any user
3. Click logout

**Expected:** Redirect to `login.php?note=logout&viewer=<username>`
**Result:** ✅ PASS — Standard login URL used correctly

### Test case 2: Normal logout with viewer parameter

**Steps:**
1. Login as user "admin"
2. Navigate to `logout.php?viewer=admin`

**Expected:** Redirect to `login.php?note=logout&viewer=admin`
**Result:** ✅ PASS — Viewer parameter passed through correctly

### Test case 3: Logout with ssodisable flag

**Steps:**
1. Login with SSO disabled
2. Logout

**Expected:** Redirect to `login.php?note=logout&viewer=<username>&ssodisable`
**Result:** ✅ PASS — `&ssodisable` appended correctly

### Test case 4: SSO logout (SSO_enabled=true)

**Steps:**
1. Configure `authentication.SSO_enabled = true`
2. Login via SSO
3. Logout

**Expected:** Redirect to `SSO_logout_destination` from config
**Result:** ✅ PASS — SSO redirect used correctly

### Test case 5: Valid logoutUrl (https://)

**Steps:**
1. Set `logoutUrl = "https://example.com/logged-out"` in config
2. Login and logout

**Expected:** Redirect to `https://example.com/logged-out`
**Result:** ✅ PASS — Configured URL used correctly

### Test case 6: Header injection attempt (CR/LF in logoutUrl)

**Steps:**
1. Set `logoutUrl = "http://evil.com\r\nX-Injected: true"` in config
2. Login and logout

**Expected:** CR/LF stripped, redirect to `http://evil.comX-Injected: true`
**Result:** ✅ PASS — Characters stripped, no header injection possible

### Test case 7: Invalid URL format (non-HTTP)

**Steps:**
1. Set `logoutUrl = "javascript:alert(1)"` in config
2. Login and logout

**Expected:** Fallback to standard login URL
**Result:** ✅ PASS — Invalid URL rejected, standard redirect used

### Test case 8: Protocol-relative URL

**Steps:**
1. Set `logoutUrl = "//evil.com/steal"` in config
2. Login and logout

**Expected:** Fallback to standard login URL
**Result:** ✅ PASS — Protocol-relative URL rejected

### Test case 9: Event Viewer check

**Steps:**
1. Perform all logout scenarios above
2. Check Event Viewer for new errors

**Expected:** No new error/warning entries
**Result:** ✅ PASS — No new events logged

Regression suite: `tmp/TLU_Test_Cases.md` — Issue #728 test suite.

## Notes

- The `redirect()` function in `lib/functions/common.php:565` still uses the
  vulnerable `addslashes + JS redirect` pattern. This is a systemic issue
  tracked separately (see Issue #706). Four callers require `top.location`
  (frame-breaking redirects), which cannot be replaced with PHP
  `header("Location:")`.

- The SSO branch (line 24) still calls `redirect()` which outputs JS. This
  should be addressed when the systemic `redirect()` fix is implemented.

- The `getDisplayName()` call on line 46 (`init_args()`) may fail if the session
  is corrupted. However, this is called before `session_destroy()`, so the
  session should still be valid at that point.
