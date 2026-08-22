# resultsByTSuite Exec-Span Null Map + $fieldList Fix

## Issue #587

Fixed: opening `lib/results/resultsByTSuite.php?tplan_id=<plan-with-linked-cases-but-zero-executions>&format=0`
rendered HTTP 200 but logged **4 fresh E_WARNING events on every call**:

```
E_WARNING Undefined variable $fieldList - tlTestPlanMetrics.class.php - Line 3657
E_WARNING Trying to access array offset on null - resultsByTSuite.php - Line 72
E_WARNING Trying to access array offset on null - templates_c/…show_table_with_exec_span.inc.tpl.php - Line 39
E_WARNING Trying to access array offset on null - templates_c/…show_table_with_exec_span.inc.tpl.php - Line 42
```

Warning 1 fires even when executions exist; warnings 2–4 need a plan with
zero rows in `executions`.

## Root Cause

Two-part pre-existing legacy defect in the "First & Latest Execution" span
path of the Metrics by L1/L2 Test Suite report:

1. **`getExecTimeSpan()`** (`lib/functions/tlTestPlanMetrics.class.php:3657`)
   used `$fieldList .= implode(',', $context);` without ever initialising
   `$fieldList`. PHP tolerates `.=` on an undefined variable (treats it as
   `''`) but logs an E_WARNING **every single call**.

2. **No-execution plans** make the aggregate
   `SELECT MIN/MAX(execution_ts) … GROUP BY …` return no rows, so
   `fetchRowsIntoMap()` / `fetchRowsIntoMap2l()` return **NULL** and the
   method returns null. The controller
   (`lib/results/resultsByTSuite.php:70-72`) then read
   `$span[$args->tplan_id]` unguarded → "array offset on null", stored
   `[0] => null` into `$gui->spanByPlatform`, and the Dashio partial
   `show_table_with_exec_span.inc.tpl` passed its
   `{if null != $gui->spanByPlatform}` check (a non-empty array holding one
   null is not null) and dereferenced `['begin']` / `['end']` of the null
   entry → the two template warnings.

## The Fix — approach

Three minimal guards, each at the exact defect site:

```php
// tlTestPlanMetrics.class.php:3657
$fieldList = implode(',', $context);        // was .=

// resultsByTSuite.php (both branches)
$gui->spanByPlatform = isset($span[$args->tplan_id]) ? $span[$args->tplan_id] : null;
```

```smarty
{* show_table_with_exec_span.inc.tpl (dashio + tl-classic) *}
{if null != $gui->spanByPlatform && isset($gui->spanByPlatform[$platId])}
```

Why this shape:

* `isset()` on a possibly-null variable/array is warning-free under PHP 8 and
  covers all three null shapes: whole map null, platform key missing (a
  platform with no executions inside a multi-platform plan), key present but
  null (the `[0] => null` no-platform case).
* Guarding the caller keeps `getExecTimeSpan()`'s existing contract (map or
  null) intact for any future caller; the template guard makes rendering
  skip only the two date lines when a given platform has no span data.
* Alternatives rejected: casting the fetch result to `(array)` inside
  `getExecTimeSpan()` alone would still warn ("undefined array key") at the
  controller; guarding only the template would leave the controller warning.

The tl-classic copy of the partial got the identical `isset()` addition so
both themes stay consistent.

## Verification

Fixture: project 900001 / plan 900002 / build 900003 / nested suites
TS TOP > TS CHILD / TC-1 v1 linked, zero executions initially.

| Step | Result |
|------|--------|
| Zero-executions load | HTTP 200, **0 new events** (was 4) |
| One execution inserted → reload | First/Latest Execution dates render correctly, **0 new events** |
| Multi-platform plan (P-Busy with execution, P-Idle without) | P-Busy section renders span dates, no warnings for the idle platform |
| Browser pass (headless Chrome, admin/admin) + Event Viewer | only normal AUDIT login event, no Error/Warning |
| Code review subagent over full diff | PASS, no required findings |

Regression suite: `Regression — Issue #587` in `tmp/TLU_Test_Cases.md`.

## Follow-up bugs discovered during verification

Filed separately (kept out of this fix to stay minimal):

* #588 dashio copy of `show_table_with_exec_span.inc.tpl` lacks the
  `property_exists($gui,'spanByPlatform')` guard the tl-classic copy has →
  one "Undefined property" E_WARNING per baselinel1l2 page render.
* #589 `saveForBaseline` on a zero-execution plan still dereferences the null
  span and INSERTs empty begin/end timestamps into `baseline_l1l2_context`.

## Files changed

* `lib/functions/tlTestPlanMetrics.class.php` — `$fieldList` initialisation
  in `getExecTimeSpan()` (~3657)
* `lib/results/resultsByTSuite.php` — isset() guards for the span map reads (~69-75)
* `gui/templates/dashio/results/show_table_with_exec_span.inc.tpl` — per-platform isset() guard (:14)
* `gui/templates/tl-classic/results/show_table_with_exec_span.inc.tpl` — same guard (:16)

