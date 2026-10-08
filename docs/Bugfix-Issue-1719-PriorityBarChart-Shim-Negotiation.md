# Issue #1719 — Legacy `priorityBarChart.php` is fatally broken: requires the removed `results.class.php` — and its replacement shim refused every real browser navigation with 405

**Issue:** [#1719](https://github.com/sebiboga/testlink-upgraded/issues/1719)
**Commits:** `0527cddf4` (the fix) + `c8b9468e7` (regression suite)
**Branch:** `fix/issue-1719`
**Status:** VERIFIED-FIXED (2026-10-08)
**Sibling defect found while testing:** [#1881](https://github.com/sebiboga/testlink-upgraded/issues/1881) (anonymous login-bounce 404 — filed, not fixed in this run)

## Symptom

As filed (2026-09-29): `lib/results/priorityBarChart.php:5` did
`require_once('../functions/results.class.php')` — a file that does not exist in 2.0.1 — and
`:17` instantiated `new results(...)`, a class that no longer exists either, so every request
answered **HTTP 500 with a 0-byte body** plus a `require_once` warning row in the Event Viewer.
The file even admitted it: line 2 was `//@TODO this file seems not to be in use`.

That fatal was removed 6 days later by `ada46a5f9` (2026-10-05, Refs #1845), which rewrote the
file as a 90-line session-guarded **launcher shim** redirecting to the modern screen
`gui/templates/results/priorityBarChart.html` (+ BFF `api/prioritybarchart/index.php`). The issue
was never re-verified, so it stayed open — and the shim turned out to be broken for its primary
consumer:

**Every real browser navigation got HTTP 405 `modern_endpoint_only` (JSON, no `Location`)
instead of the redirect to the report.**

```
$ curl -b cj "http://localhost:8082/lib/results/priorityBarChart.php?tplan_id=3" \
    -H "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,…" \
    -H "Sec-Fetch-Dest: document" -H "Sec-Fetch-Mode: navigate"
HTTP/1.1 405 Method Not Allowed
{"status":"error","code":"modern_endpoint_only","message":"the chart is served by /api/prioritybarchart/index.php"}
```

Reproduced 1:1 in headless Chrome: the browser sat on that JSON dead end. The report itself was
fine when opened directly (200, correct aggregate) — only the legacy entry point was dead.

## Investigation

**Environment.** App `http://localhost:8082` (PHP 8.3 built-in server, docroot = repo root);
MariaDB `127.0.0.1:3306/testlink` (`testlink`/`testlink`), freshly imported; `admin/admin`;
headless Chrome via chrome-devtools MCP. Fixtures recreated for the run:
`php tmp/fixtures_1845.php` → project 1 "PBC1", plan 3 "PBC Plan" (6 assigned versions,
executions 1p + 1f-latest + 1b + 1p, 2 not-run, keywords login/checkout), plan 34 (empty),
user `pbcnorights` (role 3).

**Repro (pre-fix).**

1. Log in (`admin/admin`).
2. Navigate in a real browser — or curl replaying the verbatim Chrome header set — to
   `http://localhost:8082/lib/results/priorityBarChart.php?tplan_id=3`.
3. **Actual:** 405 JSON dead end, no `Location`. **Expected:** 302 → the modern report screen.

**Measured evidence.**

| Probe | Pre-fix | Post-fix |
|---|---|---|
| Chrome verbatim `Accept` + `Sec-Fetch-Dest: document` | **405**, no Location | **302** → `-L` final **200** |
| Real headless-Chrome navigation | sat on the 405 JSON body | lands on `priorityBarChart.html?tplan_id=3&tproject_id=1`, 0 console errors |
| Old-browser `text/html` w/o Sec-Fetch / iframe / default `*/*` | 302 | 302 (unchanged) |
| `<img>`-style `Accept: image/…` (no text/html) / `dest=image` / XHR | 405 | 405 (contract preserved) |
| `fetch`-style `dest=empty` + `Accept: */*` | 302 (dead `SEC_FETCH_DEST` key) | 405 |
| no `tplan_id` | 400 | 400 |
| anonymous | 302 → `/lib/login.php` → **404** (**#1881**) | unchanged (out of scope) |
| `python3 tmp/suite_1845.py` | 71/71 (false PASS, see below) | 71/71 |
| Event Viewer `log_level IN (32,50)` | 0 | 0 |

## Root cause

Two defects in `lib/results/priorityBarChart.php`, one of them introduced by the very run that
removed the fatal:

1. **Content-negotiation misfire (`:74`, original line).**
   `if ($isXhr || $dest === 'empty' || strpos($accept, 'image/') !== false) → 405`.
   A Chrome/Firefox/Edge **document** navigation sends
   `Accept: text/html,…,image/avif,image/webp,image/apng,…` — the substring `image/` is present in
   100% of real navigations, so the "this is an `<img>`/XHR caller" test fired on every one of
   them. The navigation signal that would have identified the caller correctly
   (`Sec-Fetch-Dest: document`) was never consulted.
2. **Dead `$_SERVER` key (`:73`, pre-existing).** The file read
   `$_SERVER['SEC_FETCH_DEST']` — PHP maps request headers to `$_SERVER['HTTP_*']`; there is no
   bare `SEC_FETCH_DEST` key (`php -r 'var_dump(array_key_exists("SEC_FETCH_DEST",$_SERVER));'` →
   `false`). Every `Sec-Fetch-Dest` comparison in the file was dead code, so `$dest` was always
   `''`. (Caught by the mandatory code review of this fix; every other consumer in the repo uses
   the prefixed key — `reqSpecSearchForm.php:72`, `reqSpecSearch.php:74`,
   `getreqmonitors.php:72`, `gettestcasesummary.php:97`.)

**Why nobody noticed:** `tmp/suite_1845.py` cases H1–H3 always pass `-H 'Accept: text/html'`
(`:206` and `:211`), stripping the offending tokens before the shim ever sees them — a **false PASS**
that hid the defect for 3 days.

**Blast radius:** `lib/results/priorityBarChart.php` only — `strpos($accept,'image/')` is used
nowhere else in the tree; no in-app caller links the legacy file (`$actions->priorityBarChart`
at `common.php:2335-2336` and the `charts.html:213` button already point at the modern screen);
the modern screen, BFF, i18n bundles and the Reports ASIDE menu are unaffected
(`suite_1845` W1–W6 PASS).

## Fix — approach and alternatives rejected

**Method:** minimal edit of the negotiation block only (`priorityBarChart.php:68-86`, +11/−2):

```php
$isDocument = $dest === 'document' || strpos($accept, 'text/html') !== false;
if ($isXhr || $dest === 'empty' || $dest === 'image'
    || (!$isDocument && strpos($accept, 'image/') !== false)) { → 405 }
```

plus the one-key correction `SEC_FETCH_DEST` → `HTTP_SEC_FETCH_DEST`. A document navigation
(`Sec-Fetch-Dest: document` **or** an `Accept` asking for `text/html`) can never be refused;
XHR, `dest=empty`, `dest=image` and an image-only `Accept` keep the documented 405 hard-fail
contract the docblock promises (the 1.9.20 answer was PNG pixels, so an `<img>` must not receive
a 302 to an HTML page).

**Rejected alternatives.**

- *Delete/retire the shim* — it is the documented legacy deep-link contract written by #1845;
  the issue's Expected allows retirement, but the shim is 2 lines away from working and deletion
  would discard a working contract because of a header bug.
- *Fix only the test suite* (drop the forced `Accept: text/html`) — hides the symptom instead of
  fixing it.
- *Adopt the sibling pattern wholesale* (refuse any present `Sec-Fetch-Dest` ≠ `document`) —
  rejected because an `iframe` navigation sends `Sec-Fetch-Dest: iframe` and 1.9.20 was a frames
  app; refusing `iframe` would break frame embedding. Only `empty`/`image` are refused.
- *405 → 406 + `Allow:` header* (code-review NIT) — pre-existing contract of this endpoint, out
  of scope for a minimal fix.
- *Fix the anonymous login-bounce 404 at `:47` in the same run* — distinct root cause
  (hand-rolled `../login.php` vs `checkSessionValid()`'s walk-up) and distinct repro; filed as
  **#1881** per FIX-ISSUE.md §4 (never expand the run's scope).

## Files changed

| File | Change |
|---|---|
| `lib/results/priorityBarChart.php` | +11/−2 — negotiation block (`:68-86`) + `HTTP_SEC_FETCH_DEST` |
| `tmp/TLU_Test_Cases.md` | +58 — `Regression — Issue #1719` suite (18 cases), gate 7/7 PASS |
| `CHANGELOG` | one-line 2.0.1 entry |
| `docs/Bugfix-Issue-1719-PriorityBarChart-Shim-Negotiation.md` | this page (docs mirror, no image lines) |
| `docs/screenshots/issue-1719-prioritybarchart-after.png` | post-fix browser evidence |

## Verification

- `php -l lib/results/priorityBarChart.php` → clean.
- Regression matrix (18 cases) **18/18 PASS** — see `tmp/TLU_Test_Cases.md`
  (`Regression — Issue #1719`).
- `python3 tmp/suite_1845.py` (shim + BFF + wiring + i18n + Event Viewer) → **71/71 PASS**.
- Real headless-Chrome navigation through the legacy URL → report screen, **0 console errors**.
- Event Viewer: **0** Warning/Error rows (`log_level IN (32,50)`); only the deliberate
  `no_right` audit rows at `log_level 1`.
- BFF aggregate byte-stable: plan 3 → 6 versions, 1 passed / 2 failed / 1 blocked / 2 not-run,
  keywords checkout 50% + login 75%.
- Suite gate: `TLU_REQUIRE_SUITE="Issue #1719" bash ai/verify_test_suites.sh` → 7 PASS / 0 FAIL.

## How to re-test

```bash
curl -s -c cj -b cj http://localhost:8082/login.php -o /dev/null
curl -s -c cj -b cj -X POST http://localhost:8082/api/auth/login \
     -H "Origin: http://localhost:8082" -d "login=admin&password=admin"
curl -s -L -b cj -o /dev/null -w "%{http_code} %{url_effective}\n" \
     "http://localhost:8082/lib/results/priorityBarChart.php?tplan_id=3" \
     -H "Accept: text/html,...,image/avif,image/webp,image/apng,..." \
     -H "Sec-Fetch-Dest: document"
# expect: 200 .../gui/templates/results/priorityBarChart.html?tplan_id=3&tproject_id=1
```
