# Execution History Error Fix

## Issue #544

Fixed: opening **Execution History** (from the modern Test Case Viewer
`gui/templates/testcases/tcView.html` → toolbar button → legacy
`lib/execute/execHistory.php`) and running **Quick Search** on a test project
without test cases wrote two error clusters into the Event Viewer.

## Symptom

1. **SQL error** (`source: DATABASE`), event log showed:

   ```
   1064 - You have an error in your SQL syntax ... near ')' at line 1
   SELECT COUNT(DISTINCT(NH_TC.id)) FROM nodes_hierarchy NH_TC
     JOIN nodes_hierarchy NH_TCV ON NH_TCV.parent_id = NH_TC.id
     JOIN tcversions TCV ON NH_TCV.id = TCV.id ...
   WHERE 1=1 AND NH_TCV.parent_id IN ()
   ```

2. **E_WARNING** (`source: GUI`):

   ```
   Array to string conversion - gui/templates_c/...execHistory.tpl.php - Line 230
   ```

   The page still rendered, but printed a literal `Array` in the custom-field
   cell of every execution row and polluted the Event Viewer.

## Root Cause

### Cluster 1 — `IN ()` SQL syntax error

The issue title attributed this to `execHistory.php`, but the query shape
(`COUNT(DISTINCT(NH_TC.id))`, aliases `NH_TC`/`NH_TCV`) belongs to the
**test case search** code:

- `lib/testcases/tcSearch.php` (legacy Quick Search)
- `api/search/index.php` (modernized Quick Search BFF)

Both build their testcase-id filter like this:

```php
$a_tcid = null;
$tproject_mgr->get_all_testcases_id($tprojectID, $a_tcid);
if (!is_null($a_tcid)) {
    $filter['by_tc_id'] = " AND NH_TCV.parent_id IN (" . implode(",", $a_tcid) . ") ";
}
```

On MariaDB, `testproject::get_all_testcases_id()` uses the CTE branch which
**always assigns `$tcIDs = $itemSet`** — an empty array `[]` (never `null`)
when the project has no test cases. The `is_null()` guard was therefore dead
code for that case and the code assembled `IN ()` → MySQL error 1064.

Reproduced pre-fix: Quick Search on a case-less project logged exactly the
1064 above from both call sites.

### Cluster 2 — Array-to-string warning

`getCustomFields()` in `lib/execute/execHistory.php` did:

```php
$dummy = (array)$tcaseMgr->html_table_of_custom_field_values(...);
$cf[$exec_id] = (count($dummy) > 0) ? $dummy : '';
```

`html_table_of_custom_field_values()` returns an **HTML string**
(`$cf_smarty`). Casting it to `(array)` produced `[0 => '<table>…']` (or
`[0 => '']` when no custom fields exist — count is still 1), so
`$gui->cfexec[$exec_id]` was always an array. The template echoes it directly:

```smarty
{assign var="cf_value_info" value=$gui->cfexec[$tcv_exec.execution_id]}
{$cf_value_info}
```

→ PHP 8.x raises *Array to string conversion* at compiled line 230 and prints
the literal word "Array".

## The Fix (and why)

Minimal guards at the two defective spots; no contract changes to shared
library code:

| File | Change |
|---|---|
| `lib/testcases/tcSearch.php` | `!is_null($a_tcid)` → `!is_null($a_tcid) && count($a_tcid) > 0`; empty project now takes the pre-existing `AND 1 = 0` / "empty testproject" path |
| `api/search/index.php` | same guard |
| `lib/execute/execHistory.php` | `getCustomFields()` keeps the string scalar: `$cf[$exec_id] = ($dummy != '') ? $dummy : '';` |
| `gui/templates/dashio/execute/execHistory.tpl` | grants lookup guarded: `{if isset($gui->grants->exec_edit_notes[$tcv_exec.testplan_id]) && ...}` |
| `gui/templates/tl-classic/execute/execHistory.tpl` | same isset() guard |

Alternatives rejected:

- **Fixing `get_all_testcases_id()` to return null for empty sets** — changes
  the function contract; many other callers (searchCommands,
  uncoveredTestCases, planImport, REST v1/v2/v3) rely on the array return.
- **Iterating `$cf_value_info` in the templates** — would need changes in two
  templates and still leave the data producer lying about its type; fixing the
  producer fixes every consumer.
- **`!empty($a_tcid)`** — equivalent but less explicit; kept the null check to
  mirror surrounding style.

The isset() guard on `grants->exec_edit_notes` also hardens a PHP 8.x edge:
an execution row belonging to a test plan the user cannot access (e.g. session
project switched after the execution was recorded) previously warned with
*Trying to access array offset on null* / *Undefined array key*.

## Verification

Regression suite 36 in `tmp/TLU_Test_Cases.md` — 10/10 PASS:

- Pre-fix repro confirmed both clusters (events #10, #15, #16 on the fixture
  instance).
- Post-fix: exec history renders rows cleanly (no warnings, no literal
  "Array"); search on empty project returns the clean "empty testproject"
  state via both legacy screen and BFF JSON; search on a project with cases
  still finds them; toolbar flow tcView → Execution History verified in the
  browser; Event Viewer shows zero new Error/Warning entries.


## Files Changed

- `lib/testcases/tcSearch.php`
- `api/search/index.php`
- `lib/execute/execHistory.php`
- `gui/templates/dashio/execute/execHistory.tpl`
- `gui/templates/tl-classic/execute/execHistory.tpl`
