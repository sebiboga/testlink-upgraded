# Bugfix — Issue #1410: print.inc.php:1234 — E_WARNING flooding Event Viewer when printing with step exec options and a TC without execution

## Problem

Generating a Test Plan report (`printDocument.php?type=test_report` →
`DOC_TEST_PLAN_EXECUTION`) — or via the modern print popup
(`/api/reportsprint/index.php?action=print`) — with the step-execution print
options enabled (`step_exec_status=y` / `step_exec_notes=y`) logs **one
`E_WARNING "Trying to access array offset on null"` per linked TC/platform
that has NO execution** into the Event Viewer (`events` table, log_level 2).

Reproduced at HEAD (fresh-import CI box, PHP 8.3, MySQL):

```
E_WARNING\nTrying to access array offset on null - in .../lib/functions/print.inc.php - Line 1234
```

Verified on 2026-09-11: 2 new log_level-2 rows per render for a single TC
linked on two no-execution platforms, firing through **both** entry points
(legacy `lib/results/printDocument.php` events id 5,6 and modern BFF
`api/reportsprint/index.php` events id 7,8 — identical signature), because the
BFF re-includes the legacy engine at top-level scope
(`api/reportsprint/index.php:193`).

## Root Cause

Chain (each hop backed by `file:line`):

1. `lib/functions/print.inc.php:980` — `$exec_info = null;` initialised before
   the `$getExecutions` block.
2. `print.inc.php:1057` — only populated when `$getExecutions` is true
   (`cfields | passfail | notes | step_exec_notes | step_exec_status`):
   `$exec_info = $db->get_recordset($sql,null,1);`
3. `lib/functions/database.class.php:776-789` — `get_recordset()` starts with
   `$output = null` and returns `null` unchanged when the SELECT has no rows
   (a TC never executed on that platform). A no-execution platform therefore
   yields **null**, not `[]`.
4. `print.inc.php:1234` — inside the steps block run whenever
   `$opt['step_exec_notes'] || $opt['step_exec_status']`:
   `$sxni = $st->tc_mgr->getStepsExecInfo($exec_info[0]['execution_id']);`
   → `$exec_info[0]` on null → PHP 8 `E_WARNING`.
5. `watchPHPErrors()` (`lib/functions/logger.class.php`) forwards the warning
   to `logWarningEvent()` → `events` table (log_level 2) → Event Viewer flood
   on every report render that includes a no-execution platform.

**Why it breaks now:** PHP 8.x strictness surfaced through retired/reused
legacy code — the same pattern previously fixed in #1248 / #1356.

**Blast radius:** only `print.inc.php:1234`. Documented sibling dereferences
are already guarded: `print.inc.php:1119` (`!is_null($exec_info)`),
`:1618/:1625/:1634` (`if ($exec_info)`), and the step body rows
`print.inc.php:1244-1268` guard null `$sxni` via `$nike`/`isset` and render
empty cells. grep of `$exec_info[0][` in `print.inc.php` confirms line 1234 is
the single unguarded read inside `renderTestCaseForPrinting()`.

## Fix

Committed on branch `fix/issue-1410`, commit `c2e77abb0`:

```php
$sxni = null;
if($opt['step_exec_notes'] || $opt['step_exec_status']) {
  // Refs #1410: a platform where the TC was never executed leaves
  // $exec_info null -> dereferencing [0] would raise an E_WARNING
  // that floods the Event Viewer on every report render.
  if(!is_null($exec_info) && isset($exec_info[0]['execution_id'])) {
    $sxni = $st->tc_mgr->getStepsExecInfo($exec_info[0]['execution_id']);
  }
  ...
}
```

The guard keeps `$sxni = null` for no-execution platforms. The step-exec header
cells and per-row `<td>` cells stay rendered (they are driven purely by
`$opt[...]`), so the colspan/table layout stays consistent and the rows show
empty execution columns — exactly the behaviour the `$nike` guard already
produces for a null `$sxni`.

**Why this method:** `is_null($exec_info)` is the correct guard because
`get_recordset()` returns `null` (not `[]`) on empty sets. Layout is preserved
by NOT conditionally dropping the header/tbody exec columns — the body loop
still emits those cells from `$opt`, so omitting them from the header would
produce a malformed table.

**Rejected alternatives:** (a) making `getStepsExecInfo()` tolerate a null id
— broadens a method contract for a single caller; (b) dropping the exec
columns entirely when `$exec_info` is null — breaks the row/header cell-count
consistency guaranteed by the body loop.

## Files Changed

- `lib/functions/print.inc.php` — line 1234: `getStepsExecInfo()` call now
  guarded by `!is_null($exec_info) && isset($exec_info[0]['execution_id'])`.
- `tmp/TLU_Test_Cases.md` — regression suite "Regression — Issue #1410", 9/9 PASS.
- `tmp/fixtures_1410.php` — tracked fixture (1 project, 2 platforms, 1 test
  plan, 1 TC with steps linked on both platforms, execution on platform 1 only).

## Verification

All checks on branch `fix/issue-1410`, PHP 8.3, MySQL fresh-import schema:

- Pre-fix: legacy `printDocument.php` and `api/reportsprint` `action=print`
  each produced 2 new `E_WARNING ... print.inc.php - Line 1234` events
  (log_level 2) for one TC × two no-execution platforms.
- Post-fix R1/R2: the same two URLs → HTTP 200, document renders / valid JSON,
  **0** new ERROR/WARNING events.
- Post-fix R3: `api/reportsprint` `action=download` → attachment streams, 0
  new events.
- Post-fix R4: the executed platform still renders its per-step execution data
  (`execution_tcsteps.notes` text "step note p1" present in the document).
- Post-fix R5: `tr` cell counts identical on both platforms (5-col step rows:
  step_number/actions/expected_results/step_exec_notes/step_exec_status), empty
  exec cells on the no-execution platform.
- Control: report without step-exec options → renders, 0 new events.
- `php -l lib/functions/print.inc.php` clean.

Regression suite: `tmp/TLU_Test_Cases.md` — "Regression — Issue #1410", 9/9 PASS.

Address: `Fixes #1410` (verified + pushed on `fix/issue-1410`).