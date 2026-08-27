# Issue 611 — api/requirements print_tree: foreach() on null $specs when project has no requirement specs

**Issue:** [#611](https://github.com/sebiboga/testlink-upgraded/issues/611)
**Branch:** `fix/issue-611` · **Fix commit:** `ca9fdd00f`
**Status:** FIXED & VERIFIED (2026-08-27)

## Symptom

Requesting the modernized print-requirement-specification tree
(`GET /api/requirements/index.php?action=print_tree&tproject_id=<id>`) for a test
project that has **zero** requirement specs logs a server-side warning:

```
E_WARNING: foreach() argument must be of type array|object, null given
in api/requirements/index.php - Line 626
```

The warning is not visible in the JSON response (it is suppressed from display)
but is captured by the events pipeline and shows up in the **Event Viewer**
(`events` table, `log_level=2` / Warning, `activity=PHP`) once per load.

## Root cause chain

1. The `print_tree` route fetches all requirement specs of the project:

   ```php
   $specs = $db->fetchRowsIntoMap($sql, 'id');   // api/requirements/index.php ~line 609
   ```

2. `tlDB::fetchRowsIntoMap()` (`lib/functions/database.class.php:655`) initializes
   `$items = null` and, when the SQL result has zero rows, the `while(...fetch_array...)`
   loop never runs → the method returns **`null`**.

3. In the route, only the *count* use is null-safe:
   - `$specIds = is_array($specs) ? array_keys($specs) : [];` (~line 610) → safe
   - `if (count($specIds) > 0) { ... }` (~line 612) → safe, skipped for empty

4. The **nested-structure build is NOT null-safe**:
   ```php
   $childrenOf = [];
   foreach ($specs as $sid => $row) {   // ~line 626 -> E_WARNING on null
   ```

5. With `$specs === null` the `foreach` fires the `E_WARNING`, which the global
   error handler parks into the `events` table (Event Viewer).

**Why it breaks now:** one of two dependent uses of `$specs` guards against the
`null` that `fetchRowsIntoMap` legitimately returns on an empty set, the other
does not. Any project with the Requirements feature enabled but no (remaining)
requirement specs triggers it on every print-tree screen load.

### Blast radius

- Only the `print_tree` route of `api/requirements/index.php` is affected (used by
  the modernized `gui/templates/requirements/printReqSpec.html`). The legacy
  `lib/requirements/reqPrint.php` builds its tree differently and is unaffected.
- The same `fetchRowsIntoMap`-returns-null pattern exists elsewhere, but each other
  call site is guarded individually; a file-wide check found no other unguarded
  `foreach` over `$specs` in this route.

## Approach — coerce null to empty array (minimal guard)

Chosen fix (commit `ca9fdd00f`): one defensive line immediately after the existing
`$specIds` guard, forcing `null` to `[]`:

```php
$specs  = $db->fetchRowsIntoMap($sql, 'id');
$specIds = is_array($specs) ? array_keys($specs) : [];
$specs   = is_null($specs) ? [] : $specs;   // NEW — empty project => no foreach warning
```

Why this method:
- It mirrors the already-present `is_array($specs) ? ... : []` idiom on the line
  above and the file's established `is_null(...)` pattern (`is_null($proj)` / `!is_null($map)`).
- Zero behaviour change for non-empty projects (array passes through untouched).
- **Rejected alternative:** wrapping the `foreach` in an `if (is_array($specs))`
  — functionally equivalent but less readable than coercing the value once at the source.

## Verification matrix

| # | Check | Result |
|---|---|---|
| R1 | `php -l api/requirements/index.php` | PASS — clean syntax |
| R2 | Empty project: print_tree returns `{"status":"ok","roots":[],"specQty":0}` | PASS |
| R3 | Empty project: `events` table gains **no** new E_WARNING row | PASS |
| R4 | Project with specs: tree builds correctly (nested specs + req counts) | PASS — `specQty:3, reqQty:1` |
| R5 | Event Viewer: no new Error/Warning after post-fix runs | PASS |

Full suite: `tmp/TLU_Test_Cases.md` → "Regression — Issue #611" (3/3 PASS).

## Files changed

- `api/requirements/index.php` (1 line: `$specs = is_null($specs) ? [] : $specs;`)
- `tmp/TLU_Test_Cases.md` (Suite 611)
- this page + wiki mirror `Bugfix-Issue-611-print-tree-null-specs.md`
