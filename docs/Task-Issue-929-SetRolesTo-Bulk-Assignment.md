# Task 929 — Implement "Set roles to" bulk assignment action in Assign Test Project Roles (gap vs legacy)

**Issue:** [#929](https://github.com/sebiboga/testlink-upgraded/issues/929)
**Status:** IMPLEMENTED & VERIFIED (2026-09-18) — branch `task/issue-929`

## The gap

Legacy `gui/templates/dashio/usermanagement/usersAssign.tpl:189-206` renders a
"Set roles to <role>" selector (`select#allUsersRole`, populated with
`$gui->optRights`) plus a Do button (`btn_do`) above the user table. Clicking Do
calls `set_combo_group('usersRoleTable','userRole_', value)` (`usersAssign.tpl:20-43`)
which sets `.value` of **every** non-disabled `<select>` whose id starts with
`userRole_` — i.e. every visible user row's role select — to the chosen role.
The user then submits once with Update, and legacy `usersAssign.php:557-573`
`doUpdate()` deletes the user-role rows and re-adds the chosen role for every
submitted user. This is the primary mass-assignment workflow for bulk role changes.

The modern screens `usersAssignProject.html` / `usersAssignPlan.html` only had
per-user `<select>`s — changing N users required editing each row individually;
the bulk selector + Do button were dropped in the 2.0.1 rewrite.

## Legacy source of truth

- `gui/templates/dashio/usermanagement/usersAssign.tpl:189-206` — `set_roles_to`
  label row + `select#allUsersRole` + `button#btn_do` invoking
  `set_combo_group('usersRoleTable','userRole_', …)`.
- `usersAssign.tpl:20-43` — `set_combo_group()`: for every `select-one` with id
  prefix `userRole_` and NOT `disabled`, assign the value.
- `usersAssign.tpl:244-247` — global-admin rows render a **disabled** select, so
  `set_combo_group` skips them (`!input_element.disabled`).
- `usersAssign.php:557-573` `doUpdate()` — deleteUserRoles for submitted users,
  then addUserRole for every non-zero `role_id`.
- One shared template serves both Test Project and Test Plan contexts
  (`featureType` switch, `usersAssign.php:48-78`), so the parity applies to both
  modern screens.

## Modern implementation

The write path already existed (BFF `PUT /roles/tproject-roles` /
`PUT /roles/tplan-roles`, `api/roles/index.php:589-635 / :706-750`) and the
`GET /roles/meta/tproject-roles` payload already carried the assignable role
catalog. The missing piece was purely the front-end bulk affordance:

- `gui/templates/usermanagement/usersAssignProject.html`:
  - toolbar gains `#bulkRoleSelect` + `#bulkDoBtn` ("Set roles to" + Do),
    styled with a teal `btn-ghost` outline button;
  - `buildBulkSelect()` populates the selector with value-0
    (`assign.noRole` = "-- no role --", the legacy id-0 "Inherited"
    pseudo-choice) plus the BFF `roles` catalog excluding admin id 8
    (issue #928 exclusion) and the id-0 pseudo-role;
  - each row `<select>` now carries `data-role="<origRoleID>"` so the bulk
    apply can re-trigger change tracking against the originally loaded value;
  - `applyBulkRole()` mirrors legacy `set_combo_group()`: it sets every
    non-disabled row select to the chosen value, re-running `onRoleChange()`
    per row so the yellow `changed` highlight + "Modified" badge + Save-button
    enablement reflect the bulk change before Save. Disabled global-admin rows
    are skipped (legacy `!input_element.disabled`, issue #927 locked rows).
- `gui/templates/usermanagement/usersAssignPlan.html`: same port; the value-0
  option is labelled `assign.noOverride` ("-- no override --", plan semantics).
  Edge found during testing: the plan BFF route (`api/roles/index.php:652-656`)
  does NOT skip `TL_ROLES_INHERITED` in its `roles` catalog (unlike the tproject
  route at :545), so `buildBulkSelect()` skips `role.id <= 0` to avoid a
  duplicate value-0 `<inherited>` option.
- i18n: `assign.do` + `assign.setRolesTo` added to **all 10** locale bundles
  (`gui/templates/i18n/{en,de,es,fr,it,ja,pt,ro,ru,zh}.json`), translated from
  the legacy `TLS_btn_do` / `TLS_set_roles_to` strings (`locale/*/strings.txt`).
- No BFF change was required — the bulk write reuses the existing
  `stripGlobalAdminAssignments()` admin guard and the
  delete-then-re-add semantics (legacy parity).

## Verification evidence

- Project screen: bulk `leader`(9) → Do → all non-admin rows become `leader`
  with `changed` + `modified` badge, Save enabled, admin row untouched → Save →
  `user_testproject_roles` = (2,1,9),(3,1,9),(4,1,9),(5,1,9); global roles
  unchanged; reload shows explicit `leader` rows with no stale badges.
- Revert path: bulk value-0 ("-- no role --") → Do → Save →
  `user_testproject_roles` empty (delete + no re-add = legacy `doUpdate()`).
- Plan screen (test plan id 6): bulk `tester`(7) → Do → Save →
  `user_testplan_roles` = (2,6,7),(3,6,7),(4,6,7),(5,6,7); admin row skipped.
- Event Viewer: only AUDIT (log_level 16) rows; no Error/Warning (≥ 32).
- Full manual pass: `tmp/TLU_Test_Cases.md` — "Suite 929" — 9/9 PASS.

Screenshots: `docs/screenshots/issue-929-bulk-set-roles-project.png`,
`docs/screenshots/issue-929-bulk-set-roles-plan.png`.

Refs #929.