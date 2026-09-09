# Bugfix — Issue #1332: Legacy tcCompareVersions — 2x E_WARNING 'array offset on null' when opening without testcase_id

## Problem

Loading the LEGACY compare page `lib/testcases/tcCompareVersions.php` without
a valid `testcase_id` (e.g. `?tproject_id=1`) logs **2x**
`E_WARNING Trying to access array offset on null` (events table, log_level 2,
activity=PHP), both pointing to Line 121. The HTTP response stays 200 — the
symptom is only visible in the Event Viewer / `events` table.

**Reproduce at HEAD (measured on the fresh-import CI box):** with an
authenticated session call

```
curl -b <cookies> ".../lib/testcases/tcCompareVersions.php?tproject_id=1"
```

→ HTTP 200, and two E_WARNING rows appear in `events`:
`E_WARNING\nTrying to access array offset on null - in .../lib/testcases/tcCompareVersions.php - Line 121`
(log_level=2).

## Root Cause

- `init_args()` (tcCompareVersions.php:87) sets
  `$args->tcase_id = intval($_REQUEST['testcase_id'] ?? 0)` → `0` when the
  parameter is absent.
- `initializeGUI()` (tcCompareVersions.php:120) calls
  `$tcaseMgr->get_by_id(0)`; `testcase::get_by_id()` (lib/functions/testcase.class.php:2749)
  builds `WHERE NHTCV.parent_id = 0` and returns `null` (no rows).
- tcCompareVersions.php:121 `$gui->tcaseName = $gui->tc_versions[0]['name'];`
  dereferences null → 2x E_WARNING (PHP 8 fires the array-offset-on-null
  warning twice for the same expression in this context).

**Blast radius:** the deref is file-local (`initializeGUI` is invoked once at
tcCompareVersions.php:24). The dashio Smarty template
`gui/templates/dashio/testcases/tcCompareVersions.tpl` tolerates a null
`tc_versions` in its `{foreach}` (renders the empty "select versions" form), so
the page remains functional without a testcase. The modern `tcCompare.html`
screen (Refs #809 / #1327) has a separate BFF (`api/testcasescompare/index.php`)
and is unaffected.

## Fix

Committed on branch `fix/issue-1332`, commit `9fbcf3533`:

```php
$gui->tc_versions = $tcaseMgr->get_by_id($argsObj->tcase_id);
$gui->tcaseName = isset($gui->tc_versions[0]['name']) ? $gui->tc_versions[0]['name'] : '';
```

The guard means "no testcase selected" now degrades gracefully to an empty
`tcaseName` (the compare subtitle simply shows an empty label slot), matching
the suggested fix in the issue. Valid requests behave identically:
`get_by_id()` returns the full row map for an existing testcase, so the guard
never trips. No new user-facing strings → no locale-bundle additions.

**Rejected alternatives:** (a) early-return / `displayInfo()` "select a test
case" page — a behavior change beyond a log-noise bug, and the legacy page
already renders the selection form fine; (b) guarding `buildDiff()` intake —
that is a *separate* code path (`compare_selected_versions=1` submitted with no
testcase triggers `foreach() argument must be of type array|object, null given`
at Line 157), filed as a distinct bug (see below) so this fix stays minimal and
scoped to the reported Line 121 warnings.

**Related issue filed during this run:** #1333 — same repro family (null
`get_by_id` when no testcase selected) but a different crash site
(`buildDiff()` Line 157 foreach on null), reachable only via crafted
`compare_selected_versions=1&testcase_id=0` requests. Left open by design per
the one-bug-per-run rule.

## Files Changed

- `lib/testcases/tcCompareVersions.php` — `initializeGUI()`, line 121: `isset(...)` guard with `''` fallback.
- `tmp/TLU_Test_Cases.md` — regression suite "Regression — Issue #1332", 7/7 PASS.

## Verification

All checks on branch `fix/issue-1332`, PHP 8.3, MySQL 11.4 `testlink`
fresh-import schema:

- Repro `?tproject_id=1` (no testcase_id), pre-fix = 2 E_WARNING rows at Line
  121 → post-fix HTTP 200, page renders "Testcase versions compare page" +
  empty version-selection form, **0** new `log_level IN (2,3)` rows.
- Happy path: 2-version testcase fixture (node 3, tcversions 4/5), open
  `?tproject_id=1&testcase_id=3` → HTTP 200, both version radios (1 and 2)
  rendered, subtitle populated, 0 new warnings.
- TEXT compare path `testcase_id=3&version_left=1&version_right=2&compare_selected_versions=1`
  → HTTP 200, diff rendered, 0 new warnings.
- Headless-Chrome browser open of the no-testcase URL → no JS/console errors
  beyond a pre-existing generic "Deprecated feature used" notice; screenshot
  stored as `tmp/wiki-repo/tcCompareVersions-no-tcase-id-fixed.png`.
- Event Viewer after the whole pass → only INFO/AUDIT (`audit_login_succeeded`,
  log_level 16); `SELECT COUNT(*) FROM events WHERE log_level IN (2,3)` = 0.

Regression suite: `tmp/TLU_Test_Cases.md` — "Regression — Issue #1332", 7/7 PASS.

Address: `Fixes #1332` (verified + pushed on `fix/issue-1332`).