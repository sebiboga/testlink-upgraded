# Task — Issue #1044: test case / version operations panel in the Test Case Viewer

## What was missing

`gui/templates/testcases/tcView.html` (the modern Test Case Viewer) had a toolbar with
**7 of the 13 buttons** the legacy operations panel offered. The 8 missing ones are core
test-case lifecycle actions that 1.9.20 exposed and 2.0.1 did not:

| # | legacy button | legacy source | modern before |
|---|---|---|---|
| 1 | **New Sibling** (`new_tc` → `doAction=create`, `containerID=testsuite_id`) | `tcView_viewer.tpl:159-165` | **missing** |
| 2 | **Move / Copy** (`move_copy_tc` → `tcMove.tpl`) | `tcView_viewer.tpl:170-178`, `tcEdit.php:187-215` | **missing** |
| 3 | **Delete Test Case** (`delete_tc` → `tcDelete.tpl`, `tcversion_id = testcase::ALL_VERSIONS`) | `tcView_viewer.tpl:176-183` | **missing** |
| 4 | **New Version** (`do_create_new_version`) | `tcView_viewer.tpl:253-263`, `tcEdit.php:277-279` | BFF action existed, **no button** |
| 5 | **New Version From Latest** (`do_create_new_version_from_latest`) | `tcView_viewer.tpl:264-270`, `tcEdit.php:280-283` | **missing** |
| 6 | **Freeze / Unfreeze Version** (`freeze_this_tcversion` / `unfreeze_this_tcversion`) | `tcView_viewer.tpl:271-289` | **missing** |
| 7 | **Delete This Version** (`delete_tc_version`) | `tcView_viewer.tpl:295-301`, `tcEdit.php:151-184` | **missing** |
| 8 | **Update Test Plan link** gate (`updTPlanTCV`, `editOnExec` mode only) | `tcEdit.php:81`, `testcaseCommands.class.php:1514` | **missing** |

Already present before this change: Edit Version, Bulk Update, Execution History,
Add to Test Plan, Export, Compare Versions, Print.

Legacy rendered all of them inside two collapsible `<fieldset class="groupBtn">` blocks behind
a **cog icon** in the header (`tcView.tpl:136-141`,
`toogleShowHide('tcView_viewer_tcase_control_panel_{$tcVersionID}','inline')`),
display toggled by `$tlCfg->gui->op_area_display->test_case`. The modern toolbar had no cog
icon and no such block at all.


## The implementation

### BFF — `api/testcases/index.php` (`action=view`)

* three more grants the panels gate on: `delete_frozen_tcversion`,
  `testproject_delete_executed_testcases`, `testplan_planning`;
* a new **`canDo`** map — a server-side port of
  `testcase::getShowViewerActions()` (`lib/functions/testcase.class.php:4939-4971`):
  * `show_mode = editOnExec` → only `edit`, `create_new_version`, `updTplanTCV` = `yes`;
  * no `mgt_modify_tc` → `editDisabled`, **everything** `no`;
  * otherwise all `yes`, then `freeze` ← `testcase_freeze` and
    `delete_frozen_tcversion` ← that grant (`:7479-7487`).

  Computing it server side means the modern toolbar renders the same buttons legacy rendered
  for the same user/mode, instead of re-deriving the matrix in JS;
* **`versionCount`** — the number of versions of the test case regardless of which one
  `?tcversion_id` selected. It drives the legacy `$args_can_delete_version` rule
  ("Delete This Version" needs *other* versions to exist, `tcView.tpl:118-122`).

### BFF — `api/testcasesedit/index.php`

* `create_version` accepts **`source=latest`** → resolves `getLatestVersionID()` on the server
  (port of `do_create_new_version_from_latest`, `tcEdit.php:280-283`), so the client cannot
  drift from the legacy "clone the LATEST, not the opened version" semantics;
* new action **`set_is_open`** — freeze/unfreeze, port of
  `testcaseCommands::freeze()/unfreeze() → setIsOpen()` + `update_last_modified()`
  (`testcaseCommands.class.php:1372-1404`); gated on `mgt_modify_tc AND testcase_freeze`;
* new action **`delete`** with `scope=all|single` — port of
  `testcaseCommands::doDelete() → testcaseMgr->delete($tcase_id,$tcversion_id)`
  (`:608-655`), reproducing the legacy gates:
  * `scope=all` → `testcase::ALL_VERSIONS` (whole test case, all versions);
  * executed test case without `testproject_delete_executed_testcases` → **403**
    (`$delete_enabled = 0`, `:530-539`);
  * frozen single version without `delete_frozen_tcversion` → **403** (`tpl:295-301`);
  * single delete refused when it is the **only** version (`tpl:118-122`);
  * audit events `audit_testcase_deleted` / `audit_testcase_version_deleted` (`:634-649`).

### Screen — `gui/templates/testcases/tcView.html`

A Dashio **operations strip** below the toolbar, toggled by the new cog **Operations** button,
holding the two legacy fieldsets:

* *Test Case Operations* — New Sibling, Move / Copy, Delete Test Case;
* *Test Case Version Operations* — New Version, New Version From Latest,
  Freeze/Unfreeze Version (single button, label + icon follow `is_open`), Delete This Version.

Delete actions open Dashio confirm modals carrying the legacy `tcDelete.tpl` text
(`title_del_tc`, `delete_linked`, `delete_linked_and_exec` → `tcview.deleteExecutedWarning`).
On success the viewer reloads (version delete) or returns to `testSpec.html` (test case delete).

New Sibling and Move / Copy deliberately **reuse the modern twins** of the legacy targets
instead of bouncing through legacy pages:

* New Sibling → `testSpec.html?tproject_id=…&containerID=<suite>&create=1`, which is the
  documented modern entry point for the legacy
  `suiteView "create_tc" -> tcEdit.php?doAction=create&containerID=<suite>` button
  (`testSpec.html:621-627`);
* Move / Copy → `containerMoveTC.html?tproject_id=…&suite_id=<parent>&tcase_ids[]=<tcase>`,
  the port of `tcMove.tpl` (target suite, copy-as-ghost-steps, optional keyword / requirement
  copy) backed by `api/tcmovecopy`.


### i18n

28 new `tcview.*` keys in **all 10** locale bundles (`en, ro, de, es, fr, it, pt, ja, ru, zh`),
validated with `python3 -m json.tool`.

## Verification

Suite **#1044 — 22/22 PASS** (`tmp/TLU_Test_Cases.md`), including:
freeze → unfreeze round-trip, the frozen-version delete gate (hidden for a user without
`delete_frozen_tcversion`), both New Version variants (`source=this` / `source=latest`
resolving to the right parent), single-version delete (3 → 2 versions), whole-test-case delete
(0 rows left in `tcversions` / `nodes_hierarchy`), the `editOnExec` mode collapsing Panel A,
New Sibling and Move/Copy landing on the right pre-filled screens, the audit log entries, and
**0 new Error/Warning rows in `events`**.

Fixture: `php tmp/fixtures_1044.php` (tproject `OpsDemo`, suite `OpsSuite`, one test case with
two versions).

## Files

| file | role |
|---|---|
| `api/testcases/index.php` | `canDo` gate map, 3 new grants, `versionCount` |
| `api/testcasesedit/index.php` | `set_is_open`, `delete` (`all`/`single`), `create_version source=latest` |
| `gui/templates/testcases/tcView.html` | operations strip, cog toggle, confirm modals, all handlers |
| `gui/templates/i18n/*.json` | 28 `tcview.*` keys × 10 locales |
| `tmp/fixtures_1044.php` | fixture (project + suite + TC with 2 versions) |
