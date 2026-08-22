# testproject::get_by_id() Null Guard Fix

## Issue #554

Fixed: calling `testproject::get_by_id()` with a **positive id that has no
matching `testprojects` row** logged a PHP warning into the Event Viewer on
every call:

```
E_WARNING
Trying to access array offset on null - in .../lib/functions/testproject.class.php - Line 342
```

The method still returned NULL (so page behavior was unaffected), but each
occurrence polluted the `events` table — noise for administrators and risk of
masking real warnings.

## Root Cause

* `get_by_id()` resolves the project via
  `$this->getTestProject("testprojects.id=" . intval($id), $opt)`.
* `getTestProject()` ends in `return $this->db->get_recordset($sql);` and
  `database::get_recordset()` initializes its output to **null** and returns
  it unchanged when the query matches **zero rows** (or fails).
* Line 342 then did an unguarded array dereference:
  `return $result[0];` → under PHP 8.x, reading `[0]` off `null` raises
  *E_WARNING: Trying to access array offset on null*.
* The class's own sibling methods already guarded this exact pattern —
  `get_by_prefix()` (line ~360): `return $rs != null ? $rs[0] : null;` — only
  `get_by_id()` was missing the guard.

## Fix Approach — mirror the existing in-class guard convention

Minimal one-line change in `lib/functions/testproject.class.php`
(`get_by_id()`):

```php
$result = $this->getTestProject($condition,$opt);
return $result != null ? $result[0] : null;
```

Why this way:

* **Return semantics are byte-identical to before**: pre-fix the method also
  evaluated to NULL after emitting the warning, so no caller can observe any
  behavioral difference except the absence of the warning. Callers already
  treat NULL as "project not found" (e.g. the guard added to
  `print.inc.php initStaticRenderTestCaseForPrinting()` during the #552 fix).
* **Consistency over invention**: reusing the same loose-comparison guard as
  `get_by_prefix()` keeps one idiom per class. Loose `!= null` also covers the
  defensive cases (`[]`, `false`) identically; in practice
  `parseTestProjectRecordset()` normalizes empty recordsets to null anyway.
* **Alternatives rejected**: changing `getTestProject()`'s return contract
  (too broad — many callers depend on it), or throwing on not-found (would be
  an API behavior change affecting ~85 call sites; not-found is a normal,
  expected outcome here).

## Files Changed

| File | Change |
|------|--------|
| `lib/functions/testproject.class.php` | `get_by_id()`: null-guard the recordset before `[0]` dereference |

## Verification

Reproduced via PHP CLI bootstrap (`testlinkInitPage($db,false,true)`):

1. Pre-fix: `get_by_id(99999999)` → NULL + E_WARNING row appended to
   `events` (log_level=2, "Line 342").
2. Post-fix: same call → clean NULL, **zero** new event rows.
3. Positive path intact: `get_by_id(<existing fixture>)` returns the full
   project record (`name`, `prefix`, …).
4. Sibling methods unchanged: `get_by_prefix()` / `get_by_name()` behave as
   before.
5. Code review subagent over the full diff: PASS (~85 call sites audited —
   none index the raw result unguarded).
6. Event Viewer after all flows: 0 Error/Warning entries.

Regression suite 38 recorded in `tmp/TLU_Test_Cases.md` (Suite ID 45): 5/5 PASS.
