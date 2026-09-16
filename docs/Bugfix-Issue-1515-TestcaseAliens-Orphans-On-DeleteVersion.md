# Bugfix — Issue #1515: deleting a test case version orphans its `testcase_aliens` rows

## Problem

Deleting a single test case version (modern BFF `POST /api/testcases/?action=delete_version`)
removes the `tcversions` + `nodes_hierarchy` entries but **leaves orphaned
`testcase_aliens` rows** (`testcase_id` + `tcversion_id` + `alien_id`) that
reference the deleted version. Same bug class as #1512 (which covered
`testcase_platforms`).

- After `delete_version`, `testcase_aliens` still holds rows for the deleted
  version while `tcversions` / `nodes_hierarchy` no longer contain it.
- Orphan check that proves it:
  `SELECT COUNT(*) FROM testcase_aliens ta LEFT JOIN tcversions t ON ta.tcversion_id = t.id WHERE t.id IS NULL` → > 0.

The same hole exists on the whole-test-case delete path
(`POST /api/testcases/?action=delete` and legacy `tcView_viewer`/`tcEdit` doDelete),
because both funnel through the same core delete routine.

## Repro steps (measured)

Environment: http://localhost:8082 (PHP built-in server, docroot = repo root),
MariaDB `testlink`, login admin/admin. Fresh-import DB; fixture rebuilt via SQL:
testproject `Aliens Demo` (id=1, prefix AL), user 1 = admin (role 8) on TP 1,
suite `Suite A` (node 2), testcase `TC Main` (node 3), tcversion node 4
(`tcversions.id=4`, v1), alien `ALIEN-BUG-1515` (`aliens.id=1`), and
`testcase_aliens` row `(1,3,4,1)`.

1. Login: `curl -X POST -c /tmp/opencode/cookies.txt -H "X-Requested-With:
   XMLHttpRequest" -d 'login=admin&password=admin' http://localhost:8082/api/auth/login`
   → `{"status":"ok"}`.
2. Create an alien-assigned version: `POST /api/testcases/?action=create_version`
   `{"tcase_id":3}` → `{"status":"ok","tcversion_id":5}` (alien rows are assigned
   per version — the design-screen alien editor and `copyAliensTo` on
   `create_new_version` at `testcase.class.php:2463` are the writers).
3. `POST /api/testcases/?action=delete_version` `{"tcase_id":3,"tcversion_id":5}`
   → `{"status":"ok",...,"versions_left":1}`.
4. Bug observed (pre-fix): `testcase_aliens` still holds `(1,3,5,1)`; `SELECT id
   FROM tcversions` has no 5; `nodes_hierarchy` has no node 5; the orphan query
   above → **1**.

**Expected:** deleting a version must also purge its `testcase_aliens` rows — no
orphans accumulate.

## Root cause

Chain (each hop = `file:line`):

1. Modern BFF `delete_version`: `api/testcases/index.php:2461` → `$tcaseMgr->delete($tcaseId, $tcversionId)` (index.php:2501); whole-TC `?action=delete` → `$tcaseMgr->delete($tcaseId)` (index.php:2452).
2. `testcase::delete()` `lib/functions/testcase.class.php:1632` → after `_execution_delete` + `deleteAllTestCaseRelations`, calls `$this->_blind_delete($id, $version_id, $children)` (testcase.class.php:1659).
3. `_blind_delete` (`lib/functions/testcase.class.php:1834-1945`) executes DELETEs on: `user_assignments`, `testplan_tcversions`, `tcsteps`, `testcase_script_links`, `testcase_keywords`, `req_coverage`, `testcase_platforms` (added by #1512), `tcversions`. **`testcase_aliens` is absent from the whole list.**
4. `testcase_aliens` has **no FK constraints** (verified via `information_schema.KEY_COLUMN_USAGE`: only local keys PRIMARY / `testcase_aliens_uidx1` / `testcase_aliens_idx1`), so nothing references or cascade-deletes it. The rows survive version destruction silently.

**Why it broke now:** alien rows are written per version (design-screen alien
editor, plus `copyAliensTo` intended to run on every new version at
`testcase.class.php:2463`), and version delete was never taught to clean them up.

**Blast radius:**
- single-version deletes: modern BFF `delete_version` + legacy `tcView_viewer`
  delete_tc_version (`doDelete` with a concrete version);
- whole-TC deletes: modern BFF `action=delete` + legacy `tcEdit` doDelete →
  `self::ALL_VERSIONS` branch of `_blind_delete` (testcase.class.php:1842-1847);
- the only other `testcase_aliens` DELETEs in `lib/` are the UI-assignment-scoped
  `deleteAliensByLink` (testcase.class.php:10416) and `deleteAliens`
  (testcase.class.php:10442) — never called from a version delete.

## Fix (approach, why this method)

Added a single DELETE inside `_blind_delete` (`lib/functions/testcase.class.php`,
right after the `testcase_platforms` block from #1512 and before the final
`tcversions` delete, which must stay last):

```php
$sql[]="/* $debugMsg */
        DELETE FROM {$this->tables['testcase_aliens']}
        WHERE testcase_id = {$id}
        AND tcversion_id IN ({$tcversion_list})";
```

Why the core was fixed instead of the API (same reasoning as #1512):
- `$tcversion_list` already holds the single version id on the single-version
  path and the comma-joined version list on `ALL_VERSIONS`, so **one** statement
  covers both delete flavors;
- `testcase_id = $id` scopes the purge; only versions being destroyed lose their
  alien rows, surviving versions keep theirs;
- fixing at core level removes the orphan risk for the modern BFF **and** the
  legacy screens (`tcView_viewer`, `tcEdit`) in both single-version and whole-TC
  deletes. An API-only fix in `delete_version` would have left `action=delete`
  and the legacy paths broken.
- Rejected alternative: API-level `DELETE FROM testcase_aliens WHERE
  tcversion_id=<id>` in `delete_version` — narrow, duplicates core logic, does
  not fix whole-TC / legacy paths. Placing the delete earlier than its sibling
  DELETEs was also considered: unnecessary, the table has no FK constraints;
  kept with the sibling version-scoped DELETEs for readability.

## Files changed

| File | Change |
|---|---|
| `lib/functions/testcase.class.php` | +5 lines: `testcase_aliens` purge inside `_blind_delete()` (Refs #1515) |

## Verification matrix (all PASS, measured)

| # | Case | Before | After |
|---|---|---|---|
| 1 | `delete_version` single-version | `testcase_aliens` orphans remain (measured: 1 orphan) | 0 orphans; surviving version keeps its alien row |
| 2 | `action=delete` whole TC | orphans remain (per version) | 0 aliens left, 0 orphans, TC nodes gone |
| 3 | Alien rows of untouched versions | — | unaffected (v1 keeps `alien_id=1`) |
| 4 | Event Viewer | — | no new Error/Warning rows (only pre-existing AUDIT=16 LOGIN row; measured in `events`) |

Proof data (measured on branch `fix/issue-1515`, commit `10204c0a4`):

- `INSERT testcase_aliens (1,3,9,1)` on version 9 → `delete_version
  {"tcase_id":3,"tcversion_id":9}` → `{"status":"ok","result":1,"versions_left":1}`;
  afterwards `testcase_aliens` = only `(3,4,1)`; orphans query → **0**.
- Whole-TC: version node 10 + `testcase_aliens (1,3,10,1)` → `action=delete
  {"tcase_id":3}` → `{"status":"ok"}`; `testcase_aliens` COUNT = 0, orphans = 0.
- `php -l lib/functions/testcase.class.php` → no syntax errors.
- `events` table during the whole run → only pre-existing row id 1
  (`log_level=16` LOGIN), no new Error/Warning.

## Related pre-existing bug (out of scope, filed separately)

While reproducing, `copyAliensTo` (testcase.class.php:10558) was found to never
copy alien rows on `create_new_version` — it passes the audit-context **array**
`$adt` as the scalar `$alienRelType` argument of `addAliens`
(testcase.class.php:10579 → signature at testcase.class.php:10306). This is filed
as **#1517** with the `bug` label. It does not affect this fix: once alien rows
exist (via any writer), the orphan purge now removes them on version delete.

## Docs / wiki

This page mirrors `tmp/wiki-repo/Bugfix-Issue-1515-TestcaseAliens-Orphans-On-DeleteVersion.md`
(backend-only bug: evidence is SQL/curl output above, no UI screenshot).