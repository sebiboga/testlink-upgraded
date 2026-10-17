# API/Risk BFF — Issue #1933

**Symptom**: `api/risk/index.php` returned HTTP 500 (`insert_failed`/`save_failed`) on a duplicate-name race instead of HTTP 409 (`duplicate_name`).

**Root cause**: `lib/functions/database.class.php::exec_query()` logs ERROR via `tLog()` for XHR failures before throwing; `tLog()` writes to `events` using the same DB connection, resetting mysqli errno to 0. The catch blocks checked `intval($db->error_num()) === 1062`, which became dead code.

**Fix**: In both catch blocks (UPDATE and INSERT), re-query for an existing row with the same `(testproject_id,name)` (excluding current `id` on UPDATE). If such row exists, return HTTP 409 `duplicate_name`; otherwise return HTTP 500 with the existing code. This mirrors the fix applied to `api/environments/index.php` in #1932.

**Files changed**
- `api/risk/index.php`: UPDATE catch now re-checks uniqueness; INSERT catch now re-checks uniqueness (no errno check).

**Verification**
- PHP syntax valid (`php -l api/risk/index.php`).
- No remaining `error_num() === 1062` checks in `api/risk/index.php`.
- Regression suite added to `tmp/TLU_Test_Cases.md` (Regression — Issue #1933). Gate `ai/verify_test_suites.sh` passed.
