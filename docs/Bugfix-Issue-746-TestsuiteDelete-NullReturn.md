# Issue 746 — `testsuite::delete()` returns null: no test suite can be deleted via PHP API

**Issue:** [#746](https://github.com/sebiboga/testlink-upgraded/issues/746)
**Branch:** `fix/issue-746-testsuite-delete` · **Commit:** `2d1218b10` (fix, +1/-0)
**Status:** FIXED & VERIFIED (2026-08-26)

## Symptom

`testsuite::delete($suiteId)` returns `null` for ALL test suites when called
via the PHP API (BFF `suite_delete` action). The BFF endpoint reports:

```json
{"status":"ok","message":"Suite deleted","result":null}
```

Programmatic callers checking the return value see a falsy `null` and
conclude deletion failed, even though the SQL DELETE operations execute
correctly at the database level.

Reproduced live against `http://localhost:8082` (PHP 8.x, MariaDB):
fixtures created through the BFF API, deletion confirmed working at DB
level but returning null to callers.

## Root cause chain

1. `lib/functions/testsuite.class.php:287-318` — `testsuite::delete()`
   has NO `return` statement. PHP functions without explicit returns yield
   `null`.
2. `api/testcases/index.php:882-883` — BFF API captures this null:
   `$ret = $tsuiteMgr->delete($sid);` and includes it as `"result": $ret`.
3. `lib/functions/testcase.class.php:1655` — `testcase::delete()` returns
   `1` on success, establishing the expected pattern that `testsuite::delete()`
   violates.

## Blast radius

- `testsuite::delete()` callers:
  - `api/testcases/index.php:882` (BFF `suite_delete`) — affected: null result
  - `lib/testcases/containerEdit.php:659` via `delete_deep()` — not affected:
    return value is never checked
- All programmatic consumers of `testsuite::delete()` receive falsy null,
  unable to verify deletion success.

## Approach — add return statement (minimal)

At the end of `testsuite::delete()`, add `return true;` after the final
`event_signal()` call.

Rejected alternatives:
- Changing the return type to a result map (like `create()` returns
  `{status_ok, msg, id}`) — over-engineered for a delete method; the
  existing `testcase::delete()` only returns `1`.
- Adding parameter validation or null checks on `$tsuite_info` — out of
  scope; the method works correctly for valid IDs.

## Verification (Suite 746 — all PASS, see tmp/TLU_Test_Cases.md)

| Case | Result |
|------|--------|
| TC-746-01 BFF API suite_delete returns `"result":true` | PASS |
| TC-746-02 Suite + child TC fully cleaned up after delete | PASS |
| TC-746-03 Pre-fix returned null, post-fix returns true | PASS |
| Event Viewer | No new Error/Warning entries |
| `php -l lib/functions/testsuite.class.php` | No syntax errors |
