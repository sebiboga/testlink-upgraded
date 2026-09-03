# Bugfix Issue #841 — Dashboard "Failed to load dashboard." (builds schema mismatch)

## Problem
Dashboard showed "Failed to load dashboard." — the BFF `api/mainpage/index.php`
returned a raw DB Access Error trace. Both `testplan::getNumberOfBuilds()` and
`testplan::get_builds()` (the non-`getCount` branch) queried
`builds.testproject_id`, but the `builds` table has **no `testproject_id`
column** (only `testplan_id`). The build-scope migration (#503/#829/#834)
updated the code before the schema was migrated.

## Fix (commit `eb4df8315`)
In `lib/functions/testplan.class.php`:
- `getNumberOfBuilds()`: filter by `builds.testplan_id` (current schema).
- `get_builds()` non-`getCount` branch: same — filter by `builds.testplan_id`.

Both methods include a NOTE to flip to project scope once
`builds.testproject_id` lands (#834). This is a hotfix, not the final
migration.

## Verification
Dashboard loads: `status:ok`, tproject/tplan names populated, total 196 TCs,
0 DB errors.
