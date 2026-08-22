# resultsByTSuite saveForBaseline Zero-Execution Guard Fix

## Issue #589

Fixed: clicking *Save for baseline* (or deep-linking
`lib/results/resultsByTSuite.php?tplan_id=<plan>&format=0&doAction=saveForBaseline`)
on a test plan that has linked level-2 suites but **zero executions** crashed
the page with a raw **DB Access Error** backtrace and polluted the Event
Viewer:

```
E_WARNING Trying to access array offset on null - resultsByTSuite.php   (x3)
DATABASE ERROR ON exec_query() - 1064 You have an error in your SQL syntax...
```

This is the follow-up flagged by #587 (which deliberately fixed only the
render path to stay minimal).

## Root Cause

`saveForBaseline` consumed `$gui->spanByPlatform` without checking that span
data exists. On a plan with no execution rows, `getExecTimeSpan()` returns
null (aggregate query yields no rows), so after the #587 guard
`$gui->spanByPlatform[$platID]` is `null`. The save block then did:

```php
$span = $gui->spanByPlatform[$platID];          // null -> E_WARNING
$sql = "INSERT ... VALUES({$span['testplan_id']}, {$platID}, '{$span['begin']}', '{$span['end']}')";
```

Each null-offset read raised *"Trying to access array offset on null"*, and
the three values interpolated as **empty strings**, producing invalid SQL
(`VALUES(,0,'','')`) → SQL 1064 → DB Access Error page. On setups where the
INSERT could run, empty begin/end timestamps would be written into
`baseline_l1l2_context`, corrupting baseline data.

## The Fix — approach

One guard at each level of the save block (`lib/results/resultsByTSuite.php`):

```php
if ($args->doAction == 'saveForBaseline') {
  $gui->baselineSaved = false;
  if( isset($gui->dataByPlatform) && !is_null($gui->dataByPlatform) ) {  // report data absent?
    $savedSomething = false;
    foreach ($gui->dataByPlatform->testsuites as $platID => $elem) {
      // no execution records for this platform => nothing to baseline
      if( !isset($gui->spanByPlatform[$platID]) ||
          is_null($gui->spanByPlatform[$platID]) ) {
        continue;
      }
      ... // unchanged INSERTs ...
      $savedSomething = true;
    }
    if( $savedSomething ) {
      $gui->baselineSaved = true;                 // success feedback only when real data saved
    } else {
      $gui->do_report['status_ok'] = 0;
      $gui->do_report['msg'] = lang_get('baseline_save_no_executions');
    }
  }
}
```

Why this shape:

* `isset()` / `is_null()` are warning-free under PHP 8 on possibly-null array
  offsets — same technique already used by #587 on the render path.
* The per-platform check keeps partial saves working: in a multi-platform
  plan where only some platforms have executions, executed platforms are
  baselined, idle ones are skipped.
* `$gui->baselineSaved = true` now fires only when something was actually
  saved, so the "Baseline saved" confirmation can no longer lie.
* When nothing was saved the screen reuses its existing error channel
  (`$gui->do_report`), which both themes (dashio / tl-classic) already render.
* Alternatives rejected: aborting before the loop on
  `is_null($gui->spanByPlatform)` alone would miss the no-platform
  `[0] => null` shape and mixed-platform plans.

i18n: new key `$TLS_baseline_save_no_executions` added to
`locale/en_GB/strings.txt` and `locale/en_US/strings.txt`
("Baseline NOT saved: the test plan has no execution records yet. Execute at
least one test case, then save the baseline again."). All other locales fall
back to en_GB via `lang_get()`. No hardcoded strings in code.

## Verification

Fixture: project 1 / plan 10 / build 300 / nested suites TopSuite > ChildSuite /
TC-589 v1 linked, zero executions.

| Case | Result |
|------|--------|
| Zero-execution save | HTTP 200 report page, localized "Baseline NOT saved…" message, **0 rows** written into baseline tables |
| Event Viewer afterwards | **0 new ERROR/WARNING events** (was 3×E_WARNING + SQL 1064) |
| One execution inserted → save again | Context row with real begin/end timestamps + n/p/f/b detail rows — happy path intact |
| Mixed platforms (PlatA executed, PlatB not) | Baseline written only for PlatA; PlatB skipped silently; success path |
| Executions exist but not on any planned platform | Message shown, nothing written, no events |

Regression suite: `Regression — Issue #589` in `tmp/TLU_Test_Cases.md`.

## Files changed

* `lib/results/resultsByTSuite.php` — guards around the whole saveForBaseline block (~90-146)
* `locale/en_GB/strings.txt` — `$TLS_baseline_save_no_executions`
* `locale/en_US/strings.txt` — same key

## Screenshots (see wiki)


