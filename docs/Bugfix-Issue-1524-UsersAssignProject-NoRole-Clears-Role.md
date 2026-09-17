# Issue 1524 — usersAssignProject: setting a role to "-- no role --" never un-assigns (map drops 0 rows)

**Issue:** [#1524](https://github.com/sebiboga/testlink-upgraded/issues/1524)
**Branch:** `fix/issue-1524`
**Status:** VERIFIED-FIXED (2026-09-17)

## Symptom

On the modern **Assign Test Project Roles** screen, clearing a user's role to
`-- no role --` and clicking **Save Changes** is silently ignored: the request
succeeds (`{"status":"ok"}`) but the user keeps their previous role, and the
table reloads showing the old role. Same flow on **Assign Test Plan Roles**
was verified **working** (the plan screen keeps `0` entries, so it was never
affected).

Measured after the failing save:

```sql
SELECT * FROM user_testproject_roles;
-- +---------+----------------+---------+
-- | user_id | testproject_id | role_id |
-- +---------+----------------+---------+
-- |       1 |              1 |       9 |   <-- still assigned, should be gone
-- +---------+----------------+---------+
```

## Repro steps

1. Fresh-import DB. Recreate fixture: nodes `FixtureProject` (id 1, node_type 1),
   `FixturePlan` (id 2, node_type 5), rows in `testprojects`/`testplans`; the
   single active user is `admin` (id 1). (Fresh DB has no projects at all.)
2. Log in as `admin/admin` at http://localhost:8082, open
   `gui/templates/usermanagement/usersAssignProject.html?tproject_id=1`.
3. Set the only user's **Assigned Role** to `leader` (`9`) and save.
   → `user_testproject_roles = (1,1,9)`.
4. Set the same select back to `-- no role --` (`0`) and save.
5. **Before fix:** no error, role NOT removed, `(1,1,9)` still present.
   **After fix:** row deleted, `SELECT` returns 0 rows.

## Root cause

`gui/templates/usermanagement/usersAssignProject.html:198`:

```js
if (uid && roleVal > 0) assignments[uid] = roleVal;
```

Chain:

1. The map builder filters out every user whose role select value is `0`, so a
   "clear role" action yields `assignments == {}` for a single user (measured
   live: `builtAssignments = "{}"` with `selectValue = "0"`).
2. `api/roles/index.php:511-513`: PUT `/tproject-roles` short-circuits an empty
   map as a no-op (legacy parity guard, added with #1523) → no manager call.
3. Even without that guard, `api/roles/index.php:516-517` deletes only
   `array_keys($assignments)`; the cleared user id is absent from the map, so
   `deleteUserRoles(tproject_id, [...])` never targets their row —
   `testproject.class.php:1831-1839` also treats `[]` as a no-op.

**Why it broke:** the modern screen dropped the legacy semantic. Legacy
`lib/usermanagement/usersAssign.php:557-573` (`doUpdate`) deletes for
`array_keys($map)` even when entries are `0` and re-adds only `if ($role_id)`.
The plan twin `usersAssignPlan.html:182` kept this with `if (uid)` and was
verified working end-to-end.

## Fix

`gui/templates/usermanagement/usersAssignProject.html:198`:

```js
if (uid) assignments[uid] = roleVal;
```

The map now always contains every visible user; users with role `0` are deleted
(correct) and roles `> 0` re-added. Empty map can only occur with zero user rows
(nothing to delete), so the #1523 empty-map no-op guard stays valid. BFF and
manager layer required **no change** (the `{uid:0}` contract already deletes).

## Verification (regression matrix, all PASS)

| Case | Result |
|---|---|
| Assign leader → save → row exists `(1,1,9)` | PASS |
| Clear to `-- no role --` → save → row DELETED | PASS (bug fixed) |
| Re-assign after a clear → row re-appears | PASS |
| Multi-user: admin keeps leader, bob cleared → only bob's row deleted | PASS |
| Plan screen (project 1 / plan 2): assign leader, clear → row deleted (control) | PASS |
| Console + `events` table after full walk | no new Error/Warning; only AUDIT/16 INFO rows |

Regression suite: `tmp/TLU_Test_Cases.md` → **Suite 1525** (8/8 PASS).