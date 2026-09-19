# Issue 1545 — usersAssignPlan: duplicate value-0 options (`-- no override --` + `<inherited>`) in Plan Role Override selects

**Issue:** [#1545](https://github.com/sebiboga/testlink-upgraded/issues/1545)
**Branch:** `fix/issue-1545`
**Status:** VERIFIED-FIXED (2026-09-19)

## Symptom

On the modern **Assign Test Plan Roles** screen
(`gui/templates/usermanagement/usersAssignPlan.html`), every `Plan Role Override`
per-user `<select>` renders **two** options with value `0`: `-- no override --`
(the hardcoded first option) plus `<inherited>` (the id-0 pseudo-role returned by
the BFF). Legacy renders exactly **one** value-0 option (`<inherited> <role>`,
`usersAssign.tpl:262-272`). As a side effect, rows without an explicit override
auto-selected the *last* value-0 option (`<inherited>`) instead of showing the
`-- no override --` state.

Measured pre-fix, per-row select (admin, project 1 / plan 2, fixture #938):

```js
value0opts: ["-- no override --", "<inherited>"],  selected: "<inherited>"
```

## Repro steps

1. Fresh-import DB; `php tmp/fixtures_938.php` (tproject `PLANROLES938`,
   tplan `PLAN938-R1`, users u938designer/4, u938senior/6, u938tester/7,
   u938leader/9; overrides senior→6, tester→7).
2. Log in `admin/admin` at http://localhost:8082, open
   `gui/templates/usermanagement/usersAssignPlan.html`.
3. Select project `PLANROLES938`, then plan `PLAN938-R1`.
4. Devtools: list each `#assignBody tr[data-uid]` select's `value === "0"` options.
5. **Before fix:** two value-0 options (`-- no override --` + `<inherited>`).
   **After fix:** exactly one (`-- no override --`).

## Root cause

1. `cfg/const.inc.php:526` defines `TL_ROLES_INHERITED = 0`.
2. `lib/functions/tlRole.class.php:521-523` — `tlRole::getAll()` injects a
   synthetic id-0 `<inherited>` tlRole into every returned role set (a legacy
   pseudo-role used to model "inherited" in legacy selects).
3. `api/roles/index.php` (`GET /meta/tplan-roles`) built `$roleOpts` from that
   result **without** filtering id 0, so the JSON `roles` payload carried
   `<inherited>` (id 0). The sibling route `tproject-roles` already filters it
   (`api/roles/index.php:619-626`, the accepted Fixes #926 fix for the project
   screen).
4. `gui/templates/usermanagement/usersAssignPlan.html:268-278` — `loadUsers()`
   emits a hardcoded `<option value="0">-- no override --</option>` and then
   appends every BFF role verbatim, so the id-0 `<inherited>` landed in the
   select as a **second** value-0 option; line 276 marks it `selected` for every
   row with `roleID = 0` (0 == 0), corrupting the default display too.

Blast radius: the only consumer of the tplan-roles `roles` payload is
`usersAssignPlan.html`; `buildBulkSelect()` there already guards `role.id <= 0`
(`usersAssignPlan.html:324`), proving the UI never needs the pseudo-role.

## Fix

`api/roles/index.php` — `tplan-roles` GET route now skips `TL_ROLES_INHERITED`
when building `$roleOpts`, mirroring the `tproject-roles` fix (issue #926):

```php
if (intval($r->dbID) == TL_ROLES_INHERITED) continue;
```

Frontend untouched. After the fix each row select has exactly one value-0 option
(`-- no override --`), which becomes the correct default for rows without an
explicit override; `buildBulkSelect()` behaviour is unchanged.

## Verification (regression matrix, all PASS)

| Case | Result |
|---|---|
| Exactly one value-0 option on all 5 rows (admin, designer, leader, senior, tester) | PASS |
| No-override rows default to `-- no override --` (admin locked/disabled, designer, leader) | PASS |
| Override rows keep explicit role selected (senior→senior tester, tester→tester) | PASS |
| Bulk `Set roles to test designer` → Do → Save → all 4 non-admin rows role 4; one value-0 option on reload | PASS |
| Bulk revert to `-- no override --` → Do → Save → all rows default back; `user_testplan_roles` count 0 | PASS |
| Global-admin row still disabled with locked hint | PASS |
| `GET /api/roles/meta/tplan-roles` payload = 9 roles, 0 with `id===0` | PASS |
| Event Viewer: only INFO(16) rows, zero `log_level>=32`; console clean | PASS |

Screenshots: `docs/screenshots/issue-1545-before.png` (pre-fix duplicate),
`docs/screenshots/issue-1545-after.png` (post-fix single option).

Regression suite: `tmp/TLU_Test_Cases.md` → **Regression — Issue #1545** (6/6 PASS).