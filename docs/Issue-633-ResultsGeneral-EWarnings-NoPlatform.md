# Issue 633 — resultsGeneral E_WARNINGs on no-platform plans

Issue: https://github.com/sebiboga/testlink-upgraded/issues/633
Related: #424 (made the no-platform code path reachable), #630 (same page, sibling warnings)

## Symptom

Viewing **General Test Plan Metrics** for a test plan **without platforms**
rendered correctly (HTTP 200) but wrote **6 E_WARNING events per view** into the
`events` table:

- `Undefined variable $items2loop` at `lib/results/resultsGeneral.php:63`
- `foreach() argument must be of type array|object, null given` (same line)
- `Undefined property: stdClass::$tprojOpt` ×2 and
  `Attempt to read property "testPriorityEnabled" on null` ×2 from the compiled
  Dashio template (`gui/templates/dashio/results/resultsGeneral.tpl`, source
  lines 179 and 247)

## Approach

Root cause chain:

1. **Undefined `$items2loop`** — `$items2loop[] = 'platform'` is only appended
   inside `if( $gui->showPlatforms )` (`lib/results/resultsGeneral.php:48-52`),
   but `foreach($items2loop as $item)` at line 63 runs unconditionally. For a
   plan with no linked platforms, `initializeGui()` sets `showPlatforms=false`,
   so the variable never exists. Latent since before #424; reachable only once
   764d625e1 made the empty-platform branch actually execute.
2. **Property-name mismatch** — `initializeGui()` assigns
   `$gui->testprojectOptions->testPriorityEnabled`
   (`lib/results/resultsGeneral.php:247-248`), while the Dashio template reads
   `$gui->tprojOpt->testPriorityEnabled` (a convention copied from other screens
   whose controllers do set `tprojOpt`, e.g. `tcEdit.php`). The tl-classic twin
   template (`gui/templates/tl-classic/results/resultsGeneral.tpl:177,245`)
   reads `testprojectOptions` correctly.

Fix (minimal, 2 files):

- `lib/results/resultsGeneral.php`: initialize `$items2loop = array();` before
  the `showPlatforms` block.
- `gui/templates/dashio/results/resultsGeneral.tpl`: both occurrences of
  `$gui->tprojOpt->testPriorityEnabled` → `$gui->testprojectOptions->testPriorityEnabled`.

Alternatives rejected: adding a second `$gui->tprojOpt` property in PHP would
duplicate project options on `$gui` and diverge from this controller's own
naming; aligning the template to the controller is the smaller, consistent fix.
Both template consumers (`resultsGeneral.php` directly and the FORMAT_MAIL_HTML
render via `generateHtmlEmail()` at `lib/results/displayMgr.php:112`) feed the same `$gui`, so the rename is safe.

## Verification (4/4 PASS — see tmp/TLU_Test_Cases.md suite 65)

Fixtures: `php tmp/fixtures_424.php` → plan id 9 with no platforms;
`php tmp/fixtures_633_r2.php` → plan id 27 with platform PLAT-633 linked and
`testPriorityEnabled=1`.

- Pre-fix repro: one view of plan 9 produced events ids 5–10 (all log_level 2).
- Post-fix view of plan 9: HTTP 200, headings = General Test Plan Metrics /
  Overall Build Status / Results by Top Level Test Suite; priority sections
  hidden; **zero** new warning events.
- Platform plan 27: "Results by Platform" AND "Results by priority" both render,
  proving the renamed property still gates priorities correctly when enabled.
- XLS export (`format=3`) for both plans: HTTP 200, no new events.
- Event Viewer after all steps: audit entries only.


