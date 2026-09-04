# Bugfix Issue #849 — MD import hitCriteria externalID/internalID accepted but never used for matching

## Symptom

`POST /api/testcasesimport/?action=import_md&tproject_id=<pid>` with
`hit_criteria=externalID` or `hit_criteria=internalID` always returned
HTTP 400 `{"status":"error","message":"Invalid hit_criteria"}`. Only
`hit_criteria=name` was accepted. Even before the validation fix, the MD
duplicate-detection block never consulted `hit_criteria`, so ID-based
duplicate matching was unreachable.

## Root Cause

Two coupled defects, both in `api/testcasesimport/index.php`:

1. **Validation case-mismatch (lines 225-233).** `$hitCriteria =
   strtolower(trim($_POST['hit_criteria'] ?? 'name'))` lowercased the
   incoming value to `externalid`/`internalid`, which never matched the
   mixed-case allow-list `['name','internalID','externalID']`. Any non-`name`
   criterion was rejected with 400, making `$hitCriteria` unreachable.

2. **tcId numeric extraction missing (lines 335-344).** Even after the
   validation was relaxed (canonical re-casing), the duplicate detection
   used `intval($c['tcId'])` but the markdown parser
   (`lib/functions/markdown_tc_import.class.php:113`) stores `tcId` in
   `TC-<N>` format. `intval("TC-1")` returns `0` in PHP (stops at the first
   non-numeric char), so the externalID/internalID lookups always decoded to
   `0` and fell through to the name-based duplicate match.

Legacy parity reference: `lib/testcases/tcImport.php:280-296` switches on
`hitCriteria` (`name` → `getDuplicatesByName`, `internalID` →
`get_node_hierarchy_info`, `externalID` → `get_by_external`). The modernized
MD loop must mirror that switch.

## Fix

Two-step correction:

1. **Canonical re-casing (already landed, commit `91075141`).** Validate
   against the lowercased set `['name','internalid','externalid']`, then map
   back to canonical `name`/`internalID`/`externalID` before passing
   downstream so the legacy XML switch (`case 'externalID':`) still matches.
   Applied to both `import_md` (line 225-233) and `import_xml` (line 555-562).

2. **Numeric tcId extraction (this run, commit `385a377`).** Before the
   externalID/internalID index lookups, extract the trailing digit run from
   `tcId`:
   ```php
   $tcNumericId = 0;
   if (preg_match('/(\d+)\s*$/', strval($c['tcId'] ?? ''), $idMatch)) {
       $tcNumericId = intval($idMatch[1]);
   }
   ```
   and use `$tcNumericId` (instead of `intval('TC-N')`) as the index key.
   The by-external and by-node indexes were already built by commit `91075141`
   (lines 260-286).

## Verification

- `php -l api/testcasesimport/index.php` → no syntax errors
- `hit_criteria=externalID` accepted (400 gone), duplicate detected → skipped
- `hit_criteria=internalID` accepted (400 gone), duplicate detected → skipped
- `hit_criteria=name` → no regression, duplicate skipped
- `hit_criteria=externalID` + `action_on_hit=generate_new` → bypasses duplicate detection
- Non-matching external ID (TC-999, ext_id 999) → creates new (no false match)
- Invalid `hit_criteria=invalid` → still 400
- Event Viewer: no new Error/Warning entries
- Regression test suite: `tmp/TLU_Test_Cases.md` — TC-849.1 … TC-849.9, 9/9 PASS

## Files Changed

- `api/testcasesimport/index.php:332-347` — numeric tcId extraction in the
  externalID/internalID duplicate branch (this run)
- `api/testcasesimport/index.php:225-233, 260-286, 555-562` — canonical
  re-casing + by-external/by-node index build (prior CI commit `91075141`)