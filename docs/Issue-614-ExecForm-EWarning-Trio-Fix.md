# Issue 614 — E_WARNING Trio When Rendering an Execution Form

## Issue #614

**Test Case Execution → Execute Tests**: opening any test case for execution
(`lib/execute/execSetResults.php`) worked, but wrote **three E_WARNING rows per
page load** into the Event Viewer (`events` table), masking real errors and
growing the table:

1. `Undefined array key "round_enabled"` — compiled
   `gui/templates_c/*_0.file.execSetResults.tpl.php` line 182
   (source: `gui/templates/dashio/execute/execSetResults.tpl:141`)
2. `Attempt to read property "value" on null` — same compiled line 182
   (same expression: PHP evaluates the array key read first, then the property
   read on the resulting null)
3. `Trying to access array offset on null` — compiled
   `gui/templates_c/*_0.file.exec_show_tc_exec.inc.tpl.php` line 244
   (source: `gui/templates/dashio/execute/include/exec_show_tc_exec.inc.tpl:186`)

## Root Cause

### Warnings 1 + 2 — one expression, two warnings

`execSetResults.tpl:141` tested a **top-level template variable**:

```smarty
{if $round_enabled}Nifty('div.exec_additional_info');{/if}
```

but the PHP side only ever assigns it inside `$gui`:

```php
// initializeGui(), lib/execute/execSetResults.php:1479
$gui->round_enabled = 1;
```

Nobody ever assigns a top-level `$round_enabled`, so Smarty 4 compiles the test
to `$_smarty_tpl->tpl_vars['round_enabled']->value`. With no such tpl var:
PHP raises *undefined array key* for `tpl_vars['round_enabled']`, then *read
property "value" on null*. One wrong expression → both warnings.

(Note: `execDashboard.tpl:17` had papered over the same missing assignment with
the Smarty workaround `{$round_enabled=1}` — evidence that this variable never
existed at top level.)

### Warning 3 — null array access on first execution

`include/exec_show_tc_exec.inc.tpl:186`:

```smarty
{if $gui->other_execs.$tcversion_id}
```

`initializeGui()` explicitly initializes `$gui->other_execs = null;`
(execSetResults.php:408), and `getOtherExecutions()` **returns null** whenever
history is off and there is no prior execution of that case — i.e. exactly the
"first execution" case. The compiled code accessed an array offset on null.

## The Fix (HOW)

Two one-line template changes — minimal, behavior-preserving:

```diff
# gui/templates/dashio/execute/execSetResults.tpl:141
-              {if $round_enabled}Nifty('div.exec_additional_info');{/if}
+              {if $gui->round_enabled}Nifty('div.exec_additional_info');{/if}

# gui/templates/dashio/execute/include/exec_show_tc_exec.inc.tpl:186
-    {if $gui->other_execs.$tcversion_id}
+    {if $gui->other_execs && $gui->other_execs.$tcversion_id}
```

Why this way:

- `$gui->round_enabled` is always defined (`initializeGui()` sets it to 1),
  so reading it through `$gui` can never warn, and `Nifty(...)` still fires.
- The short-circuit `&&` guard keeps the original truthiness semantics: when
  `other_execs` is null the whole condition is false without ever touching the
  array offset — identical rendering to before, minus the warning. All nested
  uses (`{foreach … from=$gui->other_execs.$tcversion_id}` at line 244) sit
  inside this `{if}` block, so they are covered by the same guard.

Alternatives rejected: assigning a top-level `$round_enabled` from PHP or the
`{$round_enabled=1}` tpl workaround (keeps two sources of truth); initializing
`other_execs` to `array()` in PHP (touches controller state used by
`is_null()` checks elsewhere).

## Verification

Reproduced pre-fix (events 23–25 in a clean DB), then verified post-fix:

| Check | Result |
|---|---|
| Direct-URL load of execution form | trio gone |
| Real UI flow (navigator → tree click, form_token flow) | zero new Error/Warning events |
| First-execution render (`other_execs` null) | "Not Tested Yet" block renders, no warning |
| Save via status icon (`fastExecf_11`) | execution saved (`audit_exec_saved`), history table renders through the patched branch |
| "Show complete execution history" toggle | re-renders clean |
| Move to Next Test Case | legacy move-without-save preserved |

Regression suite: `tmp/TLU_Test_Cases.md` **Suite 61** — 7/7 PASS.

![Clean execution form after fix](issue-614-exec-form-clean.png)

## Bugs discovered during verification (filed separately)

- #620 — `Undefined property: stdClass::$refreshTree` at execSetResults.php:2338
  when the form is reached via direct URL without prior navigator load.
- #621 — `file_upload_step_exec_ok` / `file_upload_step_exec_ko` not localized
  (en_GB) on step-execution save.

## Files Changed

- `gui/templates/dashio/execute/execSetResults.tpl` — read `$gui->round_enabled`
- `gui/templates/dashio/execute/include/exec_show_tc_exec.inc.tpl` — null guard
- `tmp/fixtures_614.php` — idempotent CLI fixtures used for reproduction
