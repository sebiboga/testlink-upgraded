# Bugfix — Issue #1594: duplicate & missing sections in `input_dimensions.conf`, and the one-option DataTables page-length menu

**Branch:** `fix/issue-1594`  ·  **Issue:** [#1594](https://github.com/sebiboga/testlink-upgraded/issues/1594)  ·  **Closed:** 2026-09-27

## 1. Symptom

The legacy Dashio screens **User Management → View Roles** (`lib/usermanagement/rolesView.php`) and
**Test Plan Management → Test Plan Milestones** (`lib/plan/planMilestonesView.php`) were the last two
list screens whose page-length control offered a single option:

```
lib/usermanagement/rolesView.php            "lengthMenu": [ 20 ],
lib/plan/planMilestonesView.php             "lengthMenu": [ 20 ],
lib/plan/planView.php                       "lengthMenu": [ [20, 40, 60, -1], [20, 40, 60, "All"] ],
```

Underneath, `gui/templates/conf/input_dimensions.conf` had drifted:

* `[platformsView]`, `[buildView]` and `[cfieldsTprojectAssign]` were each declared **twice**
  (once for their legacy field dimensions, once for the Dashio `item_view_*` / `pagination_length`
  keys that were appended later).
* `[rolesView]` and `[planMilestonesView]` were **never declared at all**.

Because Smarty's `{config_load}` silently ignores a section name that does not exist, the two
un-declared screens fell back to the conf file's **unnamed default section** — no error, no warning,
no `events` row.

## 2. Root cause

### 2.1 The missing sections (the actual defect)

`gui/templates/dashio/usermanagement/rolesView.tpl:17-18` and
`gui/templates/dashio/plan/planMilestonesView.tpl:6-7` both request a section named after themselves.
Neither existed in the conf file.

`vendor/smarty/smarty/libs/sysplugins/smarty_internal_method_configload.php:110-124`
(`_assignConfigVars`) first copies the config file's **global** vars — everything before the first
`[section]`, here `input_dimensions.conf:43-51`, which contains `BUTTON_CLASS`, `TITLE_CLASS`,
`item_view_table`, `item_view_thead` and `pagination_length=20` — and then loops over the requested
sections. Finding nothing, it simply returns. The fallback is silent by design.

`rolesView.tpl:33` and `planMilestonesView.tpl:35` then read `{$ll = #pagination_length#}`, i.e. the
global `20`, and `gui/templates/dashio/include/DataTables.inc.tpl:102`

```js
"lengthMenu": [ {$DataTablesLengthMenu} ],
```

hard-wraps that bare scalar in a **1-element array**. A scalar can therefore *never* express a
length menu — `"lengthMenu": [ 20 ]` is the only thing it can produce.

Every other list screen reads the second mechanism, established by **#1591**:
`config.inc.php:658-699` defines `$tlCfg->gui->{<section>}->pagination->length` with the value
`'[20, 40, 60, -1], [20, 40, 60, "All"]'`, and the template reads
`{$ll = $tlCfg->gui->{$cfg_section}->pagination->length}`. `rolesView` and `planMilestonesView` were
the only two that had never been migrated to it.

### 2.2 Two reported causes that were **disproved**

The issue was filed with three root causes. Reproducing it on HEAD `8222bdf4d` showed that two of
them were wrong. Both corrections are recorded here because they point the next reader away from the
real fix if left uncorrected.

**"The Smarty loader keeps only the second block's values" ⇒ the duplicates drop nothing.**
`vendor/smarty/smarty/libs/sysplugins/smarty_internal_configfileparser.php:1037-1044`:

```php
private function add_section_vars($section_name, array $vars)
{
    if (!isset($this->compiler->config_data['sections'][$section_name]['vars'])) {
        $this->compiler->config_data['sections'][$section_name]['vars'] = array();
    }
    …
}
```

The vars array is created once and **reused** for the second `[platformsView]` block, so every
distinct key survives; only a *re-declared* key would be overwritten. The compiled config proves it —
`platformsView` carried all eight keys (5 from the first block, 3 from the second), including
`PLATFORM_NOTES_TRUNCATE_LEN => 120`, which `platformsView.tpl:102` truncates notes with. The
duplicated sections were a **readability trap, not a key-loss bug**.

**"`rolesView.tpl` builds `$cfg_section` as a full path" ⇒ it does not, on Smarty 4.5.7.**
`rolesView.tpl:17` really is `{$cfg_section=$smarty.template|replace:".tpl":""}` with no `|basename`.
But `$smarty.template` is a *special variable* and on Smarty 4 it already resolves to a basename —
`vendor/smarty/smarty/libs/sysplugins/smarty_internal_compile_private_special_variable.php:81-82`:

```php
case 'template':
    return 'basename($_smarty_tpl->source->filepath)';
```

and the generated code for this very template shows it
(`gui/templates_c/8296a168…_0.file.rolesView.tpl.php:41`):

```php
$_smarty_tpl->_assignInScope('cfg_section',
    smarty_modifier_replace(basename($_smarty_tpl->source->filepath), ".tpl", ''));
```

So `$cfg_section === "rolesView"` and the section lookup was never malformed. The `|basename` filter
used by ~100 sibling templates is a **redundant no-op** on Smarty 4 (it only mattered on Smarty 2/3);
`results/resultsNavigator.tpl:5` and `navBar.tpl` omit it too and are equally correct.

## 3. The fix

| File | Change |
|---|---|
| `gui/templates/conf/input_dimensions.conf` | **Declare `[rolesView]` and `[planMilestonesView]`** — `item_view_table`, `item_view_thead`, `pagination_length=20`, shape-identical to the six sibling `*View` blocks |
| `gui/templates/conf/input_dimensions.conf` | **De-duplicate** `[platformsView]`, `[buildView]`, `[cfieldsTprojectAssign]` — the second declarations were deleted and their 3 keys merged into each section's first declaration |
| `config.inc.php` | **Add `$tlCfg->gui->{rolesView,planMilestonesView,cfieldsTprojectAssign}->pagination`** blocks, byte-identical in shape and value to the eight added by #1591 |
| `rolesView.tpl:33`, `planMilestonesView.tpl:35`, `cfieldsTprojectAssign.tpl:22` | `{$ll = #pagination_length#}` → `{$ll = $tlCfg->gui->{$cfg_section}->pagination->length}` |
| `rolesView.tpl:17` | Add the redundant `\|basename` to match the ~100 sibling templates (convention alignment; verified no-op) |

No i18n bundle was touched — the change introduces no user-facing string. `config_db.inc.php` is
untouched.

### 3.1 The merge is provably lossless

The compiled `input_dimensions.conf` (`gui/templates_c/…input_dimensions.conf.php`) was captured
before the change, deleted to force a recompile, and diffed section by section:

```
sections before/after: 44 46
removed: []
added  : ['planMilestonesView', 'rolesView']
=> all pre-existing sections IDENTICAL
```

Every key that existed before still exists with the same value; only the two new sections were added.
`platformsView` still truncates notes at 120 characters, `buildView` still carries `BUILD_*`, and
`cfieldsTprojectAssign` still carries `DISPLAY_ORDER_*`.

### 3.2 The bare scalar is now fully retired

After the change, `grep -rn "#pagination_length#" gui/templates/` has exactly one hit left:
`planView.tpl:38`, which is the *defensive* form — it keeps the scalar as a default and overrides it
only when the config block is enabled:

```smarty
{$ll = #pagination_length#}
{if $tlCfg->gui->planView->pagination->enabled}
  {$ll = $tlCfg->gui->planView->pagination->length}
{/if}
```

Every screen that actually drives a DataTable now reads the single `config.inc.php` source of truth,
so `pagination_length` can no longer drift into a second, silently-different setting.

## 4. Verification

Regression suite: `tmp/TLU_Test_Cases.md` → *"Regression — Issue #1594"*, **14 assertions, 14 PASS / 0 FAIL**.

| Check | Result |
|---|---|
| `rolesView.php` ×3 cache-bypassing loads | HTTP 200, `"lengthMenu": [ [20, 40, 60, -1], [20, 40, 60, "All"] ]` (was `[ 20 ]`) |
| Roles grid markup | `<table id="item_view" class="table table-bordered">`, 10 `<tr>` — unchanged |
| `planMilestonesView.php?tplan_id=1` | HTTP 200, milestone rendered, full length menu |
| `platformsView.php` | HTTP 200, `PLATFORM_NOTES_TRUNCATE_LEN => 120` intact, notes still truncated |
| `cfieldsTprojectAssign.php` | HTTP 200, full length menu (was `[ 20 ]`) |
| Sibling screens | `planView` / `platformsView` / `keywordsView` (`[40, 60, 80]`) / `projectView` byte-identical to the pre-fix baseline |
| Locales `ro_RO` / `en_GB` / `es_ES` | HTTP 200, full length menu, 0 warning rows each |
| Browser DOM (`chrome-devtools`) | `.dataTables_length select` options = `["20=20","40=40","60=60","All=-1"]`, 9 role rows, no init error |
| Compiled-config diff | 44 → 46 sections, nothing removed, nothing changed |
| `php -l` on `config.inc.php` + both recompiled templates | clean |
| Event Viewer (`log_level IN (1,2)`) | **0 new ERROR/WARNING rows**; only 2 pre-existing `testPriorityEnabled` warnings, one per `planMilestonesView` load |

## 5. Rejected alternatives

* **Put a comma list in `pagination_length=20,40,60,-1`.** `DataTables.inc.tpl:102` hard-codes
  `[ {$…} ]` around a single scalar, so this would emit `"lengthMenu": [ 20,40,60,-1 ]` — four values,
  no labels, `-1` rendered as "-1" instead of "All" — and would fork a *third* pagination mechanism on
  top of the one #1591 introduced.
* **Change `DataTables.inc.tpl` to accept a pre-built array.** That file is `{include`d by 20+
  templates; a signature change is a far larger blast radius than this issue warrants.
* **Delete the duplicated sections instead of merging them.** Their first blocks hold keys that are
  genuinely referenced (`PLATFORM_NOTES_TRUNCATE_LEN`, `BUILD_*`, `DISPLAY_ORDER_*`); removing them
  would break platform-notes truncation.
* **Give the milestone grid `id="item_view"`** so its DataTable would actually initialise. Rejected
  for this run — see below.

## 6. Follow-ups filed (not fixed here)

* **#1649** — `planMilestonesView.php` writes one `E_WARNING Undefined property:
  stdClass::$testPriorityEnabled` per page load. Pre-existing, unrelated to the conf defect.
* **#1650** — `planMilestonesView.tpl:36` includes `DataTables.inc.tpl` with
  `DataTablesSelector="#item_view"`, but the template has **no** element with that id (only
  `<table class="common">` at :48 and `<table class="simple_tableruler sortable">` at :103), so
  `DataTables.inc.tpl:110` is a silent no-op and the — now correct — length menu never becomes
  visible on that screen. Deliberately not patched here: the milestone grid has a `colspan` summary
  row and a `simple_tableruler` layout, so attaching DataTables is a markup migration, not a
  one-attribute patch.
* **Wider observation (from code review)** — 59 other Dashio templates derive a `$cfg_section` with
  no matching conf section and therefore inherit the globals silently (`usersView.tpl`, `tcEdit.tpl`,
  `tcTree.tpl`, `execNavigator.tpl`, `planExport.tpl`, …). All of them consume only *global* conf
  keys, so the fallback is harmless today, but it is the same latent trap.
