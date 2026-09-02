# Issue 827 — Data migration: merge duplicate (testproject_id, name) builds

## Issue #827

Sub-task of enhancement
[#503](https://github.com/sebiboga/testlink-upgraded/issues/503) — *"builds
should be scoped to the Test Project, not to a single Test Plan"* — step 3/4
(migration). Requires a merge of duplicate builds before the new UNIQUE
`(testproject_id, name)` key (#834) can be applied.

**Goal**: provide an operator-run migration that merges builds sharing the same
`(testproject_id, name)` (created across different test plans) into a single
survivor, re-pointing every reference, without data loss.

## The problem (root cause)

Under the project-scoped model (#826 adds `builds.testproject_id`; #834 will
enforce `UNIQUE (testproject_id, name)`), pre-migration data can contain builds
with the **same name in different test plans of the same project**. Example:
plan A build `v1.0` (id 3001) and plan B build `v1.0` (id 3002) inside project
2000. The new unique key would reject both coexisting.

## The fix (HOW) — what the migration does

`tmp/migrate_827.php` groups builds by `(testproject_id, name)`, picks the
**earliest `creation_ts`** (lowest `id` as tiebreak) as the survivor, then:

1. **Dry-run by default** — reports group/survivor/victim and logs per-artifact
   metadata conflicts (`commit_id`, `tag`, `branch`, `release_date`,
   `closed_on_date`, `release_candidate`) — survivor keeps its values, discarded
   values are logged. Writes nothing.
2. **`--apply`** — inside an explicit `BEGIN`/`COMMIT`:
   - re-point `executions.build_id`, `execution_tcsteps_wip.build_id`,
     `user_assignments.build_id` → survivor;
   - for `cfield_build_design_values` (PK `(field_id, node_id)`): delete the
     victim's rows whose `field_id` the survivor already owns (collision), then
     re-point the rest → survivor;
   - delete each victim build row.
3. **Final verification** — recomputes remaining duplicate name-groups and
   reports `Merge complete ✓` when none remain.
4. **Idempotent** — re-running after a merge prints
   `No duplicate builds found — nothing to do.`

### Why this method (alternatives rejected)

* **Survivor = earliest creation_ts** — deterministic and matches "the product
  release was first born here"; earliest metadata is preserved.
* **Cascading FK deletes** — rejected: would destroy execution/assignment
  history instead of re-pointing it.
* **Renaming duplicate builds** (suffix `_2`, etc.) — rejected: changes
  user-visible build names and corrupts reports; merging is the least-surprise
  path.
* **Ignoring the cfield PK collision** — rejected: bare re-pointing would raise
  a duplicate-key error; the delete-then-repoint order avoids it while keeping
  the survivor's value.
* **`try/catch/ROLLBACK` around a failed merge** — rejected during development:
  `database->exec_query()` dies and auto-rolls-back (closes the connection) on a
  DB error, so such a block was dead code.

## Files

* `tmp/migrate_827.php` — the migration (dry-run default, `--apply` merges).
* `tmp/fixtures_827.php` — seeds project 2000, plans 2001/2002, duplicate `v1.0`
  builds 3001/3002, executions, wip, assignment and cfield rows for testing.
* `tmp/TLU_Test_Cases.md` — regression TC-503.1–503.13.

Commit: `d5cf598e8` (`feat(builds): data migration to merge duplicate
project-scoped builds (Refs #827)`).

## Verification (bug-fix/verification run)

Ran against MariaDB 11.4 (fresh import, empty `builds`):

* Fixture seeded 2 duplicate `v1.0` builds across plans 2001/2002.
* **Dry-run**: reported survivor=3001 / merge=3002 plus `commit_id`/`branch`
  conflicts, and wrote nothing (2 builds remained, exec 6002 still → build 3002).
* **`--apply`**: re-pointed executions 6001/6002, execution_tcsteps_wip 7001,
  user_assignments 7101 and the cfield value → build 3001; victim 3002 deleted;
  survivor kept `commit_id='abc'`, `branch='main'` (earliest).
* **cfield PK collision edge case**: seeded `(1,3001)`, `(1,3002)`, `(2,3002)`;
  after merge → `(1,3001)` survivor value kept, `(2,3001)` re-pointed; **no PK
  violation**.
* **Idempotency**: re-run `--apply` → `No duplicate builds found`.
* **Events**: `events` table 0 rows — no Error/Warning introduced.

## Regression matrix

* (a) dry-run writes nothing ✓
* (b) merge re-points executions / wip / assignments / cfield ✓
* (c) victim build deleted ✓
* (d) survivor keeps earliest metadata, conflicts logged ✓
* (e) cfield PK collision handled without duplicate-key error ✓
* (f) rerun idempotent ✓
* (g) no new events table entries ✓

All 13/13 regression cases (TC-503.1–503.13) PASS.
