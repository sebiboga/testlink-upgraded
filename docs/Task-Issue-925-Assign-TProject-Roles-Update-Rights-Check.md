# Task 925 — Assign Test Project Roles: `checkRightsForUpdate()` on PUT (update rights check, gap vs legacy)

**Issue:** [#925](https://github.com/sebiboga/testlink-upgraded/issues/925)
**Status:** IMPLEMENTED & VERIFIED (2026-09-17) — branch `task/issue-925-update-rights-check`

## The gap

The legacy Assign Test Project Roles flow only applies a submitted role map when the
caller passes `checkRightsForUpdate()`
(`lib/usermanagement/usersAssign.php:246-266`, guard at `:85`). For the `testproject`
case the caller must hold `user_role_assignment` on the target project — or the
**inherited** `testproject_user_role_assignment` — or nothing at all is written.

Issue report (verified on a fresh DB at creation time): `PUT /api/roles/tproject-roles`
ran `deleteUserRoles()` / `addUserRole()` with **no rights check**, so **any
authenticated user** — including `frank`, global role 3 `<no rights>` — could rewrite
the role assignments of any project (`user_testproject_roles` row written + `ASSIGN`
audit event) → privilege escalation.

## Legacy source of truth

- `lib/usermanagement/usersAssign.php:246-266` — `checkRightsForUpdate()`:
  - `testproject` → `user->hasRight(db,'user_role_assignment',$featureID)=='yes'` **or**
    `user->hasRight(db,'testproject_user_role_assignment',$featureID,-1,true)=='yes'`;
  - `testplan` → `user->hasRight(db,'testplan_user_role_assignment',$tprojectID,$featureID)=='yes'`.
- `lib/functions/roles.inc.php:191-205` — `user_role_assignment` lives in
  `$g_rights_users_global` (a **global-propagable** right): `tlUser::hasRight()` strips
  it from a test-project-scoped role and propagates it only from the global role, so a
  scoped project role alone does **not** grant the update right (same in 1.9.20).

## Modern implementation (already landed via `7f4553712`, Refs #924 — re-verified here)

The PUT enforcement shares the dispatch block fixed for #924:

- `api/roles/index.php:84-94` — `userCanUpdateAssignments()`: literal port of
  `checkRightsForUpdate()` (reads the caller's project + plan roles first).
- `api/roles/index.php:155-159` — PUT `/tproject-roles` gate.
- `api/roles/index.php:160-167` — PUT `/tplan-roles` gate (resolves the tplan's tproject
  before the check).
- `api/roles/index.php:96-104` — `denyAssignRights()`: HTTP `403` +
  `logAuditEvent(TLS('audit_security_user_right_missing'), 'AUTH')`.

`denyAssignRights()` uses the same error contract the modern screen already renders
(`no_permissions_for_action`, i18n) and writes the legacy audit key, mirroring
`checkUserRightsFor()` in `lib/functions/common.php:1010-1048`.

## Verification matrix (live server, admin + frank + alice sessions)

| Caller | Request | Result |
|---|---|---|
| frank, role 3 | `PUT /api/roles/tproject-roles {"tproject_id":1,"assignments":{"3":7}}` | **403** `no_permissions_for_action` / `testproject_user_role_assignment`; no row; AUTH audit |
| admin, role 8 | same PUT | **200**; row `(alice,1,tester)` written |
| alice, global role 9 (leader, holds `user_role_assignment`) | `PUT ... tproject_id:2` | **200**; global holder may assign on any project |
| alice, global role 3 + scoped role leader(9) on tproject 1 | `PUT ... tproject_id:1` | **403** — legacy-equivalent (global-propagable right, not granted by a scoped role) |
| frank, role 3 | `PUT /api/roles/tplan-roles {"tplan_id":3,...}` (incl. `assignments:{}`) | **403** — gate precedes handler/no-op |
| admin | `PUT /api/roles/tplan-roles {"tplan_id":3,"assignments":{"2":4}}` | **200**; row `(frank,3,test designer)` written |
| frank (in-page fetch) | `PUT /api/roles/tproject-roles` | **403** identical JSON |

Browser (chrome-devtools MCP):
- admin `usersAssignProject.html`: alice role changed to `test designer` via Save →
  PUT 200, row updated, `UPDATE` (`Test project roles updated for project #1`) +
  `ASSIGN` (`audit_users_roles_added`) audit events, "modified" badge visible
  (screenshot: `docs/screenshots/issue-925-admin-assign-screen.png`).
- frank `usersAssignProject.html`: combo disabled + legacy
  `testproject_roles_assign_disabled` feedback (GET `meta/tproject-roles` → 403, the #924
  screen gate); direct `fetch` PUT still 403 server-side — defense in depth
  (screenshot: `docs/screenshots/issue-925-frank-disabled-screen.png`).
- Console after both tracks: no JS errors; `events` gained only AUDIT/16-INFO rows —
  **no new Error/Warning** (Event Viewer clean).

## Regression

`php -l api/roles/index.php` clean; all 10 i18n bundles valid JSON; the authorized paths
(admin update, global `user_role_assignment` holder, tplan assign) are unaffected — full
matrix in `tmp/TLU_Test_Cases.md` suite "Task — Issue #925" (11/11 PASS).