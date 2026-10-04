
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

---

# Task — Issue #1078: working custom-field filter on Search Requirements

**Status:** ✅ Implemented & verified (branch `task/issue-1078`)
**Issue:** [#1078](https://github.com/sebiboga/testlink-upgraded/issues/1078)

## The gap

Legacy TestLink 1.9.20 offered a **"Custom field"** select + **"Custom Field Value"** textbox on
the Search Requirements form:

- `lib/requirements/reqSearchForm.php:51-57` — `$gui->design_cf = get_linked_cfields_at_design($tpid, 1, null, 'requirement')`
  with the **default access key `'id'`**, so the map was keyed by numeric cfield id.
- `gui/templates/dashio/requirements/reqSearchForm.tpl:168-186` —
  `{foreach from=$gui->design_cf key=cf_id item=cf}<option value="{$cf_id}">{$cf.label|escape}</option>`,
  i.e. **the option value was the cfield id**.
- `lib/requirements/reqSearch.php:196-199 + 351-365` — `custom_field_value` is in `$strnull`, and
  when `custom_field_id > 0` both branches of the search UNION get

  ```sql
  JOIN cfield_design_values CFD ON CFD.node_id = REQV.id   -- and REQR.id on the revision branch
  AND CFD.field_id = <id> AND CFD.value like '%<value>%'
  ```

The modern screen rendered the same two controls, but the filter could **never** filter. Two
independent defects:

1. `api/requirements/index.php:287-325 buildMeta()` serialized each custom field as
   `{name,label,type,verbose_type}` — **no numeric `id`** (the id was in the row all along:
   `get_linked_cfields_at_design()` selects `CF.*`). `searchReq.html` therefore rendered
   `<option value="undefined">`, `parseInt()` → `NaN`, so `custom_field_id` was **never sent**.
2. `GET /search` did not read `custom_field_value` into `$args` (missing from `$strnull`), so
   `reqBuildSearchSql()` fell back to `''` and the predicate degenerated to
   `CFD.value like '%%'` — "carries **any** value for this field".

Measured before the fix (fixture: 3 requirements, CF `Req Severity` with `high` / `medium` / none):

| request | before | after |
|---|---|---|
| `custom_field_id=9101&custom_field_value=high` | 2 rows (REQ-1 **and** REQ-3) | **1 row** (REQ-1) |
| `custom_field_id=9101&custom_field_value=medium` | 2 rows | **1 row** (REQ-3) |
| `custom_field_id=9101&custom_field_value=` | 2 rows | 2 rows (legacy `like '%%'`) |
| `custom_field_id=9101&custom_field_value=zzz` | 0 rows | 0 rows, "no results" panel |
| from the UI (`cf.id` undefined → param dropped) | 3 rows — filter inert | see below |

## Implementation

### 1. `api/requirements/index.php` — `buildMeta()` emits the numeric id

```php
'id' => intval($cf['id']),   // legacy: {foreach from=$gui->design_cf key=cf_id ...}
```

Additive: the other 4 consumers of `buildMeta()` only read `$cf['type']` / `$cf['name']` / `count()`.

### 2. `api/requirements/index.php` — `GET /search` reads `custom_field_value`

`custom_field_value` (and legacy's `targetRequirement`) added back to `$strnull`, mirroring
`lib/requirements/reqSearch.php:196-199`. `reqBuildSearchSql()` already carried the legacy JOIN +
`field_id` / `like` predicates for **both** the `ver` and the `rev` branch of the UNION — only the
parameter plumbing was missing.

### 3. `gui/templates/requirements/searchReq.html` + `searchReqSpec.html` — client guard

```js
var cfId = parseInt(cf.id, 10);
if (!(cfId > 0)) { return; }          // never render value="undefined"
$('#custom_field_id').append('<option value="' + cfId + '">' + esc(cf.label) + '</option>');
```

Note: `prepare_string()` escapes quotes but **not** LIKE wildcards, so a value of `%` or
`_` in "Value contains" is interpreted as a wildcard. That is unchanged legacy behaviour
(`lib/requirements/reqSearch.php:353` uses the same helper) — not a regression from this fix.

`searchReqSpec` was **not** broken server-side — its route (`api/requirements/index.php:2062-2071`)
already emitted `id` from the map key — but it got the same guard.

### 4. i18n

**No new keys.** `reqsearch.customField` ("Custom field") and `reqsearch.customFieldValue`
("Value contains") were already translated in all 10 modern locale bundles.

## Verification (14-case suite "Task — Issue #1078")

Browser, admin, project `CF1:CF1078 Project`:

| Custom field | Value contains | match count | rows |
|---|---|---|---|
| Req Severity (9101) | `high` | 1 | `REQ-1:REQ-1 has severity` |
| Req Severity (9101) | `medium` | 1 | `REQ-3:REQ-3 medium severity` |
| Req Severity (9101) | *(empty)* | 2 | legacy `like '%%'` |
| Req Severity (9101) | `zzz` | 0 | "no results" panel |
| *(none)* | `high` | 3 | value inert without a field |
| deep link `?custom_field_id=9101&custom_field_value=high` | — | 1 | URL prefill works |
| Reset | — | — | select → `0`, value cleared |

Captured wire request after the fix:
`/api/requirements/index.php/search?tproject_id=9001&custom_field_value=high&custom_field_id=9101`
(before the fix `custom_field_id` was absent from the request entirely).

`searchReqSpec` re-checked with a spec-scoped CF 9102 "Spec Kind": options `["0|", "9102|Spec Kind"]`,
filter `functional` → `Matches: 1`. Event Viewer: `SELECT COUNT(*) FROM events WHERE log_level<>16` → **0**.
Browser console: no errors or warnings. `php -l` + `node --check` clean on all touched files.

(Screenshot: `docs/screenshots/issue-1078-searchReq-customfield-filter.png`.)
