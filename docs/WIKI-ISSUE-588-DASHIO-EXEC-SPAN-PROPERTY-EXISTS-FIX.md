# dashio show_table_with_exec_span.inc.tpl property_exists Guard Fix

## Issue #588

Fixed: every render of `lib/results/baselinel1l2.php` (Dashio theme) with a saved
baseline logged **one E_WARNING per page load**:

```
E_WARNING Undefined property: stdClass::$spanByPlatform
  - templates_c/…show_table_with_exec_span.inc.tpl.php - Line 36
```

The page itself rendered fine — the warning was noise in the Event Viewer on
every single view of the "Baselines Level 1 & Level 2 Test Suites" report.

## Root Cause

Template divergence between the two theme copies of the shared partial
`show_table_with_exec_span.inc.tpl`. It is included by both `baselinel1l2.tpl`
and `resultsByTSuite.tpl`:

* `lib/results/resultsByTSuite.php` assigns `$gui->spanByPlatform` (lines 73/75)
  → property exists → reading it is fine.
* `lib/results/baselinel1l2.php` only builds `$gui->span`
  (per platform / per context index) and **never** `$gui->spanByPlatform`.
  Under PHP 8, `null != $gui->spanByPlatform` on a stdClass without that
  property raises "Undefined property".

The `tl-classic` copy already guarded the read:

```smarty
{if property_exists($gui,'spanByPlatform')
    && null != $gui->spanByPlatform && isset($gui->spanByPlatform[$platId])}
```

the `dashio` copy had lost the first clause:

```smarty
{if null != $gui->spanByPlatform && isset($gui->spanByPlatform[$platId])}
```

## The Fix — approach

Re-align the dashio partial's condition with the tl-classic guard — one
condition, two lines, no behavior change on pages where the property exists:

```smarty
{* gui/templates/dashio/results/show_table_with_exec_span.inc.tpl :14 *}
{if property_exists($gui,'spanByPlatform')
    && null != $gui->spanByPlatform && isset($gui->spanByPlatform[$platId])}
```

Why this shape:

* `property_exists()` short-circuits before the property read, so controllers
  that legitimately don't build a per-platform span map (baselinel1l2) no
  longer warn. This mirrors upstream TestLink's own tl-classic template.
* The existing `isset(...)` clause from #587 stays, so all three null shapes
  (whole map null / missing platform key / key holding null) remain covered.
* Alternatives rejected: initializing a dummy `$gui->spanByPlatform = null` in
  `baselinel1l2.php` would fix this page but leave the partial fragile for any
  future includer; dropping the span block entirely would lose the dates on
  resultsByTSuite pages.

## Verification

Fixture: project 900001 / plan 900002 / suites TS TOP > TS CHILD /
baseline_l1l2_context row (platform 0) + 3 detail rows; TC-1 v1 linked,
build B1, one execution for the positive path.

| Step | Result |
|------|--------|
| baselinel1l2 render after fix | metrics table identical; **0 new spanByPlatform warnings** (was 1/render) |
| resultsByTSuite render (property exists) | "First/Latest Execution on" dates still render; 0 new events |
| Guard parity check | dashio + tl-classic conditions now identical |

Regression suite: `Regression — Issue #588` in `tmp/TLU_Test_Cases.md`.

![baselinel1l2 after fix](images/issue-588-baselinel1l2-after-fix.png)

## Related

* Discovered during #588 verification and filed separately:
  #601 — `dashio/results/baselinel1l2.tpl` reads four more undefined
  `$gui` properties (`baselineSaved`, `actionSendMail`, `actionSpreadsheet`,
  `mailFeedBack`) because its controller never initializes them → 4 further
  E_WARNINGs per render.

## Files changed

* `gui/templates/dashio/results/show_table_with_exec_span.inc.tpl` —
  added `property_exists($gui,'spanByPlatform') &&` to the condition (:14-15)
