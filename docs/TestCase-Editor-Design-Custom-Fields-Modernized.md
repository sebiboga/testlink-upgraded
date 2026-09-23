# Test Case Editor — Design-Time Custom Fields Restored (Refs #1312)

The modern **Test Case Editor** (`gui/templates/testcases/tcEdit.html` +
`api/testcasesedit/index.php`) regains the legacy **design-time custom fields**
the 1.9.20 editor rendered in the design view.

## The gap

Legacy `testcaseEdit.php?doAction=edit` renders ONE OR MORE custom-field
blocks (`gui/templates/dashio/testcases/tcEditViewer.inc.tpl:47-137`), each
covering a subset of the CFs linked to the test project, positioned at the
7 legacy design-time locations; values live in `cfield_design_values` and are
persisted on save by `testcaseCommands::show()` after `update()` via
`cfield_mgr->design_values_to_db()` (`lib/testcases/testcaseCommands.class.php:1228-1235`).

The modern editor shipped no custom-field rendering and no persistence — the
`edit` payload carried neither per-location HTML nor CF metadata, so all
design-time CFs silently disappeared and their saved values stayed frozen.

## What was changed

BFF `api/testcasesedit/index.php`:

- `tceDesignCustomFields()` — renders per-location legacy input HTML via
  `testcase::html_table_of_custom_field_inputs($tcaseId, null, 'design', '', $tcverId, null, $tprojId, $locFilter)`
  for every entry of `buildCFLocationMap()` (skip_location is left true, so
  hidden `hide_because_is_used_as_variable` CFs are not rendered), plus a
  structured `meta` list from `get_linked_cfields_at_design` (id / name /
  label / type / type_verbose / possible_values / required / location /
  current value from `cfield_design_values`).
- `edit` payload gains `custom_fields: {locations, html, meta}`.
- `update` handler: when `body.custom_fields` is a hash shaped exactly like the
  legacy `$_REQUEST` (`custom_field_<type>_<id>` …), rebuilds the project
  design-CF map via
  `cfield_mgr->getLinkedCfieldsAtDesign(['tproject_id'=>..,'enabled'=>1,'node_type'=>'testcase'], ['location'=>[1..7]])`
  and persists through `cfield_mgr->design_values_to_db($body['custom_fields'], $tcverId, $cfMap)`.
  The location filter keeps hidden variable-CFs (location 8) whose values are
  never submitted by the editor from being wiped by `_build_cfield`'s
  seed-and-overwrite logic.

HTML `gui/templates/testcases/tcEdit.html`:

- 7 location blocks: `cf_after_title`, `cf_before_summary`, `cf_after_summary`,
  `cf_before_preconditions`, `cf_after_preconditions`, `cf_before_steps_results`,
  `cf_standard_location`, styled with `.design-cf-block` Dashio CSS.
- `renderCustomFields()` injects each served block verbatim inside an
  `<form onsubmit="return false" novalidate>` wrapper — the legacy markup (and
  its `this.form.<name>` references used by the text-area counter) works without
  a real `<form>`, and `novalidate` prevents native HTML5 constraint bubbles
  from ever intercepting save.
- `window.textCounter` shim (mirror of `gui/javascript/validate.js:235`) and
  `window.showCal` shim (native date-picker overlay writing back through the
  legacy strftime format) so the served legacy date/textarea markup is fully
  interactive.
- `collectCustomFields()` walks the injected block controls and produces the
  legacy hash (checkbox/radio groups → `[]` arrays, empty groups → `''` so the
  stored row is deleted); `validateCustomFields()` runs the required gate plus
  numeric/float/email checks (mirror of `execSetResults.html`); both are wired
  into `doSave` (`custom_fields` in the update payload).
- `#formCard` change-tracking selector extended to `checkbox/radio/email/number/date`.

i18n: `tcedit.cfRequired` (`The custom field "{field}" is required.`) and
`tcedit.cfInvalid` added to ALL 10 locale bundles.

## Verification

- Browser-verified on fixture `tmp/fixtures_1312.php` (TCEDDemo, tcA v2):
  Severity (list) + Priority Flag (checkbox) render after preconditions using
  the exact legacy markup; Notes (textarea) renders at standard location.
- UI save round-trips all three values through `cfield_design_values`; reload
  re-renders the saved state.
- Required gate measured: empty required field blocks save with
  `The custom field "Severity" is required.`
- Browser console clean (only pre-existing a11y "no label" issues), Event
  Viewer shows only INFO audit rows for the feature saves.
- Suite 1312 7/7 PASS in `tmp/TLU_Test_Cases.md`.

## Related

- Bug #1571: design-time **datetime** CF values are wiped on save because
  legacy `cfield_mgr::_build_cfield` (`lib/functions/cfield_mgr.class.php:1924-1973`)
  reads `$value['input']` from a last-suffix-wins array → `E_WARNING` +
  `DELETE`. Filed separately (repro + measured evidence); fix belongs in the
  legacy class, outside this task's scope.