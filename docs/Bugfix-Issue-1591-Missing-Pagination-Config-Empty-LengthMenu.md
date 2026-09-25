# Bug fix — Issue #1591: missing `$tlCfg->gui->…->pagination` config broke the DataTables page-size menu

## Symptom

Three legacy Dashio list screens render a DataTables **page-size control** ("Show N entries")
from the PHP configuration tree `$tlCfg->gui->{section}->pagination->length`. `config.inc.php`
defined that subtree for only five sections, so the remaining screens dereferenced a missing
`stdClass` child. Every page load therefore:

1. wrote **three** `E_WARNING` rows to the `events` table (Event Viewer noise), and
2. emitted `"lengthMenu": [  ]` into the page, so the page-size `<select>` was rendered with a
   single **blank** option — the control was unusable.

Affected legacy entry points (still reachable by URL/bookmark):

| Screen | `cfg_section` | Emitted before the fix |
|---|---|---|
| `lib/issuetrackers/issueTrackerView.php` | `issueTrackerView` | `"lengthMenu": [  ],` |
| `lib/platforms/platformsView.php` | `platformsView` | `lengthMenu: [],` |
| `lib/codetrackers/codeTrackerView.php` | `codeTrackerView` | `"lengthMenu": [ 20 ],` (single option) |

The modern screens the ASIDE menu now links
(`/gui/templates/issuetracker/issuetrackerView.html`,
`/gui/templates/codetracker/codetrackerView.html`,
`/gui/templates/reqmgrsystems/reqMgrSystemView.html`,
`/gui/templates/platforms/platformsView.html`, wired in `lib/functions/common.php:1918-1926`)
are **not** affected: they hardcode `pageLength: 25` and use the DataTables default length menu.

`lib/reqmgrsystems/reqMgrSystemView.php` is not affected either — that template has no
DataTables include at all, so it has no pagination config to read.

## Environment and fixtures

- TestLink 2.0.1, PHP built-in server on `http://localhost:8082` (docroot = repository root),
  MariaDB `127.0.0.1:3306`, db/user/password `testlink`.
- The database is freshly imported on every run and contains **no** list data, so the fixtures
  were recreated:

```sql
INSERT INTO issuetrackers (name,type,cfg) VALUES ('Bugzilla Demo',1,'{"uribase":"http://localhost:9999/","uriview":"bz.cgi?id=%bugid%"}');
INSERT INTO codetrackers  (name,type,cfg) VALUES ('Git Demo',1,'{"uribase":"http://localhost:9999/g","uriview":"%commitid%"}');
INSERT INTO testprojects (id,prefix,api_key) VALUES (1,'TL','aaaa…1111');
INSERT INTO platforms (name,testproject_id,notes,enable_on_design,enable_on_execution,is_open)
  VALUES ('Linux',1,'demo',1,1,1);
```

- Login: `admin` / `admin`. Browser checks run in headless Chrome.

## Root cause

Two *different* configuration mechanisms exist in TestLink and were being conflated:

1. **PHP config tree** — `$tlCfg->gui->{section}->pagination->{enabled,length}` in
   `config.inc.php`, holding a JS length-menu string such as
   `'[20, 40, 60, -1], [20, 40, 60, "All"]'`.
2. **Smarty `{config_load}` data** — `pagination_length` in
   `gui/templates/conf/input_dimensions.conf`, injected as `#pagination_length#`, holding a
   bare scalar (`20`).

They are not interchangeable: the `.conf` scalar ends up as `lengthMenu: [20]` (a single page
size), the config-tree string ends up as a full two-array DataTables menu.

`gui/templates/dashio/include/DataTables.inc.tpl:102` interpolates whatever it receives verbatim:

```
"lengthMenu": [ {$DataTablesLengthMenu} ],
```

The call sites split as follows:

| Template | Reads | Config present? |
|---|---|---|
| `projectView.tpl:48`, `planView.tpl:40`, `buildView.tpl:41`, `keywordsView.tpl:45`, `usersAssign.tpl:112` | `$tlCfg->gui->…->pagination->length` | yes (`config.inc.php:658-684`) |
| `platformsView.tpl:51`, `issueTrackerView.tpl:27` | `$tlCfg->gui->…->pagination->length` | **no** → the bug |
| `codeTrackerView.tpl:26` | `#pagination_length#` | `.conf` only → single-option menu |

Chain for `issueTrackerView.tpl:27` (`$cfg_section` set at line 9 from the template name):

```
$tlCfg->gui->issueTrackerView          -> null  (E_WARNING 1: Undefined property: stdClass::$issueTrackerView)
  ->pagination                        -> null  (E_WARNING 2: Attempt to read property "pagination" on null)
    ->length                          -> null  (E_WARNING 3: Attempt to read property "length" on null)
```

`$ll` is empty, so `DataTables.inc.tpl:102` renders `"lengthMenu": [  ]`.

The regression source is the dashio porting of these two templates: they were copied from
`projectView.tpl` (which reads the config tree) without carrying the matching `config.inc.php`
block along. It is pre-existing and independent of #1584.

## The fix

`config.inc.php` — the three missing blocks were added at the end of the pagination section,
using exactly the shape and the same length string as the five existing ones:

```php
$tlCfg->gui->issueTrackerView = new stdClass();
$tlCfg->gui->issueTrackerView->pagination = new stdClass();
$tlCfg->gui->issueTrackerView->pagination->enabled = true;
$tlCfg->gui->issueTrackerView->pagination->length = '[20, 40, 60, -1], [20, 40, 60, "All"]';

$tlCfg->gui->codeTrackerView = new stdClass();
$tlCfg->gui->codeTrackerView->pagination = new stdClass();
$tlCfg->gui->codeTrackerView->pagination->enabled = true;
$tlCfg->gui->codeTrackerView->pagination->length = '[20, 40, 60, -1], [20, 40, 60, "All"]';

$tlCfg->gui->platformsView = new stdClass();
$tlCfg->gui->platformsView->pagination = new stdClass();
$tlCfg->gui->platformsView->pagination->enabled = true;
$tlCfg->gui->platformsView->pagination->length = '[20, 40, 60, -1], [20, 40, 60, "All"]';
```

`gui/templates/dashio/codetrackers/codeTrackerView.tpl:26` — one line, so the Code Tracker list
behaves like its sibling list screens instead of offering a single page size:

```diff
-  {$ll = #pagination_length#}
+  {$ll = $tlCfg->gui->{$cfg_section}->pagination->length}
```

`reqMgrSystemView.tpl` is deliberately **unchanged** — it includes no DataTables, so adding a
config block for it would be dead configuration.

### Why this method

- The config tree is the mechanism TestLink already uses for the five other list sections, is
  discoverable in one place, and fixes the *root cause* (missing configuration) rather than
  masking the symptom.
- The alternative — a Smarty-side guard such as
  `{if isset($tlCfg->gui->{$cfg_section}->pagination->length)}…{else}$ll=#pagination_length#{/if}` —
  still has to evaluate the dynamic property expression, keeps the error-prone null walk in the
  template layer, and hides the real defect behind a silent fallback.
- Aligning `codeTrackerView` with the config tree is what makes the reported expectation ("the
  page-length control offers the configured options") true on all three listed screens.

## Verification

| Screen | `lengthMenu` before | after | `<select>` options after |
|---|---|---|---|
| `issueTrackerView` | `[  ]` | `[ [20, 40, 60, -1], [20, 40, 60, "All"] ]` | 20 / 40 / 60 / All |
| `platformsView` | `[]` | `[[20, 40, 60, -1], [20, 40, 60, "All"]]` | 20 / 40 / 60 / All |
| `codeTrackerView` | `[ 20 ]` | `[ [20, 40, 60, -1], [20, 40, 60, "All"] ]` | 20 / 40 / 60 / All |

- `events` after reloading the three screens: **no** `Undefined property` /
  `Attempt to read property` rows remain (previously 3 per screen).
- Browser console on `issueTrackerView`: no errors, no warnings. Selecting `40` really changes
  the page length (`DataTable().page.len()` = 40).
- Regression of the pre-existing consumers: `planView` still renders
  `[20, 40, 60, -1], [20, 40, 60, "All"]`, `keywordsView` still renders
  `[40, 60, 80, -1], [40, 60, 80, "All"]`; a direct config dump shows the five original blocks
  unchanged and all eight sections resolving.
- Empty list (`DELETE FROM issuetrackers`): the screen still renders (HTTP 200) and logs nothing.
- Full suite: `tmp/TLU_Test_Cases.md` → *Regression — Issue #1591*, 12/12 PASS.

## Files changed

| File | Purpose |
|---|---|
| `config.inc.php` | +15 lines: `issueTrackerView`, `codeTrackerView`, `platformsView` pagination blocks |
| `gui/templates/dashio/codetrackers/codeTrackerView.tpl` | 1 line: read the length menu from the config tree instead of the bare `.conf` scalar |
| `docs/screenshots/issue-1591-issueTrackerView-lengthMenu-fixed.png` | browser evidence |

## Related

- #1582 / #1584 / #1585 — sibling read-only delete-cell contract defects in the same list templates.
- #1590 — `testproject_alt_delete` label key missing in `issueTrackerView.tpl:81` (same template,
  separate E_WARNING, still open).
- #1592 / #1593 — warnings found while testing this fix, both in the `reqMgrSystemView` legacy
  path (`tproject_id` array key, missing `contoursoapInterface.class.php`).
