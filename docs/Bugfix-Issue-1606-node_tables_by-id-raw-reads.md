# Bug fix — Issue #1606: the *id*-keyed `node_tables_by['id']` twin of the #1589 lookup was read raw at 5 sites

**Issue:** [#1606](https://github.com/sebiboga/testlink-upgraded/issues/1606)
**Fix commit:** `d806fa16f` — `fix(opencode): leftover changes from bug-fix run`
(the code change landed on the default branch in an aggregated commit; the
verification, the regression suite and this page are in `7f0adb4b1` and its
follow-ups on branch `fix/issue-1606`)
**Branch:** `fix/issue-1606`
**Status:** VERIFIED-FIXED (2026-09-27)
**Files changed:** `lib/functions/tree.class.php` (+35 / −6),
`lib/functions/testproject.class.php` (+3 / −1), `lib/functions/testplan.class.php` (+4 / −2)

## Symptom

Every row of the tree whose node type is one of the two **tableless PSEUDO
types** made a PHP 8 `E_WARNING` on *every* walk, once per row:

```
Undefined array key 9 in tree.class.php - Line 615    (tree::get_children)
Undefined array key 9 in tree.class.php - Line 1147   (tree::_get_subtree_rec)
```

`watchPHPErrors` (`lib/functions/logger.class.php:1407`, registered
unconditionally at `:1483`) routes every `E_WARNING` to `logWarningEvent()` →
the `events` table, so each of them became one **Event Viewer** WARNING row.
The counting rule is **one warning per `testcase_step` node** (measured: 2 step
rows → 2 warnings in `get_children()` **and** 2 in `get_subtree(recursive)`).

This is the *sibling* of #1589, not its duplicate: #1589 hardened the lookup by
node-type **NAME** and left the lookup by node-type **ID** untouched, so the
noise continued through every recursive tree read and through the public
`get_children()` API.

## Root cause

`tree::$node_types` (`lib/functions/tree.class.php:24-28`) maps **12** node type
ids and its own comment says *"Now contains also PSEUDO NODES => build"*:

```php
var $node_types = array( 1 => 'testproject','testsuite',
                          'testcase','tcversion','testplan',
                          'requirement_spec','requirement','req_version',
                          'testcase_step','req_revision','requirement_spec_revision',
                          'build');
```

`$node_tables_by['name']` (`:42-53`) maps a node-type name to its **row
table** and has **no entry for the two types that have no row table at all** —
`testcase_step` (9) and `build` (12); the constructor then **derives** the
id-keyed map from it:

```php
// tree.class.php:81-84
foreach($this->node_tables_by['name'] as $key => $tbl)
{
  $this->node_tables_by['id'][$this->node_descr_id[$key]] = $tbl;
}
```

Measured on a live object **before** the fix: `1,2,5,3,4,6,7,8,10,11` — ten
keys, **no 9, no 12**. (`requirement_spec_revision` (11) *does* have a table,
`req_specs_revisions`, so the warning is always about key **9**.)

The class itself already declares the contract: `$class_name` (`:31-34`) is
`null` for ids 4, 7, 8, 9, 10, 11 and 12 — "a node type with no class and no
table" is legal. The readers simply did not honour it: five sites read the map
**raw**, and on PHP 5/7 that was a silent `null` + an `E_NOTICE` nobody logged.
It is a **latent PHP-8 upgrade defect, not a regression** — introduced when the
project moved to PHP 8 and left visible for the first time.

### Fault sites (grep, measured pre-fix)

```
$ grep -rn "node_tables_by\['id'\]\[" lib/      # 6 hits: 1 ctor write + 5 raw reads
lib/functions/tree.class.php:83          <- ctor WRITE (correct, must stay)
lib/functions/tree.class.php:493         <- _get_path()                      raw read
lib/functions/tree.class.php:615         <- get_children()                   raw read
lib/functions/tree.class.php:1147        <- _get_subtree_rec()               raw read
lib/functions/testproject.class.php:3463 <- testproject::_get_subtree_rec()  raw read
lib/functions/testplan.class.php:4392    <- testplan::_get_subtree_rec()     raw read

$ grep -rn "node_tables\[" lib/           # the #1589 fix, the twin style
lib/functions/tree.class.php:973-974     <- _get_subtree(), isset()-guarded since #1589
```

**Two maps, six readers, five of them unguarded** — that duplication *is* the
root cause, and it is why simply patching the five lines would leave the next
caller free to re-introduce it.

### Measured exposure — three of the five sites are latent, not observable

Each site was exercised on its **real entry point** (not on the class in
isolation) with a fixture whose test case has 2 steps:

| Site | Report's claim | **Measured** |
|---|---|---|
| `tree.class.php:615` `get_children()` | 1 warning, ordinary call | **2 warnings / 2 step rows** — reachable with **no** option |
| `tree.class.php:1147` `_get_subtree_rec()` | 3 warnings / 3 steps | **1 warning per step row** (2 here); the count scales with steps, not with a fixed 3 |
| `tree.class.php:493` `_get_path()` | "same pattern" | **0** — the row read there is the *parent* of the node being walked; a step never has children and no `build` row exists → structurally unreachable for key 9/12 |
| `testproject.class.php:3463` | "same pattern" | **0** — `_get_subtree_rec` never descends into `testcase`/`tcversion`, so no step row is ever in `$rs` → latent |
| `testplan.class.php:4392` | "same pattern" | **0**, same reason → latent |

All five are still fixed: leaving three raw readers of a map *known* to lack
keys 9/12 would re-arm the identical defect for the next caller (the XML-RPC
API passes arbitrary node ids, and `get_children()` is public API).

## The fix — ONE shared resolver

Rather than five `isset()`s, the resolution now happens in a single place:

```php
// lib/functions/tree.class.php:1718-1724
function getNodeTable($node_type_id)
{
  $nodeTypeId = intval($node_type_id);

  return isset($this->node_tables_by['id'][$nodeTypeId])
         ? $this->node_tables_by['id'][$nodeTypeId] : null;
}
```

All five raw readers **and** the already-guarded #1589 *name* reader route
through it, so the two lookup styles can no longer drift apart. The only place
that still touches the array literal is the constructor that fills it (`:83`)
and the resolver itself.

`_get_subtree()`'s recursion guard still needs the type **NAME**
(`$this->node_types[...]`), so its own guarded name resolution is kept — that is
what keeps the control case warning-free.

**Alternatives rejected**

* *Patch the five sites with `isset()` each* — leaves the duplicated resolution
  strategy in place, i.e. the actual root cause. This is what made #1589 and
  #1606 two tickets for one mistake.
* *Add a `null` entry to `$node_tables_by['name']` for the two pseudo types* —
  would corrupt the name map, which is also used to derive `node_tables` and is
  iterated in other places; a `null` table name there is a different bug.
* *Insert `testcase_step => testcase_steps` / `build => testplan_builds`* — the
  pseudo types have **no** row table; `build` rows live in `testplan_builds` and
  are not tree nodes at all, so a name would be a lie that some future consumer
  would then query.

`isset()` (not `array_key_exists()`) is correct: all ten values are non-null
strings.

## Consumers are unaffected

Every consumer of `node_table` compares it against a **table-name string**
(`tree.class.php:437`, `lib/execute/execSetResults.php:1980`,
`lib/functions/testcase.class.php:5604`, `lib/functions/testproject.class.php:3466`),
so `null` behaves exactly like the pre-PHP-8 silent `null` they used to get.
This is a **warning/Event-Viewer-noise fix, not a data fix** — proven below.

## Verification (all executed)

Fixture `tmp/fixtures_1606.php` (re-runnable): project `TQ1606` → suite
`TQ1606-S1` → test case `TQ1606-TC1` **with 2 steps** → plan `TQ1606-P1`; it
writes `tmp/fixture_1606.json` and the 2 step nodes carry `node_type_id = 9`.
Harness `tmp/repro_1606.php` (exit 0 = green, one `set_error_handler` per case).

```
pre-fix  (worktree 0fc6dcf64)        post-fix (default branch 7ca4e397e)
----------------------------------   ------------------------------------
FAIL  S1 get_children()  warnings=2   PASS  S1 get_children()  warnings=0
FAIL  S2 get_subtree(rec) warnings=2 PASS  S2 get_subtree(rec) warnings=0
PASS  S3 get_path('full')  warnings=0 PASS  S3 get_path('full')  warnings=0
PASS  S4 getTestSpec(rec)  warnings=0 PASS  S4 getTestSpec(rec)  warnings=0
PASS  S5 getSkeleton(rec)  warnings=0 PASS  S5 getSkeleton(rec)  warnings=0
PASS  S6 CONTROL essential warnings=0 PASS  S6 CONTROL essential warnings=0
SUMMARY 4/6 (4 warnings), exit 1     SUMMARY 6/6 (0 warnings), exit 0
raw node_tables_by[id] keys =          getNodeTable() unit table 13/13:
1,2,5,3,4,6,7,8,10,11                1..8,10,11 unchanged; 9, 12, 99 => null
```

**Data equivalence** — the harness JSON-snapshots every affected function; the
pre-fix snapshot (from the `0fc6dcf64` worktree) and the post-fix snapshot are
byte-identical for **all 6 cases**: `get_children`, `get_subtree` recursive and
essential, `get_path('full')`, `getTestSpec` recursive, `getSkeleton` recursive.

**Static gates** — `grep -rn "node_tables_by\['id'\]\[" lib/` now returns only
the constructor write (`:83`) and the resolver (`:1722-1723`): **0 raw readers**
(pre-fix: 6 hits). `php -l` clean on all three changed files.

**Live UI** (headless Chrome, `admin`/`admin`)

* Test Specification `gui/templates/testcases/testSpec.html?tproject_id=51&tplan_id=53`
  renders the fixture tree intact: `1 suites · 1 cases`, `TQ1606 > TQ1606-S1 > TQ1606-TC1`
  — the exact subtree whose recursive walk used to emit 2 warnings per call.
* Event Viewer `gui/templates/eventviewer/eventviewer.html`: `ERROR 3, WARNING 10`
  after the whole verification, **identical to the pre-session baseline**; the only
  new rows are the 2 AUDIT logins. **0 new Error/Warning rows.**

No i18n bundle was touched: no user-facing string changed.

## Regression suite

`tmp/TLU_Test_Cases.md` → *Regression — Issue #1606* — **19/19 PASS**:
6 warning cases (S1-S6), 13 resolver unit assertions (the 10 mapped ids return
their unchanged table; 9, 12 and 99 return `null`), 6 byte-compared data
snapshots, the static grep + `php -l` gates and the two live-UI/Event-Viewer
observations. The suite **proves it detects the bug**: run against `0fc6dcf64`
it reports 4/6 and exits 1.

**RESUME**

```bash
php tmp/fixtures_1606.php     # rebuild the fixture
php tmp/repro_1606.php        # 19/19, exit 0   (pre-fix worktree: exit 1)
git worktree add /tmp/opencode/prefix1606 0fc6dcf64
grep -rn "node_tables_by\['id'\]\[" lib/       # ctor write + resolver only
```
