# Task 1643 — public/private access-type indicator for Test Project + Test Plan in Assign Test Plan Roles (gap vs legacy)

**Issue:** [#1643](https://github.com/sebiboga/testlink-upgraded/issues/1643)
**Status:** IMPLEMENTED & VERIFIED — branch `task/issue-1643` (the issue is closed
by the same run, after the branch was pushed)
**Related:** #1609 (the *identical* defect on the **project** twin,
`usersAssignProject.html` — a separate issue, deliberately not touched here),
#1631, #1644, #1664, #1707

## The gap

Legacy `lib/usermanagement/usersAssign.php` (deleted in `ab387af72`, readable at
`ab387af72^`) rendered an **access-type icon with a localized tooltip** next to
both context labels of the shared assign template:

```php
// usersAssign.php:70-77 (testplan branch) — the PROJECT indicator
$accessKey = 'private';
if ($tprojectMgr->getPublicAttr($args->testprojectID)) { $accessKey = 'public'; }
$gui->tprojectAccessTypeImg = '<span title="' . lang_get('access_' . $accessKey) .
                              '">' . $imgSet[$accessKey] . '</span>';

// usersAssign.php:129-135 — the SELECTED PLAN indicator
$accessKey = 'vorsicht';
if (isset($gui->features[$gui->featureID])) {
  $accessKey = $gui->features[$gui->featureID]['is_public'] ? 'public' : 'private';
}
$gui->accessTypeImg = '<span title="' . lang_get('access_' . $accessKey) .
                      '">' . $imgSet[$accessKey] . '</span>';
```

```smarty
{* gui/templates/dashio/usermanagement/usersAssign.tpl:163-169 (deleted in ab387af72) *}
<td class="labelHolder">{$labels.TestProject}{$gui->tprojectAccessTypeImg}</td>
<td>{$gui->tproject_name|escape}</td>
...
<td class="labelHolder">{$labels.TestPlan}{$gui->accessTypeImg}</td>
```

Icon set (`lib/functions/tlsmarty.inc.php` `getImageSet()`):

| key | icon |
|---|---|
| `public` | `fa-globe` |
| `private` | `fa-lock` |
| `vorsicht` | `fa-exclamation-triangle` |

Tooltip strings from `locale/<lang>/strings.txt`: `$TLS_access_public`
(`en_GB`: *"Public"`), `$TLS_access_private` (*"Private - User need specific role
assignment"*), `$TLS_access_vorsicht` (*"Attention internal error"*).

The 2.0.1 port (`gui/templates/usermanagement/usersAssignPlan.html:80-85`) reduced
this to two bare labels:

```html
<label data-i18n="assign.testProject">Test Project:</label>
<select id="projectSelect"></select>
<label data-i18n="assign.testPlan">Test Plan:</label>
<select id="planSelect" disabled></select>
```

No icon, no tooltip, and **no data**: the BFF computed `$projIsPublic` /
`$planIsPublic` internally (`api/roles/index.php:921-930`, used by
`getTplanEffectiveRoleMap()`) but never returned them; `getAssignablePlans()`
returned only `{id,name}` per plan. A manager could not tell whether the roles
they were about to overwrite lived on a public or a private project/plan — on a
private plan a non-admin without an explicit plan role collapses to `<no rights>`
(`roles.inc.php:407-416`), so this is consequential context.

## What was implemented

### BFF — `api/roles/index.php` (tplan-roles GET)

- `$projIsPublic` (1|0|null) is now resolved for **every** request that carries a
  `tproject_id` (legacy `testproject::getPublicAttr()`, usersAssign.php:70-77),
  not only when a plan was selected.
- `$planIsPublic` (1|0|null; `null` = no plan selected) from
  `testplans.is_public` (legacy `$gui->features[$id]['is_public']`).
- `getAssignablePlans()` now emits `isPublic` per plan (the source row already
  carries `is_public`, `testproject.class.php:2642`).
- The response envelope returns `projectIsPublic` and `planIsPublic`.

```jsonc
// GET /api/roles/index.php/meta/tplan-roles?tproject_id=1&tplan_id=4
{
  "projectIsPublic": 1,
  "planIsPublic": 0,
  "plans": [ {"id":4,"name":"A-PRIVATE-1643","isPublic":0},
             {"id":3,"name":"A-PUBLIC-1643","isPublic":1} ]
}
```

### Screen — `gui/templates/usermanagement/usersAssignPlan.html`

Two icon slots added to the toolbar, right after each label:

```html
<label data-i18n="assign.testProject">Test Project:</label>
<span id="projectAccessIcon" class="access-icon"></span>
<select id="projectSelect"></select>
<label style="margin-left:8px;" data-i18n="assign.testPlan">Test Plan:</label>
<span id="planAccessIcon" class="access-icon"></span>
<select id="planSelect" disabled></select>
```

A single tri-state renderer maps the payload value to the Dashio icon + localized
tooltip and is called from every path that changes or clears the context:

| state | icon | colour | tooltip key |
|---|---|---|---|
| `1` public | `fa-globe` | teal `#4ECDC4` | `assign.accessPublic` |
| `0` private | `fa-lock` | amber `#f0ad4e` | `assign.accessPrivate` |
| `vorsicht` | `fa-exclamation-triangle` | red `#e6605e` | `assign.accessVorsicht` |
| `null` | — (cleared) | — | — |

Call sites: `loadPlans()` (project icon on response + clear the plan icon before
the request), `loadUsers()` (both icons from the full context), both combo
`change` handlers (clear on context change/empty), `showPlanDisabled()` and
`showNoAccess()` (clear behind the hidden toolbar).

### i18n — three keys in all 10 bundles

`assign.accessPublic`, `assign.accessPrivate`, `assign.accessVorsicht` reused the
legacy wording per locale where it existed, faithful translations otherwise:

| bundle | public | private | vorsicht |
|---|---|---|---|
| `en.json` | Public | Private - User need specific role assignment | Attention internal error |
| `de.json` | Öffentlich | Privat - Benutzer benötigt spezifische Rollenzuweisung | Achtung: interner Fehler |
| `es.json` | Público | Privado - El usuario necesita tener asignado un rol específico | Atención, error interno |
| `fr.json` | Public | Privé - un rôle spécifique doit être affecté à l'utilisateur | Attention, erreur interne |
| `it.json` | Pubblico | Privato - l'utente necessita un'assegnazione di ruolo specifica | Attenzione: errore interno |
| `ja.json` | 公開中 | 非公開 - ユーザーの役割を指定する必要があります | 注意 内部エラー |
| `pt.json` | Público | Privado - O Utilizador precisa especificar regra de atribuição | Atenção, erro interno |
| `ro.json` | Public | Privat - utilizatorul necesită o atribuire de rol specifică | Atenție: eroare internă |
| `ru.json` | Публичный | Приватный - пользователю требуется назначение конкретной роли | Внимание: внутренняя ошибка |
| `zh.json` | 公开的 | 私有的 - 用户需要特定的角色分配 | 注意! 内部错误 |

## Verification

Fixtures recreated this run (DB freshly imported):

```bash
mysql -h 127.0.0.1 -utestlink -ptestlink testlink <<'SQL'
INSERT INTO testprojects (id,notes,color,active,prefix,tc_counter,is_public,api_key)
VALUES (1,'Alpha','#9BD',1,'ALPHA1643',0,1,'alpha1643key...');
INSERT INTO nodes_hierarchy (id,name,parent_id,node_type_id,node_order) VALUES (1,'Alpha Public Project',NULL,1,1);
INSERT INTO testplans (id,testproject_id,notes,active,is_open,is_public,api_key) VALUES
 (3,1,'',1,1,1,'planpublic1643...'),(4,1,'',1,1,0,'planprivate1643...');
INSERT INTO nodes_hierarchy (id,name,parent_id,node_type_id,node_order) VALUES
 (3,'A-PUBLIC-1643',1,5,1),(4,'A-PRIVATE-1643',1,5,2);
-- project 2 (private) + plan 5 (public) for the project-private state
SQL
```

Suite **1643** in `tmp/TLU_Test_Cases.md` — all measured in headless Chrome as
`admin`:

- public project + private plan → project `fa-globe` "Public", plan `fa-lock`
  "Private - User need specific role assignment"
- switch plan combo to `A-PUBLIC-1643` → plan icon becomes `fa-globe` "Public"
- private project (id 2) + public plan → project `fa-lock`, plan `fa-globe`
- `?locale=ro` → tooltips in Romanian (`Privat - utilizatorul necesită o
  atribuire de rol specifică`)
- `php -l api/roles/index.php` OK, `node --check` on the inline script OK,
  10/10 bundles `json.tool`-valid, 0 console errors/warnings.
