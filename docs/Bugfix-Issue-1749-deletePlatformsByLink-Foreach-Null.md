# Bugfix — Issue #1749: testcase::deletePlatformsByLink() — foreach() on null for an unknown link id

## Problem

`testcase::deletePlatformsByLink()` raised
`E_WARNING foreach() argument must be of type array|object, null given`
whenever the supplied `tcplat_link_id` matched no `testcase_platforms` row — a
stale chip (already unassigned in another tab) or a concurrent unassign. The
request still answered normally; the only symptom was one Error row pushed into
the Event Viewer (`events`, `log_level=2`) pointing at
`lib/functions/testcase.class.php`.

**Reproduce at HEAD (fresh-import CI box, no projects exist — build the fixture
first):**

```
php tmp/fixtures_1749.php      # tproject prefix PL1749, 1 TC + version, 1 platform, 1 link
php tmp/repro_1749.php
```

→ pre-fix: `FAIL caseA stale-link-id warnings=1` at
`testcase.class.php - Line 9890`; post-fix: `warnings=0`.

## Root Cause

1. `database::get_recordset()` (`lib/functions/database.class.php:787`) starts
   with `$output = null` and only appends rows inside
   `while($row = $this->fetch_array($result))`. For an **empty result set** the
   loop body never runs, so the method **returns `null`** (line 799).
2. `deletePlatformsByLink()` (`lib/functions/testcase.class.php:9888`) assigns
   that result to `$rs` and then runs `foreach($rs as $link)` (line 9890) with no
   guard → PHP 8 raises the warning.
3. `set_error_handler("watchPHPErrors")` (`lib/functions/logger.class.php:1483`)
   persists the warning as one `events` row.

PHP 7 tolerated `foreach(null)` silently; PHP 8 promoted it to a warning, so the
defect was latent until the 2.0.1 PHP 8 target.

**Blast radius:**
- `lib/testcases/testcaseCommands.class.php:1584` — legacy test-spec platform
  unassign (`tcEdit` `doAction=removePlatform`), no ownership pre-check → fully
  exposed.
- `api/testcases/index.php:3096` — modern BFF `remove_platform`. Its own `SELECT`
  guard at `:3084-3095` already 404s a stale id, so it is safe today, but the
  guard is **not atomic**: a concurrent unassign between the SELECT and the
  DELETE re-opens the window.
- Sibling `deletePlatforms()` (`:9899`) is already null-safe (`(array)` cast at
  `:9920`) and was left untouched.

## Fix

`lib/functions/testcase.class.php` — guard the iteration; an empty/unknown link
id becomes a clean no-op:

```php
$rs = $this->db->get_recordset($sql);

// get_recordset() returns null for an empty result set (database.class.php:787);
// an unknown/stale link id must be a clean no-op, not an E_WARNING (#1749).
foreach((array)$rs as $link) {
  $this->deletePlatforms($safeTCID, $link['tcversion_id'],
    $link['platform_id'],$audit);
}
```

The `(array)` cast is the same pattern already used in this method (`:9878`) and
in `deletePlatforms()` (`:9920`). Rejected alternatives: a local `is_array()`
check (noisier, inconsistent with the file) and changing `get_recordset()`
globally (blast radius far beyond this bug). No user-facing strings → no
locale-bundle additions.

## Files Changed

- `lib/functions/testcase.class.php` — `deletePlatformsByLink()`: `(array)` cast
  on the record set.
- `tmp/fixtures_1749.php` — fixture (project + TC + platform + link).
- `tmp/repro_1749.php` — CLI repro/regression harness (stale id + positive
  control).
- `tmp/TLU_Test_Cases.md` — suite "Regression — Issue #1749", 7/7 PASS.

## Verification

All checks on the fresh-import CI box:

- Pre-fix `tmp/repro_1749.php` = 1 E_WARNING at `testcase.class.php:9890`;
  post-fix = 0, and the positive control (real link) still deletes the row.
- HTTP `POST api/testcases/index.php?action=remove_platform` with a stale
  `tcplat_link_id` → HTTP 404 clean JSON error, **0** new `events` rows.
- Same action with the valid link id → HTTP 200, row removed, free list updated.
- `php -l lib/functions/testcase.class.php` clean.

Address: `Fixes #1749` (pushed on the fix branch; CI lands it on the default
branch).
