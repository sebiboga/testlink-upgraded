# Print Document on-build Report Missing build_id Warning Fix

## Issue #575

Fixed: requesting a **Test Report on Build** document
(`printDocument.php?type=testreport_onbuild&level=testproject&...`)
**without** `build_id` produced the document but logged **3 E_WARNING
events per generation**, sourced to `lib/functions/print.inc.php`:

```
E_WARNING Undefined property: stdClass::$build_name - print.inc.php - Line 741
E_WARNING Undefined property: stdClass::$build_name - print.inc.php - Line 2317
E_WARNING Undefined property: stdClass::$build_notes - print.inc.php - Line 2319
```

## Symptom

1. Event Viewer spam on every on-build document generation that omits
   `build_id` (the three warnings above).
2. The generated first page showed `Build:` with an empty value —
   cosmetically correct for "no build selected", but reached via an
   undefined-property read.
3. Related hazard (same code path): with an **invalid but present**
   `build_id`, `get_builds(..., array('buildID' => $args->build_id))`
   returns no rows, so `$xx[$args->build_id]['name']` triggered
   array-offset-on-null style warnings too.

## Root Cause

In `lib/results/printDocument.php` the plan-based document branch only
assigned the two properties *inside* the positive-build branch:

```php
if($args->build_id > 0) {
  $xx = $tplan_mgr->get_builds($args->tplan_id,null,null,array('buildID' => $args->build_id));
  $doc_info->build_name = htmlspecialchars($xx[$args->build_id]['name']);
  $doc_info->build_notes = $xx[$args->build_id]['notes'];
}
```

The renderers in `print.inc.php` (`renderFirstPage()` line 741 and
`renderBuildItem()` lines 2317/2319) then read
`$doc_info->build_name` / `->build_notes` **unconditionally** whenever
`$doc_info->type == DOC_TEST_PLAN_EXECUTION_ON_BUILD`. Under PHP 8,
reading an undefined property of `stdClass` is an `E_WARNING`, and
TestLink's error handler logs it into the `events` table — one triplet
per generated document.

## Fix Approach

Minimal initialization + defensive guard in `printDocument.php`
(the single producer of these properties):

```php
$doc_info->build_name = '';
$doc_info->build_notes = '';
if($args->build_id > 0) {
  $xx = $tplan_mgr->get_builds($args->tplan_id,null,null,array('buildID' => $args->build_id));
  if( !is_null($xx) && isset($xx[$args->build_id]) ) {
    $doc_info->build_name = htmlspecialchars($xx[$args->build_id]['name']);
    $doc_info->build_notes = $xx[$args->build_id]['notes'];
  }
}
```

* Initializing both properties **before** the branch guarantees the
  unconditional reads in the renderers always see strings ("no build"
  → empty label, exactly as before visually).
* The `isset()` guard additionally covers the invalid-`build_id`
  variant mentioned in the issue report: unknown builds now degrade to
  an empty label instead of array-offset warnings. `get_builds()`
  returns `null` when no rows match, hence the explicit null check.

Alternatives considered: guarding each read inside `print.inc.php`
(three scattered edits in shared renderer functions, higher regression
surface) and rejecting requests without `build_id` outright (changes
documented behavior — legacy allowed generating the report without a
build). Both rejected; the producer-side fix keeps all rendering logic
untouched.

## Files Changed

* `lib/results/printDocument.php` — property init before the branch +
  `isset()` guard around the fetched build row.

## Verification

Regression suite in `tmp/TLU_Test_Cases.md` (5/5 PASS), events table
cleared before every step:

| Step | Result |
|------|--------|
| No `build_id` → doc renders, "Build:" empty, **0 warning events** | PASS |
| `build_id=10` → doc shows "Build: Build B1", 0 warning events | PASS |
| `build_id=9999` (invalid) → graceful empty label, 0 warning events | PASS |
| Regression `type=testplan` / `type=testreport` (shared case block) → 200, 0 warnings | PASS |
| `php -l printDocument.php` clean | PASS |

Pre-fix reproduction produced exactly the 3 E_WARNINGs from the issue
report (events table verified).
