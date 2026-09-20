# Task 942 — Localized update/empty feedback in Assign Test Plan Roles (gap vs legacy)

**Issue:** [#942](https://github.com/sebiboga/testlink-upgraded/issues/942)
**Status:** IMPLEMENTED & VERIFIED (2026-09-20) — branch `task/issue-942`

## The gap

Legacy `lib/usermanagement/usersAssign.php` shows a localized feedback banner after every
submit on the Assign Test Plan Roles page:

- `usersAssign.php:87-89` — after a successful `doUpdate()` the page shows
  `user_feedback = test_plan_user_roles_updated` ("User Roles updated").
- `usersAssign.php:82-84` — an empty submitted map (`map_userid_roleid == null`, "this can
  happen when filtering via Javascript") shows `no_users_selected` ("No users selected -
  nothing done").
- `usersAssign.php:109-111` — project without usable plans shows `no_test_plans_available`
  ("There are no usable test plans on this test project").
- `usersAssign.php:123-127` — plans exist but none assignable shows
  `testplan_roles_assign_disabled` ("Your role configuration do not allow you Assign Roles
  for Test Plans").

Rendered via `inc_update.tpl` as a banner.

When the issue was created the modern `usersAssignPlan.html` `saveAssignments()` reloaded
the table on success **with no feedback at all** — the BFF `PUT /roles/tplan-roles` returned
only `{"status":"ok"}` with no `feedback_key` unlike its `tproject-roles` sibling
(`api/roles/index.php:674-732`, issue #931).

## Implementation

- `api/roles/index.php` PUT `/roles/tplan-roles` (:822-878) — mirrors the `tproject-roles`
  pattern:
  - empty assignment map → `feedback_key: 'no_users_selected'` (:846)
  - map reduced to zero by the admin-strip (`stripGlobalAdminAssignments`) →
    `feedback_key: 'no_users_selected'` (:852)
  - successful delete+add+audit → `feedback_key: 'test_plan_user_roles_updated'` (:876)
- `gui/templates/usermanagement/usersAssignPlan.html`:
  - `assignFeedback()` (:528-534) — maps the BFF keys to TLi18n keys (`assign.rolesUpdated`,
    `assign.noUsersSelected`), unknown keys default to the success message
    (usersAssignProject.html:161-167 pattern).
  - `saveAssignments()` success handler (:559-565) — reads `r.feedback_key` and shows the
    toast with `ok`/`warn` class ("no users selected" is a warning, not a success) before
    reloading (usersAssignProject.html:508-517 pattern).

The `no_test_plans_available` / `testplan_roles_assign_disabled` empty-plan states were
already surfaced by issue #937: the GET `/roles/meta/tplan-roles` returns `totalPlans`
(raw active-plan count) plus the assignable-only `plans` list, and `showPlanDisabled()`
(`usersAssignPlan.html:256-266`) renders `assign.noUsablePlans` vs
`assign.rolesForPlansDisabled` — re-verified in this run.

## i18n

No new keys — `assign.rolesUpdated` ("User Roles updated"), `assign.noUsersSelected`
("No users selected - nothing done"), `assign.noUsablePlans` and
`assign.rolesForPlansDisabled` already exist in all 10 locale bundles and mirror the legacy
string texts (`locale/en_US/strings.txt:2149,2151,3016,3848`).

## Verification matrix (live server, admin session; fixtures created via SQL in this run)

| # | Case | Result |
|---|------|--------|
| 1 | Save a role override → toast "User Roles updated" (`toast ok`) + grid reload + DB row `user_testplan_roles` (2,2,7) | PASS |
| 2 | `PUT` empty map → `{"status":"ok","feedback_key":"no_users_selected"}` | PASS |
| 3 | `assignFeedback('no_users_selected')` → toast `toast warn` "No users selected - nothing done"; ok key → `toast ok` | PASS |
| 4 | Project without plans → plan combo disabled, `assign.noUsablePlans` shown | PASS |
| 5 | Regression: project with plan loads the 2-row grid | PASS |
| 6 | Event Viewer: no new Error/Warning entries | PASS |

Suite 942: 6/6 PASS. Screenshots: `docs/screenshots/issue-942-roles-updated-toast.png`,
`docs/screenshots/issue-942-empty-selection-warn-toast.png`.