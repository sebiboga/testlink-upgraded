
---

# Task — Issue #1077: "Test Case ID" filter on Search Requirements (search by linked test case)

**Status:** ✅ Implemented & verified (branch `task/issue-1077`)
**Issue:** [#1077](https://github.com/sebiboga/testlink-upgraded/issues/1077)

## The gap

Legacy TestLink 1.9.20 let you narrow a requirement search down to the requirements
**linked to a given test case**:

- `gui/templates/dashio/requirements/reqSearchForm.tpl:157-161` rendered a
  **"Test Case ID"** input (`name="tcid"`, label `$labels.th_tcid` =
  "Test Case ID"), **pre-filled with the project TC prefix** (`$gui->tcasePrefix`).
- `lib/requirements/reqSearch.php:368-391` filtered only when the value actually
  changed (`strcmp($argsObj->tcid, $guiObj->tcasePrefix) != 0`), stripped the prefix and
  added

  ```sql
  JOIN req_coverage RC     ON RC.req_version_id = NH_REQV.id
  JOIN nodes_hierarchy NH_TCV ON NH_TCV.id = RC.tcversion_id
  JOIN tcversions TCV      ON TCV.id = NH_TCV.id
  AND TCV.tc_external_id = '<id>'
  ```

  on **both** branches of the search UNION (REQ versions *and* REQ revisions).

The modern screen had no such control at all (measured: the rendered form's labels were
`Doc ID, Name, Scope, Status, Type, Version, Expected coverage, Relation type, Custom
field, Value contains, Log message, Creation date from/to, Modification date from/to`),
so the `tcid` parameter that `GET /api/requirements/index.php/search` already implemented
was **unreachable from the UI**.

## Implementation

### 1. `api/requirements/index.php` — the legacy guard in `reqBuildSearchSql()`

- the project prefix (`testproject::getTestCasePrefix($tpid) . glue_character`, the same
  value as `lib/requirements/reqSearch.php:44-45`) is resolved and the filter is
  **skipped when the submitted value is unchanged** from it;
- the **whole prefix** is stripped from the entered value (legacy
  `str_replace($guiObj->tcasePrefix, "", $tcid)`), falling back to dropping just the glue
  character, so `TL-42`, `42` and `42-` all resolve to `42`;
- the `req_coverage → NH_TCV → tcversions` JOIN and its `ver`/`rev` duplication are kept
  as they were.

### 2. `gui/templates/requirements/searchReq.html` — the control

- new `#grpTcid` group, **first field of the criteria grid**, label `data-i18n="search.tcid"`
  rendered as `input-group` with a `#tcidPrefixAddon` addon — the shape already used by
  the modern `gui/templates/search/searchView.html:76-84`;
- prefilled from `search-context`'s (previously unused) `context.tcase_prefix`;
- `doSearch()` sends `tcid` **only when it differs from the prefix**;
- `?tcid=` deep-link prefill and Reset restoring the prefix default;
- a notice line explaining that the project prefix in the field is ignored
  (`search.prefixIgnored`).

### 3. i18n

**No new keys** — `search.tcid` ("Test Case ID") and `search.prefixIgnored` already exist,
translated, in all 10 modern locale bundles (en, de, es, fr, it, ja, pt, ro, ru, zh).
All 10 bundles re-validated with `python3 -m json.tool`.

## Verification (12-case suite "Task — Issue #1077")

| input | before | after |
|---|---|---|
| `TL-` (untouched default) | 0 rows (bogus filter on external id `TL`) | whole-project result set, and **no `tcid` in the request at all** |
| `42` | 1 row | 1 row |
| `TL-42` | **0 rows** | 1 row |
| `42-` | 0 rows | 1 row |
| `999` (unlinked TC) | 0 rows | 0 rows |
| `42` + Name `ZZZ-no-match` | 0 rows | 0 rows (AND semantics kept) |
| deep link `?tcid=TL-999` | n/a | field prefilled, 0 rows |
| Reset | – | field back to `TL-`, results hidden |

Also: Event Viewer `events` table — only the `LOGIN` audit row, **no new Error/Warning**;
browser console — no errors or warnings; `php -l` clean on both files.
