# Issue 1414 — planImport (modern BFF) HTTP 500: `count(null)` crash at api/planimport/index.php:208 for a missing test-case version

**Issue:** [#1414](https://github.com/sebiboga/testlink-upgraded/issues/1414)
**Branch:** `fix/issue-1414`
**Status:** VERIFIED-FIXED (2026-09-11)

## Symptom

`POST /api/planimport/?action=import&tproject_id=<id>&tplan_id=<id>` with a valid import
file crashes with **HTTP 500, empty body** whenever the file contains a link whose test
case EXISTS on the test project but the requested **version does not**. PHP 8 throws:

```
TypeError: count(): Argument #1 ($value) must be of type Countable|array, null given
  at api/planimport/index.php:208
```

This is the modern-BFF twin of the legacy crash already fixed in #1390
(`lib/plan/planImport.php:372`, commit `995ca7f36`). It only became reachable once the
#1389 XML parse fix landed (the parse always failed first, masking this crash).

## Repro steps

1. Log in as admin.
2. Fixture `tmp/fixtures_pimp.php`: tproject **1 PIMP**, plan **12 PIMP-Plan**, TCs
   PIMP-1..3 (Login/Logout/Settings, version 1 each, tcversions 4/7/10), platform 1
   PIMP-Android.
3. `POST /api/planimport/?action=import&tproject_id=1&tplan_id=12` with
   `uploadedFile` = `tmp/pimp_xml/repro1390.xml` (2 links: Login extid 1 v1 [exists];
   Login extid 1 v99 [does NOT exist]).
4. Before fix: HTTP 500, empty body, fatal TypeError at `api/planimport/index.php:208`.
   After fix: HTTP 200 with `{"status":"ok", ...}` and a
   `Test Case with external id 1 version 99 does not exist on Test Project PIMP` row.

**Expected:** graceful per-link feedback; links with missing versions reported as not
imported, never a server crash.

## Root cause

`api/planimport/index.php:206-208`:

```php
$dummy = $tcaseMgr->get_basic_info($tcaseSet[$externalID], array('number' => $version));
if (count($dummy) > 0) {
```

Chain (identical to #1390):

1. `lib/plan/planImport.php:366` equivalent — `isset($tcaseSet[$externalID])` passes for
   any test case present on the project, regardless of version.
2. `get_basic_info()` (`lib/functions/testcase.class.php:5740`) runs
   `SELECT ... WHERE TCV.version = <n> AND NH_TCASE.id = <id>` and returns
   `$this->db->get_recordset($sql)`.
3. `get_recordset()` (`lib/functions/database.class.php:776`) initializes `$output = null`
   and only builds an array while rows are fetched — **zero rows ⇒ returns `null`**.
4. `count(null)` is a **TypeError on PHP 8.x** (silently coerced to `0` on PHP < 8). The
   intended `else` branch at line 216 (`tcversion_doesnot_exist`) was unreachable.

`get_basic_info` has 15 call sites; the others guard the result with `!is_null()`
(e.g. `testcase.class.php:6762`) or stay inside a version-exists context. Only the legacy
`planImport.php:372` (fixed in #1390) and its modern twin `api/planimport/index.php:208`
fed the result straight into `count()`.

## Fix (minimal)

`api/planimport/index.php:208` — honor the low-level contract (`null` = version missing):

```php
if (!is_null($dummy) && count($dummy) > 0) {
```

`count()` is only invoked on a non-null array; a `null`/empty result falls through to the
same `else` branch. Exactly the guard applied to the legacy path in `995ca7f36`. No other
files touched. UI strings, i18n bundles, rights checks unchanged.

## Verification (regression matrix, all PASS on localhost:8082)

Fixture reset before the run (`php tmp/fixtures_pimp.php`): tproject **1**, plan **12**,
platform **1**, tcversions Login **4** / Logout **7** / Settings **10**.

- **create (existing TC + existing version):** mixed.xml → `external id 1 version 1 has
  been linked to Test Plan for Platform PIMP-Android` + same for extid 2; DB rows
  tcversion 4/platform 1 (node_order 10) and tcversion 7/platform 1 (node_order 20).
- **missing version (the crash case):** repro1390.xml Login v99 → `Test Case with external
  id 1 version 99 does not exist on Test Project PIMP`, no crash, no link row.
- **non-existent TC:** regr1390.xml externalid 777 → `Attention!! - Can not find test case
  identified by 777`; no row.
- **no platform element on a platform-linked plan:** regr1390.xml link #4 → `Test case link
  #4 has no platform element` / Not imported.
- **platform not on test project:** badplat.xml → `... request platform NoSuchPlatform be
  linked ... does not exist on target Test Project.` / Not imported, no crash.
- **malformed XML:** invalid.xml → HTTP 200, `simplexml_load_file_wrapper_error`
  ("Failed to load XML") row, no crash.
- **idempotency (re-import same file):** repro1390.xml → `... was already linked to Test
  Plan, only execution order has been updated.`; no duplicate link row.
- **browser:** upload + submit on `gui/templates/plans/planImport.html?tplan_id=12`
  renders the 2-row report with `Import completed successfully.`, zero console errors.
  HTTP 200. Screenshot: `tmp/wiki-repo/planImport-fixed-issue1414.png`.
- **hygiene:** `php -l api/planimport/index.php` clean; `events` table — only AUDIT(16)
  rows from the run (`audit_testproject_created`, `audit_login_succeeded`, 2×
  `audit_tc_added_to_testplan`); no new Error/Warning entries from the fixed path.

## Discoveries while testing

- The earlier pre-fix 500 request in this run had already processed link 1 (Login v1)
  successfully before crashing on link 2 (v99), which is why the first post-fix import
  reports "already linked ... execution order updated" instead of "has been linked". This
  matches legacy behaviour and the idempotency regression case.
- No new bugs discovered while testing this run.

## Evidence

Commit range: `(fix)`, `(regression suite)`, `(docs + wiki)` on branch `fix/issue-1414`.
Wiki page: `tmp/wiki-repo/Bugfix-Issue-1414-PlanImport-BFF-Count-Null-Crash.md`
(screenshot `planImport-fixed-issue1414.png`).