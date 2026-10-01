# Task — Issue #1041: `has_been_executed` attribution in the Test Case Viewer (EXECUTED badge)

## What was missing

The Test Case Viewer shows an **EXECUTED** badge on a version card
(`gui/templates/testcases/tcView.html:425-427`, CSS `.badge-executed` at `:38`) and, when the
opened version is the executed one, a banner — *"This version has been executed and can not be
edited."* (`tcview.executedNoEdit`) or *"…has been executed and can be edited."*
(`tcview.executedCanEdit`), rendered by `renderBanners()` (`tcView.html:368-373`). The same
flag also hides the **Edit** button through `canEditCurrent()` (`tcView.html:349`), feeds
`canAssignPlatforms()` (`api/testcases/index.php:1167`), the relations `canEdit`
(`api/testcases/index.php:1348`) and the `(✓ executed)` marker of the version dropdown in
`testSpec.html:955-960`.

Legacy derives that flag from `testcase::get_versions_status_quo()`
(`lib/functions/testcase.class.php:3057-3119`), called with a single argument from
`testcase.class.php:1091` — **no `tcversion_id`, no `testplan_id`**, so every version of the
test case and every test plan is considered. `tcView.tpl:113-115` reads
`$gui->status_quo[idx][$tcVersionID].executed` into `$hasBeenExecuted`, which gates the marker
(`:228`), the `downloadOnlyAfterExec` attachment gate (`:192-193`, `:314`) and
`tcViewViewer.inc.tpl:90-96`.

## The gap

The legacy loop does **not** flag the node an execution row points at:

```php
// lib/functions/testcase.class.php:3102-3116
foreach($rs as $elem) {
  $tcvid = null;
  if ($elem['tcversion_number'] != $elem['version']) {     // recorded number != node version
    if (!is_null($elem['tcversion_number'])) {
      $tcvid = $version_id[$elem['tcversion_number']]['id'];   // -> node whose VERSION NUMBER matches
    }
  } else {
    $tcvid = $elem['tcversion_id'];                            // -> the execution's own node
  }
  if (!is_null($tcvid)) { $recordset[$tcvid]['executed'] = $tcvid; }
}
```

`$version_id` (`:3082`) is a version-number → node-id map built over **all** versions of the
test case.

The modern BFF instead asked:

```sql
SELECT DISTINCT tcversion_id FROM executions WHERE tcversion_id IN (...)
```

which flags the execution's own node and never looks at `executions.tcversion_number`. On a
healthy install the two coincide, which is why this stayed invisible.

The issue as filed reported the sharper symptom — `array_keys()` on the numerically indexed
recordset returned by `testcase::get_by_id()`, producing `WHERE tcversion_id IN (0,1)` and a
permanently `false` flag. That part was already repaired by commit `4f549b24f`; the
number-based remapping had not been ported.

### Measured

Fixture `tmp/fixtures_1041.php` (project `SM1041`, suite, plan, three two-version cases). The
ground truth is produced by calling the **legacy** function live
(`php tmp/verify_1041.php`), the candidate by calling the BFF over HTTP
(`bash tmp/verify_1041.sh`).

| case | data | legacy | modern before | modern after |
|---|---|---|---|---|
| `SM1041EXEC`   | execution on the v1 node, `tcversion_number=1` | `{1:true, 2:false}` | `{1:true, 2:false}` | `{1:true, 2:false}` |
| `SM1041NONE`   | no execution | `{1:false, 2:false}` | `{1:false, 2:false}` | `{1:false, 2:false}` |
| `SM1041NUMBER` | execution on the **v1 node**, `tcversion_number=2` | `{1:false, 2:true}` | **`{1:true, 2:false}`** ✗ | `{1:false, 2:true}` |

A second, smaller divergence fell out of the same read: because the modern flag was derived
from `$versionsRaw`, which `?tcversion_id=` narrows to a single version, opening one version of
a test case silently lost the execution context that legacy (which builds the status quo over
all versions) still had.

## The fix

`api/testcases/index.php` gains one shared helper and both endpoints that expose the flag use
it:

* **`tcVersionExecutedSet($dbHandler, $tcaseId)`** (`api/testcases/index.php:1755-1818`) —
  builds the `version number → node id` map over all versions of the test case, selects
  `DISTINCT E.tcversion_id, E.tcversion_number, TCV.version` for every version node across all
  test plans, and applies the legacy branch verbatim (differing number → the node with that
  number, matching number → the execution's own node, NULL number → nothing flagged). Returns
  `{tcversion_id: 1}`.
* **`action=view`** (`api/testcases/index.php:1093-1099`) — the inline row-id collection and
  the `IN (...)` query are replaced by `$executedSet = tcVersionExecutedSet($db, $tcaseId);`.
* **`action=version_list`** (`api/testcases/index.php:2330-2334`) — same call, replacing its
  own inline query, so the viewer and the test-spec dropdown can no longer disagree.

No screen and no i18n change was needed: the flag already drove the badge, the banners and
`canEditCurrent()` — only the attribution behind them was wrong.

## Verification

* Suite 1041 in `tmp/TLU_Test_Cases.md` — **14/14 PASS** (`python3 tmp/suite_1041.py`), which
  diffs the live legacy answer against both endpoints for all three fixture cases plus the
  `?tcversion_id=` filter, `is_latest`, the 404/anonymous paths, the `version_list` grants
  block and all 10 i18n bundles.
* Browser (admin): `SM1041NUMBER` now shows the **EXECUTED badge on the "Version 2 LATEST"
  card** with *"This version has been executed and can not be edited."* — before the fix the
  badge was on Version 1. `SM1041EXEC` badges Version 1 only, `SM1041NONE` badges nothing,
  and opening the executed version directly hides the Edit button.
* Regression: `bash tmp/verify_1038.sh` **21 PASS / 0 FAIL**; Event Viewer `log_level=16`
  (audit) only from this work; console clean.

Commit `d05417318` (Refs #1041).