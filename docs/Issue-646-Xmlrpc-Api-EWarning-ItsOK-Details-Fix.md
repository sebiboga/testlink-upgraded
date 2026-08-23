# Issue 646 — xmlrpc API E_WARNINGs on success paths (`$itsOK` :2200, `details` :4403)

**Issue:** [#646](https://github.com/sebiboga/testlink-upgraded/issues/646)
**Branch:** `fix/issue-646` · **Commits:** `c2bfbe6b7` (fix), `f527cb52f` (regression suite)
**Status:** FIXED & VERIFIED (2026-08-23)

## Symptom

Every API-driven fixture run wrote E_WARNINGs to the Event Viewer while the operations
themselves succeeded (`status=1`):

```
E_WARNING Undefined variable $itsOK      - lib/api/xmlrpc/v1/xmlrpc.class.php:2200   (per createTestProject)
E_WARNING Undefined array key "details"  - lib/api/xmlrpc/v1/xmlrpc.class.php:4403   (per createTestSuite without details)
```

Measured during reproduction (events truncated first): event id 2 fired by a single
`tl.createTestProject` call without `itsname`; event id 3 fired by a single
`tl.createTestSuite` call without `details`. Both calls returned `status=true`.

## Root cause chain

**Bug A — `$itsOK` (:2200)**
1. `createTestProject()` initializes `$its = null;` but assigns `$itsOK` ONLY inside
   `if ($optional[self::$itsNameParamName] != "")` (xmlrpc.class.php:2184-2193).
2. The success path reads it unconditionally: `if ($itsOK && $tproject_id > 0)` at :2200.
3. Any successful `createTestProject` that does not pass an issue-tracker name (the common
   case) hits the read with `$itsOK` never defined → E_WARNING per call.

**Bug B — `details` (:4403)**
1. In `createTestSuite()` the `'create'` branch builds `$opt` WITHOUT a `details` default
   (xmlrpc.class.php:4328-4332); only the `'update'` branch declares
   `self::$detailsParamName => null` (:4317).
2. Line 4403 reads `$args[self::$detailsParamName]` from the RAW request struct (local
   `$args` = function parameter). A request struct without `details` → undefined key warning.

## Blast radius

- Bug A: every `createTestProject` without `itsname` (grep: `$itsOK` only at :2188/:2189/:2200).
- Bug B: every `tl.createTestSuite 'create'` without `details` — CI fixture scripts trigger
  this routinely (the reporter saw ×2 for two suites).
- No functional impact: both operations returned `status=1`; pure log noise.

## Approach — minimal initialization / defaulting

Chosen fix (+5 lines, zero behavior change):

1. **Bug A**: initialize `$itsOK = false;` beside `$its = null;`. When `itsname` IS provided,
   `$itsOK` is overwritten at :2189 exactly as before, so ITS linking semantics are unchanged;
   when absent, the link block is now skipped via a defined `false` instead of an undefined var.
2. **Bug B**: normalize before the create/update switch:
   `if (!isset($args[self::$detailsParamName])) $args[self::$detailsParamName] = null;`
   — same default the update branch already declares. `testsuite::update()` guards
   `!is_null($details)` (testsuite.class.php:228) so null means "don't touch details";
   `testsuite::create()` runs `prepare_string(null)` → `''` on INSERT
   (database.class.php:401-402), byte-identical to the pre-fix coerced runtime value.

Rejected alternatives: restructuring `$opt` tables (larger diff, same effect); suppressing
warnings at the handler level (hides future bugs).

## Files changed

| File | Change |
|---|---|
| `lib/api/xmlrpc/v1/xmlrpc.class.php` | +5 lines: `$itsOK = false;` init (:2184); missing-`details` → `null` normalization (:4391-4393) |
| `tmp/TLU_Test_Cases.md` | Suite 646 regression entry (7 cases) |

## Verification

- `php -l lib/api/xmlrpc/v1/xmlrpc.class.php` PASS.
- Repro re-run after fix, events table delta measured across ALL calls = **zero log_level=2 rows**
  (only the level-16 audit `audit_testproject_created`):
  - R1 createTestProject w/o itsname → status=true, no event (was: E_WARNING)
  - R2 createTestProject unknown itsname → clean IXR_Error 13000, no crash
  - R3 createTestSuite w/o details → status=true, no event (was: E_WARNING)
  - R4 createTestSuite WITH details → status=true; DB check: `testsuites.details` persisted verbatim
  - R5 suite action=update w/o details → status=true, no warning from raw read at :4398
  - R6 bad devKey → clean "invalid developer key" fault

Regression suite: `Regression — Issue #646` in `tmp/TLU_Test_Cases.md` — 7/7 PASS.

Follow-up discovered during code review: sibling unguarded read of optional `testsuitename`
on the update path filed as #648.
