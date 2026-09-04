# Bugfix / Feature — Issue #833: XML-RPC & REST API build scope

## Summary

Sub-task of #503 ("builds scoped to Test Project, not Test Plan").

The `builds` entity is scoped to the **Test Project**. The XML-RPC and REST
APIs still exposed the legacy, plan-centric creation contract, which did not let
a caller state the project scope and did not reflect the project-scoped
resolution used by `getBuildsForTestPlan` / `getLatestBuildForTestPlan` /
`reportTCResult`. This change makes the project scope explicit and
backward-compatible.

## What Changed

### XML-RPC — `createBuild`

**File:** `lib/api/xmlrpc/v1/xmlrpc.class.php`

- **New optional parameter `testprojectid`.**
  - When supplied, it **takes precedence** and defines the builds-scope project
    (passed as `$testProjectID` to `build::create()`'s optional `$tproject_id`
    arg and used for the source-build `copy_testers_from_build` pre-check query).
  - When omitted, the project is derived from `testplanid` via
    `testplan::getProjectIdOfPlan()` — **unchanged legacy behaviour**.
  - `testplanid` remains **required** and is still used for the
    `testplan_create_build` right check.
- **Validation** (`_checkCreateBuildRequest`): when `testprojectid` is present it
  is validated with `checkTestProjectID()` and must equal the plan's project. A
  mismatch returns `INVALID_TESTPROJECTID` / `INVALID_TESTPROJECTID_STR`.
- `build::create()` already dual-writes `testproject_id` and `testplan_id`, so
  rows are stored in the correct project scope.

### XML-RPC — dead code fix

**File:** `lib/api/xmlrpc/v1/xmlrpc.class.php`

- `_getLatestBuildForTestPlan()` (protected) referenced the non-existent method
  `$this->_getBuildsForTestPlan($args)`. It now resolves via the real
  `testplan::get_builds()` and returns the max-id build of the plan's project.

### XML-RPC — documentation

Docblocks updated to state the project-scoped semantics and the possible row
re-binding for `getBuildsForTestPlan`, `getLatestBuildForTestPlan`, and
`reportTCResult` (`buildname`/`buildid` resolution now occurs within the plan's
**project**, not the plan alone).

### REST v2 & v3 — `createBuild`

**Files:** `lib/api/rest/v2/tlRestApi.class.php`, `lib/api/rest/v3/RestApi.class.php`

- **New optional `testprojectid`** request property: when supplied it must equal
  the plan's project; otherwise a `422 Unprocessable Entity` is returned with a
  `details` entry. When omitted, behaviour is unchanged (project derived from the
  plan). Docblocks updated; new `API_BUILD_PROJECT_MISMATCH` l10n label added to
  `en_GB` and `en_US` locales.

### Sample client

**File:** `lib/api/xmlrpc/v1/sample_clients/php/clientCreateBuild.php`

- Demonstrates the new optional `testprojectid` argument and notes the
  backward-compatible semantics.

## Semantics Summary

| Method | Parameter | Pre-condition | Behaviour |
|--------|-----------|---------------|-----------|
| `createBuild` | `testplanid` | required | rights check + plan resolution |
| `createBuild` | `testprojectid` | optional | must equal the plan's project when supplied; else derived from plan |
| `getBuildsForTestPlan` | `testplanid` | required | returns builds of the plan's **project** (may include builds from other plans) |
| `getLatestBuildForTestPlan` | `testplanid` | required | returns max-id build of the plan's **project** |
| `reportTCResult` | `buildid`/`buildname` | optional | buildname resolved within the plan's **project** |

## Why Backward Compatible

- All new parameters/properties are **optional**; omitting them yields the exact
  legacy behaviour.
- The only behavioural difference for existing callers is **row-binding**: by
  design (issue #503) resolutions return builds across the plan's project. This is
  documented in the docblocks.

## Verification

- `php -l` passes on all edited PHP files and locale files.
- DB inspection not possible in the sandbox (no reachable MySQL/MariaDB), so a
  live API round-trip is not asserted here. Run `php tmp/repro_833.php` against a
  test Link instance to exercise `createBuild` with and without `testprojectid`.
