# Task 938 — "Set roles to <role> / Do" bulk assignment in Assign Test Plan Roles (gap vs legacy)

**Issue:** [#938](https://github.com/sebiboga/testlink-upgraded/issues/938)
**Status:** IMPLEMENTED & VERIFIED (2026-09-19) — branch `task/issue-938`

## The gap

Issue #938 was filed as part of the Assign Test Plan Roles screen-parity analysis
(batch #935-#947): the modern screen originally offered only per-row `Plan Role
Override` selects with **no bulk setter**, while legacy
`gui/templates/dashio/usermanagement/usersAssign.tpl:190-206` (the shared
template used for BOTH test-project and test-plan contexts) renders a
"Set roles to" selector (`select#allUsersRole` + `btn_do`) above the table.
Clicking `Do` calls `set_combo_group('usersRoleTable','userRole_', value)`
(`usersAssign.tpl:20-43`), which sets the value of **every non-disabled** row
select whose id starts with `userRole_` to the chosen role, before the single
Save/Update.

The legacy semantics to replicate:
- the bulk selector lists all assignable roles (value 0 = the id-0 `<inherited>`
  pseudo-option, i.e. revert to the inherited/effective role);
- `Do` applies to every **enabled** row select — global-admin rows render a
  `disabled="disabled"` select (usersAssign.tpl:244-247) and are skipped
  (`!input_element.disabled`);
- the `admin` role (id 8) is never offered as an assignable option
  (usersAssign.tpl:259-271 `$removeRole`, unless it is the row's current
  explicit assignment).

## Modern implementation

The bulk "Set roles to / Do" affordance for the plan screen was landed by commit
`291c69d01` ("feat(usermanagement): 'Set roles to' bulk assignment in Assign
Test Project/Plan Roles", Refs #929) — the same change that covered the project
screen — and is present in `gui/templates/usermanagement/usersAssignPlan.html`:

- **Toolbar** (usersAssignPlan.html:66-71): `Set roles to:` label
  (`data-i18n="assign.setRolesTo"`), `#bulkRoleSelect` + `#bulkDoBtn` (Do,
  `data-i18n="assign.do"`), styled with the teal `btn-ghost` outline.
- **`buildBulkSelect()`** (usersAssignPlan.html:317-327): value-0
  `assign.noOverride` option + every BFF role except `role.id <= 0` (the id-0
  `<inherited>` pseudo-role injected by `tlRole::getAll()` would duplicate the
  value-0 option) and except `ADMIN_ROLE_ID` (8).
- **`applyBulkRole()`** (usersAssignPlan.html:333-345): mirrors
  `set_combo_group()` — iterate `#assignBody tr`, skip rows without a select and
  **disabled** (global-admin) rows, `sel.val(roleVal)`, then re-run
  `onRoleChange()` per row so the yellow `changed` highlight, "Modified" badge
  and Save-button enablement follow the bulk change before Save.
- **Persistence**: the existing BFF write `PUT /roles/tplan-roles`
  (`api/roles/index.php:813-858`) deletes the users' `user_testplan_roles` rows
  and re-adds each non-zero role — legacy `usersAssign.php:557-573`
  `doUpdate()` parity — and `stripGlobalAdminAssignments()` enforces the
  global-admin lock server-side; disabled selects never submit.
- **i18n**: `assign.setRolesTo` + `assign.do` exist in all 10 locale bundles
  (`gui/templates/i18n/{en,de,es,fr,it,ja,pt,ro,ru,zh}.json`), each validated
  with `python3 -m json.tool`.

No BFF or i18n change was needed for #938 — this run verified the already-merged
implementation end-to-end on a freshly imported database and closed the issue.

## Browser verification (2026-09-19)

Fixture `tmp/fixtures_938.php`: project `PLANROLES938` (id 1), active test plan
`PLAN938-R1` (id 2), users `u938designer` (global role 4), `u938senior` (6),
`u938tester` (7), `u938leader` (9) with explicit overrides `u938senior=6`,
`u938tester=7`; `admin` = locked global-admin row.

1. **Selector**: `#bulkRoleSelect` shows `-- no override --` + roles
   1,2,3,4,5,6,7,9 — no `admin`, no `<inherited>` duplicate.
2. **Do (senior tester=6)** → enabled rows all become `6`, `admin` disabled row
   untouched (`0`), changed rows get `changed`+badge, Save enabled.
3. **Save → DB**: `user_testplan_roles` for plan 2 = (2,6) designer, (3,6)
   senior, (4,6) tester, (5,6) leader; no admin row. Reload keeps values.
4. **Revert**: bulk `-- no override --` (0) → Do → Save →
   `user_testplan_roles` empty for plan 2 (delete + no re-add).
5. **Event Viewer**: only INFO(16) audit events (`Test plan roles updated`,
   `audit_users_roles_added_testplan`); zero Error/Warning (log_level ≥ 32).
6. **i18n**: all 10 bundles valid JSON, `assign.setRolesTo`/`assign.do` present.

Full manual pass: `tmp/TLU_Test_Cases.md` — **Suite 938 — 6/6 PASS**.

Screenshot: `docs/screenshots/issue-938-bulk-set-roles-plan.png` (assign test
plan roles, bulk "senior tester" applied, admin row locked).

## Related issues

- #929 — same bulk control implemented/verified for the project + plan screens.
- #1545 — bug found during this verification: every plan-screen row select
  renders a **duplicate value-0** option (`-- no override --` plus the BFF's
  id-0 `<inherited>` pseudo-role); cosmetic-but-legacy-divergent, filed with
  the `bug` label (the project screen fixed this in issue #926 by filtering the
  tproject-roles BFF catalog; the tplan-roles catalog still emits id 0).

Refs #938.