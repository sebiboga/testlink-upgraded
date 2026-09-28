# Bugfix — Issue #1608: `tree::get_subtree()` warned once per row whenever `output=rspec` met a non-`rspec` `order_cfg`

**Issue:** [#1608](https://github.com/sebiboga/testlink-upgraded/issues/1608) — *tree::get_subtree(): output=rspec with default order_cfg raises one "Undefined array key doc_id" per row (tree.class.php:995)*
**Commits:** `4f5d18874`, `74da4e208`, `cd681dd7a`, `853bc603b` on `fix/issue-1608`
**Affected code:** `lib/functions/tree.class.php` (`_get_subtree()` output switch, `createHierarchyMap()`), `lib/functions/testproject.class.php` (`_createHierarchyMap()`)
**Regression suite:** `tmp/TLU_Test_Cases.md` → *Regression — Issue #1608* (16 cases, 16 PASS)
**Follow-up filed, deliberately not fixed here:** [#1693](https://github.com/sebiboga/testlink-upgraded/issues/1693) — `tree::_get_subtree()`'s `static $my` is process-wide

---

## 1. Symptom

`tree::get_subtree()` raised **one PHP 8 `E_WARNING "Undefined array key \"doc_id\""`
per returned row** whenever a caller asked for `output => 'rspec'` without also
overriding `order_cfg` to `['type' => 'rspec']`. Through the application's own
error handler (`watchPHPErrors()`, `lib/functions/logger.class.php:1407`) every
one of those warnings became a row in the `events` table — Event Viewer WARNING
noise.

**Honest scoping:** the offending option combination is **legal but not used by
any in-tree caller** (see §6). No 2.0.1 screen triggered this before the fix.
This was a **hardening** change, not the repair of a screen-visible defect, and
the reproduction below is therefore intentionally synthetic.

## 2. Reproduction (pre-fix)

```bash
php tmp/fixtures_1607.php          # project TQ1607 (prefix TQ1): S1=2, TC1=5, RS1=11
php tmp/repro_1608.php mismatch    # custom set_error_handler
php tmp/ev1608.php                 # the SAME call under the app's own watchPHPErrors()
```

```
$ php tmp/repro_1608.php mismatch
case=mismatch
  rows=3  warnings=3
  keys[0]=id,parent_id,doc_id,node_type_id,node_order,node_table,name
  doc_id[0]=NULL
  names: TQ1607-RS1, TQ1607-RS1-CHILD, TQ1607-RS1-CHILD
  ! Undefined array key "doc_id" in tree.class.php - Line 999
  ! Undefined array key "doc_id" in tree.class.php - Line 999
  ! Undefined array key "doc_id" in tree.class.php - Line 999
```

```
$ php tmp/ev1608.php
rows=3
events before=1 after=4 (delta=3)
4  2  GUI  E_WARNING\nUndefined array key "doc_id" - in .../lib/functions/tree.class.php - Line 999
3  2  GUI  E_WARNING\nUndefined array key "doc_id" - in .../lib/functions/tree.class.php - Line 999
2  2  GUI  E_WARNING\nUndefined array key "doc_id" - in .../lib/functions/tree.class.php - Line 999
```

One `get_subtree()` call ⇒ 3 returned rows ⇒ 3 `events` rows at `log_level = 2`.

## 3. Root cause

### Hop 1 — the default `order_cfg` does not project `doc_id`

`lib/functions/tree.class.php:842` sets the default
`'order_cfg' => array("type" => 'spec_order')`.

### Hop 2 — the SQL is chosen by the **first** switch

`lib/functions/tree.class.php:888` switches on `$my['options']['order_cfg']['type']`:

| branch | projects `doc_id`? |
|---|---|
| `case 'spec_order'` (`:889`) | **no** — `id,name,parent_id,node_type_id,node_order` |
| `case 'rspec'` (`:902`) | **yes** — `INNER JOIN req_specs … RSPEC.doc_id` |
| `case 'exec_order'` (`:914`) | no |
| `case 'req_order'` (`:941`) | no |

### Hop 3 — the row shape is chosen by a **second, independent** switch

`lib/functions/tree.class.php:981` switches on `$my['options']['output']`, and its
`case 'rspec'` read `$row['doc_id']` unconditionally (`:999`).

### Hop 4 — nothing validates that the two agree

`get_subtree()` accepts `output` and `order_cfg` as two free knobs, so
`output=rspec` + default `order_cfg` is a valid call whose rows have no `doc_id`.
**The dependency is documented nowhere and enforced nowhere.**

### Hop 5 — PHP 8 made the silent `NULL` loud

On PHP 5.x/7.x the same expression was a silent `NULL`; on PHP 8 it is an
`E_WARNING`, which `watchPHPErrors` converts into an `events` row. No code change
introduced the defect — **the runtime did**, and 2.0.1 only made it visible.

### Hop 6 — the `NULL` then corrupted the combo label (the half of the bug the report missed)

PHP 8 *materialises* the missing key as `NULL` instead of leaving it absent, so
line 999 no longer warned downstream — it just produced a **silently wrong
requirement-spec combo**:

```
$ php tmp/repro_1608.php downstream          # -> tree::createHierarchyMap(field doc_id)
  hierarchy: {"12":".:TQ1607-RS1","13":".:TQ1607-RS1-CHILD","14":"..:TQ1607-RS1-CHILD"}
```

`sprintf('%s:', NULL)` → `":"`, so every entry lost its `TQ1-REQ-1-1:` prefix. The
legacy twin `testproject::_createHierarchyMap()` was worse: `"[] - TQ1607-RS1"`.

## 4. The fix

### 4.1 `tree::_get_subtree()` — resolve the optional field once per row

`lib/functions/tree.class.php`, `case 'rspec'` of the output switch:

```php
$doc_id = isset($row['doc_id']) ? $row['doc_id'] : null;
$node_list[] = array('id' => $row['id'],
                     'parent_id' => $row['parent_id'],
                     'doc_id' => $doc_id,
                     …);
```

The same defensive-resolver idiom #1606 landed for `node_table` three lines
above, so the file now has one way of reading an optional SQL projection. The key
is always emitted, so callers can `isset()`-check it instead of tripping on
their own.

**Rejected alternative — auto-upgrade `order_cfg` to `rspec` when
`output=rspec`.** Rejected: the `case 'rspec'` SQL `INNER JOIN`s `req_specs`, so it
returns a **different set of nodes**, not merely a different projection — measured
1 row vs 3 rows on the same node (`repro_1608.php matched` vs `mismatch`). It
would have silently changed which nodes a caller receives.

**Rejected alternative — the report's option (b), "fall back to `essential`".**
Rejected: both hierarchy-map builders index `$current['doc_id']` unconditionally,
so it would only have *moved* the `E_WARNING` one stack frame down.

### 4.2 `tree::createHierarchyMap()` — guard the optional prefix

```php
if( isset($current[$field2add['field']]) && !is_null($current[$field2add['field']]) )
{
  $hmap[$current['id']] .= sprintf($field2add['format'],$current[$field2add['field']]);
}
```

### 4.3 `testproject::_createHierarchyMap()` — same guard, legacy twin

```php
$dm = ($addprefix && isset($current[$addfield]) && !is_null($current[$addfield]))
       ? "[{$current[$addfield]}] - " : '';
```

The two copies of this algorithm had already drifted (one used
`sprintf($format, …)`, the other string interpolation) — exactly the recurrence
pattern #1606 was filed for, so both were brought in step.

### 4.4 Deliberately left alone

- `testproject.class.php` / `testplan.class.php` `_get_subtree_rec()` copies —
  `grep -n "'rspec'" lib/functions/testproject.class.php` is **1** hit (the
  *caller*, `:1663`, which pairs correctly), and neither copy has an `rspec`
  output branch. The report's "check the twins" worry is resolved: **no change
  needed**.
- `createHierarchyMap()`'s `'array'` mode (`tree.class.php:1636`,
  `$current[$field2add]`, where `$field2add` is itself an array) is a latent
  `Illegal offset type` warning, but an exhaustive grep over all file types found
  only 6 call sites and **none** passes `mode='array'` with a 3rd argument. Dead
  code, unrelated to #1608.
- The SQL switch has no `default:`, so an unknown `order_cfg['type']` would leave
  `$sql` undefined — pre-existing, different trigger, untouched.

## 5. Verification

| check | before | after |
|---|---|---|
| `repro_1608.php mismatch` | 3 rows / **3** `E_WARNING` | 3 rows / **0** |
| `repro_1608.php matched` (control) | 1 row, `doc_id='TQ1-REQ-1-1'` | **identical** |
| `repro_1608.php downstream` | `".:TQ1607-RS1"`, 3 warnings | `".TQ1607-RS1"`, **0** warnings |
| `repro_1608.php id` (control) | 3 rows / 0 | **identical** |
| `testproject::genComboReqSpec(11)` | `[TQ1-REQ-1-1] - TQ1607-RS1-CHILD` | **identical** |
| `ev1608.php` (app's own handler) | `events` delta **3** | `events` delta **0** |
| `repro_1607.php mismatch` | 3 rows / 3 warnings | 3 rows / **0** |
| `repro_1607.php flat-leak` / `flat-b` | 3 rows each | **identical**, 0 warnings |
| `repro_1607.php rec-leak` / `rec-b` | 13 nodes each | **identical**, 0 warnings |
| live BFF `api/reqspec/index.php?action=copy_options&id=11` | — | `200`, `{"11":".TQ1-REQ-1:TQ1607-RS1","13":"..TQ1-REQ-1-1:TQ1607-RS1-CHILD"}` |
| Event Viewer screen | — | `WARNING: 0 (0%)`, `ERROR: 0 (0%)`, 0 console errors |
| `php -l` both files | — | no syntax errors |

Full 16-case matrix in `tmp/TLU_Test_Cases.md` → *Regression — Issue #1608*.

## 6. Reachability audit (why this was latent)

All `output => 'rspec'` / `order_cfg.type => 'rspec'` call sites:

| call site | order_cfg | output | pairing |
|---|---|---|---|
| `lib/functions/testproject.class.php:1663` (`genComboReqSpec`) | `rspec` | `rspec` | correct |
| `api/reqspec/index.php:1250` | `rspec` | `rspec` | correct |
| `lib/requirements/reqCommands.class.php:552` | `rspec` | `rspec` | correct |
| `lib/requirements/reqSpecCommands.class.php:438` | `rspec` | `rspec` | correct |
| `lib/functions/specview.php:683` | `$type` (can be `rspec`) | unset ⇒ `full` | never `rspec` |
| `lib/search/searchCommands.class.php:123` | default | `id` | n/a |

Every caller pairs correctly, so the fix makes the invariant hold for the *next*
caller rather than repairing a live screen.

## 7. Related defect found by the code review — filed as #1693

The review surfaced a **larger, different** bug in the same function, verified
before filing and deliberately **not** fixed here (different root cause; fixing it
would have expanded this run's scope): `tree::_get_subtree()`'s `static $my`
(`tree.class.php:873`) is a **function**-static, hence shared by every `tree`
instance in the request, and `array_merge($my['options'], (array)$options)` is
additive — a key the next caller omits keeps the previous caller's value.
Measured, two separate processes, same code, **new `tree` instance** for the
second call:

```
$ php tmp/verify_static1608.php pristine
case=pristine call2 (bare opts) rows=3 names=TQ1607-TC1, ,

$ php tmp/verify_static1608.php leak
call1 (order_cfg=rspec, output=rspec) rows=1 doc_id='TQ1-REQ-1-1'
case=leak call2 (bare opts) rows=0 names=
```

3 rows → 0 rows, silently. That is a *silent wrong-subtree* defect — materially
worse than the warning #1608 reported — and it is the `static` twin of #1607,
which removed `static` from `_get_subtree_rec()` and left this one in place. Two
in-tree direct callers are exposed (`testproject::getTestSpec()` `:3225→:3258`,
`testplan::getSkeleton()` `:4226→:4260`), both currently latent. Tracked as
[#1693](https://github.com/sebiboga/testlink-upgraded/issues/1693).
