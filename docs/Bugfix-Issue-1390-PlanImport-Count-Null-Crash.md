# Issue 1390 — planImport (legacy) HTTP 500: `count(null)` crash on import XML with a missing test-case version

**Issue:** [#1390](https://github.com/sebiboga/testlink-upgraded/issues/1390)
**Branch:** `fix/issue-1390`
**Status:** VERIFIED-FIXED (2026-09-11)

## Symptom

Submitting the legacy import form `lib/plan/planImport.php?tplan_id=<id>` with a valid
import file crashes with **HTTP 500, empty body** whenever the file contains a link whose
test case EXISTS on the test project but the requested **version does not**. The browser
shows a failed navigation; the PHP server log shows:

```
PHP Fatal error:  Uncaught TypeError: count(): Argument #1 ($value) must be of type Countable|array, null given in .../lib/plan/planImport.php:372
Stack trace:
#0 .../lib/plan/planImport.php(91): importTestPlanLinksFromXML()
#1 {main}
```

## Repro steps

1. Log in as admin.
2. Fixture `tmp/fixtures_pimp.php`: tproject **13 PIMP**, plan **24 PIMP-Plan**, TCs
   PIMP-1..3 (version 1 each), platform PIMP-Android.
3. `POST /lib/plan/planImport.php?tplan_id=24` with `importType=XML&uploadFile=1` and
   `uploadedFile` = `tmp/pimp_xml/repro1390.xml` (2 links: Login extid 1 v1 [exists];
   Login extid 1 v99 [does NOT exist]).
4. Before fix: HTTP 500, empty body, fatal TypeError at `lib/plan/planImport.php:372`.
   After fix: HTTP 200 with `Test Case with external id 1 version 99 does not exist on
   Test Project PIMP`.

**Expected:** graceful per-link feedback; links with missing versions reported as not
imported, never a server crash.

## Root cause

`lib/plan/planImport.php:369-372`:

```php
$dummy = $tcaseMgr->get_basic_info($tcaseSet[$externalID], array('number' => $version));
if( count($dummy) > 0 )
```

Chain:

1. `lib/plan/planImport.php:366` — `isset($tcaseSet[$externalID])` passes for any test
   case present on the project, regardless of version.
2. `get_basic_info()` (`lib/functions/testcase.class.php:5740`) runs
   `SELECT ... WHERE TCV.version = <n> AND NH_TCASE.id = <id>` and returns
   `$this->db->get_recordset($sql)`.
3. `get_recordset()` (`lib/functions/database.class.php:776`) initializes `$output = null`
   and only builds an array while rows are fetched — **zero rows ⇒ returns `null`**.
4. `count(null)` is a **TypeError on PHP 8.x** (silently coerced to `0` on PHP < 8). The
   intended `else` branch at line 474 (`tcversion_doesnot_exist`) was unreachable.

`get_basic_info` has 15 call sites; the others guard the result with `!is_null()`
(e.g. `testcase.class.php:6762`) or stay inside a version-exists context. Only the
legacy `planImport.php:372` and its modern twin `api/planimport/index.php:208` feed the
result straight into `count()` (the latter is masked by #1389; filed as #1414).

## Fix (minimal)

`lib/plan/planImport.php:372` — honor the low-level contract (`null` = version missing):

```php
if( !is_null($dummy) && count($dummy) > 0 )
```

`count()` is only invoked on a non-null array; an empty result falls through to the same
`else` branch. No other files touched. UI strings, i18n bundles, rights checks unchanged.

## Verification (regression matrix, all PASS on localhost:8082)

Fixture reset before the run (`php tmp/fixtures_pimp.php`), plan ref id **24**.

- **create (existing TC + existing version):** regr1390.xml link 1 → `Test Case with
  external id 1 version 1 has been linked to Test Plan for Platform PIMP-Android`; DB row
  tcversion 16 / platform 2 (1 link total).
- **missing version (the crash case):** link Login v99 → `Test Case with external id 1
  version 99 does not exist on Test Project PIMP`, no crash, no link row.
- **non-existent TC:** externalid 777 → `Attention!! - Can not find test case identified
  by 777`; no row.
- **no platform element on a platform-linked plan:** link #4 → `Test case link #4 has no
  platform element` / Not imported.
- **idempotency (re-import same file):** link 1 → `... was already linked to Test Plan,
  only execution order has been updated.`; link count stays 1, `node_order` 10 preserved.
- **browser:** upload + submit renders the full import report (4 rows) with no console
  errors. HTTPS 200. Screenshot:
  `tmp/wiki-repo/planImport-fixed-issue1390.png`.
- **hygiene:** `events` table — only AUDIT(16) rows from the imports plus the 2×
  `E_WARNING "Undefined array key 1"` template rows from a **pre-existing** legacy render
  quirk (new bug **#1413**, filed with `bug` label; not part of this crash fix), and
  `E_WARNING planImport.php:149` only for a non-existent `tplan_id` (invalid input guard,
  pre-existing). No new Error entries from the fixed path itself.

## Discoveries while testing

- **New bug #1413 (filed, label `bug`):** once the crash is fixed, a mixed import that
  contains a missing TC/version logs 2× `E_WARNING "Undefined array key 1` at compiled
  `planImport.tpl.php:105`. `lib/plan/planImport.php:476` and `:481` build single-element
  messages `$msg[] = array(sprintf(...))` while the template unconditionally prints
  `$result[1]`. Pre-existing (the non-existent-TC branch was reachable before this fix);
  surfaced now for the missing-version branch. Legacy screen is slated for deletion with
  the modern path (#1389) — left to the fix-bug factory.
- **New bug #1414 (filed, label `bug`):** the modern BFF `api/planimport/index.php:208`
  contains the **identical** unguarded `count($dummy)` on `get_basic_info()` (same
  `TypeError` once its #1389 parse blocker is fixed). Left for the fix-bug factory; not
  touched in this run per one-issue scope.
- The fixture must be re-run after every fresh-DB import (ids move); `id` values in this
  report are from the run used to verify.

## Evidence

Commit range: `(fix)`, `(regression suite)`, `(docs + wiki)` on branch `fix/issue-1390`.
Wiki page: `tmp/wiki-repo/Bugfix-Issue-1390-PlanImport-Count-Null-Crash.md`
(screenshot `planImport-fixed-issue1390.png`).