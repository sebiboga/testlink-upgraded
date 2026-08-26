# Bugfix — Issue #564: E_WARNING spam from unserialize() in testproject::getOptions()

## Symptom

Every page load that resolves the current test project emits 1–5 Event Viewer rows:

```
E_WARNING unserialize(): Error at offset 0 of 1 bytes - in testproject.class.php - Line 3897
```

This happens when `testprojects.options` holds a non-serialized scalar value (e.g. `"0"` from a SQL migration or import).

## Root cause

`testproject::getOptions()` (`lib/functions/testproject.class.php:3897`) calls `unserialize($rs[0]['options'])` without checking whether the value is a valid serialized string. PHP 8.x promotes the old E_NOTICE to E_WARNING, and `tlLogger` records it to the events table.

**Why it breaks now:** PHP 8 upgrade. The same code was silent under PHP 7.

## Fix

Guard `unserialize()` in `getOptions()`:

1. Check the raw value is a string, non-empty, and starts with a valid serialized type token (`O`, `a`, `s`, `i`, `d`, `b`, `N`, `R`).
2. If not, return `(object)[]` — callers already handle empty objects safely.
3. After `unserialize()`, check for `false` return and fall back to `(object)[]`.

**File changed:** `lib/functions/testproject.class.php` (lines 3894–3903)

**Before:**
```php
if (null == $rs || count($rs) <=0) {
  return (object)[];
}
return unserialize($rs[0]['options']);
```

**After:**
```php
if (null == $rs || count($rs) <=0) {
  return (object)[];
}
$raw = $rs[0]['options'];
if (!is_string($raw) || $raw === '' || !in_array($raw[0], ['O','a','s','i','d','b','N','R'], true)) {
  return (object)[];
}
$obj = unserialize($raw);
return $obj !== false ? $obj : (object)[];
```

## Blast radius

Same unguarded `unserialize()` pattern exists at lines 239 and 433 (`parseTestProjectRecordset`) — those are also vulnerable but out of scope for this minimal fix.

## Regression matrix

| Scenario | Before fix | After fix |
|----------|-----------|-----------|
| `options = "0"` | E_WARNING × 5 | 0 warnings |
| `options = ""` | E_WARNING × 1 | 0 warnings |
| `options = NULL` | (handled by count check) | 0 warnings |
| Valid serialized options | Works | Works |
| Non-existent project | Returns `(object)[]` | Returns `(object)[]` |
