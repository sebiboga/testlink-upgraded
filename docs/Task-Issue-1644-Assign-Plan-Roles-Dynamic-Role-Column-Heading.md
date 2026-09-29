# Task 1644 — dynamic localized role-column header `Test Plan Role (<plan>)` in Assign Test Plan Roles (gap vs legacy)

**Issue:** [#1644](https://github.com/sebiboga/testlink-upgraded/issues/1644)
**Status:** IMPLEMENTED & VERIFIED — branch `task/issue-1644` (the issue is closed
by the same run, after the branch was pushed)
**Related:** #1612 (the *identical* defect on the **project** twin,
`usersAssignProject.html` — a separate issue, deliberately not touched here),
#1676, #1664, #1707, #1645, #1646

## The gap

Legacy `usersAssign.tpl` — the template shared by the test-project and the
test-plan contexts — built the role-override column caption out of TWO parts:

```smarty
{* gui/templates/dashio/usermanagement/usersAssign.tpl:216-218 (deleted in ab387af72) *}
<th>{$labels.User}</th>
{$featureVerbose=$gui->featureType}
<th>{lang_get s="th_roles_$featureVerbose"} ({$my_feature_name|escape})</th>
```

- `$featureVerbose` is `$gui->featureType`, so on this screen `lang_get` resolves
  `th_roles_testplan` → `$TLS_th_roles_testplan` = **`Test Plan Role`**
  (`locale/en_US/strings.txt:2144`). The label was **localized in every shipped
  locale**, not English-only.
- `$my_feature_name` is assigned **inside the combo loop, only for the selected
  option** (`usersAssign.tpl:177-179`):

  ```smarty
  {foreach from=$gui->features item=f}
    <option value="{$f.id}" {if $featureID == f.id} selected="selected" {/if}>{$f.name|escape}</option>
    {if $featureID == f.id}{$my_feature_name=$f.name}{/if}
  {/foreach}
  ```

  i.e. the name of the **currently selected test plan**, HTML-escaped.

So the header read e.g. **`Test Plan Role (A-PUBLIC-1644)`** and changed with the
plan — including via a deep link, because `$featureID` comes from `tplan_id`.

The 2.0.1 port replaced it with a static, context-free label:

```html
{* gui/templates/usermanagement/usersAssignPlan.html:116, before *}
<th style="width:220px;" data-i18n="assign.planRoleOverride">Plan Role Override</th>
```

Nothing anywhere rendered `th_roles_testplan` or the selected plan name
(`grep -rn "th_roles" gui/templates/i18n/*.json` → 0 hits). The consequence is
practical, not cosmetic: the **same user list is reused for every plan**, so the
column that assigns plan-specific overrides carried no indication of *which*
plan it applies to.

## What was implemented

### i18n — `header.planRoleHeading`, all 10 bundles

Each value is seeded from **that locale's own** legacy `$TLS_th_roles_testplan`,
so restoring the string cannot regress it to English-everywhere:

| bundle | value | seeded from |
|---|---|---|
| `en.json` | `Test Plan Role` | `locale/en_US/strings.txt:2144` |
| `de.json` | `Testplan Rolle` | `locale/de_DE/strings.txt:2145` |
| `es.json` | `Rol para el Plan de Pruebas` | `locale/es_ES/strings.txt:2213` |
| `fr.json` | `Rôle de campagne de test` | `locale/fr_FR/strings.txt:2170` |
| `it.json` | `Ruolo in Test Plan` | `locale/it_IT/strings.txt:1464` |
| `ja.json` | `テスト計画での役割` | `locale/ja_JP/strings.txt:2459` |
| `pt.json` | `Papéis do Plano de Teste` | `locale/pt_BR/strings.txt` |
| `ru.json` | `Роль в тест-плане` | `locale/ru_RU/strings.txt` |
| `zh.json` | `测试计划角色` | `locale/zh_CN/strings.txt` |
| `ro.json` | `Rol în Planul de Test` | **no `$TLS_th_roles_testplan` in `locale/ro_RO/strings.txt`** → faithful translation |

### The screen — `gui/templates/usermanagement/usersAssignPlan.html`

```html
<th style="width:220px;" id="planRoleHeading">Test Plan Role</th>
```

No `data-i18n` on purpose: `TLi18n.apply()` (`i18n.js:190-209`) would overwrite
the composed caption with the bare label on every load — the very defect the
issue is about.

```js
var planNameById = {};   // plan id (string) -> plan name, rebuilt per project load
function updatePlanRoleHeading() {
  var label = TLi18n.t('header.planRoleHeading');
  var planName = (currentPlan == null) ? '' : (planNameById[String(currentPlan)] || '');
  $('#planRoleHeading').text(planName ? label + ' (' + planName + ')' : label);
}
```

The plan name is interpolated **raw** and `.text()` does the escaping — that is
exactly what Smarty's `{$my_feature_name|escape}` does (escape, then write
markup, so the browser shows the characters). Calling `esc()` *on top of* `.text()`
double-escapes; that was the one bug this implementation found in itself, caught
by the escaping test case and fixed before landing.

**Call sites** — every path that changes or clears the plan context:

| where | why |
|---|---|
| `TLi18n.load` callback | bare label, so the `<th>` is never empty while the combo loads |
| `loadPlans()` (synchronous reset + map rebuild + no-plan branch) | the caption is reset **synchronously, before the request leaves**, so it can never name the previous project's plan during the in-flight window (measured); the map is rebuilt *before* anything reads it |
| `loadUsers()` | the caption follows the plan **as the context changes**, not when its users arrive, so it cannot lag one redraw behind the combo |
| `#planSelect` change, empty branch | combo cleared → bare label, never `Test Plan Role ()` |
| `#projectSelect` change, empty branch | no project → no plan → bare label |

### No BFF change

`api/roles/index.php:895` already returns `planOpts = getAssignablePlans()` (defined at `:153`) →
`[{id, name}, …]`, and the screen already consumes it to fill the combo
(the combo fill sits a few lines below the map build in the same `loadPlans()`). This is a pure front-end port.

## Verification

Fixture (the DB is freshly imported every run — `testplans` was empty):

```bash
php tmp/fixtures_1644.php
# project ALPHA1644 (1) with plans A-PUBLIC-1644 (2), A-PRIVATE-1644 (3),
#   'A&B <draft>-1644' (5, HTML metacharacters); project BETA1644-EMPTY (4), no plans
```

17-case suite **Suite 1644** in `tmp/TLU_Test_Cases.md` — **17 PASS**, plus the
regression matrix from the issue's investigation comment, all measured in headless
Chrome as `admin`:

- auto-selected first plan → `Test Plan Role (A-PRIVATE-1644)`
- manual combo switch public ↔ private → caption follows both ways
- combo cleared → `Test Plan Role` (no empty `()`)
- project with no plans → legacy disabled state, caption without a plan
- project switch → caption follows the new project's own plans
- plan named `A&B <draft>-1644` → rendered as text, `children.length === 0`
- `?locale=de` → `Testplan Rolle (A-PUBLIC-1644)`; `?locale=ro` → `Rol în Planul de Test (…)`
- deep link `?tplan_id=2` → caption names plan 2
- DataTables sort/search redraw, bulk "Do", row `onRoleChange` → caption survives every re-init
- `node --check` OK, 10/10 bundles `json.tool`-valid, 0 console errors/warnings,
  `events` table: 3 rows, all `log_level=16` (audit) — **0 new ERROR/WARNING rows**

