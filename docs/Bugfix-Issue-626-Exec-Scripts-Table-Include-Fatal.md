# Bugfix: Issue #626 — Exec screen fatal "Unable to load template inc_show_scripts_table.tpl" when a TC version has linked test scripts

## Summary

The server-rendered execution screen fataled with `SmartyException: Unable to load template 'file:inc_show_scripts_table.tpl'` whenever a test case version had at least one linked test script. The fatal blocked rendering of the whole execution screen for that case.

## Root Cause

`gui/templates/dashio/execute/include/exec_test_spec.inc.tpl:145-146` — the guard `{if isset($gui->scripts[$tcversion_id]) && !is_null($gui->scripts[$tcversion_id])}` becomes true whenever the executing TC version has ≥1 linked test script. Line **147** then executes:

```smarty
{include file="inc_show_scripts_table.tpl" ...}
```

`inc_show_scripts_table.tpl` is a **tl-classic theme filename**, literal and unqualified. Smarty resolves unqualified include names against the 7 `template_dir` roots (`lib/functions/tlsmarty.inc.php:101-110`), and `inc_show_scripts_table.tpl` exists only at `gui/templates/tl-classic/inc_show_scripts_table.tpl` — which is **not** among those roots. Result: resolvable nowhere → `SmartyException` → hard fatal.

The dashio theme's equivalent is `gui/templates/dashio/include/showScriptsTable.inc.tpl`, registered as the config key `showScriptsTable.inc` in `$stdTPLCfg` (`lib/functions/tlsmarty.inc.php:229-230`).

**Why it breaks now:** the exec spec template carries the correct dashio pattern for other includes (e.g. `exec_tc_relations.inc.tpl`, `attachments.inc.tpl` use short names that DO resolve), but the script-links include kept the original tl-classic filename — a legacy leftover never renamed when the theme was ported. The guard makes it latent: only projects that link scripts to TC versions and use the server-rendered execution path trigger it.

## Fix

One-line change in `gui/templates/dashio/execute/include/exec_test_spec.inc.tpl:147`:

```diff
-        {include file="inc_show_scripts_table.tpl"
+        {include file="{$tplConfig['showScriptsTable.inc']}"
          scripts_map=$gui->scripts[$tcversion_id]
          can_delete=false
          tcase_id=$tcversion_id
          tproject_id=$gui->tproject_id
```

This matches the already-correct precedent at `gui/templates/dashio/testcases/include/tcViewViewer.inc.tpl:605` and the registered config key `showScriptsTable.inc` at `lib/functions/tlsmarty.inc.php:229`.

## Why This Approach

- **Minimal**: only the include path changed; the four args (`scripts_map`, `can_delete`, `tcase_id`, `tproject_id`) are identical to the reference usage and map 1:1 to `showScriptsTable.inc.tpl`'s variables.
- **Proven precedent**: the exact same syntax already works in the TC viewer (`tcViewViewer.inc.tpl:605`).
- **Alternative rejected**: renaming/create a dashio `inc_show_scripts_table.tpl` would add a duplicate template and diverge from the established config-key convention.

## Files Changed

- `gui/templates/dashio/execute/include/exec_test_spec.inc.tpl` — line 147, include path (only file changed)

## Commit

`00683f985` — `fix(opencode): leftover changes from bug-fix run` (contains the one-line template fix; the only other hunk is `.ci_target_issue` housekeeping).

## Regression Matrix

| Case | Result |
|---|---|
| TC with a linked script (guard active) | PASS — scripts table renders, no fatal |
| TC with no linked script (guard off) | PASS — table absent, no regression |
| Full exec screen (steps/attachments/relations paths untouched) | PASS — regressed via template render |
| Event Viewer / events table | PASS — no new Error/Warning entries from the fix |

## Related

- Issue **#778** (separate, discovered here): `gui/templates/dashio/testcases/tcView_viewer.tpl:577` uses unregistered key `{$tplConfig.inc_show_scripts_table}` — a distinct latent defect in the TC-viewer screen, out of scope for #626.
