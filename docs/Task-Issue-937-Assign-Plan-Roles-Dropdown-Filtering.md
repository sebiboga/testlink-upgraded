# Task 937 — Assign Test Plan Roles: filter the Test Plan dropdown by assign rights (gap vs legacy)

**Issue:** [#937](https://github.com/sebiboga/testlink-upgraded/issues/937)
**Status:** VERIFIED & CLOSED (2026-09-19) — branch `task/issue-937`

## The gap

The legacy Assign Test Plan Roles flow only lists test plans the caller is allowed
to assign roles for. It calls `getTestPlanEffectiveRoles()`
(`lib/usermanagement/usersAssign.php:344-378`) which fetches the caller's effective
roles per accessible test plan and renders the `<select id="feature_id">` list from
**those** plans only — when none qualify it renders the disabled control with a
warning note:

```php
// usersAssign.php:344-378 (getTestPlanEffectiveRoles) + :123-127 (disabled branch)
$plans = $tprojectMgr->getTestPlans($tprojectID);        // active plans only
if($testPlanEffectiveRoles) { $plans = ...intersection... }
// else select is disabled + label 'Your role configuration do not allow you
// Assign Roles for Test Plans' (x_project_user_right_req)
```

The modern screen (`api/roles/index.php` GET `/meta/tplan-roles`, pre-fix it
populated the dropdown from **all active plans** of the project,
`api/roles/index.php:684-697`) — so a user without right 15 / the global assign
capability still saw every active plan listed, and the disabled branch was dead
code. Verified at creation time: `u937assign` (global role holding only right 15)
saw `AlphaPlan` + `BetaPlan` in the plan dropdown — legacy shows **no** plans + the
warning.

## Root cause recap

`testplan_user_role_assignment` is a **global propagable right** (`lib/functions/roles.inc.php`
`$g_rights_users_global`). For users who do not own it at global scope,
`tlUser::hasRight()` (`lib/functions/tlUser.class.php:808`) subtracts the plan scope of
the right extracted from tproject/plan-scoped roles — so the plan combo is binary:

- global right hold (e.g. `admin`, `leader` built-in roles) → all **accessible** active plans;
- everything else (right 15 only via plan-scoped roles, without the global right) → no plans at all.

This exactly mirrors legacy `getTestPlanEffectiveRoles()`: users with the global right get the
full accessible list; users without it (like `u937tproj`, plan-scoped right alone) get the
disabled + warning branch. A pure plan/tproject-scoped assigner that tries to open the screen
gets the legacy 403 at the read gate (`checkRights()` `usersAssign.php:201-240`) — unchanged here.

## Implementation summary

### BFF — `api/roles/index.php`

- Added `getAssignablePlans(&$db, &$user, $tprojectID)` right after
  `getAssignableProjects()`: reads the caller's test-project + test-plan roles first
  (mirroring legacy `readTestProjectRoles()/readTestPlanRoles()`), then
  - active plans only (`getTestPlans($tprojectID)` — legacy parity),
  - if the caller is `mgt_users` on the project → all active plans,
  - else if the caller holds the global right `testplan_user_role_assignment`
    (`roles.inc.php` model) → the caller's accessible plans
    (`tlUser::getAccessibleTestPlans()` `tlUser.class.php:929`),
  - else → per-plan `hasRight('testplan_user_role_assignment', null, plan_id) === 'yes'`.
- GET `/meta/tplan-roles` now builds the dropdown from `getAssignablePlans()` AND exposes
  `totalPlans` (raw active-plan count) so the front-end can tell "project has no active plans"
  from "caller cannot assign".

### Screen — `gui/templates/usermanagement/usersAssignPlan.html`

- New `#disabledMsg` warning block (reuses the legacy `x_project_user_right_req` message shape)
  placed above the role map table.
- `showPlanDisabled(totalPlans)`: when the BFF returns zero assignable plans the plan select
  is disabled, the warning shows (`assign.noUsablePlans` when `totalPlans === 0`, otherwise
  `assign.rolesForPlansDisabled`), the save button disables and the grid stays hidden — same UX
  as legacy `usersAssign.php:123-127`.
- `loadPlans()` handles the `r.plans` empty array; the project-change handler and
  `showNoAccess()` reset/hide `#disabledMsg` so a later project selection re-evaluates.

### i18n — all 10 bundles (`gui/templates/i18n/{de,en,es,fr,it,ja,pt,ro,ru,zh}.json`)

- `assign.rolesForPlansDisabled` — "Your role configuration do not allow you Assign Roles for
  Test Plans" (ported from legacy `x_project_user_right_req`), translations from
  `locale/*/strings.txt`.
- `assign.noUsablePlans` — "No usable test plan available" (legacy had no separate key for the
  empty-project case; translated per-locale).
- Every touched bundle validated with `python3 -m json.tool`.

## Verification

### Browser (EN)

Admin (`admin`): project `A937` → plan dropdown `AlphaPlan` + `BetaPlan`, enabled, no warning,
grid loads (5 users).



`u937assign` (global right 15 only): plan dropdown **disabled**, zero options, warning shown,
save button disabled, grid hidden.



`u937leader` (right 5): same list as admin, no warning → authorized non-admin path intact.

### i18n (RO)

`usersAssignPlan.html?locale=ro`: warning renders "Configuratia rolului dumneavoastra nu va
permite sa atribuiti roluri pentru planurile de test".



### BFF matrix (`GET /meta/tplan-roles?tproject_id=10&tplan_id=0`, HTTP 200)

| caller | `plans` | `totalPlans` |
|---|---|---|
| admin | `["AlphaPlan","BetaPlan"]` | 2 |
| u937assign (right 15 only) | `[]` | 2 |
| u937leader (right 5) | `["AlphaPlan","BetaPlan"]` | 2 |

### Event Viewer

No `events` rows with `log_level >= 32` produced by the new filter (only INFO login/lookup
audits). Test suite 937: **6/6 PASS** in `tmp/TLU_Test_Cases.md`.

## Files touched

- `api/roles/index.php` — `getAssignablePlans()` + `totalPlans` in GET `/meta/tplan-roles`.
- `gui/templates/usermanagement/usersAssignPlan.html` — `#disabledMsg`, `showPlanDisabled()`,
  `loadPlans()`/`showNoAccess()` handling.
- `gui/templates/i18n/{de,en,es,fr,it,ja,pt,ro,ru,zh}.json` — `assign.rolesForPlansDisabled`,
  `assign.noUsablePlans`.
- `tmp/fixtures_937.php` — re-runnable fixture (project A937 / AlphaPlan / BetaPlan / 3 users).
- `docs/screenshots/issue-937-*.png` — before/after evidence.

Branch: `task/issue-937` → landed via the implement-task CI worker.