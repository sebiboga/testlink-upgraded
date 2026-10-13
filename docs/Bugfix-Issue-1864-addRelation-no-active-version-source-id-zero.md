# Bugfix-Issue-1864-addRelation-no-active-version-source-id-zero (#1864)

## Symptom

`testcase::addRelation()` reported success and silently wrote a **corrupt** row into
`testcase_relations` whenever the source test case had **no active version** (all its
`tcversions` rows `active=0`):

- return value `array('status_ok' => true, 'msg' => 'relation_added')`
- inserted row `(source_id=0, destination_id=<latest active version of the destination>, ...)`
- two `E_WARNING` rows per call in the Event Viewer (`events`), both from
  `lib/functions/testcase.class.php:8211`.

Reachable from the legacy controller `lib/testcases/tcEdit.php` (`doAddRelation` →
`testcaseCommands::doAddRelation`) **and** the modern BFF `POST /api/testcases/index.php?action=add_relation`.

## Reproducing it by hand

Fixture: `php tmp/fixtures_1859.php` (isolated project `TC Edit 1859`) creates
`TC1859-NOACTIVE` (test case **3**, its only version **4** deactivated) and
`TC1859-ACTIVE` (test case 6, version 7). Optionally add a second active test case.

```
php tmp/repro_1864.php 3 6
```

Before the fix:

```
return=array ( 'status_ok' => true, 'msg' => 'relation_added', )
relations_before=0 relations_after=1
zero_id_rows=array ( 0 => array ( 'id' => '1', 'source_id' => '0',
                       'destination_id' => '7', 'relation_type' => '1', ... ) )
```

and `events` gained:

```
2  2  E_WARNING\nUndefined array key 3 - .../lib/functions/testcase.class.php - Line 8211
3  2  E_WARNING\nTrying to access array offset on null - .../lib/functions/testcase.class.php - Line 8211
```

Via the BFF (session `admin/admin`, same-origin header):

```
curl -b cookie.txt -X POST \
  'http://localhost:8082/api/testcases/index.php?action=add_relation' \
  -H 'Content-Type: application/json' -H 'Origin: http://localhost:8082' \
  -d '{"tcase_id":3,"tcversion_id":4,"relation_type":"1_source","relation_destination_tcase":"2"}'
```

Before the fix this returned HTTP 200 with `feedback_code=relation_added` and wrote the
`source_id=0` row; after the fix it returns **HTTP 422** with
`feedback_code=related_tcase_has_no_active_version` and writes nothing.

## Root cause

`addRelation()` (`lib/functions/testcase.class.php:8189`) resolves a test-case source to its
concrete version ids:

```php
$tcvSet = $this->get_last_active_version(
    array($safeID['s'], $safeID['d']), null,
    array('access_key' => 'testcase_id'));
$safeID['s'] = intval($tcvSet[$safeID['s']]['tcversion_id']);   // :8211
$safeID['d'] = intval($tcvSet[$safeID['d']]['tcversion_id']);   // :8212
```

`get_last_active_version()` (`:6182`) joins `tcversions` with `TCV.active=1` (`:6212`); with no
active row the map stays empty and `fetchRowsIntoMap()` (`lib/functions/database.class.php`)
returns its un-initialised `$items = null`. The method then returns **`null`** (`:6224` guard is
false → post-processing skipped → `:6304 return $recordset`).

Indexing that `null` at `:8211-8212` emits the two warnings and `intval(null) = 0`, which flows
straight into `INSERT INTO testcase_relations (source_id, destination_id, ...)`. The open-state
check at `:8218` only validates the **destination**, so it never catches the missing source.

### Why it breaks NOW

Latent defect inherited from 1.9.20; PHP 8 promoted the silent PHP 7 null-access to warnings that
`watchPHPErrors` persists into `events`. It belongs to the same `get_last_active_version()` null
family as #1859, #1861, #1862 and #1863.

### Blast radius

`grep -n "addRelation(" ` → 3 call sites, all passing **test-case** ids:

| caller | file:line | path |
|---|---|---|
| legacy controller | `lib/testcases/testcaseCommands.class.php:1293` | `tcEdit.php ?doAddRelation` |
| modern BFF | `api/testcases/index.php:3219` | `POST ?action=add_relation` |
| commented-out copy | `lib/functions/testcase.class.php:9010` | inactive |

Corrupt `source_id=0` rows are then read by `tcView` and the legacy relations blocks.

## The fix

A null-safe guard **before** `$safeID` is rewritten, in `addRelation()`:

```diff
   $tcvSet = $this->get_last_active_version(
     array($safeID['s'],$safeID['d']), null,
     array('access_key' => 'testcase_id'));

+  // get_last_active_version() returns NULL when a test case has no active
+  // tcversions row (or does not exist). Indexing it would emit 2 warnings
+  // and cast the missing entries to 0, inserting a relation with
+  // source_id/destination_id = 0 (#1864).
+  if( !is_array($tcvSet) ||
+      !isset($tcvSet[$safeID['s']]['tcversion_id']) ||
+      !isset($tcvSet[$safeID['d']]['tcversion_id']) ) {
+    return array('status_ok' => false,
+                 'msg' => 'related_tcase_has_no_active_version');
+  }
+
   // Overwrite
   $safeID['s'] = intval($tcvSet[$safeID['s']]['tcversion_id']);
   $safeID['d'] = intval($tcvSet[$safeID['d']]['tcversion_id']);
```

Alternatives rejected: `intval($tcvSet[...] ?? 0)` keeps the silent `source_id=0` corruption; a
BFF-only pre-check would leave the legacy `doAddRelation` path broken (the issue scopes the
**class** method). The guard checks both sides because either test case can be the source after
the `_destination` relation-type swap.

### i18n wiring

Because the new code is user-facing, it is localized in both renderers:

- modern relation block: `gui/templates/testcases/tcView.html` — `REL_I18N` maps
  `related_tcase_has_no_active_version` → `tcview.relatedTcaseHasNoActiveVersion`
- `gui/templates/i18n/*.json` — the key exists in **all 10** bundles (verified by
  `ai/verify_i18n_coverage.sh`), so `TLi18n` never falls back to the raw key.
- legacy `lang_get()` path (`testcaseCommands::doAddRelation` does
  `sprintf(lang_get($ret['msg']), ...)`): `$TLS_related_tcase_has_no_active_version` added to
  `locale/en_GB/strings.txt` (the fallback locale) and the locales that already carry the
  relation family (`fr_FR`, `pt_PT`, `pt_BR`, `ja_JP`).

## Verification

| # | Check | Result |
|---|---|---|
| 1 | `php tmp/repro_1864.php 3 6` (no-active source) | **PASS** — `status_ok=false`, `msg=related_tcase_has_no_active_version`, no row, `zero_id_rows=NULL` |
| 2 | `events` after case 1 | **PASS** — 0 new rows (the 2 E_WARNINGs are gone) |
| 3 | active→active `addRelation(6,9)` | **PASS** — `relation_added`, row `(source_id=7,destination_id=10)` |
| 4 | no-active **destination** `addRelation(6,3)` | **PASS** — `related_tcase_has_no_active_version`, no row |
| 5 | frozen destination (`is_open=0`) | **PASS** — `related_tcase_not_open` unchanged |
| 6 | BFF no-active source | **PASS** — HTTP 422 `feedback_code=related_tcase_has_no_active_version` |
| 7 | BFF positive path | **PASS** — HTTP 200 `relation_added`, row written |
| 8 | `SELECT COUNT(*) FROM events WHERE log_level=2` after the whole pass | **PASS** — 0 |
| 9 | `python3 -m json.tool` each bundle + `ai/verify_i18n_coverage.sh` | **PASS** — 9 bundles, 0 missing |
| 10 | `php -l lib/functions/testcase.class.php` | **PASS** — clean |

Regression suite: `## Regression — Issue #1864` in `tmp/TLU_Test_Cases.md` (12/12 PASS, gate
7 PASS / 0 FAIL).

## Files changed

| file | change |
|---|---|
| `lib/functions/testcase.class.php` | null-safe guard in `addRelation()` (`:8206-8219`) |
| `gui/templates/testcases/tcView.html` | `REL_I18N` mapping for the new feedback code |
| `gui/templates/i18n/*.json` (10) | `tcview.relatedTcaseHasNoActiveVersion` in all bundles |
| `locale/{en_GB,fr_FR,pt_PT,pt_BR,ja_JP}/strings.txt` | `$TLS_related_tcase_has_no_active_version` |
| `CHANGELOG` | `[KEY BUGFIX] - #1864` |

## Related

While verifying, a **pre-existing, unrelated** duplicate-insert defect in the same method was
found (`relationExits()` compares argument ids while the row stores version ids) and filed
separately as **#1903** — not fixed in this change.

## Re-verification (2026-10-13, fresh DB)

The fix landed on the default branch (`21c533ed7`) and the suite (`227ad82ac`)
but the issue had been left OPEN. Re-verified against a freshly imported DB
(default branch `70632dc24`, 0 test cases):

| # | Check | Result |
|---|---|---|
| 1 | `addRelation(3,6,1,1)` no-active source | **PASS** — `related_tcase_has_no_active_version`, no row, 0 warnings |
| 2 | `addRelation(6,9,1,1)` active→active | **PASS** — `relation_added`, 1 row |
| 3 | duplicate `addRelation(6,9,1,1)` | **PASS** — `relation_already_exists`, no new row |
| 4 | frozen destination `addRelation(6,9,2,1)` | **PASS** — `related_tcase_not_open`, no row |
| 5 | BFF no-active source | **PASS** — HTTP 422 `related_tcase_has_no_active_version` |
| 6 | BFF active source | **PASS** — HTTP 200 `relation_added` |
| 7 | `events` log_level=2 after the pass | **PASS** — 0 |
| 8 | `testcase_relations` zero-id rows | **PASS** — 0 |

No code change was required; the landed guard behaves exactly as specified.
Re-verification suite: `## Regression — Issue #1864 re-verification` in
`tmp/TLU_Test_Cases.md` (9/9 PASS, gate 7 PASS / 0 FAIL).
