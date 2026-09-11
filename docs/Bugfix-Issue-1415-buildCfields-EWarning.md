# Bugfix — Issue #1415: print.inc.php — E_WARNING Undefined variable $buildCfields on every report render with unexecuted TCs on a build

## Problem

Generating a Test Plan **Execution report on a build**
(`printDocument.php?type=testreport_onbuild` → `DOC_TEST_PLAN_EXECUTION_ON_BUILD`)
raises **one `E_WARNING "Undefined variable $buildCfields"` per linked Test Case
that has NO execution row on the selected build**. Each warning is logged as an
event (`log_level=2`, source 'GUI - Test Project ID : 1') in the `events` table,
polluting the Event Viewer used for CI integration checks.

Reproduced at HEAD (fresh-import CI box, PHP 8.x, MySQL), both on the legacy
apikey path and the logged-in session path:

```
E_WARNING\nUndefined variable $buildCfields - in .../lib/functions/print.inc.php - Line 1595
```

Verified 2026-09-11 on fixture data (plan with 3 TCs, 2 builds, an execution on
build 2 for 1 TC only): exactly 2 new events per render (one per unexecuted-on-
build-2 TC). Document output is unaffected because the undefined variable
short-circuits its own guard.

## Root Cause

Chain (each hop backed by `file:line`):

1. `lib/functions/print.inc.php:922` —
   `renderTestCaseForPrinting(&$db,&$node,&$options,$env,$context,$indentLevel)`
   never declares `$buildCfields`; a grep of the function confirms the single
   bare reference is line 1595.
2. `print.inc.php:1067-1071` — build custom fields are fetched onto the static
   tree object: `$st->buildCfields[$tbuild_id] = $st->build_mgr->html_table_of_custom_field_values(...)`,
   gated on the `build_cfields` print option and only when `$exec_info` is not null.
3. `print.inc.php:1594-1602` — for a TC with **no execution on the selected
   build** (`is_null($exec_info)`) the build-name block runs its build-CF row;
   its outer guard `!is_null($buildCfields)` (line 1595) reads the **undefined**
   variable → PHP8 E_WARNING → event row. The row body already used the real
   source, `$st->buildCfields[$build_id]`.
4. Git history pinpoints the regression source:
   - `25619cd009` (2016) used `static $buildCfields;` populated per build id
     inside the same function.
   - `90c461a54` ("#0008556 DB Access Error", 2019) refactored the storage to
     `$st->buildCfields`, migrating the write, the row body and
     `buildTestExecResults()` — but **forgot the guard on the then-old line 1563**.
     From that commit on, `$buildCfields` is a leftover name: the variable is
     undefined, and the whole CF row is dead code.

**Why it breaks now:** PHP 8.x surfaces the 2019 refactor leftover as an
E_WARNING on every affected render. Not a modernization regression; the legacy
`printDocument.php` path reproduces it identically.

**Blast radius:** grep `buildCfields` in `lib/functions/print.inc.php` →
6 hits; only line 1595 uses the bare variable; the other 5 already use
`$st->buildCfields` (lines 1068, 1069, 1596, 1597, 1600, 1630) plus an
unrelated `$things->buildCfields` (2356). No other file references it.
Single-line blast radius.

## Fix

Committed on branch `fix/issue-1415`, commit `e9fa9f0e9`:

```php
if(is_null($exec_info)) {
  if(isset($st->buildCfields[$build_id]) &&
     $st->buildCfields[$build_id] != '') {
    $code .= '<tr><td width="' . $cfg['firstColWidth'] .
             '" valign="top"></td>' . '<td colspan="'  . $tsp . '">' .
             $st->buildCfields[$build_id] . "</td></tr>\n";
  }
}
```

The stale `!is_null($buildCfields)` clause is deleted. The remaining
`isset($st->buildCfields[$build_id])` provides the same "CF data already
fetched for this build" semantics the original static-null check was meant to
express, against the real data source. The row renders its build-CF table when
data exists — the behaviour the 2019 refactor intended but had accidentally
dead-branched.

**Why this method:** the guard should read exactly what the row body reads.
`isset()` is null-safe and covers "initialised" — an extra `is_null($st->buildCfields)`
adds nothing.

**Rejected alternatives:** (a) `$buildCfields = null;` initializer — silences
the warning but keeps the CF row permanently dead, losing intended output;
(b) switching the whole guard to `$st->buildCfields` with an added null check —
redundant given `isset()`.

## Files Changed

- `lib/functions/print.inc.php` — line 1595: removed the undefined
  `!is_null($buildCfields)` clause from the build-CF guard (-2/+1).
- `tmp/TLU_Test_Cases.md` — regression suite "Regression — Issue #1415", 7/7 PASS.
- `tmp/fixtures_1415.php` — fixture (1 project, plan with 3 linked TCs on
  platform 1, 2 builds, execution on build 2 for the 3rd TC only).

## Verification

All checks on branch `fix/issue-1415`, PHP 8.x, MySQL fresh-import schema:

- Pre-fix control (file reverted to HEAD): session render of the affected URL
  → HTTP 200 + 2 new `E_WARNING ... Line 1595` events.
- Post-fix R1 (build_id=2, allOptionsOn=1): HTTP 200, **0** new events.
- Post-fix R2 (build_id=1, no executions at all): HTTP 200, **0** new events.
- Post-fix R3 (build_id=2, print options off): HTTP 200, **0** new events.
- Post-fix R4 (anonymous/apikey path): HTTP 200, **0** `buildCfields` events.
- Post-fix R5 (positive path): a build custom field seeded
  (`cf_build_1415`, value `BUILDCF-MARKER-VALUE`) renders its row in the
  document exactly once for the unexecuted TCs — the restored branch works.
- `php -l lib/functions/print.inc.php` clean.
- Events table after all fixed-path renders: 0 rows matching `%buildCfields%`.

Regression suite: `tmp/TLU_Test_Cases.md` — "Regression — Issue #1415", 7/7 PASS.

## Related Find

While testing, the anonymous/apikey direct access to `printDocument.php` also
emits 3 `E_WARNING "Undefined array key basehref"` events
(`printDocument.php:175/254`, `print.inc.php:700`) because `$_SESSION['basehref']`
is unset without a session. Pre-existing (reproduced identically against the
pre-fix file), filed separately as a `bug` issue.

Address: `Fixes #1415` (verified + pushed on `fix/issue-1415`).