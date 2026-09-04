# Issue #831 — Build UI Pages Resolve Project Scope (verification)

> Child tracking sub-task of enhancement #503 ("builds scoped to the Test Project").

## What this sub-task covered

The scoped deliverable (commit `748555985`, `Refs #831`) makes the build UI pages operate in
**project scope** now that builds carry their own `testproject_id` (added in #826):

- `lib/plan/buildCopyExecTaskAssignment.php` — `init_args()` resolves the owning test project
  directly from the build's `testproject_id` (`lib/plan/buildCopyExecTaskAssignment.php:93-95`)
  instead of walking the testplan node via `get_node_hierarchy_info`. `tplan_id` is read
  defensively (still populated by dual-write until #834 drops it).
- Build listing/selector methods in `lib/functions/testplan.class.php` are project-scoped, so a
  build is shared across the plans of its project:
  - `get_builds_for_html_options()` — `WHERE testproject_id = getProjectIdOfPlan($id)`
    (`testplan.class.php:2076`)
  - `get_builds()` / `get_build_by_name()` / `get_max_build_id()` / `getNumberOfBuilds()` /
    `check_build_name_existence()` — all project-scoped (lines 2127/2328/2377/2404/2431/2481/2523)

## Investigation result — not a defect

A bug-fix pass was triaged onto #831 (oldest open issue without a work-type label — a label gap:
this tracking sub-task should carry `enhancement`). After a **fresh DB import**, the deliverable
is confirmed **already landed on the default branch and correct**:

```
DESCRIBE builds;          -> testproject_id int unsigned NO MUL (column exists, from #826)
git merge-base --is-ancestor 748555985 HEAD -> true

php tmp/repro_831_verify.php → 7/7 PASS
  init_args resolves tproject_id=2000 / tplan_id=2001 from the build row
  get_builds_for_html_options(plan 2001) and (plan 2002) both contain project build 3001
  check_build_name_existence is project-wide
```

There is **no code defect**. The remaining parent-#503 sub-tasks — dropping `builds.testplan_id`
+ swapping the unique key (#834), the cross-plan per-build results report (#835), and the
Test-Project-menu move (which is the body DoD of this very issue #831) — are owned by the
modernize workflow and tracked separately (all still OPEN).

## Files

| File | Purpose in this verification |
|---|---|
| `tmp/repro_831_verify.php` | Seeds project 2000 (plans 2001/2002) + project build 3001; asserts project scope (7 checks) |
| `tmp/TLU_Test_Cases.md` | Regression suite TC-831.1–831.6 |
