# Issue #834 — Finalize builds schema: drop `testplan_id` + swap unique key (blocked)

> Child tracking sub-task of enhancement #503 ("builds scoped to the Test Project").

## Status: OPEN — destructive drop blocked by un-migrated readers

Issue #834 asks to finalize the `builds` schema:
- Replace `UNIQUE KEY (testplan_id, name)` with `UNIQUE KEY (testproject_id, name)`.
- Drop `builds.testplan_id`.

The issue's own precondition is *"After all readers are migrated (sub-tasks #826-#832)"*.
That precondition **is not yet met**, so the drop must not be forced. Attempting it would
break several live `builds.testplan_id` readers at runtime.

## Approach (the HOW)

1. **Investigate before touching code** — reproduce the state on a freshly imported DB,
   enumerate every reader of `builds.testplan_id`, and check whether the #826-#832 reader
   migration actually completed.
2. **Quantify the blast radius** rather than assume "ready to drop".
3. **Refrain from a partial, risky migration** — the rework spans many SQL sites whose
   correct project-scoped form is non-trivial (builds are now shared across a project's
   plans), and doing it half-way would leave a broken build surface. Documented findings
   instead; the issue stays open for the modernize flow / a larger follow-up.

## Measured evidence

Live schema (MariaDB 127.0.0.1 `testlink`) — `testplan_id` still present, unique key
still `(testplan_id, name)`:

```
id, testproject_id (NOT NULL DEFAULT 0), testplan_id (NOT NULL DEFAULT 0), name, …
UNIQUE KEY `name` (testplan_id, name)
KEY testplan_id (testplan_id); KEY testproject_id (testproject_id)
```

`builds` is currently empty (`count(*)` = 0), so the #827 duplicate-merge has nothing to
conflict on today.

## Un-migrated `builds.testplan_id` readers (would break on drop)

| Location | Query / use |
|---|---|
| `lib/functions/testplan.class.php:4154` (+ 4427, 4473, 4514, 4567, 4630, 4743, 4828, 4916, 5015, 5061, 5361, 5435, 5583, 5678) | `JOIN builds B ON B.testplan_id = TPTCV.testplan_id` — per-build execution assignment / reporting (15 sites) |
| `lib/functions/tlTestPlanMetrics.class.php:2432, 2478, 2567, 2616` | Not-Run / metrics joins builds to `testplan_tcversions` by plan id |
| `lib/functions/testplan.class.php:2024` | `DELETE FROM builds WHERE testplan_id={$id}` |
| `lib/functions/testplan.class.php:7298` | `SELECT … FROM builds WHERE testplan_id={$id}` (build max-id lookup) |
| `lib/api/rest/v2/tlRestApi.class.php:1308` | `$build['testplan_id']` → permission context in `updateBuild` |
| `lib/api/rest/v3/RestApi.class.php:710` | same |
| `lib/plan/buildCopyExecTaskAssignment.php:95` | defensive `isset($bi['testplan_id'])` (only already-defensive site) |

Sub-task #832 (execution page build selection → project builds) is also still **OPEN**
(`gh issue view 832`). The execution-page selector already routes through the
project-scoped `get_builds_for_html_options()` (execSetResults.php:1566 / execDashboard.php:208),
but the per-build reporting/metrics joins and the plan-scoped delete/read above remain.

## Root cause chain

1. #503 scoped builds to the Test Project; `builds.testproject_id` added in #826.
2. `builds.testplan_id` retained as **dual-write** during migration (see
   `docs/Bugfix-Issue-831-Build-UI-Project-Scope-Verification.md`: *"dual-write until #834 drops it"*).
3. The reporting/metrics/delete readers that JOIN `builds.testplan_id` were never converted —
   so the "all readers migrated" precondition is unmet.

## Recommended fix path (future run)

1. Convert metric/assignment joins to project scope: join `testplans TP ON TP.id =
   TPTCV.testplan_id` then `B ON B.testproject_id = TP.testproject_id` (plus `B.id IN
   (project-builds)` predicate), or `B ON B.id = UA.build_id` where `user_assignments`
   already carries `build_id`.
2. Rework `testplan.class.php:2024/7298` delete/read to project scope via
   `getProjectIdOfPlan($id)`.
3. Rework REST v2/v3 `updateBuild` permission context off `$build['testplan_id']`.
4. Run `tmp/fixtures_827.php` duplicate-merge, then `ALTER TABLE builds DROP COLUMN
   testplan_id` and recreate `UNIQUE KEY (testproject_id, name)`, re-run full regression.

## Conclusion

This is an architecture-grade migration, not a single-line defect, and its prerequisite in
the issue body is not satisfied. Per the fix rulebook the destructive drop is **not forced**;
findings are documented and the issue remains **OPEN** for the modernize flow.

## Files

| File | Purpose |
|---|---|
| `docs/Bugfix-Issue-834-Schema-Finalize-Blocked.md` | This analysis |