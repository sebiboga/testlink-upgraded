# Bugfix — Issue #632: execTimelineStats.tpl E_WARNING "Undefined array key tit" + value-on-null

**Date:** 2026-08-27 · **Branch:** `fix/issue-632` · **Commit:** `84cc18206`

## Summary

The "Execution By Date/Time Statistics" screen rendered correctly but wrote two `E_WARNING` entries to the events table on every view of a fresh dataset. Both warnings originated from a single line in the Smarty template that referenced an undefined variable `$tit`.

## Symptom

- `lib/results/execTimelineStats.php?tplan_id=9&format=0` renders the report fine (HTTP 200) but logs two warnings per view:
  - `E_WARNING Undefined array key "tit"` (compiled template line 116)
  - `E_WARNING Attempt to read property "value" on null` (same line)
- Event Viewer shows these as new Warning events (log_level=2).

## Root Cause

In `gui/templates/dashio/results/execTimelineStats.tpl` (and the identical `tl-classic` variant), line 80:

```
{include file="results/show_table_qty_datetime.inc.tpl"
      args_title=$tit
```

The Smarty variable `$tit` was referenced but **never assigned** — neither in the template nor in the controller `lib/results/execTimelineStats.php`. When compiled, this becomes `$_smarty_tpl->tpl_vars['tit']->value`:
1. `tpl_vars['tit']` is an undefined array key → `E_WARNING Undefined array key "tit"`
2. Reading `->value` on the resulting null → `E_WARNING Attempt to read property "value" on null`

**Root cause chain:**
1. Template declares `args_title=$tit` but never does `{$tit = ...}`.
2. Controller `execTimelineStats.php` only assigns `$gui->...` properties; never a `tit` template var.
3. The shared include `show_table_qty_datetime.inc.tpl` defaults `$args_title` to `""`, but the warning fires during argument assembly before the default applies.

**Why it's a genuine upstream bug:** The sibling templates (`baselinel1l2.tpl`, `resultsByTSuite.tpl`, `resultsGeneral.tpl`) all assign `$tit` first — e.g. `{$tit = $labels.title_res_by_build_on_plat}` — establishing the intended pattern. `execTimelineStats.tpl` is the one that forgot, so `$tit` was always undefined.

**Blast radius:** Only this screen, in both `dashio` and `tl-classic` template variants, rendered via `displayReport()`.

## Fix

Added one line assigning the already-loaded report label before the include, in both template variants:

```
{if isset($gui->statistics->exec) }
  {$tit = $labels.execTimelineStats_report}
  {include file="results/show_table_qty_datetime.inc.tpl" ...}
```

**Approach:** Minimal, mirrors the established `{$tit = $labels.title_res_by_*}` pattern in sibling templates. Reuses the existing `execTimelineStats_report` label already present in the template's `lang_get` list, so **no new i18n key** was needed in any locale bundle. Side benefit: the results table now renders a meaningful `<h2>` heading (was silently empty).

## Files Changed

- `gui/templates/dashio/results/execTimelineStats.tpl` — +1 (`{$tit = $labels.execTimelineStats_report}`)
- `gui/templates/tl-classic/results/execTimelineStats.tpl` — +1 (same assignment)

## Verification

- Cleared compiled template cache and flushed `events` where `log_level=2`.
- Re-ran `lib/results/execTimelineStats.php?tplan_id=9&format=0`:
  - HTTP 200; table renders (Qty / Date / Number of testers; data row `2 / 2026-08-27 / 1`).
  - `<h2>Execution By Date/Time Statistics</h2>` heading present.
  - `SELECT * FROM events WHERE log_level=2` → **0 rows** (previously 2 warnings).
  - Browser console: no errors.

## Regression Matrix

| Case | Result |
|------|--------|
| Fresh dataset, single date (fixtures_424) | ✅ No warnings, heading shown |
| Both dashio & tl-classic variants fixed | ✅ Diff shows +1 in each |
| Event Viewer | ✅ No new Error/Warning entries |
