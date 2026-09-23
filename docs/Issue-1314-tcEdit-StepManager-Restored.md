# Issue 1314 — tcEdit: per-step execution type + step-manager commands restored

The modern Test Case Editor (`gui/templates/testcases/tcEdit.html`) regains the
legacy per-step editor capabilities that were dropped during modernization:
a **per-step execution type**, and the **step-manager commands** Insert, Copy,
Move Up/Down, Resequence, plus the legacy duplicate step-number guard.

## Legacy behaviour (1.9.20 — `lib/testcases/tcStepEdit.tpl` + `testcaseCommands.class.php`)

- each step row had a `Select exec type`; shown only when the project option
  `automationEnabled` is ON;
- toolbar: insert step after current, copy current step, move up/down,
  resequence steps, delete step;
- saving a step set with a duplicated step number was rejected with
  `warning_step_number_already_exists`.

## Modern re-implementation

- **BFF** `api/testcasesedit/index.php`: the edit payload now carries
  `automation_enabled` (project flag) so the screen knows when to render the
  per-step selector. The `update` route already accepted per-step
  `execution_type` (`index.php:495`) and persists through `testcase::update` →
  `update_tcversion_steps` (testcase.class.php:6405) → `create_step`, which
  validates against the `execution_types` map (1 Manual / 2 Automated; 3/4 are
  normalized to Manual, exactly like legacy).
- **HTML** `gui/templates/testcases/tcEdit.html`:
  - `execTypeOptions()` renders Manual/Automated options; `addStepRow()` sets
    the per-row `.step-exect` only when `ctx.info.automation_enabled`;
  - per-row toolbar Insert/Copy/MoveUp/MoveDown/Delete + header `Resequence`
    (insertStepRow/copyStepRow/moveStepRow/deleteStepRow/renumberSteps);
  - `collectSteps()` reads the per-row selector, falling back to the version
    default when the column is hidden;
  - `doSave()` validates step numbers (non-positive → `warningStepNumber`,
    duplicate → `warningStepDup`) and aborts with the localized message;
  - CSS `.step-tools/.btn-mini/.step-ctrls/.step-execctl`.
- **i18n** — 8 keys added to all 10 locale bundles: `tcedit.stepInsert`,
  `stepCopy`, `stepMoveUp`, `stepMoveDown`, `resequenceSteps`,
  `stepsResequenced`, `warningStepNumber`, `warningStepDup` (translations from
  legacy `locale/*/strings.txt`).

## Verification

Browser (chrome-devtools, fixture `tmp/fixtures_1314.php`, project
SMgrDemo automationEnabled=1, tc `stepTC` v1 = manual/1 + automated/2):
- per-row selects render with the right values, column hidden when automation
  is disabled; Insert and Copy produce correctly renumbered rows; `moveStepRow`
  reorders; resequence renumbers.
- Save no longer wipes a step's execution type (pre-fix runs measured
  exec_type 2 -> 1 wipe; post-fix the automated step persists as 2).

Deterministic BFF round-trip (`testcase::update` with a reordered 3-step set:
automated/ex2 first, manual/ex1, inserted/ex1) stored DB rows in exactly that
order with per-step exec types (SQL on `tcsteps` JOIN `nodes_hierarchy`
WHERE parent_id=34): step1 automated/2, step2 manual/1, step3 inserted/1.

Event Viewer: the modern UI path emits no new Error/Warning entries (the
E/W rows logged into `events` during this run trace to the CLI verification
harness's deliberately bad-parameter calls, not to the screen).

Screenshots: `docs/screenshots/issue-1314-tcedit-stepmanager-rendered.png` and
wiki `issue-1314-tcedit-stepmanager-rendered.png`.

Test suite: `tmp/TLU_Test_Cases.md` — Suite #1314, 8/8 PASS. (Refs #1314)
