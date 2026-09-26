# Bug fix — Issue #1607: `_get_subtree_rec()` `static` froze the tree configuration for the whole process

**Issue:** [#1607](https://github.com/sebiboga/testlink-upgraded/issues/1607)
**Fix commit:** `681ab1eff` — `fix(tree): make _get_subtree_rec config per-call so top-level get_subtree() stops leaking order_cfg/output (Refs #1607)`
**Branch:** `fix/issue-1607`
**Status:** VERIFIED-FIXED (2026-09-26)
**Files changed:** `lib/functions/tree.class.php` (+44 / −44, of which 38 lines are the de-indent)

## Symptom — silent wrong subtree, not a warning

`tree::_get_subtree_rec()` froze its **entire configuration** on the *first*
call of a PHP process. Every later top-level `get_subtree(..., ['recursive' =>
true])` in that process had its `$filters` / `$options` accepted, carried around
and then **silently discarded**, reusing call #1's `order_cfg` (including
`tplan_id`), `key_type`, `platform_filter`, `exclude_branches` and
`exclude_children_of` — and `additionalWhereClause`, which is baked into the
cached `$fclause` **SQL string**.

Measured on a fresh DB, against the same data, in two processes:

```
call B alone, own process  : 13 nodes, std shape
call A then call B, one    :  2 nodes, extjs shape   <-- call B inherited call A
process
```

Two `exec_order` recursive calls in one process, the second explicitly asking
for `tplan_id = 0`, both returned the same 13 nodes (13 then 7 is correct).

## Root cause

```php
// lib/functions/tree.class.php:1043  (BEFORE)
function _get_subtree_rec($node_id,&$pnode,$filters = null, $options = null)
{
  static $tcNodeTypeID;          // never reset ->
  static $qnum;                   //   the guard below runs ONCE per process
  static $my;
  static $platform_filter;
  static $fclause;
  static $exclude_branches;
  static $exclude_children_of;

  if (!$tcNodeTypeID)             // tree.class.php:1048 (BEFORE)
  {
    $tcNodeTypeID = $this->node_descr_id['testcase'];
    $qnum = 0;
    $my['filters'] = array(/* defaults */);
    $my['options'] = array(/* defaults */);
    // :1061-1062  the ONLY place the caller's arguments are ever read
    $my['filters'] = array_merge($my['filters'], (array)$filters);
    $my['options'] = array_merge($my['options'], (array)$options);
    $platform_filter = /* from order_cfg.platform_id */;
    $fclause = " AND node_type_id <> {$tcNodeTypeID} "
             . "{$my['filters']['additionalWhereClause']} ";   // :1069
    $exclude_branches   = $my['filters']['exclude_branches'];
    $exclude_children_of= $my['filters']['exclude_children_of'];
  }
  // ... traversal uses only the frozen values
```

The recursion itself was never the problem: it passes the merged arrays down
explicitly at `tree.class.php:1195`:

```php
$this->_get_subtree_rec($row['id'],$node,$my['filters'],$my['options']);
```

so rebuilding the defaults at each level is **idempotent** — the `static` bought
nothing but the per-level `array_merge` it was meant to skip.

This is a latent 1.9.20 design flaw that became reachable in 2.0.1 the moment the
BFF layer started aggregating more than one tree read per request. The sibling
defect fixed in #1589 (`7f1e01056`) is what surfaced it.

## What the report got wrong (measured, not assumed)

The ticket described the symptom as a spurious
`Undefined array key "doc_id" - Line 995`. That part is **not** the `static`
leak:

* `get_subtree()` (tree.class.php:836-849) builds a **complete** filter/option
  map on every call, and `_get_subtree()`'s `array_merge()` also runs on
  **every** call — only the *defaults* sit behind `if(!$my)` (tree.class.php:872).
  Every top-level key is therefore overwritten each time and nothing survives.
  Measured: `tmp/repro_1607.php flat-leak` (call A then B) is identical to
  `flat-b` (B alone) — 3 rows each. **The flat path never leaked.**
* The `doc_id` warning reproduces from a **single** call that asks
  `output => 'rspec'` while leaving `order_cfg` at its `spec_order` default: the
  output switch reads `$row['doc_id']` (tree.class.php:995) but only the
  `case 'rspec'` **SQL** branch projects `RSPEC.doc_id` (tree.class.php:895-901).
  That is a **caller-side option mismatch**, filed separately as **#1608**.

The real defect was therefore more serious than reported: wrong data, silently,
with no warning to make it discoverable.

## The fix

`lib/functions/tree.class.php`, `_get_subtree_rec()` only:

1. `static` removed from all seven locals; `$tcNodeTypeID` and `$qnum` become
   plain assignments at the top of the function;
2. the `if (!$tcNodeTypeID) { … }` wrapper deleted and its body de-indented one
   level, so the two `array_merge()` calls apply **every** call's own arguments;
3. the recursion at tree.class.php:1195 untouched;
4. `_get_subtree()`'s `static $my` (line 871) **deliberately not touched** — it
   is measured not to leak, and changing it would be churn outside the defect.

A comment at the fault site records the reason so it is not "optimised" back in.

## Verification

| # | test | expected | observed | result |
|---|---|---|---|---|
| 1 | `php tmp/repro_1607.php rec-b` (control) | 13 nodes, std | 13 nodes, std | PASS |
| 2 | `php tmp/repro_1607.php rec-a` (control) | 2 nodes, extjs | 2 nodes, extjs | PASS |
| 3 | `php tmp/repro_1607.php rec-leak` — A then B, one process | 13 nodes, std | 13 nodes, std | **PASS** (was 2 / extjs) |
| 4 | `flat-leak` vs `flat-b` | identical | 3 / 3 | PASS |
| 5 | suite subtree, `recursive => true` | 3 nodes, 0 warnings | `TQ1607-TC1/3 \| /4 \| /9` | PASS |
| 6 | `order_cfg => ['type'=>'exec_order','tplan_id'=>132]` | executes, no warning | 13 nodes, 0 new warnings | PASS |
| 7 | two `order_cfg` in one process (`tplan_id` 132 then 0) | **different** counts | 13 then **7** | **PASS** (was 13 then 13) |
| 8-11 | browser: Test Specification / Requirement Spec Management / Test Plan Management / Execute Tests | full trees | as expected | PASS |
| 12 | `php -l lib/functions/tree.class.php` | no syntax errors | clean | PASS |
| 13 | Event Viewer `events` after rows 8-11 | no new rows | `77 77` (unchanged), `id>77` → 0 rows | PASS |
| 14 | browser console `error` + `warn` | none | none | PASS |

Regression suite: **15/15 PASS** (`tmp/TLU_Test_Cases.md`, "Regression — Issue
#1607").

Pre-fix / post-fix were measured by restoring the original file with
`git show ab387af72:lib/functions/tree.class.php` and re-running the same
harnesses, so every row is proven to discriminate.

## Not fixed here (deliberately, separate tickets)

* **#1606** — `_get_subtree_rec()` reads
  `$this->node_tables_by['id'][$row['node_type_id']]` (tree.class.php:1147)
  against an array the constructor fills with the 10 *real* node types, so the
  pseudo types `testcase_step`(9) and `build`(12) raise
  `Undefined array key 9`. Identical before and after this change.
* **#1608** — the `doc_id` / Line-995 warning described above.

`lib/functions/testproject.class.php:3143` and
`lib/functions/testplan.class.php:4236` carry their **own copies** of the same
`static`-plus-guard pattern and are out of scope for this ticket.

## Reproducing

```
php tmp/fixtures_1607.php      # project TQ1607, suites, cases, plan, req specs
php tmp/repro_1607.php rec-leak   # the primary symptom (rows 1-4)
php tmp/verify_1607.php           # matrix rows 5-7 (seeds testplan_tcversions)
php tmp/verify_1607.php | grep '^row 7'   # must say "differs => no leak"
```
