# Bugfix — Issue #1554: auth/login.html success fallback redirects to a 404

## Problem

A successful login on the modern screen
(`http://localhost:8082/gui/templates/auth/login.html`) with **no** `destination`
query parameter landed on a **404** at
`/gui/templates/auth/index.php?caller=login` instead of the root app
`/index.php?caller=login`. Every destination-less login hit this.

Legacy `login.php` redirects to `$_SESSION['basehref'] . "index.php?caller=login&viewer=..."`
(`login.php:452-455`) which works.

## Root Cause

`gui/templates/auth/login.html:228` used a **relative** URL as the no-destination
fallback: `window.location.href = 'index.php?caller=login';`.

Because the modern page is served at the URL path `/gui/templates/auth/login.html`,
the browser resolves that relative URL against the directory `/gui/templates/auth/`
→ `/gui/templates/auth/index.php?caller=login`, which is not a served file → 404.

The backend is not involved: `api/auth/index.php:214` runs
`$destination = safeDestination($body['destination'] ?? '')`, and
`safeDestination('')` returns `''` (`api/auth/index.php:59-61`), echoed at
`api/auth/index.php:244` as `'destination' => ''`. `safeDestination()` only
accepts root-relative paths, so it could never produce that relative target —
the defect is purely the client-side fallback literal.

The bug is masked on the `/login.php` entry point because there `login.php:383`
serves the **same** `login.html` via `readfile()`, and in that context the base
directory is `/`, so relative `index.php` resolves to `/index.php` and works.
Only the direct modern entry `/gui/templates/auth/login.html` exposes it.

Introduced with the screen's modernization: commit `6e193c574`
("feat(auth): modernize login screen — Dashio HTML + BFF wiring + i18n keys").

## Fix

**File:** `gui/templates/auth/login.html:228`

```diff
-          window.location.href = 'index.php?caller=login';
+          window.location.href = '/index.php?caller=login';
```

One line, root-absolute target. It works from **both** serving contexts:
`/gui/templates/auth/login.html` (resolves to docroot `/index.php`) and `/login.php`
(readfile, base `/` — behaviour unchanged). It matches the convention already used
everywhere else on this screen (`/api/...`, `/gui/...`) and in the sibling modern
auth pages (commit b895957f5, Refs #1338).

**Alternatives considered and rejected:** computing a base-href aware target from
PHP — the whole modernized stack hardcodes root-absolute `/api` + `/gui` URLs, so a
root-relative fallback matches the established repo-root deployment contract; the
modern screen already ignores `$_SESSION['basehref']` for every other URL it emits.

No backend change. No i18n key touched (no user-facing string).

## Blast Radius

`grep "window.location.href = 'index" gui/templates/**` → exactly 1 match (this
fallback). No other modern screen hardcodes a relative post-action jump; the rest
already use `/`-prefixed URLs. `safeDestination()` is untouched — the server-side
open-redirect guard keeps its full behaviour.

## Regression Testing

Regression suite `Regression — Issue #1554` in `tmp/TLU_Test_Cases.md` (6 cases),
executed live in headless Chrome (incognito, fresh DB import):
- 1554.1 no-destination login → `/index.php?caller=login`, full shell renders (PASS);
- 1554.2 valid destination `/gui/templates/mainpage/mainPage.html` still followed (PASS);
- 1554.3 hostile `javascript:` destination still sanitized → safe fallback (PASS);
- 1554.4 wrong password → error box, no redirect (PASS);
- 1554.5 legacy `/login.php` readfile entry unaffected (PASS);
- 1554.6 events table: only log_level=16 AUDIT rows, zero WARNING/error (PASS).

Result: 6/6 PASS.

Screenshots:
`docs/screenshots/issue-1554-login-prefix-404.png` (pre-fix symptom) and
`docs/screenshots/issue-1554-login-postfix-landing.png` (post-fix landing).

## Files changed

- `gui/templates/auth/login.html` (+1/-1)
- `docs/screenshots/issue-1554-login-prefix-404.png`, `docs/screenshots/issue-1554-login-postfix-landing.png`
- `tmp/TLU_Test_Cases.md` (suite `Regression — Issue #1554`)