# Task 906 — Full step management in Test Specification editor (gap vs legacy)

**Issue:** [#906](https://github.com/sebiboga/testlink-upgraded/issues/906)
**Status:** IMPLEMENTED (2026-09-14)

## The gap

The legacy step editor grid (`gui/templates/dashio/testcases/tcStepEdit.tpl`,
`lib/testcases/testcaseCommands.class.php`) supports inserting a step at any
position (`doInsertStep`), copying/cloning a step (`doCopyStep`), re-ordering
steps (`doReorderSteps` via `set_step_number`), auto-resequencing after delete,
plus a **per-step `execution_type` selector** (manual vs automated, shown when
`tprojOpt->automationEnabled`).

The modern `testSpec.html` step editor only offered **+ Add Step** (appends at
the end) and **Remove step** per row. There was no insert / copy / reorder /
resequence, and no per-step execution type. The BFF normalization
(`api/testcases/index.php` `$normSteps`) forced every step to the TC-level
`execution_type`, discarding any per-step value the client sent.

## Legacy source of truth

- `lib/testcases/testcaseCommands.class.php` — `doReorderSteps` (set_step_number),
  `doInsertStep` (create step + shift subsequent step numbers),
  `doCopyStep` (clone step incl. execution_type), `doResequenceSteps`,
  `doUpdateStepAndInsert` / `doOptimizeSteps`.
- `lib/testcases/tcEdit.php:64-106` — dispatches all the above step actions.
- `gui/templates/dashio/testcases/tcStepEdit.tpl:165-239` — step grid with a
  per-step `exec_type` `<select>` (only when `$gui->tprojOpt->automationEnabled`)
  and the copy/insert buttons; the executor rows are exposed so users can
  update-and-insert, copy, etc.
- `install/sql/mysql/testlink_create_tables.sql:472-482` — `tcsteps` already
  carries `execution_type tinyint(1)` (1 = manual, 2 = automated); the modern
  BFF already reads it back in `get` (`api/testcases/index.php:334/352`).

## Modern implementation (port)

**Backend `api/testcases/index.php`**
- `$normSteps($rawSteps, $execType)` now honours a **per-step `execution_type`**
  from each element (`intval($st['execution_type'] ?? $execType)`) and falls
  back to the TC-level execution type when the value is missing or outside the
  manual/automated domain (`TESTCASE_EXECUTION_TYPE_MANUAL` /
  `TESTCASE_EXECUTION_TYPE_AUTO`). Applies to both `create` and `update`
  (both route through `$normSteps`).
- No schema change required: `update_tcversion_steps()`
  (`lib/functions/testcase.class.php:6395-6422`) already persists the per-step
  `execution_type` it receives.

**Frontend `gui/templates/testcases/testSpec.html`**
- Step grid now has an extra **Execution Type** column (select manual/automated
  per row) shown only when the owning test project has `automationEnabled` — the
  same gate as the legacy editor (`ctx.options.automationEnabled` from the
  `context` BFF).
- Each step row gets a control cluster: **Insert step after** (`+`),
  **Copy step** (`fa-copy`), **Move up** (`fa-arrow-up`), **Move down**
  (`fa-arrow-down`) and the existing **Remove** (`fa-xmark`).
- New helpers: `insertStepRow()` (clones the row's execution type), `copyStepRow()`
  (clones actions/expected/execution_type), `moveStepUp()` / `moveStepDown()`
  (row swap), all followed by `renumberSteps()` so step_numbers stay sequential.
- `collectForm()` reads the per-row `execution_type` and sends it in `steps[]`;
  `formHtml()`/`stepRowHtml()` accept and default it via `ctx.stepDefaultExec`
  (the TC-level execution type).
- Read-only `renderTcView()` also shows an Execution Type column per step when
  automation is enabled.

**i18n**
- New keys added to all 10 locale bundles (`en/de/es/fr/it/ja/pt/ro/ru/zh`):
  `tspec.insertStep`, `tspec.copyStep`, `tspec.moveUp`, `tspec.moveDown`.

## Verification

Browser checks on a local instance:

- Edit a TC with 2 steps → **Insert step after** between step 1 and 2 → step 2/3
  renumbered; after Save the new order and step_numbers persist.
- **Copy step** → duplicate row with identical actions/expected/execution_type.
- **Move up / Move down** → row order swaps and renumbers.
- Remove a middle step → **resequence** (step_numbers collapse to 1..N).
- On a project with automation enabled → per-step execution type select
  (Manual/Automated); Save persists differing per-step values (visible on
  reload and in the read-only view).
- `api/testcases` `get`/`create`/`update` responses reflect the stored per-step
  `execution_type`.

## Files changed

- `api/testcases/index.php`
- `gui/templates/testcases/testSpec.html`
- `gui/templates/i18n/{en,de,es,fr,it,ja,pt,ro,ru,zh}.json`
- `CHANGELOG`