# Issue 419 — Execution Navigator EXDS(,N) JS Syntax Error Fix

## Issue #419

**Test Case Execution → Execute Tests**: the left-hand navigator rendered an
empty tree — no suites, no cases, nothing executable. DevTools console showed
`Uncaught SyntaxError: Unexpected token ','` followed by
`Uncaught ReferenceError: treeCfg is not defined`.


## Root Cause

`gui/templates/dashio/execute/execNavigator.tpl:57` emits the dashboard
bootstrap call:

```smarty
EXDS({$gui->tproject_id},{$control->args->setting_testplan});
```

but `initializeGui()` in `lib/execute/execNavigator.php` never assigned
`$gui->tproject_id`. Smarty renders an unset property as an empty string, so
the browser received:

```js
EXDS(,18);
```

That is a JavaScript **syntax error**, and because the call sits in the same
inline `<script>` block that defines `treeCfg` and registers the
`Ext.onReady()` handler which builds the Ext JS tree, parsing of the entire
block aborted. Result: `#tree_div` was created but never populated.

`EXDS(tproject_id, tplan_id)` grew its first parameter in
`gui/javascript/testlink_library.js` (`EXDS(tproject_id,tplan_id)` navigates to
`lib/execute/execDashboard.php?tproject_id=...&tplan_id=...`) while the PHP
side was never updated to supply it — a signature drift in the same family as
the historical `ETS()`/`ET()` drift.

## The Fix (HOW)

The minimal PHP-side fix assigns the missing GUI property from the filter
control before rendering:

```php
// lib/execute/execNavigator.php — initializeGui()
// Needed by the template to emit EXDS(tproject_id,tplan_id).
// Without it the call is rendered as EXDS(,NN) => JS syntax error that
// aborts the whole inline script, leaving the execution tree unbuilt.
$gui->tproject_id = intval($control->args->testproject_id);
```

Why this method:

- The template already expects `$gui->tproject_id`; supplying it keeps the
  Smarty/JS contract intact without touching the theme.
- Alternatives rejected: making `EXDS()` tolerate empty args (would silently
  navigate to the wrong dashboard), or moving the bootstrap call into a
  separate script block (masks the real defect, more churn).

Landed on the default branch in commit `857555dad` ("Fix test execution and
reporting flow", 2026-08-17).

## Verification (2026-08-23)

Fixtures recreated fresh (`tmp/fixtures_419.php`): project *EXDS Demo Project*
(prefix EXD), suites *EXDS Top Suite* / *EXDS Child Suite*, cases
EXD-1..3 linked to plan *EXDS Plan*, build *EXDS Build 1*.

| Check | Result |
|-------|--------|
| Inline script emits `EXDS(6,18);` (no empty arg) | PASS |
| Console free of SyntaxError / `treeCfg is not defined`; `typeof treeCfg === 'object'` | PASS |
| `#tree_div` populated (1941 chars of tree markup) | PASS |
| Full flow index.php → aside Execute Tests renders navigator + dashboard frames | PASS |
| Expanded tree lists root, both suites and all 3 cases | PASS |
| Clicking case fires `ST(9,10)` and loads `execSetResults.php` form in workframe | PASS |
| Event Viewer: no new Error/Warning attributable to the navigator bootstrap | PASS |

Full suite: `tmp/TLU_Test_Cases.md`, Suite 59 (7/7 PASS).

## Related findings (filed separately)

- #613 — chosen.jquery.js loaded before jQuery in `frmInner.tpl`/`workbench.tpl`
  (console ReferenceError on every workarea load).
- #614 — E_WARNING trio when rendering an execution form
  (`round_enabled` undefined in execSetResults.tpl:141 + two null-access warnings).
