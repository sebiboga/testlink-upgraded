# Bugfix — Issue #1512: deleting a test case version orphans its `testcase_platforms` rows

## Problem

Deleting a single test case version (modern BFF `POST /api/testcases/?action=delete_version`)
removes the `tcversions` + `nodes_hierarchy` entries but **leaves orphaned
`testcase_platforms` rows** that reference the deleted version. Reproduced on
every single-version delete (testcase TC Login, version with platforms
Android/iOS/Windows assigned).

- After `delete_version`, `SELECT tcversion_id,platform_id FROM testcase_platforms
  WHERE testcase_id=<tid>` still returns the rows of the deleted version while
  `tcversions` / `nodes_hierarchy` no longer contain it.
- Manual cleanup was previously required:
  `DELETE FROM testcase_platforms WHERE tcversion_id=<dead version>;`

The same hole exists on the whole-test-case delete path
(`POST /api/testcases/?action=delete` and legacy `tcEdit` doDelete), because both
funnel through the same core delete routine.

## Repro steps (measured)

Environment: http://localhost:8082 (PHP built-in server, docroot = repo root),
MariaDB `testlink`, login admin/admin. Fresh-import DB; fixture rebuilt via SQL to
mirror the reported layout: testproject `Platform Demo` (prefix PD), suite `Suite
A`, testcase `TC Login` (node 3), tcversion node 4 (`tcversions.id=4`, v1), step
node, platforms Android(1)/iOS(2)/Windows(3) `enable_on_design=1`, and
`testcase_platforms` rows (3,4,1),(3,4,2),(3,4,3).

1. Login: `curl -X POST -c /tmp/opencode/cookies.txt -H "X-Requested-With:
   XMLHttpRequest" -d 'login=admin&password=admin'
   http://localhost:8082/api/auth/login` → `{"status":"ok"}`.
2. `curl ... -X POST 'http://localhost:8082/api/testcases/?action=create_version'
   -d '{"tcase_id":3}'` → `{"status":"ok","tcversion_id":5}` — `create_version`
   copies the platform rows to v5 via `copyPlatformsTo`
   (`lib/functions/testcase.class.php:2456`).
3. `curl ... -X POST 'http://localhost:8082/api/testcases/?action=delete_version'
   -d '{"tcase_id":3,"tcversion_id":5}'` → `{"status":"ok",...,
   "versions_left":1}`.
4. Bug observed (pre-fix): `testcase_platforms` still holds (5,1),(5,2),(5,3);
   `SELECT id FROM tcversions WHERE id IN (4,5)` → only 4; `nodes_hierarchy`
   has no id 5. `` `orphans` `` query
   (`SELECT COUNT(*) FROM testcase_platforms WHERE tcversion_id NOT IN (SELECT
   id FROM tcversions)`) → > 0.

**Expected:** deleting a version must also purge its `testcase_platforms` rows —
no orphans accumulate.

## Root cause

Chain (each hop = `file:line`):

1. Modern BFF `delete_version`: `api/testcases/index.php:2254` →
   `$tcaseMgr->delete($tcaseId, $tcversionId)`.
2. `testcase::delete()` `lib/functions/testcase.class.php:1659` →
   `$this->_blind_delete($id, $version_id, $children)` after `_execution_delete`
   + `deleteAllTestCaseRelations`.
3. `_blind_delete` (`lib/functions/testcase.class.php:1834-1946`) executes
   DELETEs on: `user_assignments`, `testplan_tcversions`, `tcsteps`,
   `testcase_script_links`, `testcase_keywords`, `req_coverage`, `tcversions`.
   **`testcase_platforms` is absent from the whole list.**
4. Result: the version subtree is removed but `testcase_platforms` rows
   (`testcase_id` + `tcversion_id`) pointing at the dead version survive. No
   other delete path touches them — the only `testcase_platforms` DELETEs in
   `lib/` are `deletePlatforms` / `deletePlatformsByLink`
   (testcase.class.php:9858-9926), both UI-assignment-scoped, never called from
   a version delete. `testcase_platforms` has **no FK constraints** (verified via
   `information_schema.KEY_COLUMN_USAGE`), so nothing references or cascade-deletes it.

**Why it broke now:** platform rows are written since the #915 design-platform
assignment work: `create_new_version` → `copyPlatformsTo` inserts them on every
new version, and version delete was never taught to clean them up.

**Blast radius:**
- single-version deletes: modern BFF `delete_version` + legacy `tcView_viewer`
  delete_tc_version (`doDelete` with a concrete version);
- whole-TC deletes: modern BFF `action=delete` (`api/testcases/index.php:2205`)
  + legacy `tcEdit` doDelete → `self::ALL_VERSIONS` branch of `_blind_delete`
  (testcase.class.php:1842-1847);
- no other tables reference `testcase_platforms` (zero FK rows).

## Fix (approach, why this method)

Added a single DELETE inside `_blind_delete`
(`lib/functions/testcase.class.php`, right after the `req_coverage` delete and
before the final `tcversions` delete, which must stay last):

```php
$sql[]="/* $debugMsg */
        DELETE FROM {$this->tables['testcase_platforms']}
        WHERE testcase_id = {$id}
        AND tcversion_id IN ({$tcversion_list})";
```

Why the core was fixed instead of the API:
- `$tcversion_list` already holds the single version id on the single-version
  path and the comma-joined version list on `ALL_VERSIONS`, so **one** statement
  covers both delete flavors;
- `testcase_id = $id` scopes the purge; only versions being destroyed lose their
  platform rows, surviving versions keep theirs;
- fixing at core level removes the orphan risk for the modern BFF **and** the
  legacy screens (`tcView_viewer`, `tcEdit`) in both single-version and whole-TC
  deletes. An API-only fix in `delete_version` would have left `action=delete`
  and the legacy paths broken.
- Rejected alternative: API-level `DELETE FROM testcase_platforms WHERE
  tcversion_id=<id>` in `delete_version` — narrow, duplicates core logic, does
  not fix whole-TC / legacy paths.

Alternative considered but not chosen: deleting `testcase_platforms` before the
other version-scoped deletes — unnecessary, because the table has no FK
constraints; kept with the sibling DELETEs for readability.

## Files changed

| File | Change |
|---|---|
| `lib/functions/testcase.class.php` | +4 lines: `testcase_platforms` purge inside `_blind_delete()` (Refs #1512) |

## Verification matrix (all PASS, measured)

| # | Case | Before | After |
|---|---|---|---|
| 1 | `delete_version` single-version | `testcase_platforms` orphans remain | 0 orphans; surviving version keeps platforms |
| 2 | `action=delete` whole TC | orphans remain (per version) | 0 orphans + TC node gone |
| 3 | `create_version` | copies platforms to the new version | unchanged (rows present, then purged correctly on delete) |
| 4 | Modern Test Specification UI delete-this-version | orphans remain | v2 removed, `testcase_platforms` for v2 gone, v1 still shows Android/iOS/Windows |
| 5 | Event Viewer | — | no new Error/Warning rows (only pre-existing AUDIT=16 LOGIN rows) |

Proof data (measured on the fixed branch):

- `create_version` → `{"status":"ok","tcversion_id":6}`; platforms (6,1),(6,2),(6,3)
  present.
- `delete_version {tcase_id:3, tcversion_id:6}` → `{"status":"ok",...,
  "versions_left":1}`; afterwards
  `SELECT tcversion_id,platform_id FROM testcase_platforms WHERE testcase_id=3`
  → only `4/1, 4/2, 4/3`; `SELECT COUNT(*) FROM testcase_platforms WHERE
  tcversion_id NOT IN (SELECT id FROM tcversions)` → **0**.
- Whole-TC `action=delete {tcase_id:3}` → `{"status":"ok",...}`;
  nodes 3/4 gone, `testcase_platforms` orphans 0.
- Browser: Test Specification → TC Login → Create New Version (Ver. 2 shows
  Android/iOS/Windows) → Delete this version → confirm → Ver. 1 remains with
  Android/iOS/Windows intact (`docs/screenshots/issue-1512-verify.png`).
- `php -l lib/functions/testcase.class.php` → no syntax errors.

## Docs / wiki

This page mirrors `tmp/wiki-repo/Bugfix-Issue-1512-TestcasePlatforms-Orphans-On-DeleteVersion.md`
(screenshots kept in the wiki clone; `docs/` keeps the code reference).