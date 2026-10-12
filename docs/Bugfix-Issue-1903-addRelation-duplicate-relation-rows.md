# Bugfix Issue #1903 — addRelation() wrote duplicate relation rows

`testcase::addRelation()` inserted a **duplicate** row into `testcase_relations` on
every call when it was given **test-case ids**, and its `relation_already_exists`
branch was unreachable for those callers.

## Symptom

Calling `addRelation($tcaseSrc, $tcaseDst, $type, $authorId)` twice with the same
arguments:

- both calls returned `array('status_ok' => true, 'msg' => 'relation_added')`;
- two identical rows were written, e.g. `(source_id=12, destination_id=15, type=1)`
  twice;
- no PHP error or warning — silent data corruption.

The duplicate rows are then rendered twice by `tcView` / the legacy relations
blocks, and the "relation already exists" feedback could never appear.

## Reproducing it by hand

Fixture/reproducer: `php tmp/repro_1903.php` (isolated project `Repro 1903`,
`R1903-SOURCE` and `R1903-DEST` active), which calls the manager twice and dumps
the rows.

Before the fix:

```
call1: {"status_ok":true,"msg":"relation_added"}
call2: {"status_ok":true,"msg":"relation_added"}
rows=2
  row id=1 source=4 dest=7 type=1
  row id=2 source=4 dest=7 type=1
```

Via the BFF (session `admin/admin`, same-origin header):

```
curl -b cookie.txt -X POST \
  'http://localhost:8082/api/testcases/index.php?action=add_relation' \
  -H 'Content-Type: application/json' -H 'Origin: http://localhost:8082' \
  -d '{"tcase_id":59,"tcversion_id":60,"relation_type":"3_source","relation_destination_tcase":"2"}'
```

Before the fix both calls returned HTTP 200 `feedback_code=relation_added` and wrote
two rows; after the fix the second call returns **HTTP 422** with
`feedback_code=relation_already_exists`.

In the modern viewer (`gui/templates/testcases/tcView.html`) a repeated add now shows
the localized sentence **"The relation to 2 already exists"** instead of a second
success message.

## Root cause

`addRelation()` (`lib/functions/testcase.class.php:8189`) accepts a test-case id and
resolves it to the concrete **tcversion** ids before writing:

```php
$tcvSet = $this->get_last_active_version(
    array($safeID['s'], $safeID['d']), null,
    array('access_key' => 'testcase_id'));
// guard added by #1864 ...
$safeID['s'] = intval($tcvSet[$safeID['s']]['tcversion_id']);   // :8222
$safeID['d'] = intval($tcvSet[$safeID['d']]['tcversion_id']);   // :8223
```

but the existence check passed the **raw arguments**:

```php
if( !$this->relationExits($source_id, $destination_id, $type_id) )   // :8227 (old)
```

while the INSERT stored the resolved ids:

```php
" values ({$safeID['s']},{$safeID['d']}, $type_id, $author_id, $time)"   // :8234
```

`relationExits()` (`:8138-8153`) filters `source_id`/`destination_id` by the values it
is given (a symmetric `OR`), so asking about the **test-case id pair** `(3,6)` could
never match the stored **tcversion id pair** `(4,7)`. Every call therefore fell into
the INSERT branch and `relation_already_exists` (`:8241`) was dead for every
test-case-id caller.

### Why it breaks NOW

`git blame` points at commit `cb365cd5e9` (2018-09-27, "FEATURE TICKET 5234: Relation
feature for Testcase"): it *added* the test-case→tcversion resolution and the
`$safeID` overwrite but left the pre-existing check call `relationExits($source_id,
$destination_id, $type_id)` untouched. Before that commit callers passed version ids,
so the check and the write were consistent. Latent since 2018.

### Blast radius

Both real call sites pass **test-case ids**:

| caller | file:line | path |
|---|---|---|
| legacy controller | `lib/testcases/testcaseCommands.class.php:1293` | `tcEdit.php ?doAddRelation` |
| modern BFF | `api/testcases/index.php:3219` | `POST ?action=add_relation` |

Storage/display paths key on tcversion ids: `getTCVersionRelations`
(`lib/functions/testcase.class.php:7995-7996`), `getTCVRelationsRaw` (`:8063-8069`),
`getRelations` (`:7915`).

## The fix

Check the ids that are actually written — one line (plus comment) in
`lib/functions/testcase.class.php:8227`:

```diff
-    // check if exists before trying to add
-    if( !$this->relationExits($source_id, $destination_id, $type_id) ) {
+    // check if exists before trying to add.
+    // Use the resolved ids ($safeID): when a test case id was supplied they
+    // hold the latest active tcversion ids, which is what the INSERT below
+    // stores. Checking the raw $source_id/$destination_id (test case ids)
+    // never matched an existing row, so duplicates were written on every
+    // call and the 'relation_already_exists' branch was dead (#1903).
+    if( !$this->relationExits($safeID['s'], $safeID['d'], $type_id) ) {
```

- For test-case-id callers `$safeID` now holds the resolved tcversion pair, matching
  the stored rows ⇒ the second call returns `relation_already_exists` and writes
  nothing.
- For direct tcversion-id callers the resolution block is skipped so `$safeID`
  equals the raw arguments ⇒ behaviour unchanged.
- The symmetric `OR` in `relationExits()` is preserved, so the
  `"<type>_destination"` source/destination swap still detects the reverse pair.

Alternatives rejected: changing the INSERT to store test-case ids would move the
mismatch to the display paths (which query by tcversion id) and break version-scoped
relations; adding a unique index would surface a DB error instead of the friendly
feedback code and would not fix the dead branch.

### i18n

No new user-facing strings were introduced. The `relation_already_exists` code was
already fully wired: `tcView.html` maps it via `REL_I18N` (`:1654`) to
`tcview.relationAlreadyExists`, present in all 10 bundles; the legacy `lang_get()`
path has `$TLS_relation_already_exists` in `locale/*/strings.txt`.

## Verification

| # | Check | Result |
|---|---|---|
| 1 | `php tmp/repro_1903.php` (2 identical calls) | **PASS** — 2nd `relation_already_exists`, 1 row |
| 2 | `php tmp/verify_1903.php` matrix (identical / different type / swapped / symmetric) | **PASS** — 7/7 |
| 3 | BFF repeated add (curl, admin session) | **PASS** — 1st HTTP 200 `relation_added`, 2nd HTTP 422 `relation_already_exists`, 1 row |
| 4 | Browser `tcView.html?tcase_id=59`, repeat "is related to" → `2` | **PASS** — localized "The relation to 2 already exists"; relations count stayed (1) |
| 5 | `events` after the whole pass | **PASS** — 0 rows with `log_level<=2` |
| 6 | `php -l lib/functions/testcase.class.php` | **PASS** — clean |
| 7 | `bash ai/verify_test_suites.sh` (TLU_REQUIRE_SUITE="Issue #1903") | **PASS** — 7 PASS / 0 FAIL |

Regression suite: `## Regression — Issue #1903` in `tmp/TLU_Test_Cases.md` (9/9 for
this fix). Screenshot: `docs/screenshots/issue-1903-relation-already-exists.png`.

## Files changed

| file | change |
|---|---|
| `lib/functions/testcase.class.php` | existence check now uses `$safeID['s']/$safeID['d']` (`:8227`) |
| `CHANGELOG` | `[KEY BUGFIX] - #1903` |

## Related

While verifying, a **separate pre-existing defect** on the other advertised input
mode (passing tcversion ids directly: `get_by_id($destination_id, LATEST_VERSION)`
resolves the version id as a test-case id → 2 × E_WARNING + `related_tcase_not_open`)
was confirmed and filed as **#1904** — not fixed here.
