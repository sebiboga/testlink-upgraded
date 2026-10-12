# Bugfix Issue #1904 — addRelation() tcversion-id input mode is dead

`testcase::addRelation()` documents that `$source_id`/`$destination_id` may be
"ID of source test case version **or test case**", but calling it with
**tcversion ids** never inserted a relation: the destination open-guard resolved
the version id as a **test-case id**, found nothing, emitted 2 `E_WARNING`s
(+ 1 PHP 8.3 `false`-to-array deprecation) and returned
`related_tcase_not_open` with zero rows written.

## Symptom

Calling `addRelation($tcversionSrc, $tcversionDst, $type, $authorId)` with the
two **tcversion** ids of two active test cases:

- returned `array('status_ok' => false, 'msg' => 'related_tcase_not_open')`;
- inserted **no** row in `testcase_relations`;
- logged three PHP diagnostics:
  ```
  E_WARNING  Trying to access array offset on false ... testcase.class.php:2977
  Deprecated Automatic conversion of false to array is deprecated ...:2992  (PHP 8.3)
  E_WARNING  Undefined array key "is_open"            ... testcase.class.php:8235
  ```

The version-id input mode advertised by the docblock was effectively dead.

## Reproducing it by hand

Fixture: `php tmp/fixtures_1904.php` (project `Rel 1904`, two ACTIVE test cases,
prints `tcaseA/tcversionA/tcaseB/tcversionB`). Repro: `php tmp/repro_1904.php`.

Before the fix (version-id pair):

```
=== CASE 1: tcversion ids (the dead documented mode) ===
PHP Warning:  Trying to access array offset on false in .../testcase.class.php on line 2977
PHP Deprecated:  Automatic conversion of false to array is deprecated in ...:2992
PHP Warning:  Undefined array key "is_open" in .../testcase.class.php on line 8235
return={"status_ok":false,"msg":"related_tcase_not_open"}
rows_after_case1=0
```

The test-case-id control call worked: `relation_added`, 0 warnings, 1 row.

## Root cause

`addRelation()` (`lib/functions/testcase.class.php:8189`) accepts test-case or
tcversion ids but, at the time of the report, resolved only **test-case** ids:

```php
if( $nu['node_type_id'] == $this->node_types_descr_id['testcase'] ) {
    // resolve BOTH endpoints to latest active tcversion ids
    $safeID['s'] = intval($tcvSet[$safeID['s']]['tcversion_id']);
    $safeID['d'] = intval($tcvSet[$safeID['d']]['tcversion_id']);
}
```

When the source is a tcversion id this block is skipped (by design — "Will check
only source") so the ids stay as versions, which the INSERT then stores
correctly. The failure is the **open-guard**:

```php
$dummy = $this->get_by_id($destination_id, self::LATEST_VERSION);   // :8234 (old)
if(($dummy[0]['is_open']) == 1) { ... }
```

`get_by_id()` treats its first argument as a **test-case id** (`WHERE
NHTCV.parent_id = {$id}`), so a version id matches no test case and no row is
returned. Under `self::LATEST_VERSION` with a scalar id the failure becomes
`array(false)` (`get_by_id` wraps `fetchFirstRow()`'s `false` at `:2918-2919`),
so the render loops inside `get_by_id` produce the `:2977`/`:2992` diagnostics
and the guard produces the `:8235` one.

### Why it breaks NOW

Not a regression — the guard has always been test-case-id-only; the docblock
(`:8176-8184`) advertises both modes. File-follows: while verifying the #1903 fix
(issue #1903), identical output with and without that fix — confirmed unrelated.

### Blast radius

Production callers of `addRelation()` (both pass test-case ids, both unaffected):

| caller | file:line | path |
|---|---|---|
| legacy controller | `lib/testcases/testcaseCommands.class.php:1293` | `tcEdit.php ?doAddRelation` |
| modern BFF | `api/testcases/index.php:3219` | `POST ?action=add_relation` |

The same `get_by_id($id, LATEST_VERSION)` shape appears in `getRelations()`
(`:7904`, `:7939`) but its inputs are test-case ids — out of scope.

## The fix

Branch the open-guard on the **destination node type** (`lib/functions/
testcase.class.php:8207` ff.):

- **test-case id** → the legacy `get_by_id($destination_id, LATEST_VERSION)`
  check, unchanged (now null-safe with `is_array()` guards so an absent test
  case is rejected without warnings);
- **tcversion id** → `SELECT is_open FROM tcversions WHERE id = $safeID['d']` —
  the exact version row the INSERT stores (this revives the documented mode);
- **anything else / missing** (id not present in `nodes_hierarchy`) → clean
  `related_tcase_not_open` with **no** `get_by_id()` call, hence no warnings.

The destination node type is read once before the resolution block:

```php
$destNodeType = null;
$sqlDest = " SELECT node_type_id ... WHERE id = {$safeID['d']}";
$nuDest = current((array)$this->db->get_recordset($sqlDest));
if( !empty($nuDest) ) { $destNodeType = intval($nuDest['node_type_id']); }
```

The source-guard is also hardened with `!empty($nu)` so a missing source id no
longer warns either.

Alternatives rejected: fixing `get_by_id()` alone would have silenced the two
internal diagnostics but left the semantic bug (version id fed as a test-case
id); per-id full resolution (test-case→latest-active-version on each side)
would be more complete for mixed pairs but changes behaviour for the production
test-case-id callers — out of scope of a minor latent fix.

### i18n

No user-facing strings changed. `relation_added`, `related_tcase_not_open`,
`relation_already_exists` are pre-existing feedback codes already localized in
the BFF screen and legacy `locale/*/strings.txt`.

## Verification

| # | Check | Result |
|---|---|---|
| 1 | `php tmp/verify_1904.php` matrix (version/version, tcase/tcase, duplicate, `is_open=0`, missing dest) | **PASS** — 5/5, every case `warn=0` |
| 2 | BFF live (`POST /api/testcases/index.php?action=add_relation`, admin session): call #1 | **PASS** — HTTP 200 `relation_added`, 1 row |
| 3 | BFF identical call #2 | **PASS** — HTTP 422 `relation_already_exists`, still 1 row |
| 4 | Browser `tcView.html?tcase_id=<A>` | **PASS** — "RELATIONS WITH OTHER TEST CASES (1)" → "is related to REL1904-2" |
| 5 | `events` after the pass | **PASS** — 0 new Error/Warning rows |
| 6 | browser console | **PASS** — no JS errors (only the expected 422 resource) |
| 7 | `php -l lib/functions/testcase.class.php` | **PASS** — clean |
| 8 | `bash ai/verify_test_suites.sh` (TLU_REQUIRE_SUITE="Issue #1904") | **PASS** — 7 PASS / 0 FAIL |

Regression suite: `## Regression — Issue #1904` in `tmp/TLU_Test_Cases.md`
(10/10 rows for this fix).

## Files changed

| file | change |
|---|---|
| `lib/functions/testcase.class.php` | destination node-type lookup + open-guard branching (+37/−5) |
| `tmp/fixtures_1904.php`, `tmp/repro_1904.php`, `tmp/verify_1904.php` | fixture / repro / regression matrix |
| `tmp/TLU_Test_Cases.md` | `## Regression — Issue #1904` suite |
| `CHANGELOG` | `[KEY BUGFIX]` line for #1904 |
| `docs/screenshots/issue-1904-addRelation-tcversion-mode.png` | tcView showing the added relation |