# Bugfix — Issue #1679: `bffSameOriginGuard()` short-circuited on `X-Requested-With` before validating `Origin`

**Status:** FIXED · verified error-free · branch `fix/issue-1679`
**Files changed:** `api/_guard.php` (+48 / −12) — one function, one file
**Severity:** defense-in-depth (not directly browser-exploitable today — see *Why it is not a live vulnerability*)

---

## 1. The defect

`bffSameOriginGuard()` in `api/_guard.php` is the CSRF control for the whole BFF layer. It returned success the moment it saw the `X-Requested-With: XMLHttpRequest` header, **before** it ever read `Origin` or `Referer`.

Pre-fix code (`api/_guard.php:78-97`):

```php
$xrw = trim((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
if (strcasecmp($xrw, 'XMLHttpRequest') === 0) {
    return;                     // <-- Origin/Referer below are never read
}

$host = bffAuthority($_SERVER['HTTP_HOST'] ?? '');
foreach (array('HTTP_ORIGIN', 'HTTP_REFERER') as $hdr) { ... }
```

Consequence: a request carrying a **foreign** `Origin` was accepted as same-origin. The authoritative same-origin signal was dead code whenever XRW was present.

## 2. Why it is not a live vulnerability (but still worth fixing)

A cross-origin `fetch`/XHR **cannot** set `X-Requested-With` without triggering a CORS preflight, and no BFF endpoint emits `Access-Control-Allow-Origin`, so the preflight fails before the real request is sent. A non-browser client (curl, a server-side script) can forge any header — but it can only ever attack itself.

So the risk is *defense-in-depth*: the guard's own stated intent is to reject foreign origins, and it did not, on exactly the header combination a real attacker would use if the CORS posture ever changed (a future endpoint returning CORS headers, or a same-site/different-subnet deployment).

## 3. Blast radius — why one function mattered

| Measure | Count |
|---|---|
| BFF endpoints calling `bffSameOriginGuard()` | **101** (107 call sites) |
| Files `include`-ing `api/_guard.php` | **102** |
| Endpoints validating `HTTP_ORIGIN` themselves | **0** |

`grep -rn HTTP_ORIGIN api/ --include='*.php' | grep -v _guard.php` returns **nothing** — `api/_guard.php` is the *only* place this rule can be enforced. The ordering inversion therefore disabled CSRF defence for the entire BFF layer, not for one screen.

## 4. Reproduction (pre-fix)

```bash
# authenticated as admin
B=http://localhost:8082/api/ltx/index.php

# the bug: foreign Origin rides in on the XRW hint
curl -s -b cj -X POST "$B?action=init&item=exec" \
     -H "X-Requested-With: XMLHttpRequest" -H "Origin: http://evil.example"
#   -> 405 {"code":"method_not_allowed"}      guard ALLOWED the request

# the control: same request WITHOUT XRW
curl -s -b cj -X POST "$B?action=init&item=exec" -H "Origin: http://evil.example"
#   -> 403 "Forbidden: missing or mismatched same-origin proof (CSRF protection)"
```

The single differing header is the entire story.

### The state-changing proof

On a read-only route a `405` could be argued as cosmetic. On a **write** route the bypassed request reaches business logic and proceeds to mutate:

```bash
curl -s -b cj -X POST "http://localhost:8082/api/builds/index.php?action=create" \
     -H "X-Requested-With: XMLHttpRequest" -H "Origin: http://evil.example"
# pre-fix: 400 {"message":"Invalid test plan id"}
```

The guard answered **neither** 403 nor 401 — the foreign-Origin request was dispatched to the create handler and stopped only by that handler's own business validation. The CSRF layer was fully bypassed on writes.

## 5. The fix

Reorder the checks. `Origin`/`Referer` are validated **first**; `X-Requested-With` survives only as the last-resort fallback for the same-origin fetch case where the browser sent neither header. Three refinements came out of a mandatory code review and are part of the final landed code:

```php
/**
 * Drop a scheme-default port from a "host[:port]" authority so that
 * "localhost:80" and "localhost" compare equal. $defaultPort includes the
 * leading colon. Only the DEFAULT port is removed, so a real port mismatch
 * survives the comparison.
 */
function bffStripDefaultPort($authority, $defaultPort) { ... }

function bffSameOriginGuard() {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($method === 'GET' || $method === 'HEAD' || $method === 'OPTIONS') {
        return;
    }

    // Refs #1679: Origin/Referer are validated FIRST. The X-Requested-With
    // shortcut used to short-circuit before either header was read, so a
    // request carrying a FOREIGN Origin was accepted as same-origin and the
    // authoritative signal was unreachable. XRW is only a hint (any client can
    // set it, browser page JS cannot), Origin is the authoritative signal, so
    // XRW now acts solely as the fallback for the same-origin fetch/XHR case
    // where the browser sent neither header.
    $host = bffAuthority($_SERVER['HTTP_HOST'] ?? '');
    // A URL omits the port when it is the scheme default (Origin/Referer), but
    // HTTP_HOST keeps it, so Host "localhost:80" and Origin "http://localhost"
    // describe the SAME origin and must not be rejected. Strip the scheme's
    // default port from both sides before comparing. Same normalization as
    // api/tcprintlaunch/index.php:247-251. Note this only ever removes the
    // DEFAULT port, so a genuine port mismatch (Origin :80 vs Host :8082) is
    // still rejected.
    $https = strtolower(trim((string)($_SERVER['HTTPS'] ?? '')));
    $defaultPort = ':' . (($https !== '' && $https !== 'off') ? '443' : '80');
    $hostKey = bffStripDefaultPort($host, $defaultPort);
    foreach (array('HTTP_ORIGIN', 'HTTP_REFERER') as $hdr) {
        $val = trim((string)($_SERVER[$hdr] ?? ''));
        if ($val === '') {
            continue;
        }
        $authority = bffAuthority($val);
        if ($authority === '') {
            // A present-but-unparseable Origin/Referer (file:// URL, malformed
            // host, port parse failure) can never be proven same-origin, so it
            // is rejected instead of falling through to the XRW fallback.
            // Browsers only ever send "scheme://host[:port]" or "null", both of
            // which parse, so this cannot reject a genuine browser request.
            bffRejectForbidden();
        }
        if ($hostKey !== '' &&
            strcasecmp(bffStripDefaultPort($authority, $defaultPort), $hostKey) === 0) {
            return;
        }
        bffRejectForbidden();
    }

    // Reached only when the browser sent neither Origin nor Referer: the
    // same-origin fetch/XHR case the XRW hint exists to cover.
    $xrw = trim((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
    if (strcasecmp($xrw, 'XMLHttpRequest') === 0) {
        return;
    }

    bffRejectForbidden();
}
```

Untouched: the safe-verb early return, `bffAuthority()`, `bffRejectForbidden()`, the 403 body, and every calling endpoint. The file docblock was corrected to state the real contract, including the two **known limits** that are now documented rather than silently assumed: only `host[:port]` is compared (not the scheme), and a reverse proxy **must** rewrite `Host` — `HTTP_X_FORWARDED_HOST` is deliberately *not* trusted, because an unvalidated forwarding header is client-spoofable and would defeat the entire control.

### Why this method, and what was rejected

The error was treating a **hint** as an **authoritative** signal and ordering it first. `Origin` is set by the browser and cannot be overridden by page JavaScript; `X-Requested-With` can be set by anything. Ordering the weak signal first makes the strong one unreachable.

| Alternative | Why rejected |
|---|---|
| Delete the XRW branch entirely | Would break legitimate same-origin `fetch` calls that send no `Origin`; too broad. |
| Add a second independent `Origin` check before the existing one | Duplicates the loop and leaves two sources of truth for one rule. The single-source reorder is strictly better. |
| Patch each of the 101 endpoints | 101× duplication of a rule already centralised in the guard. |
| Trust `HTTP_X_FORWARDED_HOST` as an extra allowed authority | Client-spoofable; for a CSRF control that is worse than the problem. Documented as an operator requirement instead. |

## 6. Verification

### 6.1 Regression matrix — 28/28 PASS

Executable suite: `python3 tmp/suite_1679.py` (self-contained: logs in, proves the session is real, asserts on status code **and** the `CSRF protection` body marker).

| ID | Case | Expected | Result |
|---|---|---|---|
| S1 | `POST` XRW + **foreign** `Origin` (ltx) | 403 CSRF | PASS *(was 405)* |
| S2 | `POST` XRW + **foreign** `Referer` | 403 CSRF | PASS *(was 405)* |
| S3 | `api/tcassignments` XRW + foreign `Origin` | 403 CSRF | PASS *(was 200)* |
| S4 | `api/builds` XRW + foreign `Origin` (no business logic) | 403 CSRF | PASS *(was 400)* |
| S5.{POST,PUT,DELETE,PATCH} | every unsafe verb | 403 CSRF | PASS ×4 |
| S6 | `POST` XRW only, JSON body → real handler answers | 409 `warning_duplicate_build` | PASS |
| S6b | `POST` XRW only, form-encoded → guard passes | not 403 | PASS |
| S7 | `POST` XRW + matching `Origin` → handler answers | 409 | PASS |
| S8 | `POST` XRW + matching `Referer` only → handler answers | 409 | PASS |
| S9 | `POST` XRW + `Origin: http://LOCALHOST:8082` (host case) | 409 | PASS |
| S9b | `POST` XRW + `Host: localhost:80` + `Origin: http://localhost` (proxy default port) | allowed | PASS *(review fix)* |
| S9c | `POST` XRW + `Origin: localhost:80` vs `Host: localhost:8082` (**real** port diff) | 403 CSRF | PASS *(must not be weakened by S9b)* |
| S10 / S10b | `GET` no headers (`api/builds` / ltx) | served | PASS (200 / 400 `build_id_not_set`) |
| S11 | `POST` no headers at all | 403 CSRF | PASS |
| S12 | `POST` foreign `Origin`, no XRW | 403 CSRF | PASS |
| S13 | `POST` XRW + `Origin: http://localhost:9999` (port mismatch) | 403 CSRF | PASS |
| S14 | `POST` XRW + `Origin: http://evil.localhost:8082` (subdomain) | 403 CSRF | PASS |
| S15 | `POST` XRW + `Origin: null` (sandboxed iframe) | 403 CSRF | PASS |
| S17 | `POST` XRW + `Origin: file:///etc/passwd` (unparseable) | 403 CSRF | PASS *(review fix)* |
| S18 | `POST` XRW + `Origin: about:blank` | 403 CSRF | PASS |
| S19 | `POST` XRW + `Origin: not-a-url` (bare garbage) | 403 CSRF | PASS *(review fix)* |
| S20 | `POST` XRW + `Origin: http://localhost:8082@evil.example` (userinfo trick) | 403 CSRF | PASS |
| S21 | `POST` XRW + `Origin: http://localhost:8082.evil.example` (host-suffix trick) | 403 CSRF | PASS |
| S16 | `php -l api/_guard.php` | clean | PASS |

S6–S9 drive a **real write route** (`POST /api/builds/index.php`) and require a *business* verdict (409 `warning_duplicate_build` for the already-existing name `LTX Build 1`), not merely "not 403" — a 401/404/500 would fail these, which a bare `not is_csrf()` assertion would have let through.

### 6.2 The suite is proven non-tautological

Restoring the pre-fix guard and re-running:

```
login as admin -> 200
[FAIL] S1    POST XRW + FOREIGN Origin -> 403 CSRF
        expected 403 CSRF   got 405 CSRF=False
[FAIL] S2    POST XRW + FOREIGN Referer -> 403 CSRF
        expected 403 CSRF   got 405 CSRF=False
[FAIL] S3    api/tcassignments XRW + FOREIGN Origin -> 403
        expected 403 CSRF   got 200 CSRF=False
[FAIL] S4    api/builds XRW + FOREIGN Origin -> 403 (no biz logic)
        expected 403 CSRF   got 400 CSRF=False
```

The failures are exactly the foreign-origin cases. Restoring the fix returns 28/28. A suite that also passed on the broken code would have been worthless.

**Gotcha found while doing this:** the first "does it still detect the bug?" run reported a suspicious **28/28 against the pre-fix guard too**. The cause was PHP opcache revalidation, not the test: the pre-fix file was written and the suite started inside the same second, so the server served stale opcodes and the suite silently re-tested the *fixed* code. Re-running with the file already in place gave the correct failures above. The detection check is therefore only trustworthy when the file has been in place for at least a second (`sleep 3`) — the suite's RESUME block does this.

### 6.3 Legitimate traffic is unaffected (live browser write)

Logged in as `admin` in headless Chrome, loaded the fixture, then from the page:

```js
fetch('/api/builds/index.php', {method:'POST',
  headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
  body: JSON.stringify({tplan_id:2, name:'BROWSER-WRITE-1679'})})
// -> 200 {"status":"ok","id":9}
```

The DevTools network panel confirms the browser really sent `origin: http://localhost:8082` next to `host: localhost:8082` → authorities match → allowed. A second fetch **without** XRW also passed the guard (`409 warning_duplicate_build` — business validation, not a 403), proving the browser's own `Origin` alone is sufficient. `SELECT id,name FROM builds` → `9 | BROWSER-WRITE-1679`: the write persisted. `GET /api/builds/index.php?tplan_id=2` then lists `9:BROWSER-WRITE-1679`.

**This is the fact the whole reorder depends on** — real browsers *do* send `Origin` on same-origin POST — and it is now measured rather than assumed.

The form-encoded row is a business-level 400, not a 403: `getBody()` in `api/builds/index.php:52` is `json_decode(file_get_contents('php://input'), true)` only, so that endpoint has never accepted form bodies. What the row proves is that the guard let a plain top-level form POST through — the classic CSRF vector.

![Builds screen after the fix](issue-1679-builds-after-fix.png)

### 6.4 `Origin: null` / sandboxed-iframe risk: none in this codebase

`grep -rhno 'sandbox="[^"]*"' gui/ lib/ api/ | sort | uniq -c` → the only value in the repo is `"allow-same-origin allow-popups allow-modals allow-downloads"` (5 occurrences: `printDocument.html`, `reqSpecPrintRevision.html`, `printReq.html`, `printTestDoc.html`, `tcPrint.html`). Because all 5 keep `allow-same-origin`, their requests carry a real `Origin` and are unaffected by the new `Origin: null` rejection.

### 6.5 Event Viewer

`SELECT count(*) FROM events WHERE log_level IN ('ERROR','WARNING','FATAL')` → **0** before the fix and **0** after. The fix introduces no Error/Warning entries. The only DB side effect of testing is the suite's own build row.

## 7. Notes

- **i18n not applicable:** the fix adds no user-facing string. The existing English-only 403 body in `bffRejectForbidden()` is unchanged (it is an API error payload, not screen text).
- **`tmp/` is gitignored** (`.gitignore:47`), so `tmp/suite_1679.py` and the `tmp/TLU_Test_Cases.md` entry are local-only by design (AGENTS.md rule 9). The full matrix is reproduced above.
- **Two known limits, documented in the file docblock rather than silently assumed:** (1) only `host[:port]` is compared, so a same-host TLS/hypertext pair is treated as same-origin; (2) a reverse proxy must rewrite `Host` to the browser-visible value, because `HTTP_X_FORWARDED_HOST` is deliberately not trusted.
- **A code review subagent ran over the full diff** and drove three changes into the final code: removal of an unreachable `$sawAuthority` block, strict rejection of present-but-unparseable `Origin`/`Referer` (so the documented contract is now true), and scheme-default-port normalization so a proxied deployment is not 403'd on every write. The suite was also hardened — S6–S9 now demand a real business verdict, S17–S21 cover the unparseable/spoof shapes, and the shared temp file was made per-PID for the 5-workflow CI factory.

## 8. Resume

```bash
python3 tmp/suite_1679.py                    # 28/28 PASS, exit 0
# prove detection - the sleep matters (PHP opcache revalidates per second)
git show HEAD~1:api/_guard.php > api/_guard.php && sleep 3 && python3 tmp/suite_1679.py
git checkout api/_guard.php                  # restore
mysql -h 127.0.0.1 -utestlink -ptestlink testlink < tmp/fixtures_1677.sql
php -l api/_guard.php
# browser: admin/admin -> Builds (gui/templates/plans/buildsView.html?tproject_id=1&tplan_id=2)
```
