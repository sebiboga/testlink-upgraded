# Bugfix — Issue #530: `tlPlatform::belongsToTestProject()` always returns false

## Symptom

`tlPlatform::belongsToTestProject()` always returned `false`, even when the
platform belonged to the specified test project. This broke all platform write
operations (update/delete) through the REST BFF API (`api/platforms/index.php`),
which returned 404 "Platform not found" for every platform.

## Root cause

`lib/functions/tlPlatform.class.php:564` — `fetchRowsIntoMap($sql, 'id')`
returns an associative map keyed by the platform's actual id value
(e.g. `[999 => [...]]`).

The old code at line 566 checked `isset($dummy['id'])` — looking for the
literal string key `'id'` instead of the numeric platform id value. Since no row
has id = the string `'id'`, this always returned `false`.

## Fix

Changed `isset($dummy['id'])` to `!is_null($dummy) && count($dummy) > 0`.

This correctly checks whether the query returned any rows (meaning the platform
exists in the given project). Applied in commit `e9da5834c`.

## Files changed

- `lib/functions/tlPlatform.class.php:566` — single line fix

## Blast radius

- `api/platforms/index.php:82` (`needOwnedPlatform()`) — all platform CRUD via
  the REST BFF API was broken
- No other callers of this method exist in the codebase

## Verification

| Test | Result |
|------|--------|
| `belongsToTestProject(999, 1)` — platform in project | TRUE (fixed) |
| `belongsToTestProject(999, 999)` — wrong project | FALSE (correct) |
| `belongsToTestProject(12345, 1)` — non-existent platform | FALSE (correct) |
| BFF `needOwnedPlatform` gate — valid ownership | PASS |
| BFF `needOwnedPlatform` gate — wrong project | 404 (correct) |
| Event Viewer | Zero new Error/Warning entries |

**Result: 7/7 PASS** (2026-08-25)
