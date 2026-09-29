# Task 1033 — "Available For" (custom-field node type) column in cfieldsView

**Issue:** [#1033](https://github.com/sebiboga/testlink-upgraded/issues/1033)
**Status:** IMPLEMENTED & VERIFIED (2026-09-29) — branch `task/issue-1033`
**Screen:** `gui/templates/cfields/cfieldsView.html` (Custom Fields list)

## The gap

The legacy Custom Fields list told you, on every row, **which kind of object a
custom field applies to** (Test Case, Test Plan, Requirement Specification, …).
The modern list did not — the node type was only visible after opening the edit
modal, so a manager scanning the list could not tell a test-plan field from a
requirement field.

### Legacy behaviour

`gui/templates/tl-classic/cfields/cfieldsView.tpl:39-70` renders six columns:

```smarty
<th class="{$noSortableColumnClass}">{$labels.enabled_on_context}</th>
<th class="{$noSortableColumnClass}">{$labels.display_on_exec}</th>
<th>{$tlImages.sort_hint}{$labels.available_on}</th>   <- sortable
...
<td>{$cf_def.enabled_on_context}</td>
<td ...>{if $cf_def.show_on_execution}<span>{$tlImages.checked}</span>{/if}</td>
<td>{lang_get s=$cf_def.node_description}</td>          <- node type, localized
```

Two details drive the port:

1. `available_on` is the **node type** — `node_types.description` reached
   through `custom_fields` ↔ `cfield_node_types` ↔ `node_types`, produced by
   `cfield_mgr::get_all()` (`lib/functions/cfield_mgr.class.php:968-971`):

   ```sql
   SELECT CF.*, NT.description AS node_description, NT.id AS node_type_id
     FROM custom_fields CF, cfield_node_types CFNT, node_types NT
    WHERE CF.id = CFNT.field_id AND NT.id = CFNT.node_type_id
    ORDER BY CF.name
   ```

2. It is rendered through `lang_get`, so the user sees a human label
   ("Test Case"), not the DB slug (`testcase`). It is also **sortable**, unlike
   the two enable/exec context columns.

### Measured repro (pre-fix)

Fixtures: four custom fields bound to four different node types
(`testcase`, `testplan`, `requirement_spec`, `testcase_version`).

`GET /api/cfields/index.php` (authenticated, admin) — the data is already there:

```json
{"status":"ok","items":[
 {"name":"tlu_deployment","node_description":"testplan","node_type_id":5,"enable_on_execution":1},
 {"name":"tlu_reqspec","node_description":"requirement_spec","node_type_id":6,"enable_on_design":1},
 {"name":"tlu_tier","node_description":"testcase","node_type_id":3,"enable_on_design":1}],
 "total":3,"can_manage":1}
```

DOM of `cfieldsView.html` at the same moment:

```
HEADERS: Label, Name, Type, Active, Display on execution, Available On, Actions
["Tlu Deployment","tlu_deployment","email","Yes","-","Execution",""]
["Tlu ReqSpec","tlu_reqspec","numeric","Yes","-","Design",""]
["Tlu Tier","tlu_tier","email","Yes","","Design",""]
```

Zero occurrences of "Test Case" / "Test Plan" / "Requirement Specification" in
the list. `cfToJSON` already emitted `node_description`
(`api/cfields/index.php:69`) — **the BFF needed no change; the gap was purely a
front-end omission.**

## Implementation

### Screen — `gui/templates/cfields/cfieldsView.html` (Refs #1033)

- **`:64`** — new `<th data-i18n="cf.availableFor">Available For</th>`, placed
  right after **Type** so the two "what kind of thing is this" columns (value
  type + node type) read together, and before the enable/execution columns. The
  modern screen keeps its own column set (`Active`, `Display on execution`,
  `Available On`) and only gains the one column legacy had and it lacked.
- **`:169-179`** — new helper:

  ```js
  function nodeTypeLabel(description) {
    var slug = String(description == null ? '' : description);
    if (!slug) return '-';
    var key = 'cf.node.' + slug;
    return TLi18n.has(key) ? TLi18n.t(key) : slug;
  }
  ```

  `TLi18n.has()` guards the lookup because `TLi18n.t()` echoes the key back when
  it is missing — without the guard a node type with no translation would render
  the literal string `cf.node.<slug>`. The raw-slug fallback means a node type
  added to `node_types` before its translation exists still shows something
  meaningful instead of an empty cell.
- **`:275`** — cell appended to the row array:
  `escAttr(nodeTypeLabel(cf.node_description))` (escaped like every other
  plain-text cell; the label is a DB value).
- **`:287`** — DataTables `columns` array extended with one more `null`, so the
  Actions column stays last and keeps `{orderable: false, searchable: false}`.
  The new column inherits DataTables' default orderable+searchable behaviour,
  matching legacy, where `available_on` was one of the sortable columns.

`nodeMap` is untouched — it remains the single source for the edit modal's
"Node Type" dropdown, so the modal prefill is unchanged.

### i18n — all 10 bundles

`gui/templates/i18n/{en,ro,de,es,fr,it,ja,pt,ru,zh}.json`, +15 keys each:

- `cf.availableFor` — column header ("Available For" / "Disponibil Pentru" /
  "Доступно Для" / "利用可能対象" / …).
- `cf.node.<node_types.description>` — 14 keys, one per row of the `node_types`
  table: `testproject`, `testsuite`, `testcase`, `testcase_version`, `testplan`,
  `requirement_spec`, `requirement`, `requirement_version`, `testcase_step`,
  `requirement_revision`, `requirement_spec_revision`, `build`, `platform`,
  `user`. The set mirrors the DB 1:1, so every node type a custom field can be
  bound to has a translated label.

The `cf.node.*` prefix (rather than reusing the generic top-level `testcase` /
`testsuite` / `build` / `platform` keys that other screens contribute) keeps this
screen's namespace self-contained, consistent with the existing `cf.msg.*`
convention.

## Verification

Test suite: `Suite 1033` in `tmp/TLU_Test_Cases.md` — **14 cases, 14 PASS**.

| # | Step | Result |
|---|------|--------|
| 1 | Read the `<thead>` | 8 columns, 4th is `Available For` |
| 2 | Read cell 4 of every row | `Test Plan` / `Requirement Specification` / `Test Case Version` / `Test Case` |
| 3 | Issue repro (`tlu_deployment`, node_type testplan) | row states `Test Plan`, matching legacy `available_on` |
| 4 | Reload with `&locale=ro` | header `Disponibil Pentru`; values `Plan de Testare`, `Specificație de Cerință`, `Caz de Testare` |
| 5 | Sort column 4 ascending | ordered by label |
| 6 | Sort column 4 descending | exact reverse |
| 7 | Search `Test Plan` | matches exactly `tlu_deployment`; clearing restores all rows |
| 8 | Click edit on `tlu_deployment` | modal opens, `#editNodeType=testplan`, `#editType=4` (regression) |
| 9 | Temporary unmapped node type `zz_future_node` | cell shows the raw slug, not a raw i18n key |
| 10 | Temporary empty-list probe | header keeps 8 columns, body "No data available in table", no misalignment |
| 11 | `node --check` on the inline script | `JS SYNTAX OK` |
| 12 | `python3 -m json.tool` on all 10 bundles | OK ×10 |
| 13 | Browser console | no errors, no warnings |
| 14 | Event Viewer / `events` | 0 Error/Warning rows |

### "Available On" vs "Available For" — do not conflate them

The two similarly-named columns answer different questions and both are needed:

| Column | Question | Source | Values |
|---|---|---|---|
| **Available For** | *what kind of object does this field apply to?* | `node_types.description` | exactly one (Test Case, Test Plan, …) |
| **Available On** | *in which UI context is the field enabled?* | `enable_on_design` / `enable_on_execution` / `enable_on_testplan_design` | zero or more (Design, Execution, Test Plan Design) |

`Tlu Deployment` therefore reads `Available For = Test Plan` /
`Available On = Execution` — a field enabled during test execution that is bound
to test-plan nodes. Before the port only the second half was visible.

## Files

| File | Purpose |
|---|---|
| `gui/templates/cfields/cfieldsView.html` | the new column: `<th>`, `nodeTypeLabel()`, cell, DataTables column count |
| `gui/templates/i18n/*.json` (10) | `cf.availableFor` + `cf.node.*` (14 node types) |
| `CHANGELOG` | 2.0.1 entry under the matching section |
| `docs/Task-Issue-1033-Cfields-Node-Type-Available-For-Column.md` | this page |
| `tmp/TLU_Test_Cases.md` | `Suite 1033` |

No BFF change was required — `cfToJSON` (`api/cfields/index.php:69`) already
returned `node_description` before the fix.

## Re-testing

```sql
-- fixtures (freshly imported DB)
INSERT INTO custom_fields (name,label,type,possible_values,default_value,valid_regexp,
  length_min,length_max,show_on_design,enable_on_design,show_on_execution,enable_on_execution,
  show_on_testplan_design,enable_on_testplan_design) VALUES
 ('tlu_tier','Tlu Tier',4,'','','',0,0,1,1,1,0,0,0),
 ('tlu_deployment','Tlu Deployment',4,'','','',0,0,0,0,0,1,0,0);
INSERT INTO cfield_node_types (field_id,node_type_id) SELECT id,3 FROM custom_fields WHERE name='tlu_tier';
INSERT INTO cfield_node_types (field_id,node_type_id) SELECT id,5 FROM custom_fields WHERE name='tlu_deployment';
```

Log in `admin`/`admin`, open
`http://localhost:8082/gui/templates/cfields/cfieldsView.html?tproject_id=0&tplan_id=0`
and read column 4 — it shows `Test Case` / `Test Plan`. Append
`&locale=ro` to confirm the translation follows the bundle.
