# Bugfix — Issue #979: tcView E_WARNING `foreach()` on null keyword set (testcase.class.php:9347)

## Problem

Rendering a test case that has **no keywords** — neither own nor inherited
from an ancestor testsuite — fired PHP warnings that landed in the Event
Viewer / `events` table:

```
E_WARNING foreach() argument must be of type array|object, null given
  - in lib/functions/testcase.class.php - Line 9347
```

Plus, for **every** keywordless test case, a set of `str_replace()` parameter
deprecations at `testcase.class.php:9358` (`Passing null to parameter #1/#2`).

The symptoms only appear on PHP 8.x (E_WARNING / E_DEPRECATED). Keywordless
TCs are the common case on a fresh database.

## Root Cause

`renderSpecialTSuiteKeywords()` (`lib/functions/testcase.class.php:9313`)
builds a static cache:

```php
$skwSet[$tcase_id] = $this->getPathLayered($tcase_id,$optSKW);
```

`getPathLayered()` (`testcase.class.php:5578`) initialises `$xtree = null`
and only fills it for nodes whose `node_table == 'testsuites'` (line 5602).
Two failure modes:

1. **Test case attached directly under the project root** — `get_path()`
   returns `[testproject, testcase]` with no testsuite node, so `$xtree`
   stays `null` and `getPathLayered()` returns `null`. `$skwSet` becomes the
   array `[tcase_id => null]`, which is **not** `null`, so the guard
   `if( is_null($skwSet) ) return;` (old line 9330) never triggers. The
   double `foreach` at line 9346-9347 then iterates the **null element** →
   `E_WARNING foreach() ... null given` at **9347**. This is the primary
   reported symptom.
2. **No `@#` special keyword anywhere in the chain** — the cache holds
   testsuite entries whose `data_management` is `null`, so nothing ever gets
   appended to `$searchSet`/`$replaceSet`, which stay `null` →
   `str_replace(null,null,...)` raises deprecations at **9358** for **all**
   keywordless TCs (including suite children).

## Fix

**File:** `lib/functions/testcase.class.php` — 3 changes in
`renderSpecialTSuiteKeywords()`:

1. Default a `null` `getPathLayered()` result to an empty array when filling
   the cache (fixes the `foreach()` E_WARNING):

   ```php
   $skwSet[$tcase_id] = $this->getPathLayered($tcase_id,$optSKW);
   if( is_null($skwSet[$tcase_id]) ) {
     $skwSet[$tcase_id] = array();
   }
   ```

2. Harden the early-return guard from `is_null($skwSet)` to
   `empty($skwSet)` (the array is never `null` once an element is stored).

3. Initialise the search/replace sets to `array()` instead of `null` (fixes
   the `str_replace()` deprecations for keywordless TCs):

   ```php
   $searchSet = array();
   $replaceSet = array();
   ```

Behaviour is unchanged when special keywords DO exist — `str_replace()` on an
empty array of replacements simply leaves `summary`/`preconditions` untouched.
No i18n impact, no user-facing strings changed.

## Files Changed

| File | Change |
|---|---|
| `lib/functions/testcase.class.php` | `renderSpecialTSuiteKeywords()` — null→`array()` cache default, `empty()` guard, `array()` search/replace init (7+ / 4-) |
| `docs/screenshots/issue-979-tcview-no-warning-postfix.png` | post-fix TC view render (new) |
| `docs/screenshots/issue-979-event-viewer-postfix.png` | Event Viewer after post-fix renders (new) |

Commit `d4bb3148` on branch `fix/issue-979`.

## Testing / Evidence

- **Primary symptom (before):** harness calling
  `renderSpecialTSuiteKeywords()` for a root-attached keywordless TC captured
  `testcase.class.php:9347 foreach() argument must be of type array|object,
  null given` plus 4 `str_replace()` deprecations; the browser produced the
  same line-9347 event (event id 24, `10:02:00`).
- **Primary symptom (after):** same harness → **0 warnings** from
  `testcase.class.php`; browser re-render of `archiveData.php?edit=testcase&id=50`
  → clean TC view, **no** new `9347`/`9358` event in the Event Viewer.
- **Keywordless suite child (TC30):** 4 `str_replace()` deprecations → **0**.
- **Positive control:** keyword `@#DYN` on the ancestor suite → summary
  `my @#DYN summary` renders as `my DYN:Suite summary`, still 0 warnings →
  replacement logic intact.
- **Syntax gate:** `php -l` → `No syntax errors detected`.
- **Blast radius:** `renderSpecialTSuiteKeywords` is referenced only inside
  `testcase.class.php` (3 occurrences: 1 def + 2 calls at lines 2944 / 4324);
  neither signature nor output contract changed.
- **Code review subagent:** PASS (see review notes).
- **Event Viewer:** no new Error/Warning from this fix. Unrelated pre-existing
  template warnings (label keys, `testcase.class.php:7490`) remain tracked
  separately.

## Regression Test Case

Suite `Regression — Issue #979` (7/7 PASS) appended to `tmp/TLU_Test_Cases.md`:
root-attached keywordless TC harness, suite-child keywordless harness, positive
`@#` keyword control, browser render of the affected view, Event Viewer
accounting, `php -l` gate, and diff-scope check.