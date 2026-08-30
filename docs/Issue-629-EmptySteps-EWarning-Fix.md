# Issue 629 — E_WARNING "Undefined array key 0" When Creating a Test Case With an EMPTY Steps Array

## Issue #629

Creating a test case with an **empty** steps array (`steps = []`, typical of
REST-API-style payloads and CLI/import fixtures) worked functionally but wrote
**one `E_WARNING` row per created case** into the Event Viewer
(`events` table, `log_level=2`, source GUI):

```
E_WARNING | Undefined array key 0
  - in lib/functions/testcase.class.php - Line 804
```

Reproduced on a fresh DB with `php tmp/repro_629.php`: two cases created with
`steps = []` → exactly one `level=2` event per case (ids 2 and 3 pre-fix).
TC creation succeeded and no step rows were written — the warning was pure log
noise.

## Root Cause

`testcase::createVersion()` — the private method behind
`testcase::create()` / `createFromObject()` — guards the step-writing block
with a null/type check but **no emptiness check**:

```php
// lib/functions/testcase.class.php:799 (pre-fix)
if ($result && ( !is_null($item->steps) && is_array($item->steps) ) ) {
  $steps2create = count($item->steps);
  $stepIsObject =  is_object($item->steps[0]);   // line 804 ← read before bounds check
```

An empty array `[]` passes `is_null()` + `is_array()`, so line 804 indexes
`$item->steps[0]`, which does not exist under PHP 8 → `E_WARNING: Undefined
array key 0`. `$steps2create` is 0, so the for-loop (line 805) never runs —
nothing is written either way. The guard was written in the PHP 5/7 era (a PHP 7
out-of-bounds read returned a silent `null`); the REST-API-style `steps=[]`
payload path is what surfaces it today.

Blast radius: the only unguarded `steps[0]` read in the codebase
(`git grep 'steps\['0'\]' lib` → 1 hit). Callers that can reach this path with
an empty array: REST API v1/v2/v3 (`tlRestApi.class.php` v2:1094,
`RestApi.class.php` v3:1439 set `$tcase->steps = []`; v1:679 / v3:1318 set
`null`, which was already safe), the modernized BFF `api/testcases/index.php`
(`$steps = []` at :327 :730 :899 :961), XMLRPC and the legacy tcNew controller
when a case is created without steps.

## Fix Approach

**Chosen:** add an emptiness condition to the guard so the index-0 read and the
no-op loop are skipped entirely for the 0-step case:

```php
// lib/functions/testcase.class.php:799 (post-fix)
if ($result && ( !is_null($item->steps) && is_array($item->steps) && count($item->steps) > 0 ) ) {
```

`count()` is evaluated strictly after the `is_array()` check, so non-array
inputs are unaffected. Behaviour for `null`, non-array and non-empty-array
inputs is byte-for-byte identical; the only observable change is the missing
E_WARNING for `[]`.

**Rejected alternatives:**
- Reading `steps[0]` with `?? null` / `?->` — silently changes semantics if
  `count > 0` but element 0 is somehow absent (out of scope for this bug).
- Moving the `is_object()` read inside the loop — bigger diff, no benefit.
- Defaulting steps to non-empty upstream at every caller — N call sites, easy
  to miss future callers; the shared class method is the right chokepoint.

## Verification

Regression matrix (`php tmp/regr_629.php`, suite 63 in `tmp/TLU_Test_Cases.md`):

| Case | Input | Step rows stored | New error/warning events |
|------|-------|------------------|--------------------------|
| Empty array | `steps = []` | 0 | none |
| Null (key omitted) | `steps = null` | 0 | none |
| Non-empty | 2 steps, `execution_type` 1 and 2 | 2, correct exec types | none |

`php tmp/regr_628.php` (adjacent execution_type fix) re-run as cross-check:
all cases PASS, `NO_ERROR_WARNING_EVENTS`. Event Viewer diff across the whole
matrix: `NO_ERROR_WARNING_EVENTS` — the only ERROR in the log is a SQL typo
artifact from the first draft of the regression driver itself (fixed in the
driver), unrelated to product code.

## Files Changed

- `lib/functions/testcase.class.php` — the +1 condition in the guard at line 799.
- `tmp/repro_629.php` — new repro driver (project REP629 → suite → 2 TCs, events diff).
- `tmp/regr_629.php` — new post-fix regression matrix driver.
- `tmp/TLU_Test_Cases.md` — new Suite 63 documenting the above (7/7 PASS).