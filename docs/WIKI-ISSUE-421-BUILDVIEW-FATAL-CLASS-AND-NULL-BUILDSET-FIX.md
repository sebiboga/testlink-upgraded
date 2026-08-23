# Issue #421 — buildView.php Fatal `build_mgr` + Empty-Plan E_WARNING Fix

**Issue:** [#421 — buildView.php instantiates non-existent class build_mgr — Builds/Releases page is a fatal error](https://github.com/sebiboga/testlink-upgraded/issues/421)
**Area:** Test Plan → Builds / Releases (legacy `lib/plan/buildView.php` + `lib/plan/buildEdit.php`)

## Symptoms

1. **Fatal error (original report):** opening *Test Plan → Builds / Releases*
   died before rendering anything:

   ```
   Fatal error: Uncaught Error: Class "build_mgr" not found in
   lib/plan/buildView.php:41
   ```

2. **Residual E_WARNING (found while verifying the fix):** with a test plan
   that has **zero builds**, every view logged

   ```
   E_WARNING foreach() argument must be of type array|object, null given
   - lib/plan/buildView.php - Line 90
   ```

   into the `events` table. Once at least one build existed, no warning fired.

## Root Cause

### 1. Non-existent class `build_mgr` (already fixed by `4c73d651e`)

`buildView.php:41` executed `new build_mgr($dbHandler)`, but the only class in
the tree is `build` (declared `lib/functions/build.class.php:26`;
`build_mgr` survives solely as a closing comment, build.class.php:658).
Commit `4c73d651e` ("Fix missing template file and class references for test
plan functionality", 2026-08-17) corrected line 41 to
`new build($dbHandler)` but the issue was never verified/closed.

### 2. Unguarded loop over a NULL build set (fixed by this run — `6ac0e88c6`)

Chain of two broken contracts:

1. `lib/plan/buildView.php:53` — `$gui->buildSet = $tplan_mgr->get_builds($gui->tplan_id);`
2. `lib/functions/testplan.class.php:2250` — `get_builds()` ends with
   `return $this->db->fetchRowsIntoMap($sql,$accessField);` → **NULL** when the
   plan has zero rows in `builds`.
3. `lib/plan/buildView.php:90` — `foreach($gui->buildSet as $elemBuild)` had no
   null guard → PHP 8.x E_WARNING (captured by TL logging as event id=12).

The null case was already a known contract inside the same function — line 58
wraps the custom-field bootstrap in `if (!is_null($gui->buildSet))`. The main
loop simply missed it, and the template renders its own
"No builds are defined within this Test Plan!" message anyway.

## The Fix

One line, `lib/plan/buildView.php:90` (commit `6ac0e88c6`):

```php
- foreach($gui->buildSet as $elemBuild) {
+ foreach(($gui->buildSet ?? []) as $elemBuild) {
```

Non-empty plans behave identically; empty plans now yield an empty array so
the loop is skipped and the template's empty-state message stands alone.

Alternatives rejected:

* Wrapping the ~25-line loop body in an added `if (!is_null(...))` block —
  bigger diff for identical behaviour.
* Changing `get_builds()` to always return an array — touches a widely used
  API (`grep` shows many call sites); violates minimal-fix rule.

## Verification (regression matrix, all PASS)

Run 2026-08-23, headless Chrome against http://localhost:8082, fresh DB,
fixtures created via UI (project "Bug421 Project", plans id=2 with one build /
id=3 zero builds), SQL event-table diffs around each step:

| # | Case | Result |
|---|------|--------|
| R1 | Zero-build plan → `buildView.php?tplan_id=3` | Renders "No builds are defined…"; ZERO events from buildView.php (pre-fix produced event id=12) |
| R2 | Create build via Create button | Build `1.0` persisted + audit event |
| R3 | Populated view lists build | Table row rendered, no warnings |
| R4 | Edit link | `buildEdit.php?do_action=edit&build_id=1` opens prefilled |
| R5 | Event Viewer after whole pass | No new Error/Warning attributable to Builds screens |

Full suite: Suite 60 in `tmp/TLU_Test_Cases.md` (8/8 PASS).

## Screenshots

Empty plan after fix (no fatal, no warning):



Populated view after fix (build listed):



## Out-of-scope findings

While verifying, repeated E_WARNINGs were observed on the **Test Plan
Management** screens (`planView.tpl` lines 213/217/279, `planEdit.tpl` lines
62–63) — filed separately as [#622](https://github.com/sebiboga/testlink-upgraded/issues/622),
not touched by this fix.
