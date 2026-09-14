# Task — Issue #911: Test Specification — Estimated Execution Duration field

**Screen:** `gui/templates/testcases/testSpec.html` (Test Specification editor)
**BFF API:** `api/testcases/index.php` (`context`, `get`, `create`, `update`)

## Gap

The 1.9.20 design-time editor rendered an **Estimated Execution Duration (min)**
input (`attributesLinear.inc.tpl`, select keyed on
`$tlCfg->testcase_cfg->estimated_execution_duration->required`, persisted via
`setEstimatedExecDuration` → `tcversions.estimated_exec_duration`). The
modernized Test Specification screen had dropped the field entirely: the BFF
`create`/`update` silently discarded `estimated_execution_duration`, and the
editor had no input — only the read-only Full Viewer (`tcView.html`) showed the
value.

## Implementation

**BFF — `api/testcases/index.php`**
- New helper `isDurationRequired($tcaseCfg)` mirrors the config flag
  `$tlCfg->testcase_cfg->estimated_execution_duration->required`
  (`config.inc.php`, default `''` = optional; enable with `'required'`).
- `context`, `get` and `keywords` responses now expose
  `estimateDurationRequired` (boolean) for the editor chrome.
- `create`: reads `estimated_execution_duration`; non-numeric non-empty →
  HTTP 400 `Invalid estimated duration`; empty while required → HTTP 400
  `Estimated execution duration is required`; otherwise passed as
  `estimatedExecDuration` in the `testcase::create` options.
- `update`: same validation; value folded into
  `['status' => ..., 'estimatedExecDuration' => $estDur]` — empty string
  writes NULL, matching legacy always-write semantics.

**Editor — `gui/templates/testcases/testSpec.html`**
- `formHtml()` renders the input `#tcDurationInput` (create + edit share the
  form); `required` attribute applied only when `ctx.durationRequired`.
- `collectForm()` adds `estimated_execution_duration` to the save payload.
- `saveForm()` blocks non-numeric values (toast `tspec.errDurationInvalid`) and
  empty-when-required (toast `tspec.errDurationRequired`).
- Detail view meta-grid shows the value ("… min") only when set.

**i18n** — `tspec.estimatedDuration`, `tspec.estimatedDurationHint`,
`tspec.errDurationInvalid`, `tspec.errDurationRequired` added to all 10 bundles
(`de en es fr it ja pt ro ru zh`).

## Verification

Browser E2E + BFF fetch against `http://localhost:8082` (admin/admin,
project 1, suite 2):
create/update persist decimal values (`7.5` → `7.50`, `23.5` → `23.50`);
edit round-trip updates detail view + DB; non-numeric blocked client-side and
HTTP 400 server-side; with config `required='required'` the label shows the red
asterisk and empty saves are blocked both client-side and server-side
(400 `Estimated execution duration is required`); clearing leaves NULL;
`tcView.html` Full Viewer displays the value; Event Viewer clean (no new
ERROR/WARNING); no new browser console errors. Test suite: `Task — Issue #911`
in `tmp/TLU_Test_Cases.md` — 12/12 PASS. Commits `e4d63cebb`,
`375aae3e4` on `task/issue-911`.