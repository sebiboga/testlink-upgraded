# Issue 628 — E_WARNING "Undefined array key execution_type" When Creating Test Cases Without Explicit Step Execution Type

## Issue #628

Creating test cases whose step arrays omit the optional `execution_type` key
(minimal CLI/import fixtures, REST-API-style payloads) worked functionally but
wrote **one `E_WARNING` row per created case** into the Event Viewer
(`events` table):

```
E_WARNING | Undefined array key "execution_type"
  - in lib/functions/testcase.class.php - Line 804
```

Reproduced on a fresh DB with `php tmp/repro_628.php`: two cases created →
events ids 8 and 9 (`log_level=2`, source GUI), exactly one per case.

## Root Cause

`testcase::createVersion()` — the private method behind
`testcase::create()` / `createFromObject()` — loops over the step array and
reads an **optional** key unguarded:

```php
// lib/functions/testcase.class.php:799-805 (pre-fix)
$this->create_step($tcase_version_id,
                   $item->steps[$jdx]['step_number'],
                   $item->steps[$jdx]['actions'],
                   $item->steps[$jdx]['expected_results'],
                   $item->steps[$jdx]['execution_type']);   // ← line 804
```

Two facts make this warning pure noise:

1. `create_step()`'s signature already defaults the parameter:
   `function create_step($tcversion_id, $step_number = null, $actions = null,
   $expected_results = null, $execution_type = TESTCASE_EXECUTION_TYPE_MANUAL)`
   (lines 5774-5775).
2. It even sanitizes out-of-range values back to MANUAL
   (`$dummy = (isset($this->execution_types[$dummy])) ? $dummy :
   TESTCASE_EXECUTION_TYPE_MANUAL;`, line 5796).

So under PHP 8 the missing key raises `E_WARNING "Undefined array key"` (a
silent notice in PHP 7), which TestLink's error handler persists to `events`,
while the stored result is correct anyway. Any caller omitting the key hits it
on every creation — and per the in-code comment at line 797 this loop is shared
with the REST API, so API clients are first-class victims.

Blast radius: `createVersion()` has exactly two call sites —
`createFromObject()` (:432, fed by `create()`) and `copy_to()` (:2264, which
always sets the value from a DB row). Similar raw reads at :2323/:2341/:2602 are
fed from DB rows where the column always exists — untouched.

## Fix Approach

**Chosen:** guard the single raw read with the same idiom already used twice in
the class (`:5786`, `:6072`):

```php
// lib/functions/testcase.class.php:799-807 (post-fix)
$this->create_step($tcase_version_id,
                   $item->steps[$jdx]['step_number'],
                   $item->steps[$jdx]['actions'],
                   $item->steps[$jdx]['expected_results'],
                   isset($item->steps[$jdx]['execution_type'])
                     ? $item->steps[$jdx]['execution_type']
                     : TESTCASE_EXECUTION_TYPE_MANUAL);
```

**Rejected alternatives:**
- Defaulting `execution_type` upstream in every caller — N call sites, larger
  diff, easy to miss future callers.
- Changing `create_step()`'s signature — unnecessary; it already defaults and
  sanitizes.
- `?? ` null-coalescing — equivalent here, but the codebase style at the two
  existing guards is `isset() ? : `, so consistency wins.

## Verification

Regression matrix (`php tmp/regr_628.php`, suite 62 in `tmp/TLU_Test_Cases.md`):

| Case | Input | Stored `tcsteps.execution_type` | New error/warning events |
|------|-------|--------------------------------|--------------------------|
| Missing key | steps without `execution_type` | `1` (MANUAL default) | none |
| Explicit value | `execution_type=2` | `2` preserved | none |
| Invalid value | `execution_type=99` | `1` (create_step sanitize, unchanged) | none |

Event Viewer diff across the whole matrix: `NO_ERROR_WARNING_EVENTS`.

## Files Changed

- `lib/functions/testcase.class.php` — the +3/-1 guard at line 804.
- `tmp/repro_628.php` — repro driver (project REP628 → suite → 2 TCs, events diff).
- `tmp/regr_628.php` — post-fix regression matrix driver.
- `tmp/TLU_Test_Cases.md` — Suite 62 documenting the above.
