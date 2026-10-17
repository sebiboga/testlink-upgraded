# Bugfix: Issue #1932 — Environments BFF duplicate-name race returns non-JSON 500 instead of 409

**Issue:** `sebiboga/testlink-upgraded#1932` (label `bug`, 2026-10-17). The BFF `api/environments/index.php` returned an uncaught-exception 500 with an empty body (non-JSON) when a concurrent INSERT hit the unique key `uq_tenv_project_name (testproject_id, name)`, instead of returning `409 duplicate_name` JSON.

## Root cause

- On XHR requests (`X-Requested-With: XMLHttpRequest`), `lib/functions/database.class.php::exec_query()` throws an `Exception` whenever the query fails (lines ~204–207). 
- For CREATE (`action=save`, `id <= 0`), the code called `$db->exec_query($sql)` directly (around line 417) and only checked `insert_id() <= 0` *after* a successful execution. When the INSERT violated the unique constraint, `exec_query()` threw before that guard → uncaught exception → 500 non-JSON. 
- For UPDATE (`action=save`, `id > 0`), the same throw path applied around line 372. 
- `error_num()` cannot be relied on in the catch: `exec_query()` logs the ERROR event via `tLog()` before throwing (`lib/functions/database.class.php:198`), and that `events` INSERT runs on the same mysqli connection (`lib/functions/logger.class.php:1006–1036`), resetting `errno` to `0` (measured). The previously suggested `intval($db->error_num()) === 1062` check is therefore dead code (also noted in `api/risk/index.php`).

## Fix

- `api/environments/index.php`: wrap the UPDATE write (`exec_query`) in `try/catch (Exception $e)` and in `catch`, re-check for an existing same-name row with a different `id` (`SELECT id FROM tc_test_environments WHERE testproject_id=? AND name=? AND id!=?`). If such a row exists → respond `409` JSON `{status:'error',code:'duplicate_name',message:'An environment with this name already exists'}` and `exit`. Otherwise → respond `500` JSON `{status:'error',code:'save_failed',message:'Failed to save environment'}` and `exit`.
- Same pattern for CREATE: catch → re-check `SELECT id FROM tc_test_environments WHERE testproject_id=? AND name=?`. If exists → `409 duplicate_name`. Else → `500` JSON `{status:'error',code:'insert_failed',message:'Failed to create environment'}` and `exit`.
- Kept the defensive `if ($id <= 0)` fallback after a “successful” CREATE execution. Inline comments document why `error_num()` is not used.

## Verification

- Deterministic race: held the unique-key slot via uncommitted `BEGIN; INSERT ... name='RaceLock' ...; SELECT SLEEP(6); COMMIT;` while POSTing `api/environments/?action=save` with the same name (XHR) → **pre-fix**: `HTTP/1.0 500`, 0-byte body (non-JSON); **post-fix**: `HTTP/1.1 409 Conflict`, `Content-Type: application/json`, body `{"status":"error","code":"duplicate_name","message":"An environment with this name already exists"}`.
- Regression: sequential duplicate → 409; create/update/delete → 200 with correct AUDIT events; rename to existing → 409; unknown action 400, PUT 405, unauth 401. Event Viewer: ERROR/WARNING count unchanged on normal flows (a genuine race still logs one ERROR row from `exec_query()`’s shared logger — accepted residual).
- `php -l api/environments/index.php` clean.

## Files

- `api/environments/index.php` (+47/-16)
- Regression suite added to `tmp/TLU_Test_Cases.md` as `Regression — Issue #1932`.

## Notes

- Sibling issue #1933 filed for `api/risk/index.php` which exhibits the identical dead `error_num()===1062` check under the same race.

Refs #1932
