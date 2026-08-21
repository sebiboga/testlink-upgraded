# mainPage.php Switch-to-Planless-Project E_WARNING Fix

## Issue #548

Fixed: every load of `lib/general/mainPage.php` that switched/initialized the
session Test Project while the session still held a **stale Test Plan** (a plan
belonging to the *previous* test project) wrote **three E_WARNING entries** into
the Event Viewer whenever the newly selected project had no accessible test
plans.

## Symptom

Event Viewer gained three entries per switch (`log_level=2`):

```
E_WARNING Undefined array key 0            - lib/general/mainPage.php - Line 102
E_WARNING Trying to access array offset on null - lib/general/mainPage.php - Line 102
E_WARNING Undefined array key 0            - lib/general/mainPage.php - Line 105
```


## Repro steps

1. Fresh DB; create test project "Alpha" with one active test plan, test
   project "Beta" with none. Log in as admin.
2. Select Alpha (session `testplanID` = Plan A).
3. Request `lib/general/mainPage.php?tproject_id=<Beta>` — any project switch
   via the nav bar does exactly this.
4. Event Viewer → the three warnings above.

## Root Cause

The block in `mainPage.php` that re-validates the session test plan ran only
when `$testplanID > 0`, but never checked that `$arrPlans` was non-empty:

```php
$arrPlans = (array)$currentUser->getAccessibleTestPlans($db,$testprojectID);
if($testplanID > 0) {
    ...
    if( $found == 0 ) {
        $index = 0;
        $testplanID = $arrPlans[$index]['id'];   // line 102: empty array!
    }
    setSessionTestPlan($arrPlans[$index]);       // line 105: empty array!
    $arrPlans[$index]['selected']=1;
}
```

When switching projects, `initProject()` validates the *old* plan id against
the *new* project (`getAccessibleTestPlans($db,$tproject,$tplan)`), gets `null`
(the old plan is not in it), falls back to "any accessible plan of the new
project", also gets `null` (Beta has none) — and leaves the stale session
`testplanID` untouched. Back in `mainPage.php` the stale id is `> 0`, so the
block runs with `$arrPlans = []`:

* `$arrPlans[0]` → *Undefined array key 0*, evaluating to null,
* `null['id']` → *Trying to access array offset on null*,
* second `$arrPlans[0]` at line 105 → *Undefined array key 0*.

PHP 8.x logs all three as E_WARNING into the Event Viewer.

## Fix Approach — guard the empty case, clear the stale plan

Considered alternatives:

1. **Null-coalesce / isset() around each read** (the issue's minimal
   suggestion). Silences the warnings but leaves the stale plan id flowing
   into `$gui->testplanID` while `$countPlans == 0` — an inconsistent state
   (page claims a selected plan that belongs to another project).
2. **Guard + explicitly drop the stale session plan** (chosen): when the
   fallback list is empty there is nothing selectable, so reset the local
   `$testplanID` to 0 and call `setSessionTestPlan(null)` — its documented
   else-branch unsets `$_SESSION['testplanID']` / `$_SESSION['testplanName']`,
   which is exactly what the buggy code was accidentally achieving anyway
   (it fed `null` into `setSessionTestPlan()`, just with warnings).

```php
if( $found == 0 ) {
    // update test plan id
    if(count($arrPlans) > 0) {
      $index = 0;
      $testplanID = $arrPlans[$index]['id'];
    }
    else {
      // Session still points at a Test Plan of ANOTHER Test Project while
      // the newly selected one has no accessible Test Plans. Nothing can be
      // selected here: drop the stale session plan instead of reading
      // offset 0 of an empty array (PHP 8.x turns this into E_WARNINGs,
      // see GitHub issue #548). setSessionTestPlan(null) clears the keys.
      $testplanID = 0;
      setSessionTestPlan(null);
    }
}

if($testplanID > 0) {
    setSessionTestPlan($arrPlans[$index]);
    $arrPlans[$index]['selected']=1;
}
```

Behavior parity for the healthy paths is preserved exactly: found-match keeps
the matched index; found-miss with a non-empty list still falls back to index
0; no plan in session skips the whole block as before.

### Files changed

* `lib/general/mainPage.php` — guarded the `found == 0` branch and made the
  session-write/selected-marking conditional on a resolved plan.

## Verification

| # | Check | Result |
|---|-------|--------|
| 1 | Pre-fix repro → events 13/14/15 (2× line 102, 1× line 105) | reproduced |
| 2 | Post-fix: same switch via direct URL and via nav-bar selector → zero new events | PASS |
| 3 | Post-fix: bare `mainPage.php` on Beta renders clean empty state (no dashboard), nothing logged | PASS |
| 4 | Regression: Beta → Alpha auto-selects Plan A again (found==0 non-empty path intact) | PASS |
| 5 | Regression: reload with explicit tproject/tplan (found==1 path intact); full round-trip ends with zero new Event Viewer entries | PASS |

