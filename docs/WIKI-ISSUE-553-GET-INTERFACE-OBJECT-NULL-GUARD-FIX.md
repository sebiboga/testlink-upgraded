# tlIssueTracker::getInterfaceObject() Null Guard Fix

## Issue #553

Fixed: with a test project where `issue_tracker_enabled=1` but **no issue
tracker is linked**, every code path reaching
`tlIssueTracker::getInterfaceObject()` logged a PHP warning into the Event
Viewer — e.g. single test case printing
(`lib/testcases/tcPrint.php` → `print.inc.php`
`initStaticRenderTestCaseForPrinting()`):

```
E_WARNING
Trying to access array offset on null - in .../lib/functions/tlIssueTracker.class.php - Line 673
```

The method still returned NULL afterwards (so pages kept working), but each
occurrence polluted the `events` table.

## Root Cause

* `getInterfaceObject()` starts with
  `$issueT = $this->getLinkedTo($tprojectID);`.
* When the project has no tracker linked (no row in
  `testproject_issuetracker`), `getLinkedTo()` runs a JOIN query that matches
  zero rows and its recordset path returns **null**.
* Line 673 then did an unguarded dereference:
  `$name = $issueT['issuetracker_name'];` → under PHP 8.x reading an array
  offset off `null` raises *E_WARNING: Trying to access array offset on null*.
* The `!is_null($issueT)` guard existed **only further down** (~line 685),
  after the array access had already happened.

## Fix Approach — guard before first use

Minimal change at the top of `getInterfaceObject()`
(`lib/functions/tlIssueTracker.class.php`):

```php
$issueT = $this->getLinkedTo($tprojectID);
// guard: project may have issue_tracker_enabled=1 but NO tracker linked,
// then getLinkedTo() returns null and PHP8 warns on array offset access
$name = !is_null($issueT) ? $issueT['issuetracker_name'] : '';
```

Why this way:

* **Behavior is identical to before**: pre-fix the code warned, set `$name`
  to null and eventually returned `$_SESSION['its'][$name] = null` via the
  existing else-branch; post-fix it silently sets `$name = ''` and returns the
  same null interface. No caller can observe any difference except the absence
  of the warning.
* **One guard covers all callers**: all call sites share this single method —
  `print.inc.php`, `execSetResults.php`, `resultsBugs.php`,
  `resultsByStatus.php`, `execHistory.php`, `bugAdd.php`,
  `testcaseCommands.class.php`, `mainPage.php`,
  `api/execute/index.php`, … — fixing it here protects every screen.
* **Alternatives rejected**: moving the `$name` assignment below the existing
  null check would leave `$name` undefined on the unlinked path (`isset()` /
  session keying below uses it), and restructuring into an early return would
  be a larger diff for no functional gain.

## Files Changed

| File | Change |
|------|--------|
| `lib/functions/tlIssueTracker.class.php` | `getInterfaceObject()`: compute `$name` with a null-safe ternary instead of dereferencing `$issueT` directly |

## Verification

Reproduced through the real browser flow against a fresh DB:

1. Fixture: project "IT553 Project" (id 1) created via UI;
   `UPDATE testprojects SET issue_tracker_enabled=1`; one test case created
   via UI; no tracker linked.
2. Pre-fix: open `lib/testcases/tcPrint.php?testcase_id=2&tproject_id=1` →
   E_WARNING row appended to `events` (log_level=2, "Line 673").
3. Post-fix same flow → full single-TC print view renders ("Test Case
   IT553-1: TC for issue 553 [Version : 1]"), **zero** new event rows.
4. Positive path intact: link a tracker (type 15 redmine-rest) via SQL,
   repeat print → interface object instantiated, page renders, zero events.
5. Negative path restored (link removed): repeat print → still warning-free.
6. Event Viewer after all flows: 0 Error/Warning entries.

Regression suite 39 recorded in `tmp/TLU_Test_Cases.md` (Suite ID 46): 6/6 PASS.
