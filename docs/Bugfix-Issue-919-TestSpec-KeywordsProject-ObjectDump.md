# Bugfix — Issue #919: Test Specification editor keyword picker shows PHP object dumps and wrong keyword ids

## Problem

In the modern Test Specification editor (`gui/templates/testcases/testSpec.html`)
the **KEYWORDS** list of the **Edit Test Case** form rendered huge PHP object
dumps instead of keyword names, and the checkboxes carried **positional** indexes
(0, 1, 2) instead of real keyword ids. Keyword editing was effectively broken:
a keyword with id 2 was never pre-checked even when assigned, and saving
reassigned the wrong keywords.

## Root Cause

`api/testcases/index.php` (action `get`, ~line 764) built the `keywordsProject`
payload from `$tprojectMgr->getKeywords($tprojectId)`. That method
(`lib/functions/testproject.class.php:1164-1167`) returns **`tlKeyword` objects**
(via `tlKeyword::getByIDs`). The object has **no `__toString()`**
(`lib/functions/tlKeyword.class.php:25-34`), so:

```php
$projKw[intval($kid)] = strval(is_array($kr) ? ($kr['keyword'] ?? '') : $kr);
```

ran PHP's object-to-string fallback and produced the giant
`tlObject, tlKeyword Object(...)` dump — one string per keyword. Moreover
`getKeywordIDsFor` (`testproject.class.php:1250-1256`) returns a **flat
positional** id list, so the loop keys were `0..n-1`, not the keyword ids.

`testSpec.html` `formHtml()` (`:540-543`) iterates `Object.keys(kwProject)` →
`["0","1"]` and renders `value="0"/"1"` (wrong ids) and the object dump as the
label. `collectForm()` (`:485`) submits those values; the BFF's `$kwString`
filter (`api/testcases/index.php:834-840`) drops `id <= 0`, so id 0 silently
vanished and id 1 survived "by coincidence" while the real assigned id 2 was lost.

## Fix — serialize keywords with real ids in the BFF

The frontend already implements the id→name map contract for `keywordsAssigned`
(the chips at `testSpec.html:427` and the checkbox loop at `:543`). The minimal
correct fix is therefore purely in the BFF `get` action: extract the id and name
from the `tlKeyword` objects and key the map by the real id:

```php
$projKw = [];
try {
    $pk = $tprojectMgr->getKeywords($tprojectId);
    if (!is_null($pk)) {
        foreach ($pk as $kwo) {
            $projKw[intval($kwo->dbID)] = strval($kwo->name);
        }
    }
} catch (Exception $e) {
    $projKw = [];
}
```

`keywordsProject` now returns `{"1":"Smoke Test","2":"Performance"}` —
keyed by real id, valued by name — which satisfies the existing editor contract
with **zero frontend changes**, and pre-checking now works because the
`assigned` set (real ids from `keywordsAssigned`) intersects the checkbox ids.
This also aligns with the established pattern in `api/keywords/index.php:64-71`
(`kwToJSON`), which already reads `$kwo->dbID` / `$kwo->name`.

Blast radius: `keywordsProject` is consumed by exactly one screen
(`testSpec.html:602`) and produced by exactly one endpoint
(`api/testcases/index.php:794`), so the change cannot leak elsewhere.

## Files Changed

| File | Change |
|---|---|
| `api/testcases/index.php` | `$projKw` loop in action `get` → `{realId: name}` from `tlKeyword::dbID`/`name` |

1 file, 2 lines changed. Commit: (see fix/issue-919 branch).

## Testing / Evidence

- **Primary symptom (before):** `GET ?action=get&tcase_id=3` →
  `keywordsProject` = JSON array of two stringified `tlKeyword Object(...)`
  dumps; `keywordsAssigned = {"2":"Performance"}`.
- **Primary symptom (after):** same request →
  `keywordsProject = {"2":"Performance","1":"Smoke Test"}`,
  `keywordsAssigned = {"2":"Performance"}` — real ids, real names.
- **Browser edit dialog (after):** `#kwList input[type=checkbox]` =
  `[{value:"1",label:"Smoke Test",checked:false},{value:"2",label:"Performance",checked:true}]`
  — labels are names, real ids, correct pre-check.
- **Save path:** checking both keywords → `testcase_keywords` holds ids 1 and 2;
  unchecking "Smoke Test" → only id 2 remains; API matrix `keywords:[1]` then
  `keywords:[2]` → table holds exactly 1, then exactly 2.
- **Event Viewer:** no new Error/Warning entries (only level-16 audit events).
- **Code review subagent:** PASS.

## Regression Test Case

Suite `Regression — Issue #919` (8/8 PASS) appended to `tmp/TLU_Test_Cases.md`
— BFF payload shape, edit-dialog labels, real-id pre-check, save/remove matrix,
API save matrix, Event Viewer, console cleanliness.

## Related

While testing, a **separate** defect surfaced in the same editor: the
**Create New Test Case** form never loads the project keywords at all
(`openCreateTc()` passes an empty map to `formHtml`). It has a different root
cause (no keywords fetch in the create path, not a payload shape issue) and is
tracked as issue **#981**.