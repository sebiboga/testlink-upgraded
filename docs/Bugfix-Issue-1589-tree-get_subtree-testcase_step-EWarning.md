# Bug fix — Issue #1589: `tree::_get_subtree()` raised one `E_WARNING` per `testcase_step` node

**Issue:** [#1589](https://github.com/sebiboga/testlink-upgraded/issues/1589)
**Fix commit:** `7f1e01056` — `fix(tree): resolve node_table defensively in _get_subtree (Fixes #1589)`
**Branch:** `fix/issue-1589`
**Status:** VERIFIED-FIXED (2026-09-26)
**Files changed:** `lib/functions/tree.class.php` (+13 / −1)

## Symptom

`tree::_get_subtree()` raised a PHP 8 `E_WARNING` for **every** `testcase_step`
child node in the walked subtree:

```
Undefined array key "testcase_step" - in lib/functions/tree.class.php - Line 963
```

`watchPHPErrors` (`lib/functions/logger.class.php:1407`, registered
unconditionally at `:1483`) routes every `E_WARNING` to `logWarningEvent()` →
the `events` table, so each of them became one **Event Viewer** row. A single
report request over a suite with 3 stepped test cases produced **6** WARNING
rows (`log_level = 2`, `activity = PHP`).

The exact counting rule is **one warning per `testcase_step` node**, not per test
case: a test case with 7 steps yields 7 rows.

## The bug is hidden on the default UI path — by accident

The *Results by Multiple Builds* screen
(`gui/templates/results/resultsMoreBuilds.html`, BFF
`api/reports/index.php`, action `more_builds`) resolves a selected suite through
a bare `get_subtree()` — but only inside this shortcut:

```php
// api/reports/index.php:4420-4431
$userWantsAll = (count($suiteSel) == count($topLevelSuites));
if (!$userWantsAll) {
    foreach ($suiteSel as $ssid) {
        $sub = $metricsMgr->tree_manager->get_subtree($ssid);   // no filter
    }
}
```

`more_builds_init` only ever offers **root** suites, so selecting one always
gives `count($suiteSel) == count($topLevelSuites) == 1` → `$userWantsAll` is
`true` → the loop is skipped. Measured: with `testsuite[]=999918` alone, an
`error_log()` probe injected at line 963 fired **0** times and the `events` table
stayed empty. The warning is reached as soon as the two counts differ (a
duplicated/extra suite id from a stale bookmark or from any API client).

Consequence for testing: a purely click-driven test would have reported
"cannot reproduce". The verification here therefore goes through a **real HTTP
request**, so it does not depend on that accidental guard.

## Root cause

`tree::$node_types` (`lib/functions/tree.class.php:24-28`) maps **12** node type
ids to names and its own comment says *"Now contains also PSEUDO NODES"*:

```php
var $node_types = array( 1 => 'testproject','testsuite',
                          'testcase','tcversion','testplan',
                          'requirement_spec','requirement','req_version',
                          'testcase_step','req_revision','requirement_spec_revision',
                          'build');
```

`tree::$node_tables_by['name']` (`:42-53`) has only **10** names (9 *distinct*
tables, because `req_revision` aliases `req_versions`). Measured
(`count()` on the live object): 12 ids, 10 names, 9 distinct tables, and exactly
**two ids with no entry at all** — `testcase_step` (9) and `build` (12).
`requirement_spec_revision` (11) **does** have one
(`'requirement_spec_revision' => 'req_specs_revisions'`, `:53`); it is *not* one of
the tableless types. `$this->node_tables = $this->node_tables_by['name']` (`:79`).

`tree::$class_name` (`:31-34`) already encodes this correctly — the pseudo types
are `null`. **`class_name` is the authoritative "has a table" flag;
`node_tables` is not, and line 963 ignored that flag:**

```php
// lib/functions/tree.class.php:963 (pre-fix)
$node_table = $this->node_tables[$this->node_types[$row['node_type_id']]];
```

Two unguarded array accesses. For `node_type_id = 9` the inner lookup yields
`'testcase_step'`, the outer has no such key → `E_WARNING`, and `$node_table`
becomes `null`. The loop **keeps going** and still appends the step row.

The recursion guard that would have stopped the descent sits **below** the
warning (`:1018` pre-fix, `exclude_children_of`) and therefore never protected
the pseudo type. The recursion itself (`:1021` pre-fix) runs for every row, so one
stepped test case in the subtree makes every step below it warn.

Why it surfaces only in 2.0.1: on PHP 5/7 an undefined array key was an
`E_NOTICE` and `$node_table` was silently `null` — harmless. PHP 8 promoted it to
`E_WARNING`, and the modernized Event Viewer is the screen that made all of this
visible. It is a latent PHP-8 upgrade defect, not a regression introduced by a
recent commit.

## Fix

`grep -rn "node_tables\[" lib/` returned **exactly one** hit before the fix
(line 963) and now returns the two halves of the guarded statement (`:973-974`).

```diff
-        $node_table = $this->node_tables[$this->node_types[$row['node_type_id']]];
+        // $node_types also holds the PSEUDO node types (testcase_step,
+        // requirement_spec_revision, build) which have NO row table of their own,
+        // so $node_tables has no key for them. On PHP 8 the raw double lookup
+        // raised one E_WARNING "Undefined array key \"testcase_step\"" per step
+        // node, which watchPHPErrors turns into Event Viewer noise (Refs #1589).
+        // Resolve defensively: for the pseudo types (and for any unknown id a
+        // plugin could introduce) node_table is null, which is exactly what
+        // $class_name already declares for them.
+        $nodeTypeName = isset($this->node_types[$row['node_type_id']])
+                        ? $this->node_tables[...] : null;   // (abridged)
```

plus a second, one-line change in the **same loop iteration** — the recursion
guard did its own second unguarded lookup of the very same name:

```diff
-        if( !isset($my['filters']['exclude_children_of'][$this->node_types[$row['node_type_id']]]) &&
+        // $nodeTypeName is the name resolved above for THIS row: reusing it
+        // keeps the recursion guard free of a second unguarded lookup (Refs #1589).
+        if( !isset($my['filters']['exclude_children_of'][$nodeTypeName]) &&
             !isset($my['filters']['exclude_branches'][$row['id']]) )
```

Without that second part the fix would only be partial: an injected row with a
genuinely unknown `node_type_id` still raised `Undefined array key 99` at
`:1029` (**measured**: 1 warning). With it, 0 warnings.

The two `isset()` guards make the resolution total. For the two tableless types —
and for any unknown `node_type_id` a plugin could introduce — `node_table` is
`null`, which is exactly what `$class_name` already declares for them, so the fix
makes `node_tables` consistent with the existing contract instead of inventing a
new one. It also cannot emit a SQL fragment like `FROM ` if a consumer ever
dereferences it.

The returned node set and every `node_table` value of the 10 mapped types are
**unchanged**. Verified against the pre-fix output: 10 nodes, per-type
`2=1 3=3 4=3 9=3`, `node_table` = `testsuites, testcases, tcversions, NULL`.

`isset()` is the right predicate here, not `array_key_exists()`: all 10 mapped
values are non-`null` strings, so no legitimate `null` can be masked. mysqli
returns `node_type_id` as the *string* `'9'`, but PHP normalises canonical
integer strings for array access, so `isset($this->node_types['9'])` resolves
exactly as the old direct access did — checked against live rows.

### Alternatives considered and rejected

1. **Stop the recursion at `testcase` by default when no filter was given**
   (the issue's option 2). Rejected: it silently changes the returned node set
   for every existing caller that legitimately wants the flat step list
   (`api/reqspec/index.php:1251`, `lib/functions/specview.php:688`,
   `lib/results/printDocument.php:52`). A behaviour change disguised as a
   warning fix.
2. **Add `testcase_step => null` to `$node_tables_by['name']`.** Rejected: a
   one-off patch of the *data* for one pseudo type; `build` (12) and any future
   pseudo type stay broken, and the `isset()` guard stays necessary anyway.
3. **Move the recursion guard (`:1011`) above the lookup.** Rejected: too late —
   the warning has already been raised by then, and the guard carries the
   separate "don't descend into test cases" semantic.

No `i18n` impact: no user-facing string, label or message is touched, so no
locale bundle was modified.

## Blast radius

32 `get_subtree()` call sites across `lib/`, `api/`, `gui/`. The live ones
without the `exclude_children_of` filter:

| Caller | Filter | Reachable? |
|---|---|---|
| `api/reports/index.php:4427` (`more_builds`) | **no** | **yes — reproduced, 6 events** |
| `api/tcautoexec/index.php:321`, `:480` | yes | no (contained by #1587) |
| `lib/functions/specview.php:688` (`getTestSpecFromNode()`) | no (`null`) | via spec view — latent |
| `api/reqspec/index.php:1251` | see filters | latent |
| `lib/functions/testplan.class.php:3919` | see filters | latent |
| `lib/functions/treeMenu.inc.php:1583,1694` | see filters | latent |
| `lib/functions/testproject.class.php:700,721,865,1557` | mixed | latent |
| `lib/functions/testsuite.class.php:739,806,850,1265` | **always** (`:52`, `:800`) | no |
| `api/reqdoc/index.php:233`, `lib/results/printDocument.php:52` | legacy print path | latent |

A second, *different* unguarded pattern — `node_tables_by['id'][$row['node_type_id']]`
— exists at `tree.class.php:493` (`_get_path`), `tree.class.php:615`
(`get_children`), `tree.class.php:1147` (`_get_subtree_rec`),
`testproject.class.php:3356` and `testplan.class.php:4384`. `node_tables_by['id']`
is **not** empty: the constructor fills it from the `name` map
(`tree.class.php:81-84`, measured keys `1,2,3,4,5,6,7,8,10,11` — i.e. it inherits
the same two missing ids, 9 and 12). `get_children()` on a test-case-version node
measures **1 warning** and is reachable from an ordinary call, so that family is
filed as a follow-up rather than folded into this minimal fix.

## Verification

Fixture `tmp/fixtures_1589.php` (re-runnable): test project `999915`
(`TreeDemo 1589`), plan `999916`, build `9`, suites `999917` (root) / `999918`
(nested), 3 test cases **each with one step** (3 `node_type_id = 9` nodes, all
linked to the plan), plus requirement spec `999928` and requirement `999930`.

| Measurement | Before | After |
|---|---|---|
| `php tmp/repro_1589.php` warnings | **3** (`EXIT=1`) | **0** (`EXIT=0`) |
| `events` rows from one `more_builds` request | **6** | **0** |
| Event Viewer `stats/byLevel` | `WARNING: 6` | `WARNING: 0` |
| `get_subtree()` node count | 10 | 10 (unchanged) |
| nodes per `node_type_id` | `2=1 3=3 4=3 9=3` | identical |
| `node_table` values | `testsuites, testcases, tcversions, NULL` | identical |

Regression matrix — **19 PASS / 1 known-fail out of scope** — each case in its
own PHP process (see below): bare `get_subtree()`; injected unknown
`node_type_id = 99`; `testsuite::get_subtree()`; all four `output` modes
(`id`, `essential`, `rspec`, `full`); `recursive = true`;
`order_cfg = exec_order` with `tplan_id`; `order_cfg = rspec` on a requirement
spec (asserts `req_specs_revisions` still resolve); the exact `api/tcautoexec`
call. Live HTTP: `more_builds` with a nested suite, with the root suite, and with
no suite filter; `api/reqspec` `specs` and `reqs` — all `200 / status:ok`, all
**0 new events**. `php -l` clean, no 5xx or fatal in `tmp/php_server.log`.

Full suite: `tmp/TLU_Test_Cases.md` →
`## Regression — Issue #1589: ...`

## Two sibling defects found while testing — filed, not fixed here

1. **#1606** — `_get_subtree_rec()` at `tree.class.php:1147` does
   `$this->node_tables_by['id'][$row['node_type_id']]` and the key for
   `testcase_step` is missing there → `Undefined array key 9`, one warning per
   step node via `get_subtree(..., ['recursive' => true])`. This is the single
   non-PASS of the matrix. Confirmed **pre-existing and untouched** by this fix:
   `git stash` → 3 warnings, `git stash pop` → the same 3 warnings (the line only
   moved 1134 → 1147 because this patch shifted the file by 13 lines).
2. **#1607** — `static $my` in `_get_subtree()` leaks `order_cfg`/`output` between
   top-level calls within one process → spurious
   `Undefined array key "doc_id"` at `tree.class.php:995`. Measured 13 spurious
   warnings from 3 calls in 1 process vs 0 from the same 3 calls in 3 processes.
   This is why every matrix case runs in its own process; the same class of leak
   exists in `_get_subtree_rec()`.

A third, lower-severity find is documented in the issue comments: an
`undefined constant` landmine at
`lib/functions/requirement_spec_mgr.class.php:119`, whose
`$type = TL_REQ_SPEC_TYPE_FEATURE` default parameter refers to a constant defined
nowhere in the tree. Currently unreachable from the GUI because every real caller
(`lib/requirements/reqSpecCommands.class.php:212`) passes the 8th argument.

## Reproduce / re-test in one command

```bash
php tmp/fixtures_1589.php          # rebuild the fixture on a fresh DB
php tmp/repro_1589.php             # expect: warnings raised: 0, EXIT=0
for c in c1 c2unknown c3 c4id c4essential c4rspec c4full c5 c6 c7 c9; do
  php tmp/matrix_1589.php "$c"; done
mysql -h 127.0.0.1 -utestlink -ptestlink testlink -e "SELECT COUNT(*) FROM events;"
```

Live check (logged in as `admin`/`admin` at `http://localhost:8082`):

```js
// in the page, after DELETE FROM events
await fetch('/api/reports/index.php?action=more_builds&tproject_id=999915' +
  '&tplan_id=999916&build[]=9&testsuite[]=999918&testsuite[]=999918&keyword=0' +
  '&owner=0&executor=0&lastStatus[]=n&search_notes_string=&display_suite_summaries=1' +
  '&display_test_cases=1&display_query_params=1&display_totals=1&display_latest_results=1');
await (await fetch('/api/eventviewer/index.php/events/stats/byLevel')).json();
// expect every level to be 0
```

## Screenshots

| State | File |
|---|---|
| BEFORE — Event Viewer with the 6 `E_WARNING … testcase_step` rows | `docs/screenshots/issue-1589-before-eventviewer.png` |
| AFTER — same screen, same request, 0 rows | `docs/screenshots/issue-1589-after-eventviewer.png` |
