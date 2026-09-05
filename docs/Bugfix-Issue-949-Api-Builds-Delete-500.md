# Bugfix — Issue #949: api/builds DELETE returns 500 empty body and does not delete the build

## Problem

`DELETE /api/builds/{id}` answered **HTTP 500 with an empty body**, and the
build stayed in place (`GET /api/builds/{id}` kept returning 200). Reproduced on
a fresh database: create a build via `POST /api/builds/`, then delete it.

## Root Cause

The DELETE route was rewritten during the build project-scoping work
(#503/#834, commit `4bcbe0042`). Its exec-count pre-check query interpolates
table names straight from a manager object's property map:

```php
// before (api/builds/index.php DELETE route, line ~552)
"SELECT COUNT(0) AS qty FROM {$tplanMgr->tables['executions']} E " .
"JOIN {$tplanMgr->tables['testplans']} TP ON TP.id = E.testplan_id " .
```

`tables` is declared **`protected`** on `tlObject`
(`lib/functions/object.class.php:78`), so reading it from procedural code throws
a PHP 8.3 fatal before the query even runs — which is why the body was empty
(`display_errors=Off`) and the row was never deleted (the fatal fires *before*
`build::delete()`):

```
Uncaught Error: Cannot access protected property testplan::$tables in
api/builds/index.php:552
```

The fault is only observable in the PHP *server* log; the app error.log stays
clean, matching the original report ("no PHP error visible").

## Fix

Applied in commit `0fe8588b2` on branch `fix/issue-949`.

`api/builds/index.php` DELETE route now resolves the two table names through the
public static accessor that every other out-of-class call site uses
(`lib/functions/object.class.php:243`), byte-identical to the class's own
`$this->tables` map (DB_TABLE_PREFIX aware):

```php
// after
$buildTables = tlObjectWithDB::getDBTables(array('executions', 'testplans'));
$qry = "SELECT COUNT(0) AS qty FROM {$buildTables['executions']} E " .
       "JOIN {$buildTables['testplans']} TP ON TP.id = E.testplan_id " .
       "WHERE TP.testproject_id = {$ctx['tproject_id']} " .
       "AND E.build_id = {$buildId}";
```

Alternatives rejected: switching the whole count to a `testplan` class method
would have been a behaviour-preserving but larger change; table-prefix managers
do not expose table names publicly, so hard-coding `executions`/`testplans`
strings would break installations with a `DB_TABLE_PREFIX` configured.

## Files Changed

- `api/builds/index.php` (DELETE route, exec-count query — 3 lines added,
  2 removed)

## Verification

- `DELETE /api/builds/1` (no executions) → `200 {"status":"ok"}`; `GET` → 404;
  row gone from `builds`.
- `DELETE` with 1 execution (admin has `exec_delete`) → 200; dependent
  executions cascade-deleted (count 0).
- `DELETE /api/builds/999999` (nonexistent) → 404 (unchanged path).
- `events` table: `audit_build_deleted` AUDIT entries logged (nothing new at
  ERROR/WARNING level).
- `php -l api/builds/index.php` → no syntax errors.
- Regression suite appended to `tmp/TLU_Test_Cases.md` as
  **Regression — Issue #949**, 6/6 PASS.

Note (out of scope): for a *nonexistent* build, `build::get_by_id()` returns
`false` (not `null`), so the GET/PUT/DELETE routes report `404 "Invalid Test
Project ID"` rather than `"Build not found"`. The HTTP status is correct; only
the message wording is off. Left untouched to keep this fix minimal.