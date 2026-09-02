# Issue 826 — Schema: add builds.testproject_id + latest_exec_by_build view

## Issue #826

Sub-task of enhancement
[#503](https://github.com/sebiboga/testlink-upgraded/issues/503) — *"builds
should be scoped to the Test Project, not to a single Test Plan"*. Step 1 of the
issue sequence (schema) plus part of the views work.

**Goal**: make a build know its owning **Test Project** directly, and provide a
project-scoped execution rollup, without changing current behaviour.

## The problem (root cause)

In TestLink 1.9.20 the `builds` table carries only `testplan_id` — a build is
born attached to exactly **one** Test Plan:

```
builds.id, builds.testplan_id, builds.name, ...
```

Consequences:

1. A build cannot be shared across multiple plans of the same Test Project; each
   plan must duplicate its own build for the same product release.
2. A project-scoped "quality of build X" report could not be expressed, because
   a build had a single plan owner and every reach to the project required
   `JOIN builds -> testplans`.
3. The existing `latest_exec_by_context` view groups on
   `(tcversion_id, testplan_id, build_id, platform_id)` — plan-baked, so a build
   executed in two plans yields two rollups for the same (tcversion, build,
   platform).

## The fix (HOW) — additive schema

Chosen method: **additive** DDL — add a column and a new view; change nothing
that other code already reads.

1. **Add `builds.testproject_id`** (`int(10) unsigned NOT NULL DEFAULT '0'`),
   backfilled from `testplans.testproject_id` via the current `builds.testplan_id`.
2. **Keep dual-write** — `testplan_id` stays populated, so zero existing queries
   change behaviour (blast radius contained to schema + one new view).
3. **Add project index** on `builds.testproject_id`.
4. **Add `latest_exec_by_build` view** — group on
   `(tcversion_id, build_id, platform_id)` with `max(id)` (NO `testplan_id`),
   i.e. per build across all plans. Unlocks the cross-plan per-build report.
5. **Register the view** in `object::getDBViews()`
   (`lib/functions/object.class.php:341`) so the DB-consistency check does not
   flag it as missing.
6. **Fresh install SQL** updated in
   `install/sql/mysql/testlink_create_tables.sql` (builds table + view), plus
   an idempotent **migration** `tmp/migrate_826.php` for existing installs.

The unique-key swap (`(testplan_id,name)` -> `(testproject_id,name)`) and the
final `testplan_id` drop are deliberately deferred to sub-task
[#834](https://github.com/sebiboga/testlink-upgraded/issues/834) — keeping this
step reversible and non-breaking.

### Why this method (alternatives rejected)

* **Dropping `testplan_id` now / swapping the unique key now** — rejected: would
  be a breaking change and is out of scope for this additive step; deferred to
  #834.
* **A separate link table `build_projects`** — rejected: over-engineering for a
  single owning project; a plain FK column on `builds` is simpler and matches the
  existing `testplan_id` pattern.

## Files changed

* `install/sql/mysql/testlink_create_tables.sql` — `builds.testproject_id` column
  + project index + `latest_exec_by_build` view (fresh installs).
* `lib/functions/object.class.php` — register `latest_exec_by_build` in
  `getDBViews()`.
* `tmp/migrate_826.php` — idempotent additive migration (column + backfill +
  index + view) for existing installs.

Commit: `14b6f673d9ec4400e3509815c45e1fe42a223342` (`feat(builds): scope builds
to test project — add builds.testproject_id + latest_exec_by_build view`).

## Verification

Ran against MariaDB `testlink` (fresh import, empty dataset):

* `SHOW COLUMNS FROM builds` → `testproject_id int unsigned NOT NULL MUL 0` present.
* Backfill: zeroed `testproject_id`, re-ran JOIN backfill → correctly restored
  from `testplans.testproject_id`.
* View: inserted 3 executions across 2 different plans (tcversion 101, build 1,
  platform 0) → `latest_exec_by_build` returned **one** row with `max(id)=3`
  (project-scoped rollup works across plans).
* Migration re-run → "already up to date" (idempotent).
* `events` table clean (only the normal LOGIN audit entry); app HTTP 200; no
  browser console errors after login.

## Regression matrix

* (a) builds CRUD — unaffected, `testplan_id` retained.
* (b) legacy view `latest_exec_by_context` — unchanged.
* (c) fresh install creates both column and new view.
* (d) migration idempotent on already-migrated DB.
