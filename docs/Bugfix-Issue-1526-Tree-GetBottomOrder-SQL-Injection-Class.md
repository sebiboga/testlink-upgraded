# Issue 1526 — tree.class.php getBottomOrder(): parent_id interpolated unescaped (SQL error / injection class)

**Issue:** [#1526](https://github.com/sebiboga/testlink-upgraded/issues/1526)
**Branch:** `fix/issue-1526-sql-injection-getBottomOrder`
**Status:** VERIFIED-FIXED (2026-09-17)

## Symptom

`tree::getBottomOrder()` interpolates its `$parentID` argument directly into the
WHERE clause of the order query with no casting or escaping. When a non-numeric
value reaches the argument (a test suite *name* such as `WLK Suite` was observed
reaching it during session testing), the generated SQL is:

```sql
SELECT MAX(node_order) AS max_order FROM nodes_hierarchy
    WHERE parent_id=WLK Suite  GROUP BY parent_id
```

MariaDB rejects this with error 1064, the error is logged to the `events` table
(log_level ERROR), and the operation silently fails (legacy path terminates the
page; BFF/XHR path throws). Because the value is unquoted and unescaped, this is
also a SQL-injection class defect (in theory crafted input could alter the query).

Measured event row (fresh DB):
```
ERROR ON exec_query() - database.class.php
1064 - You have an error in your SQL syntax; ... near 'Suite  GROUP BY parent_id'
SELECT MAX(node_order) AS max_order FROM nodes_hierarchy
    WHERE parent_id=WLK Suite  GROUP BY parent_id
```

## Repro steps

1. `require config.inc.php` + `lib/functions/common.php`; `doDBConnect($db)`.
2. `$tree = new tree($db); $tree->getBottomOrder('WLK Suite');`
3. **Before fix:** SQL error 1064, page termination / exception, ERROR event row
   in `events`.
4. **After fix:** returns `int(0)` (safe default), no SQL error, no event row.

## Root cause

`lib/functions/tree.class.php:718-737`:

```php
function getBottomOrder($parentID,$opt=null) {
    ...
    $sql = "SELECT MAX(node_order) AS max_order" .
           " FROM {$this->object_table} " .
           " WHERE parent_id={$parentID} ";   // line 727 — unescaped interpolation
```

Chain:

1. `$parentID` is a public-function argument with NO type/range validation.
2. Line 727 interpolates it raw into SQL (`WHERE parent_id={$parentID}`).
3. Non-numeric input → SQL syntax error 1064 → `exec_query()` (`database.class.php:190-231`) logs to `events` and dies/throws.
4. Callers: `tree.class.php:701` (`change_child_order()` bottom case),
   `testsuite.class.php:146` (`testsuite::create()` order computation),
   `testcaseCommands.class.php:324` (`testcaseCommands::doCreate()`).

All current mainline GUI/BFF entry points intval the container id before calling
(e.g. `tcEdit.php:310`, `api/testcases/index.php:2328`), so normal flows were
never broken by this; the function simply never enforced its own numeric-input
contract. Fixing the function is defense-in-depth at the sink.

## Fix

`lib/functions/tree.class.php:720`:

```php
function getBottomOrder($parentID,$opt=null) {
    $debugMsg='Class:' .__CLASS__ . ' - Method:' . __FUNCTION__ . ' :: ';
    $parentID = intval($parentID);
```

`intval('WLK Suite')` / `intval('abc123')` both evaluate to `0`, so any non-numeric
parent degrades to the same result as a container with no rows (`max_order` = 0),
which is the exact legacy behaviour for an empty container. Valid numeric IDs are
unaffected (`intval(int) === int`).

**Alternative considered and rejected:** wrapping the value with
`$this->db->prepare_int($parentID)` is equally correct but the earlier `intval()`
cast is simpler, has zero escaping-cost, and makes the numeric contract explicit
at the top of the method.

## Verification (regression matrix, all PASS)

| Case | Result |
|---|---|
| `getBottomOrder('WLK Suite')` | `int(0)`, no SQL error |
| `getBottomOrder('abc123')` | `int(0)`, no SQL error |
| `getBottomOrder(0)` / `getBottomOrder(999)` | `int(0)` |
| Create project + 2 suites → `getBottomOrder(projectId)` | `2` (correct MAX) |
| `testsuite::create()` ordering (`testsuite.class.php:146`) | suites get distinct node_order 1,2 |
| Create 2 testcases + `change_child_order(...,'bottom')` | completes; MAX advances to 1 |
| BFF browser flow: project → `suite_create` → testcase `create` | 200 ok for all three; suite node_order=1 |
| `events` table after all of the above | no new Error rows; only INFO/audit |
| `php -l lib/functions/tree.class.php` | No syntax errors |

Regression suite: `tmp/TLU_Test_Cases.md` → **Regression — Issue #1526** (9/9 PASS).