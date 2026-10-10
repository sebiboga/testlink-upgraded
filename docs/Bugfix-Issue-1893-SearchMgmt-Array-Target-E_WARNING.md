# Issue #1893 — the `searchMgmt.php` redirect shim cast `$_REQUEST['target']` without a shape check: `?target[]=x` wrote an E_WARNING row into `events` on every request

**Issue:** [#1893](https://github.com/sebiboga/testlink-upgraded/issues/1893)
**Branch:** `fix/issue-1893-searchmgmt-array-param` (base `d61807355`)
**Status:** VERIFIED-FIXED — regression suite `Issue #1893` 6/6 PASS, suite gate 7/7
**Related:** [#1731](https://github.com/sebiboga/testlink-upgraded/issues/1731) and
[#1732](https://github.com/sebiboga/testlink-upgraded/issues/1732) (the same array-shaped-`$_REQUEST`
defect family, already fixed), [#1785](https://github.com/sebiboga/testlink-upgraded/issues/1785)
(the shim this file belongs to).

## Symptom

One crafted GET against the 2.0.1 session-guarded redirect shim wrote **one** `events` row,
repeatably, with no cap:

```
GET /lib/search/searchMgmt.php?target[]=x        (authenticated)
-> HTTP 302, +1 events row:
log_level 2 | E_WARNING
             Array to string conversion - in .../lib/search/searchMgmt.php - Line 24
```

5 requests = 5 rows. Reachable from ANY authenticated session (the shim has no rights check beyond
`testlinkInitPage`). Same "Event-Viewer-only" class as #1654 / #1721 — it pollutes the Event Viewer
with attacker-triggerable E_WARNING rows, no data-integrity or auth impact.

## Investigation (measured before any code was touched)

Environment: app `http://localhost:8082` (PHP built-in server, docroot = repo root), PHP 8.3.35,
DB `testlink` freshly imported, `admin`/`admin` via curl session cookie (`POST login.php`).

| probe | result |
|---|---|
| `events` log_level in (1,2) before the repro | 0 |
| `GET ?target[]=x` (authenticated) | HTTP 302, `Location: ...&tproject_id=0&tplan_id=0&target=Array` |
| `events` log_level in (1,2) after 1 request | **1 row** (`E_WARNING` at Line 24) |
| `events` log_level in (1,2) after 5 more requests | **7** (+1 per request) |
| anonymous `GET ?target[]=x` | login page (200), no row — shim's session guard runs first |

## Root cause chain

| # | file:line | what happens |
|---|---|---|
| 1 | request `?target[]=x` | PHP parses it into `$_REQUEST['target'] = array('x')`; `isset()` returns true for arrays. |
| 2 | `lib/search/searchMgmt.php:24` (pre-fix) | `$target = isset($_REQUEST['target']) ? trim((string)$_REQUEST['target']) : '';` → `(string)` of an array raises `E_WARNING Array to string conversion` (persisted `log_level=2` by `watchPHPErrors`, `lib/functions/logger.class.php:1407-1446`) and yields the literal `"Array"`. |
| 3 | `lib/search/searchMgmt.php:25-36` | the coerced `"Array"` passes the `strlen <= 200` clamp and is `rawurlencode`d into the `Location` header (`...&target=Array`). |

**Why it breaks / persistence of the defect:** the raw `$_REQUEST` read was introduced by the 2.0.1
shim rewrite (`db1a4335e feat(searchmgmt): wire modern screen + legacy redirect shim (Refs #1785)`);
the legacy controller never had this read. Same family as the #1731 fix.

## Blast radius

`grep -c 'trim((string)$_REQUEST\|(string)$_REQUEST\|(string)$_GET\|(string)$_POST'` over
`lib/ api/ gui/` at HEAD returns **55 raw casts** and **41 already-`is_scalar()`-guarded
`$_REQUEST[...]` sites**. This issue is scoped to `lib/search/searchMgmt.php`. The sibling shims
`listTestCases.php:57-63` (#1732) and `reqMgrSystemEdit.php:80-86` (#1731) already carry the guard
and are the precedent idiom.

## The fix (minimal, 1 file, +19/-1)

`lib/search/searchMgmt.php` reads `target` through the same `is_scalar()`-guarded `shimReqScalar()`
idiom already established in the repo (listTestCases.php:57-63, reqMgrSystemEdit.php:80-86,
`bffQueryScalar()` in api/reqmgrsystemedit):

```php
function shimReqScalar($name)   // null when absent OR array-shaped, else the trimmed string
{
    if (!isset($_REQUEST[$name]) || !is_scalar($_REQUEST[$name])) {
        return null;
    }
    return trim((string)$_REQUEST[$name]);
}

// Legacy input contract: POST/GET `target` (the navBar one-box search term).
$target = shimReqScalar('target');
if ($target === null) {
  $target = '';
}
```

A present-but-non-scalar `target` is treated as **absent** (empty target) — the coercion `"Array"` is
never built, the E_WARNING is never raised, and the redirect drops the `target` parameter entirely.
The shim's own contract already treats an absent target as "no search term" and redirects to the
modern search screen.

### Why this method (and what was rejected)

* **Reusing `shimReqScalar`** rather than inventing a new guard: makes the fix obviously equivalent
  to the already-approved shim hardening a reviewer knows (#1731/#1732).
* **Refusing with a 4xx** for a non-scalar `target`. Rejected: this file is a *bookmark redirector*
  whose contract is "always 302 somewhere sane"; a malformed target is treated exactly as absent,
  no new code path, no new response shape.
* **Guessing a search term from array contents.** Rejected outright — the shim's contract is
  single-term navBar search; concatenating array elements invents behaviour.

No user-facing string changed (the shim emits only redirects), so **no i18n keys were added**.

## After the fix

`?target[]=x` (and any array-shaped target) is treated as absent → 302 to the modern search screen
with **zero** Event-Viewer rows. Every valid scalar target still forwards exactly as before.

## Verification

Regression suite `Issue #1893`, appended to `tmp/TLU_Test_Cases.md`; suite gate
`TLU_REQUIRE_SUITE="Issue #1893" bash ai/verify_test_suites.sh` → 7/7 PASS.

| case | before | after |
|---|---|---|
| `?target[]=x` × 5 | +1 `log_level=2` row per request | **+0 rows** (count unchanged), 302 |
| `?target=foo%20bar` | 302 → `...&target=foo%20bar` | unchanged |
| (no target) | 302 → `...?tproject_id=0&tplan_id=0` | unchanged |
| anonymous `?target[]=x` | login page, no row | unchanged |
| `php -l lib/search/searchMgmt.php` | — | No syntax errors detected |

## How to re-test in one command

```bash
# login admin/admin via curl session cookie, then:
curl -s -b c.jar -D- -o /dev/null \
  'http://localhost:8082/lib/search/searchMgmt.php?target%5B%5D=x'
# expect: HTTP/1.1 302 Found + Location: .../gui/templates/search/searchMgmt.html?tproject_id=0&tplan_id=0
mysql -h 127.0.0.1 -utestlink -ptestlink testlink \
  -e "SELECT COUNT(*) FROM events WHERE log_level IN (1,2);"   # expect 0
```
