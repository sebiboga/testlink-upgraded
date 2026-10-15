# Bugfix — Issue #1858: `POST /api/requirements/assign-reqs` fatals HTTP 500 for a test case with no active version

**Status:** Fixed (PHP 8.3.35) — duplicate of the already-landed **#1861** fix
**Issue:** [#1858](https://github.com/sebiboga/testlink-upgraded/issues/1858) — the BFF write `POST /api/requirements/assign-reqs` (used by `assignReqs.html:411`, `tcEdit.html:1041`, `testSpec.html`) returned **HTTP 500 with 0 bytes** — an uncaught fatal — whenever the target test case had **no active version** (`tcversions.active = 0` for every version). Nothing was linked and nothing was written to the Event Viewer.

## Relationship to #1861

#1858 reports the **BFF-facing symptom** (HTTP 500 from `assign-reqs`); **#1861** reports the **same root cause at the same call site** (`requirement_mgr::assign_to_tcase()`). They are duplicates. #1861 was fixed by commit **`7d7dbb0e5`** on the default branch; that single change also removes the #1858 symptom. This issue was left open by accident and is closed here with measured before/after evidence (see `Regression — Issue #1858` in `tmp/TLU_Test_Cases.md`).

## Root cause

- `testcase::get_last_active_version($id)` filters `tcversions.active = 1` and returns a map via `fetchRowsIntoMap()`. With **all versions deactivated** the query returns 0 rows → `fetchRowsIntoMap()` returns **`null`** (`lib/functions/database.class.php:666`).
- `requirement_mgr::assign_to_tcase()` at `lib/functions/requirement_mgr.class.php` ran `current($tcMgr->get_last_active_version($testcase_id))` **with no cast/guard**. On PHP 8 `current(null)` throws `TypeError: current(): Argument #1 ($array) must be of type array, null given` **before** the intended refusal `if ($tcv == null) { return $output; }` ("Latest version is Inactive => operation not allowed") could execute — so the "not allowed" branch was dead code.
- The uncaught fatal propagates through the BFF (`api/requirements/index.php:2578`) as **HTTP 500 / 0 bytes** and through the legacy CLI path (`lib/requirements/reqCommands.class.php:943`) as a CLI fatal.
- On PHP 7 `current(null)` returned `false` and the guard worked by accident — a **PHP-8-only regression**, same family as **#1708**.

## Fix (already landed as `7d7dbb0e5`)

`lib/functions/requirement_mgr.class.php` (`:1132`), the `current((array)...)` + existing-guard pattern already applied to the `requirement_spec_mgr` siblings by #1708:

```php
if ($testcase_id && $req_id) {
  // get_last_active_version() returns null when the test case has no active
  // version at all; current(null) raises a PHP 8 TypeError, so cast to array.
  $tcv = current((array)$tcMgr->get_last_active_version($testcase_id));
  if ($tcv == null) {
    return $output;   // Latest version is Inactive => operation not allowed
  }
}
```

`null` → `(array)null = []` → `current([]) = false` → the refusal runs and returns `0`; `false` (the #1708 case) also keeps `false == null` true. The BFF then turns the `0` return into a clean HTTP 200.

## Why this method

The `(array)` cast is the minimal, already-proven pattern used by the two sibling call sites (`requirement_spec_mgr.class.php:2668`, `:2792`) and by the #1861/#1859 fixes. Rejected alternatives: `@`/`error_reporting()` suppression (hides real bugs), a new 409/404 response (the legacy contract is `return 0`, and the caller already maps `0` → `failed:[...]`), and a base-class refactor (scope creep with redeclaration risk).

## Evidence (measured, A/B on the live server)

| State | Code at the call site | BFF `POST /api/requirements/assign-reqs {"tcase_id":3,"req_ids":[11]}` | `php_server.log` |
|---|---|---|---|
| **PRE-FIX** (guard temporarily reverted on a scratch branch) | `current($tcMgr->get_last_active_version(...))` | `HTTP 500`, `SIZE=0` | `Uncaught TypeError: current(): Argument #1 ($array) must be of type array, null given … requirement_mgr.class.php:1136` |
| **POST-FIX** (landed code, `git diff` clean) | `current((array)$tcMgr->get_last_active_version(...))` | **`HTTP 200`** `{"status":"ok","failed":[11],"assigned_qty":0}` | `[200]: POST /api/requirements/assign-reqs` |

- CLI: `php tmp/repro_1859_req.php` → `assign_to_tcase returned: 0`, `NO FATAL - survived`.
- Happy path (test case **with** an active version): `POST … {"tcase_id":6,"req_ids":[11]}` → **HTTP 200** `{"status":"ok","failed":[],"assigned_qty":1}`.
- Event Viewer / `events` table: 3 rows, all `log_level=16` (INFO/audit) — **no new Error/Warning**.

## Regression test suite

Added `Regression — Issue #1858: POST /api/requirements/assign-reqs fatals HTTP 500 for a test case with no active version (duplicate of #1861, same call site)` to `tmp/TLU_Test_Cases.md`. Gate: `TLU_REQUIRE_SUITE="Issue #1858" bash ai/verify_test_suites.sh` → **7 PASS / 0 FAIL**, exit 0.

**Files touched by this run:** `tmp/TLU_Test_Cases.md` (appended suite), `docs/Bugfix-Issue-1858-…` + wiki page (documentation), `CHANGELOG` (one line). **No code change** — the fix was already on `sebiboga` (`7d7dbb0e5`); this run verified the symptom is gone and closed the still-open duplicate.
