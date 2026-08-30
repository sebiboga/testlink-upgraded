# Bugfix: Issue #627 — Exec screen step-level issue UI fatal "Unable to load template execute/add_issue_on_step.inc.tpl"

## Summary

With an issue tracker running and a tester allowed to create issues at step level, the legacy execution screen (`lib/execute/execSetResults.php`) fataled with an HTTP 500:

```
Smarty: Unable to load template 'file:execute/add_issue_on_step.inc.tpl'
in 'testcases/include/steps_horizontal.inc.tpl'
```

The per-step add-issue UI (checkbox + hidden issue-inputs row) could never render in the **dashio** theme.

## Root Cause

`gui/templates/dashio/testcases/include/steps_horizontal.inc.tpl` (the horizontal steps layout used on the exec screen) referenced the two step-level issue templates with a **wrong include prefix**:

- line 114: `{include file="execute/add_issue_on_step.inc.tpl" ...}`
- line 126: `{include file="execute/issue_inputs_on_step.inc.tpl" ...}`

Both guards are inside `{if $inExec && $gui->tlCanCreateIssue}` blocks (lines 113/123), so the fatal fires **only** when the tester may create issues at step level.

The dashio theme's 7 Smarty `template_dir` roots are `lib/functions/tlsmarty.inc.php:101-110` (`main` → `gui/templates/dashio/`, `execInc` → `gui/templates/dashio/execute/include/`, ...). An include named `execute/...` is resolved as `dashio/execute/...` (missing) then as `dashio/execute/include/execute/...` (missing). The real templates live at:

- `gui/templates/dashio/execute/include/add_issue_on_step.inc.tpl`
- `gui/templates/dashio/execute/include/issue_inputs_on_step.inc.tpl`

Other dashio exec templates already use the correct `execute/include/` prefix — precedent at `gui/templates/dashio/execute/include/exec_test_spec.inc.tpl:92` and `exec_show_tc_exec.inc.tpl:497`.

The **tl-classic** twin (`gui/templates/tl-classic/testcases/steps_horizontal.inc.tpl:117,129`) is correct and was **not** changed: its templates genuinely live under `gui/templates/tl-classic/execute/`.

**Why it breaks now:** the two `steps_horizontal.inc.tpl` includes kept the tl-classic `execute/...` prefix when the theme was ported to dashio, while the files were moved to `execute/include/` here.

## Fix

Two-line change in `gui/templates/dashio/testcases/include/steps_horizontal.inc.tpl`:

```diff
-          {include file="execute/add_issue_on_step.inc.tpl"
+          {include file="execute/include/add_issue_on_step.inc.tpl"
                    args_labels=$labels
                    args_step_id=$step_info.id}
...
-      {include file="execute/issue_inputs_on_step.inc.tpl"
+      {include file="execute/include/issue_inputs_on_step.inc.tpl"
                args_labels=$labels
                args_step_id=$step_info.id}
```

## Why This Approach

- **Minimal**: only the two include paths changed; `args_labels`/`args_step_id` pass-through identical.
- **Matches dashio conventions**: same prefix already proven by `exec_test_spec.inc.tpl` and `exec_show_tc_exec.inc.tpl`.
- **Alternative rejected**: copying the templates to `gui/templates/dashio/execute/` would create duplicate artifacts and diverge from the agreed layout.
- **tl-classic must stay untouched** — changing its prefix would break that theme's resolution.

## Verification

The modernized `execTest.html` does not use this template; the surface is the legacy `execSetResults.php`. Because `tlCanCreateIssue=true` requires a tracker implementing `addIssue` AND a live API connection (unreachable in the offline sandbox), the guarded include was exercised on the REAL page with a temporary, never-committed harness forcing `$gui->tlCanCreateIssue=true` while the stack (Smarty engine, real tracker object, DB, templates) stayed intact.

- **PRE-FIX repro:** HTTP 500 + `PHP Fatal error: Smarty: Unable to load template 'file:execute/add_issue_on_step.inc.tpl'` (server log). Screenshot: `docs/screenshots/issue-627-pre-fix-fatal.png`.
- **POST-FIX:** steps render and the per-step add-issue UI appears (`issueForStep_<id>` checkbox, `issueSectionForStep_` inputs row, `issueSummaryForStep_`). Screenshot: `docs/screenshots/issue-627-steps-with-fix.png`.
- **Clean run without harness:** legacy exec screen renders correctly (mantis/db tracker connected, step execution UI intact). Screenshot: `docs/screenshots/issue-627-clean-render.png`. No new Error/Warning events from the clean run.

## Fixture

`tmp/fixtures_627.php` (idempotent per DB import) seeds a tracker-enabled project TRACK627 with the mantis/db tracker (mantis_bug_table stored inside the `testlink` schema — the `testlink` MySQL user cannot create other databases). Config note: the `cfg` string must NOT contain an `<?xml ...?>` prolog — `issueTrackerInterface::setCfg()` prepends its own.

## Files Changed

- `gui/templates/dashio/testcases/include/steps_horizontal.inc.tpl` — lines 114 & 126, include prefixes (only file changed)

## Regression Matrix

| Case | Result |
|---|---|
| Steps render on legacy exec screen, tracker connected | PASS — no fatal, steps + exec UI intact |
| Step-level add-issue guarded block (harness `tlCanCreateIssue=true`) | PASS — the two includes resolve and render their markup |
| tl-classic twin template | PASS — untouched, correct |
| Event Viewer / events table (clean run) | PASS — no new Error/Warning from the fix |

## Related

- Issue #627: exec screen step-level issue UI — wrong include prefix `execute/` instead of `execute/include/` fatals when tester can create issues.
- Issue #779 (discovered during verification, separate `bug`): 4× `E_WARNING` "Undefined property: stdClass::$issuetype/..." on every tracker-connected exec render (pre-existing in HEAD, unrelated to this fix).