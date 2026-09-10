# Bugfix — Issue #1333: Legacy tcCompareVersions — buildDiff() foreach on null input fires E_WARNING

## Problem

Submitting a compare on the LEGACY page `lib/testcases/tcCompareVersions.php`
with `testcase_id=0` (or any project-scoped request where the testcase lookup
returns no versions) fires E_WARNING `Invalid argument supplied for foreach()` at
`buildDiff()` (Line 157 area). The HTTP response stays 200; the symptom is only
visible in the Event Viewer / `events` table (log_level=2, activity=PHP).

**Reproduce at HEAD (measured on the fresh-import CI box):** with an
authenticated session call

```
curl -b <cookies> ".../lib/testcases/tcCompareVersions.php?tproject_id=1&testcase_id=0&compare_selected_versions=1"
```

→ HTTP 200 + one `E_WARNING\nInvalid argument supplied for foreach()` row per
request.

## Root Cause

- `init_args()` sets `$args->tcase_id = intval($_REQUEST['testcase_id'] ?? 0)` → `0`.
- `getTestcaseVersions()` / the compare handler feed `buildDiff()` with
  `$items = null` when the testcase has no candidate versions, and
  `buildDiff()` iterates it unconditionally:

```php
foreach ($items as $item) { ... }
```

- PHP 8 throws `Invalid argument supplied for foreach()` for `null`, logging the
  warning to `events`.

**Blast radius:** file-local. The dashio Smarty template tolerates an empty diff
(renders the selection form fine). The modern `tcCompare.html` screen (Refs
#809/#1327, BFF `api/testcasescompare/index.php`) is unaffected — it has its own
guards.

## Fix

Committed on the default branch as part of the #1333 run, commit `1f2d0a5d3`:

```php
public function buildDiff($items, $fieldNames) {
  $diff = array();
  if (!is_array($items)) {
    return $diff;
  }
  foreach ($items as $item) { ... }
}
```

Early-return on non-array input — no more foreach warning, and the page keeps
rendering the "select versions" form exactly as before. No new user-facing
strings → no locale-bundle additions.

## Files Changed

- `lib/testcases/tcCompareVersions.php` — `buildDiff()`: `is_array()` guard.
- `tmp/TLU_Test_Cases.md` — regression suite "Regression — Issues #1333/#1329/#1340/#1341", 17/17 PASS.

## Verification

All checks on the fresh-import CI box, admin session:

- Pre-fix repro via curl = 1 E_WARNING row (`Invalid argument supplied for foreach`).
- Post-fix same URL = HTTP 200, **0** new `log_level IN (2,3)` rows.
- Happy path compare (`testcase_id=3&version_left=1&version_right=2&compare_selected_versions=1`) still renders the diff.
- Event Viewer after the pass: only INFO/AUDIT (`audit_login_succeeded`).

Address: `Fixes #1333` (pushed on the default branch; issue auto-closes on merge).