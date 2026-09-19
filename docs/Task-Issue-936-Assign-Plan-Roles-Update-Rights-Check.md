# Task 936 — Assign Test Plan Roles: `checkRightsForUpdate()` on PUT update rights check (gap vs legacy)

**Issue:** [#936](https://github.com/sebiboga/testlink-upgraded/issues/936)
**Status:** VERIFIED & CLOSED (2026-09-19) — branch `task/issue-936`

## The gap

The legacy Assign Test Plan Roles flow only applies a submitted role map when the
caller passes `checkRightsForUpdate()`
(`lib/usermanagement/usersAssign.php:246-266`, guard at `:80-87`). For the `testplan`
case the caller must hold `testplan_user_role_assignment` on
`(testproject_id, testplan_id)`:

```php
case 'testplan':
    $yes_no = $user->hasRight($dbHandler,"testplan_user_role_assignment",
                               $testprojectID,$featureID);
```

Issue report (browser-verified at creation time on a fresh DB):
`PUT /api/roles/index.php/tplan-roles` ran `deleteUserRoles()` / `addUserRole()`
with **no rights check**, so any authenticated user — including `guest1`, global role
`guest` (zero rights) — could change any user's plan role (`user_testplan_roles` row
written) → privilege escalation.

## Server-side enforcement (already on the default branch)

When this task was picked, the gate was already present in `api/roles/index.php`
(landed with commit `7f4553712` Refs #924, same shared gate as #925):

- `userCanUpdateAssignments()` (`api/roles/index.php:85-94`) — a faithful port of
  legacy `checkRightsForUpdate()`: for the `testplan` feature type it returns
  `hasRight($db,'testplan_user_role_assignment',$tprojectID,$featureID) === 'yes'`,
  i.e. the exact `(testproject_id, tplan_id)` scoped check the legacy performs.
- Route dispatch gate `api/roles/index.php:213-220` — on `PUT /tplan-roles` it reads
  `tplan_id` from the JSON body, resolves the owning `tproject_id` from the test plan
  row, and denies when `userCanUpdateAssignments()` fails.
- `denyAssignRights()` (`api/roles/index.php:96-104`) → HTTP 403
  `{"status":"error","message":"no_permissions_for_action","right":"testplan_user_role_assignment"}`
  plus an `audit_security_user_right_missing` AUTH event (legacy `warn_perm_denied`
  parity), exactly mirroring the legacy `checkRightsForUpdate()` rejection path.

## What THIS run did — live verification of the denied + authorized matrix

The default branch already enforced the check; the run reproduced the exact issue
scenario on a fresh import, exercised the full matrix, and recorded evidence so #936
could be closed.

**Fixture (`tmp/fixtures_936.php`):** public project **ASSIGN** (id 1), active plan
**RPlan** (id 2), users `admin` (role_management), `guest1` (global role `guest`, zero
rights), `planner1` (global role `leader`, holds `testplan_user_role_assignment` +
`user_role_assignment`, no `role_management`), `tester1`, `leader1`.

Verified matrix (live browser session):

| Caller | Request | Result |
|--------|---------|--------|
| guest1 (guest, no rights) | `PUT /api/roles/tplan-roles {"tplan_id":2,"assignments":{"3":9,"4":5}}` | **403** `no_permissions_for_action` (right `testplan_user_role_assignment`); no DB mutation; AUTH event written |
| planner1 (leader, has the right) | same PUT | **200** `{"status":"ok"}`; `user_testplan_roles` rows `(3,2,9)`/`(4,2,5)` written; `UPDATE` event |
| planner1 (UI) | open `usersAssignPlan.html`, change tester1 role, Save | roles persist (row `(3,2,7)`), `UPDATE` event |
| guest1 (UI) | open `usersAssignPlan.html` | localized "You do not have enough rights..." deny box (read-route check #935) |

Event Viewer: only log_level 16 audit rows (login / AUTH right_missing / ASSIGN /
UPDATE); **0 Error/Warning** entries from the run. Browser console clean.

## Conclusion

The privilege escalation described in #936 (any authenticated user could rewrite any
test plan's role map with no rights check) is fully closed on the default branch:
`PUT /api/roles/tplan-roles` enforces the legacy `checkRightsForUpdate()` semantics
before any `deleteUserRoles`/`addUserRole` and answers 403 + audit when the caller
lacks `testplan_user_role_assignment` on `(tproject_id, tplan_id)`. Authorized callers
are unaffected and the modern screen surfaces the denial exactly as the legacy page did.

Refs #936.