# Task 945 — honour tplan_id URL param to auto-load the selected plan in Assign Test Plan Roles (gap vs legacy)

**Issue:** [#945](https://github.com/sebiboga/testlink-upgraded/issues/945)
**Status:** IMPLEMENTED & VERIFIED, issue CLOSED (2026-09-21) — branch `task/issue-945-tplan-url-autoload`

## The gap

Legacy `lib/usermanagement/usersAssign.php:380-397` (`getTestPlanEffectiveRoles`)
never opens the Assign Test Plan Roles screen with an empty plan combo:

- the URL `featureID` (= the plan id for `featureType=testplan`) wins when present;
- otherwise the session plan is preferred, then the **first** assignable plan
  (`current($features)` — the plans the caller can actually assign roles on);
- the role table renders **immediately** for the selected plan.

**What modern did instead:** `gui/templates/usermanagement/usersAssignPlan.html`
honoured only `tproject_id` from the URL — `TLi18n.load()` read it and passed it
to `loadProjects(tp)`, but `tplan_id` was dropped (kept only for the tabs-bar
`ctx` string). `loadPlans()` always left the combo at the value-0
`-- select plan --` placeholder and showed `#emptyMsg`; `loadUsers()` fired only
from the manual `change` handler. Redirects that build the URL with both params
(`lib/functions/common.php:1889` `getActions()`, `api/plans/index.php:113`
`viewActions()` `assignRolesAction`) therefore landed on an **empty screen**.

## Implementation

Pure client-side change in `gui/templates/usermanagement/usersAssignPlan.html`
(no BFF change, no i18n — no new user-facing strings):

- `TLi18n.load()` (:208-210) now reads `tplan_id` into `tpid` and passes it to
  `loadProjects(tp, tpid)`.
- `loadProjects(selectedId, selectedPlanId)` (:225) threads the plan id into
  `loadPlans(selectedId, selectedPlanId)`.
- `loadPlans(pid, selectedPlanId)` (:268-313) — after building the plan options
  and before returning, applies the legacy featureID resolution:
  1. requested `tplan_id` wins **when it is one of the assignable plans**
     (parsed via `parseInt`, matched against `r.plans[].id`);
  2. otherwise falls back to the **first** assignable plan
     (`current($features)` legacy parity);
  3. on success it sets `sel.val(planToLoad)` and calls `loadUsers(pid,
     planToLoad)` automatically — jQuery `.val()` does not fire `change`, so no
     double `loadUsers`.
- The no-assignable-plan paths are untouched: `showPlanDisabled()` still renders
  the localized `assign.noUsablePlans` / `assign.rolesForPlansDisabled` note and
  never auto-selects (legacy shows the same disabled states there).

The legacy mid-branch "session plan" preference (`$argsObj->testplanID`) is dead
code on this screen — `init_args()` hard-sets `testplanID = 0`
(usersAssign.php:166) and only derives it from the URL `featureID`
(:179-182), so the branch can only trigger when `featureID` is also empty —
hence the first-plan fallback is the faithful port, per the issue's suggested
fix ("fall back to first plan like legacy").

## Verification (browser, admin/admin, fixtures_941: tproject=1, tplan=2)

- `usersAssignPlan.html?tproject_id=1&tplan_id=2` → plan combo value `2`
  (PLAN941-R1), 6 role rows rendered, `#emptyMsg` hidden, no interaction.
- `usersAssignPlan.html?tproject_id=1` (no tplan_id) → falls back to first
  assignable plan (2 / PLAN941-R1), 6 rows.
- `usersAssignPlan.html?tproject_id=1&tplan_id=999` (out of range) → first-plan
  fallback, 6 rows.
- Clearing the project combo disables the plan combo again; re-selecting
  project 1 auto-loads the first plan (behavior closer to legacy than the
  previous empty select).
- Event Viewer: only AUDIT (log_level 16) rows, 0 Error/Warning; console clean.

Screenshot: `docs/screenshots/issue-945-tplan-url-autoload.png`.
(URL with `tproject_id=1&tplan_id=2`: PLAN941-R1 auto-selected, grid rendered.)

Task suite: `tmp/TLU_Test_Cases.md` — **Suite 945, 4/4 PASS**.

Refs #945.