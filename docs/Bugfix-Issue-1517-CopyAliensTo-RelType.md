# Bugfix — Issue #1517: `copyAliensTo` passes the audit array as alien relation type → aliens not copied on `create_new_version`

## Problem

Creating a new version of a test case (modern BFF `POST
/api/testcases/?action=create_version`, legacy `tcEdit`, importer) never copies
its **alien (related issue/task) rows** to the new version — and, on the BFF
path, the call exploded into a PHP fatal error (`HTTP 500`).

- First `create_version` on a tracked-with-aliens testcase → **HTTP 500**,
  server error:
  `PHP Fatal error: testcase::addAliens tpr cannot be 0 in
  testcase.class.php:10327`, stack `addAliens ← copyAliensTo (10584) ←
  create_new_version (2468) ← api/testcases/index.php:2595`.
- A second identical request returns 200 — but **no** `testcase_aliens` row is
  ever created for the new version (guards skip the broken copy silently once
  the source has no more aliens).
- Expected: every new version must receive a copy of the source version's
  aliens with their original **relation types** preserved.

## Repro steps (measured)

Environment: http://localhost:8082 (PHP built-in server, docroot = repo root),
MariaDB `testlink`, login admin/admin. Fresh-import DB; fixture via SQL:
testproject 1, suite node 2, testcase node 3, tcversion node 4 (`tcversions.id=4`,
v1), alien `aliens.id=1`, `testcase_aliens (1,3,4,1,1)`, then
`testcase_aliens (1,3,4,2,2)` for a second alien with `relation_type=2`.

1. `curl -X POST -c /tmp/tl_cookies.txt -H "X-Requested-With: XMLHttpRequest"
   -d 'login=admin&password=admin' http://localhost:8082/api/auth/login`
   → `{"status":"ok"}`.
2. `curl -b /tmp/tl_cookies.txt -H "X-Requested-With: XMLHttpRequest"
   -d '{"tcase_id":3}' http://localhost:8082/api/testcases/index.php?action=create_version`
   → pre-fix **HTTP 500**; server log shows the fatal above.
3. Pre-fix `SELECT tcversion_id,alien_id,relation_type FROM testcase_aliens
   WHERE testcase_id=3` → **no row** for the new version.

**Expected:** the new tcversion row is created and `testcase_aliens` receives
one row per alien keeping its `relation_type`.

## Root cause

Chain (each hop = `file:line`):

1. BFF `create_version`: `api/testcases/index.php:2595` → `$tcaseMgr->create_new_version($tcaseId)` → `testcase::create_new_version` `lib/functions/testcase.class.php:2463` → `$this->copyAliensTo(...)` (testcase.class.php:10584, feature landed in commit `34499948d`).
2. **Bug A:** `copyAliensTo` called `addAliens($cedula, array_keys($sourceIT), $adt, ...)` for every alien: it passed the **audit-context array `$adt`** as the 3rd scalar argument `$alienRelType` — `addAliens` signature is `addAliens($idCard, $idSet, $alienRelType, $audit = null)` (testcase.class.php:10306). `array_keys()` also discarded each alien's `relation_type`, so even after a type fix each alien would have been copied with an overwritten/array relation type.
3. **Bug B (BFF-only):** line ~10581 `$cedula->tproject_id = $this->tproject_id;`. Legacy callers set the testproject first (`tcEdit.php:487 tcaseMgr->setTestProject(...)`, `tcImport.php:90/205`), but the BFF constructors `new testcase($db)` **without** `setTestProject()` at `api/testcases/index.php:453`, `api/testcasesedit/index.php:135`, `api/testcasesimport/index.php:110` leave `$tproject_id = null/0`. `addAliens` then throws `if($tpr == 0) ... 'tpr cannot be 0'` (testcase.class.php:10327).

**Why it broke now:** the copy-aliens feature (`copyAliensTo`, landed in `34499948d`) was written against the legacy flow and never exercised through the BFF manager path and never against multiple relation types.

**Blast radius:** `create_new_version` is called by the modern BFF
(`api/testcases/`, `api/testcasesedit/` — edit version applies on new version,
`api/testcasesimport/`), legacy `tcEdit.php` and `tcImport.php`. Every new
version created from a version holding aliens was either 500ing (BFF) or
dropping aliens (legacy).

## Fix (approach, why this method)

`lib/functions/testcase.class.php` — two edits:

1. **`copyAliensTo`** — resolve the destination testproject instead of trusting
   the possibly-empty BFF field, then copy **per alien** with its own relation
   type and the audit array in its proper slot:
   ```php
   $tpr = intval($this->tproject_id) > 0 ? intval($this->tproject_id)
                                         : intval($this->get_testproject($dest['id']));
   $cedula->tproject_id = $tpr;
   ...
   foreach( $sourceIT as $alienID => $elem ) {
       $this->addAliens($cedula, array($alienID), $elem['relation_type'], $adt);
   }
   ```
   - `get_testproject($dest['id'])` returns the numeric testproject id given
     the testcase node id (verified: returns `'1'`); it reuses existing
     machinery (`testplan*_testcases` / project association) instead of adding
     a new query.
   - Iterating `$sourceIT` as `key → ['relation_type' => ..]` instead of
     `array_keys()` keeps every alien's original relation type; the audit
     array `$adt` is now passed as the 4th parameter (its real slot).
   - Rejected alternatives: (a) whitelist `relation_type=1` for everyone —
     loses legitimate type diversity (verified: rel_types 1 and 2 coexist);
     (b) passing `$adt` still but casting to int — nonsense value, rejected;
     (c) looping `addAliens` with the whole set and one type — same defect as
     (a).

2. **`addAliens`** — harden the issue-tracker hook so a project with **no
   tracker** cannot throw: line ~10372 became
   `if ( !is_null($repo) && method_exists($repo, 'addLink') )`. Previously
   `method_exists(null, 'addLink')` threw a PHP 8 `TypeError`, 500ing the
   alien insert on every tracker-less project. This surfaced during
   verification of #1517 and is tracked separately as **#1520**
   (https://github.com/sebiboga/testlink-upgraded/issues/1520).

Why `copyAliensTo` (core) and not the API layer: `create_new_version` is the
single writer shared by all entry points; fixing the core repairs BFF, legacy
and importer at once, matching how #1515 fixed the delete path centrally.

## Files changed

| File | Change |
|---|---|
| `lib/functions/testcase.class.php` | `copyAliensTo`: tproject resolution + per-alien relation-type copy with correct audit slot; `addAliens`: tracker-null guard (Refs #1517, #1520) |

## Verification matrix (all PASS, measured)

| # | Case | Result |
|---|---|---|
| 1 | BFF `create_version` on source with 1 alien (rel 1) | HTTP 200, new version 8; `testcase_aliens (1,3,8,1,1)` copied with rel 1 |
| 2 | `create_version` again (source now a version with aliens) | HTTP 200; alien copied to version 9 |
| 3 | Source has 2 aliens, rel_types 1 **and** 2 | Both copied; relation types preserved (`(1,3,10,1,1)` + `(1,3,10,2,2)`) |
| 4 | Testcase with **no** aliens | HTTP 200, no exception, 0 alien rows on the new version |
| 5 | Browser E2E: testSpec.html → TC-1 → Create New Version → confirm modal | Tree shows "Ver. 5 (current)" (node 14); `testcase_aliens (1,3,14,1,1)+(1,3,14,2,2)`; console has no Error/Warning |
| 6 | Event Viewer (`events` table) | only 2 LOGIN audit rows (`log_level=16`), 0 rows `log_level IN (1,2)` |
| 7 | `php -l lib/functions/testcase.class.php` | no syntax errors |

Proof data (branch `fix/issue-1517-copyaliens-reltype`):
- `create_version {"tcase_id":3}` → `{"status":"ok","tcversion_id":10}` then
  `SELECT tcversion_id,alien_id,relation_type FROM testcase_aliens WHERE
  testcase_id=3 ORDER BY tcversion_id` shows both aliens with rel 1 and 2 on
  versions 8, 9, 10 (and 14 via UI).
- Pre-fix traceback recorded in `tmp/php_server.log` (fatal at 08:04:44);
  post-fix the same flow leaves the error log empty.

![Create New Version copies aliens with relation types](
docs/screenshots/issue-1517-aliens-copied.png)

## Related issue (discovered, folded in)

While verifying, a tracker-less project still 500ed: `addAliens` called
`method_exists(null, 'addLink')` → PHP 8 `TypeError`. Filed as **#1520** with
the `bug` label and guard-fixed in the same change (minimal one-line guard);
repro and evidence live on that issue.

## Docs / wiki

This page mirrors `tmp/wiki-repo/Bugfix-Issue-1517-CopyAliensTo-RelType.md`
(screenshot included above, committed under `docs/screenshots/`).