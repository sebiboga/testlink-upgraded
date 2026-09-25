# Task 947 — Delete legacy `usersAssign.php` (+ `usersAssign.tpl`)

**Issue:** [#947](https://github.com/sebiboga/testlink-upgraded/issues/947)
**Status:** IMPLEMENTED (2026-09-25) — branch `task/issue-947`

## The gap

Cleanup task filed by the screen-compare pass on
`usermanagement/usersAssignPlan.html` (SCREEN-COMPARE-STATUS row 6, batch
#935–#947). Once a modern screen reaches full parity, its legacy controller and
Smarty template are retired so they stop being a second, divergent
implementation of the same feature.

`usersAssign.php` served **both** contexts from a single file, switched on the
`featureType` request param:

- `featureType=testproject` → the *Assign Test Project Roles* screen
  (modern counterpart: `gui/templates/usermanagement/usersAssignProject.html`,
  SCREEN-COMPARE-STATUS row 5, gaps #924–#932);
- `featureType=testplan` → the *Assign Test Plan Roles* screen
  (modern counterpart: `gui/templates/usermanagement/usersAssignPlan.html`,
  row 6, gaps #935–#946).

All 12 functional gaps of the plan-context batch have shipped with passing
regression suites in the CHANGELOG, and the 9 project-context gaps have their
own entries — so the legacy file had no remaining behaviour to carry.

## Files deleted

| File | Size | Role |
| --- | --- | --- |
| `lib/usermanagement/usersAssign.php` | 18 898 B | legacy controller (both contexts) |
| `gui/templates/dashio/usermanagement/usersAssign.tpl` | 9 304 B | legacy Smarty template (dashio theme) |
| `gui/templates/tl-classic/usermanagement/usersAssign.tpl` | 8 512 B | legacy Smarty template (tl-classic theme) |

## Why deleting them was safe

- **Nothing in the modern UI reached the legacy controller.** The modern action
  map (`lib/functions/common.php:1887-1889`, consumed by `api/aside/index.php`)
  and the plans BFF (`api/plans/index.php:112-114`) already pointed at
  `usersAssignProject.html` / `usersAssignPlan.html`.
- **Every function in the file was file-local** — `init_args`, `checkRights`,
  `checkRightsForUpdate`, `getTestProjectEffectiveRoles`,
  `getTestPlanEffectiveRoles`, `getTestPlanEffectiveRolesNEW`, `doUpdate`,
  `initializeGui`, `initLabels`. No other file called any of them.
- **The shared `.tpl` includes survive** — `usersAssign.tpl` pulled in
  `inc_head.tpl`, `inc_ext_js.tpl`, `bootstrap.inc.tpl`, `DataTables.inc.tpl`,
  `usermanagement/menu.inc.tpl` and `inc_update.tpl`; all of those are used by
  many other legacy templates, so nothing was orphaned.
- **No other template included `usermanagement/usersAssign.tpl`** — the file was
  only ever rendered by `usersAssign.php` itself (via `templateConfiguration()`),
  and no compiled Smarty artifact for it remained in `tmp/`.

## Dangling links retargeted at the modern screens

Deleting the controller would have 404'd every legacy entry point, so each was
repointed instead:

| Location | Before | After |
| --- | --- | --- |
| `lib/functions/testplan.class.php:8243` (`getViewActions()` → `assignRolesAction`, feeds `dashio/plan/planView.tpl:172`) | `lib/usermanagement/usersAssign.php?featureType=testplan&…&featureID=` | `gui/templates/usermanagement/usersAssignPlan.html?tproject_id=…&tplan_id=` |
| `gui/templates/dashio/usermanagement/menu.inc.tpl:20-21` | `$lib/usersAssign.php?featureType=…&` + `$context` | `gui/templates/usermanagement/usersAssign{Project,Plan}.html?` + `$context` |
| `gui/templates/dashio/usermanagement/tabsmenu.tpl:17-18` | `lib/usermanagement/usersAssign.php?featureType=…` | `gui/templates/usermanagement/usersAssign{Project,Plan}.html` |
| `gui/templates/tl-classic/usermanagement/menu.inc.tpl:11-12` | `$lib/usersAssign.php?featureType=…` | `gui/templates/usermanagement/usersAssign{Project,Plan}.html?tproject_id=…&tplan_id=…` |
| `gui/templates/tl-classic/usermanagement/tabsmenu.tpl:17-18` | `lib/usermanagement/usersAssign.php?featureType=…` | `gui/templates/usermanagement/usersAssign{Project,Plan}.html` |
| `gui/templates/tl-classic/plan/planView.tpl:21` | `lib/usermanagement/usersAssign.php?featureType=testplan&featureID=` | `gui/templates/usermanagement/usersAssignPlan.html?tplan_id=` |
| `gui/templates/tl-classic/mainPageLeft.tpl:76` (project) | `lib/usermanagement/usersAssign.php?featureType=testproject&featureID=` | `gui/templates/usermanagement/usersAssignProject.html?tproject_id=` |
| `gui/templates/tl-classic/mainPageRight.tpl:25` (plan) | `lib/usermanagement/usersAssign.php?featureType=testplan&featureID=` | `gui/templates/usermanagement/usersAssignPlan.html?tplan_id=` |

The new `assignRolesAction` keeps the legacy `…&tplan_id=` + caller-appends-the-id
convention that `dashio/plan/planView.tpl` and `api/plans/index.php` rely on, and
now also carries the real `tproject_id` (the modern plan screen needs it, per
#945) instead of dropping it.

## Retained: legacy-parity comments

The ~60 `Legacy parity: usersAssign.php:NNN` / `usersAssign.tpl:NNN` comments in
`api/roles/index.php`, `lib/functions/testplan.class.php`,
`lib/functions/testproject.class.php` and the two modern `.html` screens are
**kept as-is**. They record which legacy line each modern behaviour was ported
from and are the audit trail for the whole #924–#946 batch; the line numbers stay
resolvable through git history.

## Verification

- `php -l lib/functions/testplan.class.php` — no syntax errors.
- Real Smarty compile of all 7 edited templates (Smarty 4.5.7,
  `createTemplate()` + `compileTemplateSource()` with the app's custom
  `lang_get` / `jsValidate` tags stubbed): **7/7 compiled**, and the generated
  PHP for the rewritten menu URLs is correct, e.g.
  `"gui/templates/usermanagement/usersAssignPlan.html?tproject_id=".((string)$gui->tproject_id)."&tplan_id=".((string)$gui->tplan_id)`.
- Repo-wide grep for `usersAssign.php` / `usersAssign.tpl` — no live references
  remain; only parity comments and historical docs mentions.
- `tools/lint_i18n.py` — unchanged from the base commit (pre-existing
  `ru.json` kana warnings; no bundle was touched by this issue).

Not covered here: an end-to-end browser pass over the modern Assign Project/Plan
Roles screens. The MariaDB instance backing `config_db.inc.php` (127.0.0.1:3307)
was not running in the dev container, so the delete was validated statically.
The modern screens and their BFF routes are untouched by this change.

## Follow-ups (not filed)

- `docs/Task-Issue-925-*`, `-927`, `-929`, `-932`, `-941` and several CHANGELOG
  entries cite `usersAssign.php` line numbers. They remain accurate as history;
  worth a one-line "legacy file removed in #947" note if the wiki is re-mirrored.
- SCREEN-COMPARE-STATUS rows 5 and 6 still list their functional gaps as `OPEN`
  even though the CHANGELOG shows them shipped with passing suites. That
  bookkeeping is left to the per-issue CI runs, not to this cleanup.
