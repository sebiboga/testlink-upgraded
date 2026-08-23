# Bugfix — Issue #422: dashio theme missing templates & mis-resolved tl-classic template names

**Issue:** [#422](https://github.com/sebiboga/testlink-upgraded/issues/422) ·
**Status:** CLOSED (verified fixed on default branch) · **Verification run:** 2026-08-23,
branch `fix/issue-422`, HEAD under test `b58a18a3c`

## The reported bugs (2026-08-16)

Two dashio pages died with `Smarty: Unable to load template` because the theme
either lacked a template that only existed under `tl-classic`, or resolved a
template name using the tl-classic naming scheme:

**A. `planAddTC_m1.tpl` missing from dashio**
Test Plan → Add / Remove Test Cases → click a suite in the tree → fatal:
`lib/plan/planAddTc.php:195` renders `$templateCfg->template_dir . 'planAddTC_m1.tpl'`,
which then existed only at `gui/templates/tl-classic/plan/`. Test cases could not
be linked to a plan from this theme.

**B. tl-classic name used inside dashio execution template**
`config.inc.php:2086` sets `$g_tpl = array('inc_exec_controls' => 'inc_exec_img_controls.tpl')`.
`tlsmarty.inc.php:237` merges `$g_tpl` into the assigned `tplConfig`, and
`exec_show_tc_exec.inc.tpl` included that tl-classic file name from a dashio
directory → pass/fail/blocked controls never rendered.

## How they were fixed (both landed 2026-08-17, one day after filing)

| Part | Commit | Change |
|------|--------|--------|
| A | `4c73d651e` | Added `gui/templates/dashio/plan/planAddTC_m1.tpl` |
| B | `857555dad` | `exec_show_tc_exec.inc.tpl:516-524` maps the config toggle onto dashio names: `inc_exec_controls.tpl` → `$tplConfig['exec_controls.inc']`, else `$tplConfig['exec_img_controls.inc']`; both keys registered in `tlsmarty.inc.php:209-210`. The `inc_exec_controls` ↔ `inc_exec_img_controls` switch keeps working |

Both commits predate this verification run; issue #422 stayed open unnoticed, so
the 2026-08-23 run re-verified everything live and closed it with evidence
instead of duplicating the fix.

## Verification approach & evidence

Fixtures (`php tmp/fixtures_422.php`, idempotent): project I422 Project, suite
I422 Suite, cases Case Gamma/Delta, plan I422 Plan + build I422 Build, zero links.

1. Add / Remove Test Cases: suite click refreshes the case table (`GET
   /lib/plan/planAddTC.php → [200]`), Save changes links both cases (DB rows
   `(9,4,0)`,`(9,7,0)` in `testplan_tcversions`) — no fatals.
2. Execute Tests: execSetResults renders Passed/Failed/Blocked controls +
   notes editor + Save — frame-level scan of all iframes found zero matches for
   `Fatal error|Unable to load template|Uncaught`.





3. Event Viewer: only AUDIT entries for the tested flows.
4. Regression suite 61 in `tmp/TLU_Test_Cases.md`: **7/7 PASS**.

## The "underlying problem" audit (requested by the issue)

Every `{include}`/`{extends}` path in all dashio templates was resolved against
the seven Smarty template-dir roots (`lib/functions/tlsmarty.inc.php:102-110`)
and the including file's directory. All four live `$tplConfig` consumers
(`inc_exec_controls`, `inc_tcbody`, `inc_steps`, `inc_show_scripts_table`)
resolve correctly. Findings:

**Live latent fatals — filed separately**
- [#626](https://github.com/sebiboga/testlink-upgraded/issues/626):
  `execute/include/exec_test_spec.inc.tpl:147` includes nonexistent
  `inc_show_scripts_table.tpl` when a TC version has linked scripts (dashio's
  real template is `include/showScriptsTable.inc.tpl`).
- [#627](https://github.com/sebiboga/testlink-upgraded/issues/627):
  `testcases/include/steps_horizontal.inc.tpl:114,126` include
  `execute/add_issue_on_step.inc.tpl` / `execute/issue_inputs_on_step.inc.tpl`
  — wrong prefix (real location `execute/include/`); fatals whenever
  `$gui->tlCanCreateIssue` is true.

**Dead code only (no live renderer, left untouched):** `results/resultsBuild.tpl`
(no `lib/results/resultsBuild.php`; references missing `inc_res_by_comp.tpl` /
`inc_res_by_keyw.tpl`), `testcases/tcView_viewer.tpl:6` (superseded by
`tcViewViewer.inc.tpl`), `testcases/tcEditViewer.tpl:69` (superseded by
`tcEditViewer.inc.tpl`).

Also discovered while testing: `testcase.class.php:804` emits an E_WARNING per
created case when step arrays omit `execution_type` — filed as
[#628](https://github.com/sebiboga/testlink-upgraded/issues/628).
