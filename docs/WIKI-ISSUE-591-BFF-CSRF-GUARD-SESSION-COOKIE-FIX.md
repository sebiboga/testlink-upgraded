# BFF CSRF Guard + Session Cookie Hardening

## Issue #591

All mutating BFF endpoints (`POST/PUT/DELETE` under `api/*`) relied on session
cookies alone — no CSRF token and no Origin/X-Requested-With check — and
`doSessionStart()` set cookie params without `HttpOnly`/`SameSite`.

**Verified pre-fix repro** (fresh DB, admin session via curl):

```
curl -b jar -X POST -d '{"active":0}' \
     http://localhost:8082/api/builds/index.php/900003/flags
=> HTTP 200 {"status":"ok"}      # executes under the victim session

# same result with hostile header:
curl -b jar -X POST -H "Origin: http://evil.example" -d '{"active":1}' …
=> HTTP 200 {"status":"ok"}

Set-Cookie: PHPSESSID=…; path=/          # no HttpOnly, no SameSite
```

Browser default `SameSite=Lax` mitigated most cross-site POSTs, but destructive
routes (e.g. build delete removes executions) deserve an explicit defense, and
same-site subdomains / top-level form posts remain a risk surface.

## Root Cause

1. No central CSRF middleware existed for `api/*`; each of the 22 BFF entry
   points only did `doSessionStart()` + session/user lookup + rights checks.
2. `doSessionStart()` (`lib/functions/common.php`) called the PHP 5-style
   positional API `session_set_cookie_params(99999)` which cannot express
   `HttpOnly`/`SameSite`.

## The Fix — approach

Two complementary pieces:

### 1. Central same-origin middleware — `api/_guard.php`

New shared include exposing `bffSameOriginGuard()`. Every BFF entry point calls
it immediately after its session bootstrap. Design decisions:

* **Proof-of-same-origin instead of CSRF tokens.** A synchronizer-token scheme
  would require issuing tokens from ~20 screens and threading them through
  every AJAX call. The Origin/Referer + `X-Requested-With` check gives the
  same protection for browser-driven flows with zero JS changes.
  *Rejected alternative:* token-based OWASP csrfguard reuse (legacy pages use
  it) — too invasive across all modern screens for this iteration.
* **Accept when any of**: `X-Requested-With: XMLHttpRequest` (jQuery `$.ajax`
  sends it automatically on same-origin requests, dropzone too), or an
  `Origin`/`Referer` whose host:port authority equals `HTTP_HOST`
  (case-insensitive, default-port tolerant).
* **Deny otherwise** with JSON `403 {"status":"error","message":"Forbidden:
  missing or mismatched same-origin proof (CSRF protection)"}`. A present but
  mismatched Origin/Referer rejects immediately; absent headers reject after
  the loop. Safe verbs (`GET/HEAD/OPTIONS`) pass untouched.
* Wired into **all 22 entry points** (`api/*/index.php`) right after
  `doSessionStart()` (or after the requires for the two files without a
  session bootstrap), so future BFF files get protection by copying the
  established boilerplate.

### 2. Session cookie hardening — `doSessionStart()`

```php
session_set_cookie_params(array(
    'lifetime' => 99999,
    'path'     => '/',
    'secure'   => auto-detected from $_SERVER['HTTPS'],
    'httponly' => true,
    'samesite' => 'Lax',
));
```

* `HttpOnly` — session id unreachable from JS (XSS exfiltration blocked).
* `SameSite=Lax` — cookie not sent on cross-site POSTs (belt to the guard's
  braces).
* `secure` follows HTTPS detection automatically.
* Array form used since PHP >= 7.3; positional fallback preserved for older
  runtimes. Lifetime/path semantics unchanged → no visible behavior change.

## Verification matrix (post-fix)

| Request | Result |
|---|---|
| POST flags, no headers | **403**, DB unchanged |
| POST flags, `Origin: http://evil.example` | **403** |
| POST flags, evil Referer | **403** |
| POST flags, `X-Requested-With: XMLHttpRequest` | 200, DB updated |
| POST flags, same-origin Origin / Referer | 200 |
| GET list without headers | 200 (safe verbs unaffected) |
| POST /api/users/ without headers | 403 (guard active on all areas) |
| Fresh login Set-Cookie | `…; path=/; HttpOnly; SameSite=Lax` |

Browser regression over `buildsView.html` (jQuery flows): active/open toggles,
edit+save, create, delete all pass unchanged — `$.ajax` already sends
`X-Requested-With`. Legacy form login still works. No new ERROR/WARNING events.

Regression suite: `tmp/TLU_Test_Cases.md` → "Regression — Issue #591" (12/12 PASS).

## Client requirements / deployment notes

* **Scripts and curl-based tooling** that POST/PUT/DELETE against `api/*` must
  now send `-H 'X-Requested-With: XMLHttpRequest'` (or a matching
  `Origin`/`Referer`) or they will get a JSON 403 — do not misdiagnose this as
  an auth bug.
* **No CORS reflection exists anywhere under `api/*`** (verified: no
  `Access-Control-Allow-*` emission, OPTIONS preflight returns 401 without any
  ACAO), so the `X-Requested-With` trust signal cannot be bypassed via an
  approved preflight.
* Behind TLS-terminating proxies `$_SERVER['HTTPS']` may be unset → cookie is
  emitted without `Secure`. If you deploy behind a proxy, either set
  `HTTPS=on` at the server or extend `$secure` to honor a trusted
  `X-Forwarded-Proto`.
* Hosts configured with explicit default ports in `Host`/`Origin`
  (`app.com:443` vs `app.com`) compare unequal by design — fail-closed.

## Files Changed

| File | Purpose |
|---|---|
| `api/_guard.php` | NEW — shared `bffSameOriginGuard()` middleware |
| `lib/functions/common.php` | hardened session cookie params in `doSessionStart()` |
| `api/*/index.php` ×22 | one-line wiring of the guard |
