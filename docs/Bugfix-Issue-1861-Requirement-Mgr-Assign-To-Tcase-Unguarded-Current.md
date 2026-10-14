# Bugfix — Issue #1861: requirement_mgr::assign_to_tcase() — unguarded current(get_last_active_version())

**Status:** Fixed (PHP 8.3.35)
**Issue:** [#1861](https://github.com/sebiboga/testlink-upgraded/issues/1861) — linking a requirement to a test case with **no active version** fatals `TypeError: current(): Argument #1 ($array) must be of type array, null given` at `lib/functions/requirement_mgr.class.php:1132` (500 response, 0 bytes from the BFF `POST /api/requirements/assign-reqs`).

## Root cause

- `get_last_active_version($testcase_id)` filters `tcversions.active=1` and returns a map via `fetchRowsIntoMap()` keyed by `tcversion_id`. If the test case has **all versions inactive (active=0)**, the query returns 0 rows → `fetchRowsIntoMap()` returns **`null`**.
- `requirement_mgr::assign_to_tcase()` did `current($tcMgr->get_last_active_version(...))` with **no cast/guard**. On PHP 8 `current(null)` throws a `TypeError` **before** the existing `if ($tcv == null)` refusal at line 1133 can execute.
- Siblings were already guarded in `lib/functions/requirement_spec_mgr.class.php` (`:2668`, `:2792`) by `current((array)...)`; this single call site in `requirement_mgr` was missed.
- On PHP 7 `current(null)` returned `false` and the guard worked by accident (PHP-8-only regression).

## Fix

**`lib/functions/requirement_mgr.class.php` (lines 1129–1140):**

```php
    if ($testcase_id && $req_id) {
      // Get Latest Active Test Case Version 
      // get_last_active_version() returns null when the test case has no active
      // version at all; current(null) raises a PHP 8 TypeError (HTTP 500 on the
      // assign-reqs BFF), so cast to array first. false == null keeps the guard
      // below working (issue #1861, same fix as #1708).
      $tcv = current((array)$tcMgr->get_last_active_version($testcase_id));
      if ($tcv == null) {
        // Latest version is Inactive => operation not allowed
        return $output;
      }
    }
```

## Evidence (measured)

| Test | Input | Expected | Observed |
|---|---|---|---|
| CLI (no active version, tcase 3) | `assign_to_tcase(11,3,1)` | `0`, no fatal | `0` (no TypeError) |
| CLI (active version, tcase 6) | `assign_to_tcase(11,6,1)` | `1`, link created | `1`; `req_coverage` row inserted (tcversion_id 7) |
| BFF assign-reqs (no active) | `POST /api/requirements/assign-reqs {"tcase_id":3,"req_ids":[11]}` | HTTP 200 + JSON `{"status":"ok","failed":[11],"assigned_qty":0}` | **HTTP 200** `{"status":"ok","failed":[11],"assigned_qty":0}` (pre-fix: 500/0 bytes) |
| BFF assign-reqs (active) | `POST /api/requirements/assign-reqs {"tcase_id":6,"req_ids":[11]}` | HTTP 200 + `assigned_qty:1` | **HTTP 200** `{"status":"ok","failed":[],"assigned_qty":1}` |
| Event Viewer | events after fix | no new Error/Warning | only INFO (16) rows; no new Error/Warning entries |

## Regression test suite

Added `Regression — Issue #1861: requirement_mgr assign_to_tcase() current(get_last_active_version()) unguarded` to `tmp/TLU_Test_Cases.md`. Gate: `TLU_REQUIRE_SUITE="Issue #1861" bash ai/verify_test_suites.sh` → **7 PASS / 0 FAIL**.

**Files touched:** `lib/functions/requirement_mgr.class.php` (1 statement + 5 comment lines), `tmp/TLU_Test_Cases.md` (appended suite).
