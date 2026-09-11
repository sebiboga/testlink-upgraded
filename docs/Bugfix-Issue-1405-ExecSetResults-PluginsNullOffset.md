# Bugfix — Issue #1405: legacy execSetResults E_WARNING 'Trying to access array offset on null' on no-valid-build path

## Problem

Rendering the **legacy execution-set-results popup**
(`lib/execute/execSetResults.php` + `gui/templates/dashio/execute/execSetResults.tpl`)
on the **closed-build / no-valid-build path** — either with `build_id=0` (missing
session cache + `setting_build` parameter ignored by the controller) or with a
real closed build (`is_open=0`) — logs an **`E_WARNING "Trying to access array
offset on null"` to the Event Viewer** on every page render:

```
E_WARNING
Trying to access array offset on null - in .../gui/templates_c/.../file.execSetResults.tpl.php - Line 381
```

Event `log_level=2`, source derived from the GUI context. The page itself renders
correctly ("Build is closed — Test cases can not be executed"), but the spurious
warning pollutes the Event Viewer used for CI integration checks.

Reproduced 2026-09-11, PHP 8.x, MariaDB 127.0.0.1:3306, fresh-import schema
(`tmp/fixtures_esr2.php` → proj=1, plan=15, builds open=1 / closed=2):
`build_id=0` via `setting_build=1` param, plus `build_id=2` (closed) — one new
`E_WARNING` event each time.

The **modernized screen** (`execSetResults.html` + BFF) shows an error toast and
avoids this code path entirely — the warning is **legacy-only**.

## Root Cause

Chain (each hop backed by `file:line`):

1. `lib/execute/execSetResults.php:1452` — `initializeGui()` seeds
   `$gui->plugins = null;`.
2. `:122-124` — the plugin-assignment block
   (`$gui->plugins['EVENT_TESTRUN_DISPLAY'] = event_signal(...)`) only executes
   inside `if(!is_null($linked_tcversions))` (`:99`).
3. `:2065-2111` — `getLinkedItems()` constructs an execution context with
   `build_id=0` (because `getSettingsAndFilters()` (`:2310-2311`) reads
   `$_REQUEST['build_id']` — NOT `$_REQUEST['setting_build']` — as fallback from
   the missing session cache, yielding `intval(null)=0`). On this path
   `getLatestExecSingleContext()` returns null → `getLinkedItems()` returns null
   → `:99` is false → `:122-124` skipped → `$gui->plugins` stays **null**.
4. `gui/templates/dashio/execute/execSetResults.tpl:290` — the unguarded branch
   `{if $gui->plugins.EVENT_TESTRUN_DISPLAY}` compiles to
   `gui/templates_c/.../file.execSetResults.tpl.php:381`:
   `<?php if ($_smarty_tpl->tpl_vars['gui']->value->plugins['EVENT_TESTRUN_DISPLAY']) {?>`
   — array offset on `null` → PHP 8 E_WARNING.
5. Identical trigger with a **real closed build** (`build_id=2`, `is_open=0`):
   `initializeGui()` sets `$gui->build_is_open=0` (`:1552`), TPL renders the
   closed-build warning message (`:200-206`), and `$gui->plugins` remains null
   for the same reason.

**Why it breaks now:** PHP 8 changed null-array-offset semantics from silent
false to `E_WARNING`. Not a modernization regression; the classic `tl-classic`
theme has the same pattern at `tl-classic/execute/execSetResults.tpl:466`.

**Blast radius:** grep `->plugins` in `execSetResults.php` + the TPL → 3 hits.
Only `execSetResults.tpl:290,292` are the reported sink. The dashio `aside.tpl:310`
uses `$gui->plugins|@count` (always array from controllers that render it). No
other file is affected.

## Fix

Commits on branch `fix/issue-1405-execsetresults-warning`:

- `11150bbd8` — initial `{if isset($gui->plugins.EVENT_TESTRUN_DISPLAY)}` guard
- `3e5fc1210` — aligned to the established house pattern from
  `tl-classic/mainPageLeft.tpl:63,66` and `mainPageRight.tpl:53,100`:
  `{if isset($gui->plugins.EVENT_TESTRUN_DISPLAY) && $gui->plugins.EVENT_TESTRUN_DISPLAY}`

Final one-line change at `gui/templates/dashio/execute/execSetResults.tpl:290`:

```smarty
  {if isset($gui->plugins.EVENT_TESTRUN_DISPLAY) && $gui->plugins.EVENT_TESTRUN_DISPLAY}
    <div id="plugin_display">
      {foreach from=$gui->plugins.EVENT_TESTRUN_DISPLAY item=testrun_item}
        {$testrun_item}
        <br />
      {/foreach}
    </div>
  {/if}
```

`isset()` on `null['EVENT_TESTRUN_DISPLAY']` returns `false` **without** any
warning (PHP null-offset semantic for `isset()` is silent, unlike direct access).
The second operand (`&& value`) prevents an empty `#plugin_display` div when a
plugin is registered but yields no output (matching the established mainPage
guard). The nested `{foreach}` at `:292` is inside the `{if}` and inherits the
guard.

**Why this method:** `isset()` is the established Smarty guard pattern already in
use at `mainPageLeft.tpl:63/66` and `mainPageRight.tpl:53/100` for the same
`$gui->plugins.EVENT_*` property. It handles both null-offset and undefined-key
warning classes in a single expression, with no runtime cost.

**Rejected alternatives:**
(a) Initializing `$gui->plugins = array()` at `execSetResults.php:1452` alone
    still emits `E_WARNING "Undefined array key EVENT_TESTRUN_DISPLAY"` (PHP 8
    undefined-key semantics) and would spill the blast radius onto the unguarded
    `tl-classic/execute/execSetResults.tpl:466` and `tl-classic/navBar.tpl:84`.
(b) Navigating the `setting_build` → `build_id` mapping to always set build_id:
    that is intended behavior for the exec dashboard session cache, not a
    warning bug; changing it would alter execution semantics far beyond an
    E_WARNING fix.

## Files Changed

- `gui/templates/dashio/execute/execSetResults.tpl` — line 290: add `isset()` &&
  truthiness guard on `$gui->plugins.EVENT_TESTRUN_DISPLAY` (+1/−1).
- `tmp/TLU_Test_Cases.md` — regression suite "Regression — Issue #1405", 6/6 PASS.

## Verification

All checks on branch `fix/issue-1405-execsetresults-warning`, PHP 8.x, MariaDB,
fresh-import schema:

- Pre-fix control (reverted): `…?level=testcase&id=3&version_id=4&tplan_id=15&setting_build=1&setting_platform=0`
  → HTTP 200 + **1** new `E_WARNING` event (event id 5).
- Post-fix R1 (build_id=0, same URL): HTTP 200 ("Build is closed" message), **0**
  new events.
- Post-fix R2 (`build_id=2`, closed build): HTTP 200 ("Build is closed"), **0**
  new events.
- Post-fix R3 (`build_id=1`, open build): full TC exec popup renders (steps,
  custom field, requirement, prior executions), **0** new events.
- Post-fix R4 (POST round-trip): on open build, set step 1 status → "Save Steps
  Work In Progress Execution" → re-renders with saved status, **0** new events.
- Post-fix R5 (hygiene): `events` table has **0** rows matching
  `%execSetResults.tpl.php%` at `log_level=2` after all fixed-path renders.
- Browser: no JS console errors on any render.

Regression suite: `tmp/TLU_Test_Cases.md` — "Regression — Issue #1405", 6/6 PASS.

## Related Findings

`tl-classic/execute/execSetResults.tpl:466` has the identical unguarded
`{if $gui->plugins.EVENT_TESTRUN_DISPLAY}` pattern. Same E_WARNING class when
that legacy theme is active. Tracked for the `tl-classic` theme cleanup, not
changed here (minimal-scope rule: dashio is the active theme).

Address: `Fixes #1405` (verified + pushed on `fix/issue-1405-execsetresults-warning`).
