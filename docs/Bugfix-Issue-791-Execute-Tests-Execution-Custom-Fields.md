# Bugfix: Issue #791 — Execute Tests screen: execution-time custom fields not rendered as inputs (regression)

## Summary

The modernized **Execute Tests** screen (`gui/templates/execute/execTest.html` +
`api/execute/index.php`) dropped the legacy **execution-time custom fields**
feature entirely. When a test project defined custom fields with
`show_on_execution=1` and `enable_on_execution=1`, the tester could no longer
enter a value for them on the execute form, and any previously-saved value was
never persisted to `cfield_execution_values`. This is a regression of a legacy
TestLink feature.

## Legacy Behavior

- `gui/templates/tl-classic/execute/inc_exec_test_spec.tpl:119-128` renders the
  execution-time custom field **input** table inside `cfields_exec_time_tcversionid_<tcv>`
  for the test case (`inc_exec_controls.tpl:108-116`, `inc_exec_img_controls.tpl:136-147`).
- `lib/execute/execSetResults.php:1968-1971` builds those inputs via
  `html_table_of_custom_field_inputs($testcase_id, null, 'execution', "_".$testcase_id, ...)`.
- `gui/javascript/execSetResults.js:113-131` (`checkCustomFields`) and
  `gui/javascript/cfield_validation.js:36` (`validateCustomFields`) validate them
  on submit.
- `lib/functions/exec.inc.php:94-100,203` (`write_execution()`) harvests every
  `custom_field_*` key out of `$exec_data`, then calls
  `execution_values_to_db()` → persists/updates/deletes `cfield_execution_values`.

## Root Cause

1. `api/execute/index.php` `?action=tcDetails` returned **no** execution-CF data:
   no `exec_cfields_html`, no `exec_cfields` metadata. The reason the legacy
   single-execution render works is the `testcase` class **overrides**
   `get_linked_cfields_at_execution()` (`testcase.class.php:5419`) to resolve the
   project from the tcversion — the BFF never invoked the rendering helper at all.
2. `gui/templates/execute/execTest.html` `renderForm()` had **no** custom field
   section, and `saveExecution()` never collected CF values.
3. `api/execute/index.php` `?action=save` never forwarded `custom_field_*` keys
   into `$execData`, so `write_execution()` had nothing to persist.

Live proof (fresh fixture, admin/admin): opening the case on the modernized
screen showed **no** CF input, and saving produced `SELECT * FROM
cfield_execution_values` → **0 rows** / empty.

### The name-parsing gotcha

The legacy input names are `custom_field_<type>_<id>_<tcaseId>` (e.g.
`custom_field_0_1_3`). **Splitting on `_` yields 5 parts, not 4**, because the
literal prefix `custom_field` itself contains an underscore:

```
[0]=custom  [1]=field  [2]=type  [3]=id  [4]=tcaseId
```

Legacy `write_execution()` uses `cf_nodeid_pos=4` (`exec.inc.php:86`) for the
tcase id and `_build_cfield()` uses `cftype_pos=2` / `cfid_pos=3`
(`cfield_mgr.class.php:1881-1883`). Any forwarding/validation code MUST use the
same 5-part parsing: `parts[2]=type`, `parts[3]=id`. (An earlier attempt used
`parts[1]`/`parts[2]` → "field_0", never matched the real `0_1`, silently dropped
the values.)

## Fix

**Backend — `api/execute/index.php`:**

- `?action=tcDetails`: when the user holds the Execute right (`$canExecute`),
  compute `exec_cfields_html` via the exact legacy call
  `html_table_of_custom_field_inputs($tcaseId, null, 'execution', '_'.$tcaseId, null, null, $tprojectId)`
  (which returns the correct `custom_field_<type>_<id>_<tcaseId>` inputs — passing
  `$tplan_id` in that argument returns empty/NULL), plus `exec_cfields` metadata
  (`id/name/label/type/required`) via
  `get_linked_cfields_at_execution($tcversionId, null, null, null, null, $tprojectId)`.
  Both baked into the JSON payload as `exec_cfields_html` + `exec_cfields`.
- `?action=save`: for every payload key prefixed `custom_field_`, verify its
  `(type,id)` against the field's real linked execution CFs (**`$allowedCf`**,
  preventing a forged payload from writing unrelated values), then forward the
  allowed keys into `$execData` so `write_execution()` →
  `execution_values_to_db()` persists them.

**Frontend — `gui/templates/execute/execTest.html`:**

- `renderForm()` renders a **"Custom Fields"** section (`#execCFields`) after
  preconditions when `current.exec_cfields_html` is present.
- Read-only mode disables `#execCFields` inputs.
- `saveExecution()` collects every CF input value into the FormData payload under
  its `custom_field_*` name (checkbox only when checked; multi-select per value;
  date/datetime `_input|_hour|_minute|_second` segments ride along naturally).
- New `validateExecCustomFields()` mirrors `cfield_validation.js`: required
  (empty → block + toast `exe.cfRequired`), numeric (type 1), float (type 2),
  email (type 4); generic failures → `exe.cfInvalid`.
- **Checkbox-group (type 5) required is "at least one checked" in the group**,
  not "every checkbox": required boxes are grouped by their base name (stripping
  the `[]`); an empty group blocks save with `exe.cfRequired`, but checking any
  one box satisfies the requirement. A later review found the initial required
  logic was per-checkbox-inverted and rejected valid single selections — fixed so
  the whole group validates on at least one checked box.
- Minimal CSS for `.exec-cf-block` / `.cf-inputs`.

**i18n:** added `exe.customFields`, `exe.cfRequired`, `exe.cfInvalid` to all 10
locale bundles (`de,en,es,fr,it,ja,pt,ro,ru,zh`), each validated with
`python3 -m json.tool`.

## Why This Approach

- **Legacy-parity rendering + persistence**: reuses the exact legacy helper
  (`html_table_of_custom_field_inputs`) and the exact legacy write path
  (`write_execution()` → `execution_values_to_db()`). No schema change, no new
  endpoint, no duplicated CF logic.
- **Minimal blast radius**: only the modernized Execute screen touches these two
  files + i18n; the legacy `execSetResults.php` screen and `write_execution()`
  are untouched.
- **Forgery-safe forward**: validating each submitted CF name against the
  version's actually-linked fields prevents a crafted payload from inserting
  arbitrary rows.
- **Alternatives rejected**: re-implementing a bespoke CF renderer in the BFF was
  unnecessary and risked drifting from the legacy input naming contract; a new
  endpoint duplicate would be redundant.

## Files Changed

- `api/execute/index.php` — `tcDetails` (exec CF render+metadata), `save`
  (forward validated `custom_field_*`).
- `gui/templates/execute/execTest.html` — CF section render, roMode disable,
  `saveExecution()` collection, `validateExecCustomFields()`, CSS.
- `gui/templates/i18n/{de,en,es,fr,it,ja,pt,ro,ru,zh}.json` — 3 new `exe.*` keys
  per bundle.
- `docs/screenshots/issue-791-exec-cf-input.png` — screenshot showing the
  "Execution Note CF" input rendered on the execute form.

## Commit

- Phase 1 (fix): `fix(execute): render & persist execution-time custom fields (Refs #791)`
  — see `git log` on `fix/issue-791-exec-cf-inputs`.

## Regression Matrix

| # | Case | Result |
|---|------|--------|
| 1 | `tcDetails` returns `exec_cfields_html` (contains `custom_field_0_1_3`) + `exec_cfields` metadata | PASS |
| 2 | CF input renders on the form ("Execution Note CF") | PASS |
| 3 | Save persists value → `cfield_execution_values(field_id=1, value='...')` | PASS |
| 4 | Read-only-execute access gating (`$canExecute`) | PARTIAL (code inspection only) |
| 5 | Forged `custom_field_0_999_3` rejected → 0 rows written | PASS |
| 6 | Required CF blocks save with toast if empty | PASS |
| 7 | All 10 i18n bundles valid JSON + contain the 3 new keys | PASS |
| 8 | Event Viewer `events` table: no new Error/Warning | PASS |

Verified 2026-09-01 with fixture `php tmp/fixtures_791.php` (project EXCCF id 1,
plan PlanExecCF id 6, build BExecCF id 1, case "Case ExecCF" id 3/version 4, CF
`ecf_exec_note` id 1), headless Chrome at http://localhost:8082, admin/admin.
Event Viewer: no new Error/Warning; browser console clean.
