# Issue 616 — `session_start(): Ignoring session_start() because a session is already active` on every remote/plan-link generation

**Issue:** [#616](https://github.com/sebiboga/testlink-upgraded/issues/616)
**Branch:** `fix/issue-616`
**Fix commit:** `79420c632`
**Status:** FIXED & VERIFIED (2026-08-28)

## Symptom

Every document generation through the legacy direct-link flow (`lnl.php?apikey=...&type=testreport_onbuild&build_id=N` → redirect → `lib/results/printDocument.php`) logs into the **Event Viewer / `events` table**:

```
E_NOTICE  (PHP 8.3) / E_WARNING (PHP 8.5)
session_start(): Ignoring session_start() because a session is already active
(started from lib/functions/common.php on line 315) - in common.php - Line 315
```

First observed 2026-08-23 (first-ever real use of per-build plan links). Functionality unaffected — it is log noise, but it writes a PHP error row into the DB on **every** generation.

## Root cause chain

1. `lib/functions/common.php` top-level includes `csrf.php`
   (`if( !defined('TL_APICALL') ) { require_once("csrf.php"); }`).
2. `lib/functions/csrf.php:211` runs `doSessionStart(false);` at include time → **first** `session_start()` at `lib/functions/common.php:315` → the session becomes `PHP_SESSION_ACTIVE` on every request.
3. The remote/plan-link controllers pass `clearSession=true`:
   - `lnl.php:205` `$opt = ['setPaths' => true, 'clearSession' => true]` → `setUpEnvForRemoteAccess` (`lnl.php:230`) or `setUpEnvForAnonymousAccess` (`lnl.php:264`);
   - `ltcp.php:53` uses the same `$opt`.
4. `setUpEnvForRemoteAccess` / `setUpEnvForAnonymousAccess` (`lib/functions/common.php:1161`, `:1310`) run `if($my['opt']['clearSession']) { $_SESSION = null; }`. Measured behaviour: the superglobal is unbound but `session_status()` **remains `PHP_SESSION_ACTIVE`**.
5. Both then call `doSessionStart($setPaths)` (`common.php:1166`, `:1315`). The old guard at `:314` was `if(!isset($_SESSION))` — on a nulled superglobal that evaluates **true**, so `session_start()` is invoked a **second** time while the session is already active → PHP "Ignoring session_start() because a session is already active (started from common.php on line 315)".
6. `watchPHPErrors` (`lib/functions/logger.class.php:1483`, `set_error_handler("watchPHPErrors")`) funnels it via `logWarningEvent` into the `events` table.

> The **trigger is inside the `lnl.php` request** (pre-redirect), not inside `printDocument.php` — `printDocument.php` never passes `clearSession=true`, so it never nulls the session. PHP docs `session_status()` explicitly warns that `isset($_SESSION)` / `session_id()` are unreliable once the superglobal is unbound.

## The fix

`lib/functions/common.php` — `doSessionStart()` guard changed from:

```php
if(!isset($_SESSION)) {
  session_start();
}
```

to:

```php
if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}
```

`session_status()` is the only robust "not running → start" check (per PHP manual and php-src#10736 guidance). Analyze by state:

- **No active session** (`PHP_SESSION_NONE`): start — unchanged.
- **Active session + nulled `$_SESSION`** (the clearSession case): **skip** the second start — the E_NOTICE/E_WARNING disappears; subsequent `$_SESSION[...]` writes rebuild the array and PHP persists it at request end, so `setPaths()`/basehref behaviour is identical.
- **Active session + intact `$_SESSION`**: skip — identical to the old guard's happy path.

`lib/functions/configCheck.php:752` and `login.php:265` contain the same micro-guard pattern but in flows where `$_SESSION` is never nulled beforehand — left untouched (minimal-fix scope).

## Verification

Fixture: `php tmp/fixtures_616.php` (tproject=1, tplan=6, build=1, plan api_key + user script_key). All checks against a fresh `events` watermark:

| # | Case | Result |
|---|------|--------|
| 1 | `curl lnl.php?apikey=<64h plan key>&type=testreport_onbuild&build_id=1` → 302, **0** new events | PASS |
| 2 | `curl lnl.php?apikey=<32h user key>&type=testreport_onbuild&build_id=1` (remote-access path) → 302, **0** new events | PASS |
| 3 | Browser (logged-in) full redirect chain → document **generated** ("Test Plan Execution Report (on specific build)", UPD616/Plan616/Build616), **0** new ERROR/WARNING events | PASS |
| 4 | GUI login + navigation regression — navBar/aside/mainframe all load | PASS |
| 5 | Event Viewer sweep — no log_level=1/2 rows created by these flows | PASS |

Screenshot: `docs/screenshots/issue-616-testreport-onbuild-generated.png` (generated report, proving the plan-link flow works after the fix).

## Files changed

- `lib/functions/common.php` — one-line session-start guard in `doSessionStart()` + explanatory comment.
- `tmp/TLU_Test_Cases.md` — regression suite `Regression — Issue #616` (5 cases, all PASS; local file, `tmp/` is gitignored).