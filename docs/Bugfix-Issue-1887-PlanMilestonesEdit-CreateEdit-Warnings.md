# Issue #1887 — `planMilestonesEdit.php` create/edit form wrote 8 E_WARNING rows per render

**Issue:** [#1887](https://github.com/sebiboga/testlink-upgraded/issues/1887)
**Branch:** `fix/issue-1887-planmilestonesedit-warnings`
**Status:** VERIFIED-FIXED — regression suite `Issue #1887` 5/5 PASS
**Related:** [#1726](Bugfix-Issue-1726-PlanMilestonesEdit-Blank-200-Default-Render-Branch.md)
(found while verifying it), [#1888](https://github.com/sebiboga/testlink-upgraded/issues/1888)
(sibling defect on the failed-`doCreate` path, filed not fixed).

## Symptom

Rendering the legacy Plan Milestones create/edit form
(`lib/plan/planMilestonesEdit.php?doAction=create|edit`) wrote **8 `log_level=2` E_WARNING
rows per request** into the Event Viewer, from the compiled Smarty template. The form still
rendered, but every legacy deep link polluted the diagnostic log.

## Investigation (measured before any code was touched)

Environment: app `http://localhost:8082`, DB `testlink` (freshly imported — 0 projects,
0 events), `admin`/`admin`. Fixture `tmp/fixtures_1887.php` created testproject `PMS1887`
(id=1, priority enabled), testplan `P8 Plan` (id=2), milestone `P8 Milestone` (id=1).

| probe | result |
|---|---|
| `GET ?doAction=create&tplan_id=2` | HTTP 200, 15712 bytes |
| `COUNT(events WHERE log_level=2)` before / after | 0 → **8** |

The 8 rows map to the compiled `gui/templates/dashio/plan/planMilestonesEdit.tpl`:
`managerURL` (`:18`), `tplan_id` (`:123`), `tproject_id` (`:124`),
`tprojOpt->testPriorityEnabled` (`:169`, 2 rows), `cancelActionJS` (`:222`),
`tprojOpt->testPriorityEnabled` (`:229`, 2 rows).

## Root cause chain

| # | file:line | what happens |
|---|---|---|
| 1 | `lib/plan/planMilestonesEdit.php:18` | `$gui = initialize_gui($db,$args);` |
| 2 | `lib/plan/planMilestonesEdit.php:207-221` | `initialize_gui()` returns a `stdClass` with only `user_feedback/main_descr/action_descr/grants`. It never calls `initUserEnv()` and never sets `tproject_id`/`tplan_id`/`tprojOpt`/`managerURL`/`cancelActionJS`. |
| 3 | `lib/plan/planMilestonesEdit.php:136-141` | `renderGui()` merges only the *command* result's properties (`create()/edit()` return `main_descr/action_descr/template/submit_button_label/milestone`) — none of the 5 missing props. |
| 4 | `gui/templates/dashio/plan/planMilestonesEdit.tpl:18,123,124,169,222,229` | reads the missing props unguarded ⇒ 8 E_WARNINGs under PHP 8. |

**Why it breaks now:** the controller has never set these props (verified back to
`8376db18b`, 2009-era). Under PHP 5/7 an unset object-property read was `E_NOTICE`, which
Smarty/TestLink suppressed; PHP 8 promoted it to `E_WARNING`, and `tLog()` persists every
warning into `events`. A 15-year-old latent defect only started polluting the Event Viewer
after the PHP 8 port.

**Sibling comparison:** `lib/plan/planMilestonesView.php:66-96` gets
`tproject_id`/`tplan_id`/`tprojOpt` from `initUserEnv()`
(`lib/functions/common.php:1718-1720,1861`) and sets `managerURL` itself (`:93-94`). The
edit controller simply omitted the equivalent initialisation.

## Blast radius

* Only this controller+template pair. `grep -rn "cancelActionJS" lib/plan/` → 0 hits; the
  other writers (`lib/testcases/*`) set it themselves.
* `managerURL` is dead in this template (defined `:18-21`, never re-used; the form `action`
  is hardcoded at `:119`).
* `tplan_id`/`tproject_id` feed the form's hidden inputs (`:123-124`); unset, the POST fell
  back to session values (`init_args():83-91`), so the form kept working.
* `tprojOpt->testPriorityEnabled` drives the priority branch (`:169-209`): unset ⇒ the form
  always rendered the "no priorities" layout even for a priority-enabled project.

## The fix (minimal, 1 file, +17 lines)

`lib/plan/planMilestonesEdit.php` `initialize_gui()` — populate the 5 props from `$argsObj`
plus the project options, mirroring `planMilestonesView.php`:

```php
$gui->tproject_id = intval($argsObj->tproject_id);
$gui->tplan_id = intval($argsObj->tplan_id);
$gui->managerURL = "lib/plan/planMilestonesEdit.php" .
                   "?tproject_id={$gui->tproject_id}&tplan_id={$gui->tplan_id}";
$tprjMgr = new testproject($dbHandler);
$gui->tprojOpt = $tprjMgr->getOptions($gui->tproject_id);
$gui->cancelActionJS = '';   // empty => historical history.back() fallback
```

### Why this method (and what was rejected)

* **Populate the controller, not guard the template.** The `.tpl` could be silenced with
  `|default:''` / `isset()`, but that only removes the noise: the priority branch stays in
  the wrong layout and the hidden `tplan_id`/`tproject_id` stay empty. Populating restores
  the intended behaviour *and* removes the warnings.
* **`cancelActionJS = ''`** keeps the template's historical `history.back()` fallback, so no
  cancel behaviour changes.
* **No `initUserEnv()` call.** That helper performs access/redirect side effects (menu,
  grants, effective role) that this lightweight editor does not need; assigning the exact
  five props is smaller and side-effect-free.

## Verification

Regression suite `Issue #1887`, appended to `tmp/TLU_Test_Cases.md`.

| case | before | after |
|---|---|---|
| `?doAction=create&tplan_id=2` | 200, 15712 B, **+8 warnings** | 200, 16940 B, **+0 warnings** |
| `?doAction=edit&id=1&tplan_id=2` | +8 warnings | **+0 warnings**, name prefilled |
| priority project | single "no priority" field | 3 inputs (low/medium/high_priority_tcases) |
| hidden ids | empty | `tplan_id=2`, `tproject_id=1` |
| `?doAction=bogus` (#1726) | 302 + 1 ERROR | unchanged |
| `php -l` | — | No syntax errors detected |

## Known, deliberately NOT fixed here

* **#1888** — a failed `doCreate` submit (invalid `target_date` format) re-renders the form
  with `$gui->milestone` unset, producing **16** E_WARNING rows. `doCreate()`
  (`lib/plan/planMilestonesCommands.class.php:105-178`) never assigns `milestone` on its
  validation-failure paths. Separate code path, filed with the `bug` label (FIX-ISSUE §4).

## How to re-test in one command

```bash
# login, then create + edit must add ZERO log_level=2 rows
curl -s -b tl_cookie.txt http://localhost:8082/lib/plan/planMilestonesEdit.php?doAction=create&tplan_id=2 >/dev/null
mysql -h 127.0.0.1 -utestlink -ptestlink testlink -N -e "SELECT COUNT(*) FROM events WHERE log_level=2;"
```
