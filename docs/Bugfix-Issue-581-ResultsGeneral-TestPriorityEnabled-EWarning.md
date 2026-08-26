# Issue 581 — E_WARNING "testPriorityEnabled on false" with empty testprojects.options

**Issue:** [#581](https://github.com/sebiboga/testlink-upgraded/issues/581)
**Branch:** `fix/issue-581`
**Status:** VERIFIED-FIXED (2026-08-26)

## Symptom

Opening `resultsGeneral.php` with a test project whose `testprojects.options` column is empty
(`""`) produced an E_WARNING on every page load:

```
E_WARNING Attempt to read property "testPriorityEnabled" on false - in .../lib/results/resultsGeneral.php - Line 251
```

This affects any project created via direct DB fixture with an empty options column, or any
legacy row where options were never properly serialized.

## Root cause

`testproject::parseTestProjectRecordset()` at `lib/functions/testproject.class.php:239` calls
`unserialize($row['options'])`. When `options` is an empty string, PHP 8 `unserialize("")`
returns `false` (not an object). The result is stored as `$recordset[$number]['opt'] = false`.

Then `resultsGeneral.php:251` accesses `$dummy['opt']->testPriorityEnabled` — property read
on `false` triggers E_WARNING.

**Blast radius**: 6 files in `lib/results/` plus 2 filter classes access `['opt']->` from
testproject data, all sharing the same `get_by_id()` → `parseTestProjectRecordset()` code path.

## Fix

Guarded `parseTestProjectRecordset()` with `is_object()` check on the `unserialize()` result.
When `false` (or any non-object), falls back to a default stdClass matching the normal serialized
format:

```php
$decoded = unserialize($row['options']);
if (!is_object($decoded)) {
    $decoded = new stdClass();
    $decoded->requirementsEnabled = 0;
    $decoded->testPriorityEnabled = 0;
    $decoded->automationEnabled = 0;
    $decoded->inventoryEnabled = 0;
}
$recordset[$number]['opt'] = $decoded;
```

This is a central fix that protects all callers at the source.

## Files changed

- `lib/functions/testproject.class.php` — `parseTestProjectRecordset()` method (lines 236-252)

## Verification

- `php -l lib/functions/testproject.class.php` → no syntax errors
- Empty options scenario: `resultsGeneral.php` loads cleanly, 0 new E_WARNING events
- Valid options scenario: `resultsGeneral.php` loads identically to pre-fix, 0 new E_WARNING events
- `SELECT description FROM events WHERE description LIKE '%testPriorityEnabled%'` → 0 rows
- Event Viewer check: no new Error/Warning entries

Regression suite: `tmp/TLU_Test_Cases.md` → **Suite 47 — 4/4 PASS**.
