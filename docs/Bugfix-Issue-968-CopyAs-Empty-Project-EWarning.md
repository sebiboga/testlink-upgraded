# Bugfix — Issue #968: PHP E_WARNING in testproject::copy_as() when copying an empty test project

## Symptom

When a new test project is created with **"Create from existing Test Project?"** pointing at a source
project that has **no children** (an empty project, i.e. it has no test suites / test plans /
requirement specs), the `events` table (Event Viewer, activity `PHP`) records one or two
`E_WARNING` rows per copy:

```
E_WARNING foreach() argument must be of type array|object, null given - in lib/functions/testproject.class.php - Line 2803
```

With the "copy requirements" feature option enabled an additional warning appears:

```
E_WARNING foreach() argument must be of type array|object, null given - in lib/functions/testproject.class.php - Line 2749
```

The project IS still created (the warnings are non-fatal) — the symptom is warning spam in the
Event Viewer on every copy-from-empty-project, which this 2.0.1 effort treats as a bug.

## Root cause

`testproject::copy_as()` (`lib/functions/testproject.class.php:2715`) contains two unguarded
`foreach` loops that assume a non-null array, while the legacy data layer returns `null` for empty
subtrees:

1. **`:2749`** — `foreach ($oldNewMappings['requirements'] as $erek)`.
   `copy_requirements()` (also in this file, `:2996`) initialises `$mappings = null;` and only replaces
   it with an array when the source has requirement-spec children (guard `if( !is_null($elements) )`
   at `:3021`). An empty source returns `list(null, array())`, so `$oldNewMappings['requirements']`
   is `null`. **Hit when `copy_requirements` is enabled.**

2. **`:2803`** — `foreach($elements as $piece)`.
   `tree_manager::get_children()` (`lib/functions/tree.class.php:592`) does `return(null)` when the
   child query yields `num_rows == 0` (`:604-606`). An empty test project root always matches that.
   **Hit on every copy from an empty project.**

The sibling `foreach($elements ...)` inside `copy_requirements()` (`:3024`) is already guarded with
`if( !is_null($elements) )` — same defect class, already safe.

**Why it breaks now:** PHP 8.x promotes the legacy tolerant behaviour (silent foreach over null in
PHP <8) to an `E_WARNING`, and `tlLogger` records every such warning as an Event Viewer row. The
code is shared by the legacy `lib/project/projectEdit.php:430` flow and the modernized BFF
`api/projectedit/index.php:373` (`action=create` with `copy_from_tproject_id`), so both entry
points inherited the warnings.

## Fix

Minimal two-line change in `lib/functions/testproject.class.php` — cast to `(array)` before the
loop, which turns `null` into `[]` so the loop no-ops but every other code path stays identical:

- `:2749` `foreach ($oldNewMappings['requirements'] as $erek)` → `foreach ((array)$oldNewMappings['requirements'] as $erek)`
- `:2803` `foreach($elements as $piece)` → `foreach((array)$elements as $piece)`

Rejected alternatives:
- `if (!is_null(...))` wrap (as used at `:3021`) — bigger diff and risks skipping the
  `$rel` / `$oldNewMappings['test_spec']` initialisation on the null branch.
- Changing `get_children()` to return `[]` instead of `null` — much larger blast radius across
  every tree.class consumer; out of scope for a minimal bug fix.

## Blast radius

- `testproject::copy_as()` callers: legacy `lib/project/projectEdit.php:430` and BFF
  `api/projectedit/index.php:373` — one fix covers both.
- `testplan::copy_as()` (invoked from `copy_testplans()` at `:2984`) is a different class/method and
  is unaffected.
- No user-facing strings changed, so no i18n bundle edits were required.

## Regression matrix

| Scenario | Before fix | After fix |
|----------|-----------|-----------|
| Copy from EMPTY source, optReq=0 | E_WARNING `:2803` | 0 warnings |
| Copy from EMPTY source, optReq=1 | E_WARNINGs `:2749` + `:2803` | 0 warnings |
| Copy from NON-empty source | Works, warning-free | Works (suite child copied), 0 warnings |
| Plain create (no copy-from) | No warnings | No warnings |

## Verification (measured)

Post-fix, on the same environment, all four regression cases run through the BFF produced **zero** new
`activity='PHP'` rows in `events` (events id 9–14 are all `AUDIT`). The only remaining `WARNING` rows
in the Event Viewer are the three pre-fix repro artifacts (ids 4, 7, 8) from 10:56; every post-fix
copy (10:58+) is `AUDIT`. Event Viewer verification screenshot is stored on the wiki page
(`Bugfix-Issue-968-eventviewer.png`).