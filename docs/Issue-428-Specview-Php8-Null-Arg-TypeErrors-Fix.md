# specview.php PHP 8 Null-Argument TypeErrors Fix (Test Automation report)

## Issue #428

**Reports and Metrics → Test Automation** (`lib/results/testAutomationSpec.php`)
died with a raw fatal TypeError on any test project whose filtered test spec
resolves to an empty set — e.g. every test case is **Manual**, so the
"execution type = Automated" filter matches nothing:

```
Fatal error: Uncaught TypeError: array_diff_key(): Argument #2 must be of
type array, null given in lib/functions/specview.php:919
```

and, after guarding that first statement, a second fatal one hop later:

```
Fatal error: Uncaught TypeError: count(): Argument #1 ($value) must be of
type Countable|array, null given in lib/functions/specview.php:1554
```

Both statements relied on the PHP 7 behaviour "warn and return null"; on the
PHP 8.3 runtime of TestLink 2.0.1 they are uncaught TypeErrors → HTTP 500.
The same `count($test_spec)` guard exists in three places (the two tree-view
spec builders and the flat one), so all three were fixed together.


## Root cause

Chain (verified against revision `385ca3317`, where both fatals were still
reproducible):

1. `testAutomationSpec.php:31-36` calls `genSpecViewFlat()` with
   `$filters = array('exec_type' => TESTCASE_EXECUTION_TYPE_AUTO)`.
2. `getTestSpecFromNode()` initializes `$allowedSet = null`
   (`lib/functions/specview.php`); it is only assigned when an
   `execution_type` / `cfields` filter matches something. With zero automated
   TCs, `filter_tcversions_by_exec_type()` returns null ⇒ `$emptySet = true`,
   `$test_spec = null`.
3. The unconditional `array_diff_key($tcversionSet,$allowedSet)` then received
   `(array, null)` → TypeError #1.
4. Once guarded, execution continues; `genSpecViewFlat()` calls
   `count($test_spec)` on the legitimately-null return of
   `getTestSpecFromNode()` → TypeError #2.

## The fix — approach

Two behaviour-preserving guards (landed on the default branch in commits
`764d625e1` + `16ba44c5e`; this run reproduced both fatals pre-fix, verified
the post-fix render end-to-end and hardened the remaining warnings on the
same path — see below):

```php
// keep null flowing into the existing !is_null($setToRemove) guard:
// "no filter active => remove nothing"
$setToRemove = is_null($allowedSet)
               ? null
               : array_diff_key($tcversionSet,$allowedSet);

// equivalent to count() for arrays, null-safe:
if (!empty($test_spec)) { ... }
```

Alternatives rejected:

* `(array)$allowedSet` cast — diffs against an empty set, so `$setToRemove`
  becomes the whole `$tcversionSet` and **the entire spec gets blanked**
  (wrong results instead of a crash);
* guarding only the report controller — the two sibling `count()` sites would
  have kept dying for other screens.

## Verification (measured)

Reproduction against a fresh DB (project `Issue 428 Repro`, suite `Manual
Suite`, TCs `Manual TC One/Two` exec_type=MANUAL, plan `Plan 428`):

| Case | Pre-fix | Post-fix |
|------|---------|----------|
| Report, all-manual project | HTTP 500, fatal at `specview.php:919` | HTTP 200, empty result set |
| Report, +1 automated TC (`Auto TC One`) | (unreachable before) | HTTP 200, lists exactly the automated TC |
| Shared-path smoke `planAddTC.php?edit=testsuite&id=2&tplan_id=7` | — | HTTP 200, all TCs listed |
| Event Viewer | — | no new Error/Warning entries |

Note: calling the report without `&format=0` exits silently at
`displayMgr.php:87-90` (`tlog('Parameter format is not defined') + exit()`);
that silent exit masked my first reproduction attempt and is worth knowing
when testing any report screen directly.

## Follow-up hardening (same render path)

Fixing the fatals unmasked 5 downstream E_WARNINGs that had been cut short by
the early script death; they are fixed under issue **#641**:

* `$gui->tplan_name` initialized in `initializeGui()`
  (`lib/results/testAutomationSpec.php`) — used by `buildMailCfg()`;
* `'platforms' => false` added to the `$useFilter` initializer
  (`lib/functions/specview.php`) — key was read but never declared;
* `is_null($tcase_memory)` checked **before** the array dereference in
  `buildSkeletonFlat()` — mirrors the sibling `buildSkeleton()` order.

## Regression suite

`tmp/TLU_Test_Cases.md` → *Suite 428 — Regression — Issue #428*.
