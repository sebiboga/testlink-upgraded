# Bugfix — Issue #777: api/testcases create under project root → E_WARNING testproject.class.php:1045 + DB 1064 (empty external id)

**Issue:** [#777](https://github.com/sebiboga/testlink-upgraded/issues/777)
**Commits:** `aee98f47b` (fix), `6eb98377a` (regression suite)
**Status:** FIXED & VERIFIED (2026-08-30)

## Symptom

Creating a test case **directly under the test project root** via the modern BFF
(`POST /api/testcases/?action=create` with `parent_id` = the test project root id)
returned HTTP 200 but with an HTML error body instead of JSON:

```
DB Access Error - debug_print_backtrace()
#0 .../lib/functions/testcase.class.php(783): database->exec_query()
#1 .../lib/functions/testcase.class.php(432): testcase->createVersion()
#2 .../api/testcases/index.php(902): testcase->create(1, ...)
```

The `events` table (Event Viewer) received several `E_WARNING`s
(`testcase.class.php:687`, `testproject.class.php:1045`) and an
`ERROR ON exec_query() 1064` "SQL syntax ... empty tc_external_id".
Orphaned `nodes_hierarchy` rows were left behind and no `tcversions` row was written.

Creating a test case under a **testsuite** parent worked fine (positive control).

## Root cause chain

1. `api/testcases/index.php:894` `create` action accepts `parent_id` **= the test project
   root id** — `$checkWrite($parentId)` (line 809 `owningProjectOf`) resolves that node to
   the project and only demands the `mgt_modify_tc` right, so the request is not rejected.
2. `testcase::create()` → `create_tcase_only()` (`lib/functions/testcase.class.php:395`).
3. `lib/functions/testcase.class.php:687`:
   ```php
   $path2root = $this->tree_manager->get_path($parent_id);
   $tproject_id = $path2root[0]['parent_id'];
   ```
   `tree::get_path()` (`lib/functions/tree.class.php:408/462`) returns the node chain
   **reversed and excluding the root node** (recursion stops when `parent_id` is 0/NULL).
   For `parent_id` = the project root, `get_path()` returns `[]`.
4. `$tproject_id = null` (`E_WARNING` "Undefined array key 0" / "Trying to access array
   offset on null" at `testcase.class.php:687`).
5. `testproject::generateTestCaseNumber(null)` (`testproject.class.php:1012` →
   `intval(null)=0`) runs `UPDATE ... SET tc_counter=tc_counter+1 WHERE id = 0` (0 rows)
   then `SELECT tc_counter ... WHERE id=0` (0 rows) → `$rs[0]['tc_counter']` at
   `testproject.class.php:1045` → `E_WARNING`, returns null.
6. `createVersion()` (`testcase.class.php:432/783`) receives an **empty `tc_external_id`**
   → `INSERT INTO tcversions (...,tc_external_id,...) VALUES(...,, ...)` → **SQL 1064**.

**Why it breaks (regression source):** the `_get_path` intval() guard that stops recursion
at root nodes makes the root itself invisible in the returned path. Every other
`get_path()` consumer resolves from a *child*, where `[0]` is the node just below the root
and `[0]['parent_id']` is the project id — so they always see a non-empty path. Only the
"parent IS the root" case yields an empty path.

**Blast radius:** `get_path($parent_id)` inside `create_tcase_only()` is the ONLY broken
consumer for root parents. `create_tcase_only()` has 2 call sites: `testcase::create()`
(testcase.class.php:395) and the copy/move path (testcase.class.php:2223). Both benefit;
neither passes a non-testproject root in normal operation.

## Approach — resolve owning project when parent IS the test project root (minimal fix)

`lib/functions/testcase.class.php` `create_tcase_only()` (lines ~685-697): when
`tree::get_path($parent_id)` returns an empty/null path (parent is the tree root, i.e. a
test case created directly under the test project root), fall back to
`$tproject_id = $parent_id`; otherwise keep the original `$path2root[0]['parent_id']`
resolution (byte-for-byte unchanged for the testsuite/nested paths).

```php
$path2root = $this->tree_manager->get_path($parent_id);
if( is_null($path2root) || count($path2root) == 0 )
{
  $tproject_id = $parent_id;
}
else
{
  $tproject_id = $path2root[0]['parent_id'];
}
```

**Alternatives rejected:**
- Reject the API request when `parent_id` is the root: changes behaviour (top-level test
  cases are legal in legacy TestLink) and does not fix the shared `create_tcase_only()`
  copy/move path.
- Change `generateTestCaseNumber()` to handle null: addresses the symptom at the wrong
  layer and would affect other callers.

## Files changed

| File | Change |
|---|---|
| `lib/functions/testcase.class.php` | +12/-2 in `create_tcase_only()`: fall back to `$parent_id` when `get_path()` returns empty |
| `tmp/TLU_Test_Cases.md` | +Suite 777 (4 test cases, 4/4 PASS, re-executed on fresh DB) |

## Verification

- `php -l lib/functions/testcase.class.php` → no syntax errors.
- **Primary (fresh DB, admin browser session):** created project `Repro Project` id=1,
  then `POST /api/testcases/?action=create {"parent_id":1,...}` → HTTP 200
  `{"status":"ok","id":5,"message":"Test case created"}`; `tcversions` row written with
  non-empty `tc_external_id=2` (pre-fix: DB 1064 page, no `tcversions` row).
- **Regression matrix:**
  1. TC directly under project root → succeeds, correct external id ✅
  2. TC under a testsuite (`suite_create` id=8 then create id=9) → HTTP 200, tcversion
     `tc_external_id=3` ✅
  3. Nested Suite→Suite→TC (Sub Suite id=12, Nested TC id=13) → HTTP 200, tcversion
     `tc_external_id=4` ✅
  4. Event Viewer / `events`: only 2 AUDIT (login + tproject created) rows, **0 ERROR /
     0 WARNING** after all creates ✅

## Related

- Issue **#629** (same family): testcase::create() E_WARNING with an empty steps array —
  separate defect in the same create path, out of scope for #777.
