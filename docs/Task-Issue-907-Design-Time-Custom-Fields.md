# Task 907 — Design-time custom field editing in Test Specification editor

**Issue:** [#907](https://github.com/sebiboga/testlink-upgraded/issues/907)
**Status:** IMPLEMENTED (2026-09-14)

## The gap

The legacy design-time custom field editor
(`lib/functions/cfield_mgr.class.php`, `lib/testcases/testcaseCommands.class.php`)
renders linked custom field inputs inside the test case editor and persists
values to `cfield_design_values` on create/update via
`cfield_mgr::design_values_to_db()`.

The modern `testSpec.html` editor and BFF (`api/testcases/index.php`) ignored
custom fields entirely: the `get` action had no CF data; `create`/`update`
never called `design_values_to_db`; the frontend form had no CF inputs. Only the
read-only `tcView.html` viewer showed CF values (via `view` action), and only
the BFF `view` action returned CFs.

## Legacy source of truth

- `lib/functions/cfield_mgr.class.php:825-889` — `design_values_to_db()`
  writes `cfield_design_values` rows keyed by `(field_id, node_id=tcversion_id)`.
  Input format: `$hash` with keys `custom_field_<type_id>_<cf_id>` (flat array);
  or pre-built `$cfield` with `['type_id' => ..., 'cf_value' => ...]` entries
  when `$hash_type` is non-null.
- `lib/functions/cfield_mgr.class.php:1873-1992` — `_build_cfield()` parses
  the flat input hash, handling date/datetime conversion (localized string →
  `mktime`), multiselect/checkbox join (`|`-separated), radio select, list,
  etc.
- `lib/functions/cfield_mgr.class.php:454-489` — `get_linked_cfields_at_design()`
  returns all linked CF definitions + assigned value for a given `tproject_id`
  + `node_id`.
- `lib/testcases/testcaseCommands.class.php:345` (create) / `:1235` (update)
  — legacy calls `design_values_to_db($_REQUEST, $tcversion_id, $cf_map)`
  using the flat input hash.
- Custom field types: `cfield_mgr::$custom_field_types` — string (0), numeric
  (1), float (2), email (4), checkbox (5), list (6), multiselection list (7),
  date (8), radio (9), datetime (10), text area (20).
- Possible values for list/checkbox/multiselect/radio are pipe-separated in
  `custom_fields.possible_values`.

## Modern implementation (port)

**Backend `api/testcases/index.php`**

- `get` action: returns `customFields[]` array — each entry contains `id`,
  `name`, `label`, `type`, `typeName`, `possible_values`, `default_value`,
  `required`, `show_on_design`, `enable_on_design`, `length_max`, `value`.
  Fetched via `tcaseMgr->get_linked_cfields_at_design()` for the current
  tcversion; date/datetime values converted from stored unix timestamp to ISO
  `Y-m-d` / `Y-m-d\TH:i`.
- `keywords` action (used by create form): returns `customFields[]` with the
  same structure, fetched at project level (no node_id → empty values); same
  date/datetime ISO conversion.
- `$saveDesignCF($body, $tcaseId, $tcversionId, $tprojectId)` helper:
  reads `body['customFields']` array of `{id, type, value}`, rebuilds
  the flat `custom_field_<type_id>_<cf_id>` hash using the linked CF map,
  converts ISO date/datetime strings back to `mktime` timestamps, and calls
  `$tcaseMgr->cfield_mgr->design_values_to_db($cfield, $tcversionId, null,
  'bff_design_cf')`. Skips CFs with empty values (matching legacy behavior).
- `create` action: after creating the first version, calls `$saveDesignCF()`
  with the new `tcversion_id`.
- `update` action: calls `$saveDesignCF()` after updating the latest version.

**Frontend `gui/templates/testcases/testSpec.html`**

- `cfInputHtml(cf)` renders per-type CF inputs:
  - `list` → `<select>` with `- select -` empty option and `possible_values`
    split on `|`
  - `multiselection list` → `<select multiple>` sized to option count
  - `radio` → radio button group
  - `checkbox` → single checkbox with "Yes" label
  - `string`/`email` → `<input type="text|email">`
  - `numeric`/`float` → `<input type="number" step="any">`
  - `text area` → `<textarea>`
  - `date` → `<input type="date">`
  - `datetime` → `<input type="datetime-local">`
  - All inputs carry `name="custom_field_<type>_<id>"` matching legacy
    naming (with `_input` suffix for date/datetime).
- `customFieldsHtml(cfList)` wraps inputs in a styled `.cf-block` container
  shown below keywords in the edit form.
- `collectForm()` gathers CF values from `$('#cfRows .cf-row')` elements:
  reads `:checked` for radio, `.is(':checked')` for checkbox,
  `$sel.val()` for select/multiselect, and `textarea.cf-input.val()` for
  textareas. Returns `customFields: [{id, type, value}]`.
- `openEditTc()` passes `customFields: r.customFields` to `formHtml()`.
- `openCreateTc()` passes `customFields: r.customFields` from the `keywords`
  API response to `formHtml()`.
- `renderTcView()` reads-only block displays CF label/value pairs after
  keywords.
- CSS additions: `.cf-block`, `.cf-block-title`, `.cf-row` sizing.

**i18n**

- New keys in all 10 locale bundles:
  `tspec.customFields`, `tspec.yes`, `tspec.cfSelectEmpty`.

## Verification

Browser checks on a local instance (project `peviitor.ro`, TC id 3):

**Edit + Update:**
1. Nine CFs seeded for project 1: tier (list), priority (list), owner (string),
   issue_tracker_url (email), complexity (radio), target_release (date),
   requires_signoff (checkbox), verification_env (multiselect list),
   notes (text area).
2. Edit TC → form renders all 9 CF inputs with correct types.
3. Fill values (Gold, High, alice, email URL, Simple, 2026-12-31, checked,
   prod, "CF note") → Save → read-only view displays all values correctly.
4. DB `cfield_design_values` rows: all 9 fields persisted; date stored as
   unix timestamp (1798675200), rest as strings.

**Create:**
5. New TC ("TM-907 create CF test") with 4 CFs filled (tier=Silver,
   owner=bob, complexity=Simple, notes="created with CF") → Save.
6. DB: 4 `cfield_design_values` rows written for the new tcversion;
   unfilled CFs correctly absent (legacy behavior).

**Read-only view:** CFs display correctly in `renderTcView()`.

**Console:** Zero errors/warnings.

## Files changed

- `api/testcases/index.php`
- `gui/templates/testcases/testSpec.html`
- `gui/templates/i18n/{en,de,es,fr,it,ja,pt,ro,ru,zh}.json`
- `CHANGELOG`
- `docs/Task-Issue-907-Design-Time-Custom-Fields.md`
