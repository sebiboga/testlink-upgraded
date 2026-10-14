# Bugfix Issue #1913 — `testplan::get_linked_cfields_at_execution()` passed its arguments to `cfield_mgr` shifted by one, feeding a scalar plan id into the array-typed `$execution_id` slot

Class of defect: a wrapper method forwarded its arguments positionally to
`cfield_mgr::get_linked_cfields_at_execution()`, whose signature has no
`$filters`/`$show_on_execution` slot (unlike the design sibling). Every argument from the
third onwards therefore landed one slot to the right. The scalar test-plan id reached the
**array-typed `$execution_id`** parameter and `implode(',', $execution_id)` threw a PHP 8
`TypeError`. Direct follow-up of #1912 (the `count(get_path())` guard in the same method,
which let execution reach this line) and of the #1862 test-case sibling.

## Symptom

Calling the *valid* path of
`testplan::get_linked_cfields_at_execution($id, $parent_id = null, $show_on_execution = null)`
(`lib/functions/testplan.class.php:2652`) died with an uncaught PHP 8 `TypeError`:

```
TypeError: implode(): Argument #2 ($array) must be of type ?array, int given
  @ lib/functions/cfield_mgr.class.php:1673
```

In the web SAPI that is an **HTTP 500**. The shifted `$show_on_execution` produced a second,
independent symptom: with `$show_on_execution = 1` the value `1` was passed into the
`$node_type` slot, so `cfield_mgr.class.php:1656` read `decode['nodes'][1]` and logged an
`E_WARNING "Undefined array key 1"` (Event Viewer pollution). With `$show_on_execution=null`
the node-type join was skipped entirely, silently returning a custom-field set **not**
restricted to the `testplan` node type.

The defect was **latent**: the only callers of the two wrappers that reach the method
(`testplan::html_table_of_custom_field_values()` for `scope != 'design'` and
`testplan::html_table_of_custom_field_inputs()` for `scope != 'design'`) are themselves
unreferenced — all live external callers (`lib/execute/execDashboard.php:217`,
`lib/results/printDocument.php:204`, `api/execdashboard/index.php:278`) pass `scope='design'`,
and `testplan::html_table_of_custom_field_inputs()` has zero callers. So no UI/API path fired
it, but any future caller with a valid plan id would have hit the 500.

## Root cause

1. `lib/functions/testplan.class.php:2676-2678` (the statement right after the #1912 guard):

   ```php
   $cf_map=$this->cfield_mgr->get_linked_cfields_at_execution($tproject_id,self::ENABLED,
       $show_on_execution,'testplan',$id);
   ```

2. Callee signature `lib/functions/cfield_mgr.class.php:1640-1643`:

   ```
   ($tproject_id, $enabled, $node_type=null, $node_id=null,
    $execution_id=null, $testplan_id=null, $access_key='id', $location=null)
   ```

   There is **no** `$filters`/`$show_on_execution` parameter here — the design variant
   `get_linked_cfields_at_design()` (`:454`) is the one that has `$filters` as its 3rd
   argument. The wrapper was apparently written copying the design call
   (`testplan.class.php:2631`). Positional mapping of the call was shifted by one:

   | slot | received | should be |
   |---|---|---|
   | `$node_type` | `$show_on_execution` (null/1) | `'testplan'` |
   | `$node_id` | `'testplan'` (string) | `$id` |
   | `$execution_id` | `$id` (scalar int plan id) | `null` |

3. `cfield_mgr.class.php:1659` — the first branch requires `$node_id`, `$execution_id` **and**
   `$testplan_id` non-null; `$testplan_id` was null, so control fell to
   `else if(!is_null($execution_id))` (`:1667-1673`) which runs
   `implode(',', $execution_id)` — an **int** → `TypeError`.
4. `cfield_mgr.class.php:1656` — the `1` in the `$node_type` slot is not a key of
   `decode['nodes']` (keyed by verbose names) → `E_WARNING`.

The bug survived because the method is dead code today and `implode()` on a scalar was
tolerated by PHP 7 (it emitted a warning and stringified) but is a fatal `TypeError` in PHP 8.

## Fix (minimal)

Correct the positional mapping in the wrapper — the plan id is the **node id**, the node type
is the literal `'testplan'`:

```php
$cf_map=$this->cfield_mgr->get_linked_cfields_at_execution($tproject_id,self::ENABLED,
'testplan',$id);
```

`$show_on_execution` is deliberately **not** forwarded: the callee's execution query
hard-codes `CF.show_on_execution=1` in its `WHERE` (`cfield_mgr.class.php:1726`) and has no
filter slot, so the argument can only be inert here. The wrapper docblock now says so.

Because the corrected call makes the execution branch of
`testplan::html_table_of_custom_field_values()` actually reachable, the pre-existing
unguarded read at `testplan.class.php:2809` was exposed:
`if(isset($cf_info['node_id']) || $cf_info['node_id'] || $show_cf)`. The `$node_id` column is
not selected in that branch, so it emitted `Undefined array key "node_id"`. The middle term is
**provably redundant** (`isset` true ⇒ the condition is already true; `isset` false ⇒ the
value is null/absent ⇒ falsy, leaving `$show_cf` in control), so it was reduced to the
equivalent, warning-free:

```php
if(isset($cf_info['node_id']) || $show_cf)
```

Behavior is identical for the design scope (valued rows carry `node_id` ⇒ `isset` true ⇒
renders, matched by the design regression case).

**Alternatives rejected:**
- *Append `$show_on_execution` as an extra positional argument* — would have shifted the
  arguments again, back into the wrong slots.
- *Add a `$filters` parameter to `cfield_mgr::get_linked_cfields_at_execution()`* — changes a
  shared API used by 12+ call sites (results reports, XML-RPC, execution screens); far larger
  blast radius than this latent defect warrants.
- *Wrap `$id` in `array($id)` to satisfy `implode()`* — the `else if` branch joins
  `cfield_execution_values` on `execution_id`, i.e. it treats the value as a set of
  **execution** ids. Feeding a plan id would return a completely different, wrong result set
  keyed by `execution_id`.

## Verification (fresh DB, fixture `php tmp/fixtures_1913.php`)

Fixture: project `T1913` (id=1), plan `T1913-PLAN` (id=2), exec-time CF `T1913_exec_note`
(show/enable_on_execution=1, linked to node type 5 = testplan and to the project), plus a
design-scope CF `T1913_design_note` with a value on the plan node. Driver:
`php tmp/repro_1913.php`.

| # | Step | Expected | Observed | Verdict |
|---|------|----------|----------|---------|
| 1 | `php -l lib/functions/testplan.class.php` | no syntax errors | No syntax errors detected | PASS |
| 2 | pre-fix `get_linked_cfields_at_execution($planId)` | PHP 8 `TypeError` | `implode(): Argument #2 ... int given` @ cfield_mgr.class.php:1673, exit=1 | PASS (bug confirmed) |
| 3 | post-fix `get_linked_cfields_at_execution($planId)` | no Throwable, 1 field | `map size=1 labels=[T1913 Exec Note]` | PASS |
| 4 | post-fix `..., null, 1` | no Throwable, 1 field | `map size=1 labels=[T1913 Exec Note]` | PASS |
| 5 | `html_table_of_custom_field_values($planId,'execution')` | renders field, no warning | `renders field OK` | PASS |
| 6 | Event Viewer (`events` count before/after post-fix run) | unchanged | `3 → 3`; no `Undefined array key 1` / `"node_id"` rows | PASS |
| 7 | design scope: `get_linked_cfields_at_design` + `html_table_of_custom_field_values('design')` + `html_table_of_custom_field_inputs('design')` | valued CF still returned/rendered | map size=1, html len=97 / 242, no new events | PASS |

Gate: `TLU_REQUIRE_SUITE="Issue #1913" bash ai/verify_test_suites.sh` → 7 PASS / 0 FAIL.
Suite: `Regression — Issue #1913` in `tmp/TLU_Test_Cases.md` (8 cases).

Screenshots: none — the defect lives in a latent server-side method unreachable from the UI,
so there is no screen state to capture; the Event Viewer evidence is the `events`-table count
(3 → 3, no new Error/Warning).

## Files changed

- `lib/functions/testplan.class.php` — corrected the `cfield_mgr` call in
  `get_linked_cfields_at_execution()` (+ documented why `$show_on_execution` is not
  forwarded) and made the `$cf_info['node_id']` read warning-free with an equivalent
  `isset()` short-circuit in `html_table_of_custom_field_values()`.
- `CHANGELOG` — 2.0.1 key-bugfix line.
- `tmp/TLU_Test_Cases.md` — regression suite (append-only).
- `docs/` mirror + GitHub Wiki page.
