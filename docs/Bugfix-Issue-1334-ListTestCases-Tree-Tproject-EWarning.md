# Bugfix — Issue #1334: listTestCases left tree fires `E_WARNING: Undefined property: stdClass::$tproject_id` on every render

## Symptom

Loading the legacy test-specification left tree (`lib/testcases/listTestCases.php` →
`gui/templates/dashio/testcases/tcTree.tpl` → `gui/templates/dashio/include/inc_filter_panel.tpl`)
writes one `E_WARNING: Undefined property: stdClass::$tproject_id` row into `events`
(Event Viewer) for every render, as soon as a test project is selected:

```
E_WARNING undefined property stdClass::$tproject_id - in gui/templates_c/…/_0.file.inc_filter_panel.tpl.php - Line 59
```

Beyond the logged warning, the hidden form input `<input type="hidden" name="tproject_id" value="">`
renders **empty**, so the form POST and the `openExportTestPlan()` / `openImportResult()` JS calls
would carry an empty project id.

## Root cause

`lib/testcases/listTestCases.php::initializeGui()` (lines 59-79) builds the `stdClass` `$gui`
for the test-spec tree and assigns `feature`, `treeHeader`, `btn_reorder_testcases`,
`tree_drag_and_drop_enabled`, `menuUrl` — but **never `tproject_id`**. The value IS available:
`tlFilterControl::init_args()` (`lib/functions/tlFilterControl.class.php:302`) populates
`$control->args->testproject_id` from `$_SESSION['testprojectID']`.

The Dashio `inc_filter_panel.tpl` dereferences `$gui->tproject_id` unconditionally at 4 sites
(source lines 43, 202, 204, 209):

- line 43 → hidden `tproject_id` input,
- lines 202/204 → `openExportTestPlan('export_testplan', '{$gui->tproject_id}', …)`,
- line 209 → `openImportResult('import_xml_results', {$gui->tproject_id}, …)`.

The three sibling navigator controllers that include the same panel already set the property:
`lib/execute/execNavigator.php:71`, `lib/plan/planTCNavigator.php:58`,
`lib/plan/planAddTCNavigator.php:67`. `listTestCases.php` was the odd one out.

**Regression source:** not a recent regression — the property was simply never assigned, and the
E_WARNING surfaced on every render once the Dashio filter panel (which uses `$gui->tproject_id`)
replaced the classic template (classic used `{$session.testprojectID}`).

## Fix

Minimal one-line assignment in `lib/testcases/listTestCases.php::initializeGui()`, using the same
guarded idiom as `planTCNavigator.php:58` (strictly safer than execNavigator's unguarded form):

```php
$gui->tproject_id = isset($control->args->testproject_id) ? intval($control->args->testproject_id) : 0;
```

No template change needed — all 4 dereference sites get a real integer. Rejected alternative:
editing `inc_filter_panel.tpl` to default the value — would fix the symptom in one template but
leave the root gap (controller not publishing the property) and diverge from the idiom every
other navigator controller uses.

## Blast radius

- Only `listTestCases.php` render path was affected. Sibling navigators already had the property
  (exec/plan/planAdd), verified by `grep` (`lib/execute/execNavigator.php:71`,
  `lib/plan/planTCNavigator.php:58`, `lib/plan/planAddTCNavigator.php:67`).
- `inc_filter_panel.tpl` is shared by `tcTree.tpl`, `planTCNavigator.tpl`, `execNavigator.tpl`,
  `planAddTCNavigator.tpl` — but these render sites' controllers either set the property already
  or (in `edit_mode`) do not draw the export/import buttons. No user-facing strings changed, so
  no i18n bundle edits were required.

## Regression matrix

| Scenario | Before fix | After fix |
|----------|-----------|-----------|
| editTc with project id=1 (listTestCases.php tree) | 1 E_WARNING per render + empty `input[name=tproject_id]` | 0 warnings; input value = `"1"` |
| editTc with no project id (0) | E_WARNING; empty input | 0 warnings; guarded default int `0` |
| Execution/plan/plan-add navigators | already warning-free (property set by their controllers) | unchanged |
| `events` table after passes | rows with `log_level=2` | 0 new Error/Warning rows |

## Verification (measured)

Post-fix, on the same environment (fresh DB, seeded Alpha Project id=1 + suite + plan 20):

- `DELETE FROM events;` then reload of `index.php?tproject_id=1&returnFeature=editTc` →
  `SELECT COUNT(*) FROM events` → **0** rows (pre-fix: 1 E_WARNING per render, event id=5).
- DOM assertion via page JS: `input[name="tproject_id"]` inside the `listTestCases.php` frame now
  renders `value="1"` (was `value=""` before the fix).
- `tproject_id=0` path → 0 new events rows.
- No browser console errors on the page (only pre-existing a11y "issue" hints).

Screenshots: `docs/screenshots/issue-1334-before.png` (tree render pre-fix, empty hidden input
state) and `docs/screenshots/issue-1334-after.png` (post-fix render).