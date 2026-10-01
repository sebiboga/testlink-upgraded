# Assign Test Project Roles — dynamic role-column header

The Assign Test Project Roles screen now renders a **dynamic, localized role-column header** that mirrors legacy behavior.

## Legacy reference
- Template (deleted in ab387af72): `gui/templates/dashio/usermanagement/usersAssign.tpl:213–219`
- Header: `{lang_get s="th_roles_$featureVerbose"} ({$my_feature_name|escape})` with `$featureVerbose = "testproject"` and `$my_feature_name` being the **selected** test project's name (assigned inside the combo loop). 
- Locale key: `$TLS_th_roles_testproject` (all shipped locales). 

## Modern implementation
- HTML (`gui/templates/usermanagement/usersAssignProject.html`): the role column `<th>` has `id="projectRoleHeading"` (no `data-i18n`, as the helper owns the caption). 
- JS: `projectNameById` map rebuilt from the same `r.projects` payload as the combo; `updateProjectRoleHeading()` composes `TLi18n.t('header.projectRoleHeading') + ' (' + name + ')'`, or the bare localized label when no project is selected. The name is injected via `.text()` (not `.html()`), so it is HTML-escaped exactly once (matches Smarty's `|escape`).
- i18n (`gui/templates/i18n/*.json`): new key `header.projectRoleHeading` added to all 10 bundles, seeded from each locale's `th_roles_testproject` (`ro_RO` had none → mirrored `Rol în Proiectul de Test` from the plan-screen pattern). 
- Call sites: updated synchronously on `loadProjects()` (pre-request, to avoid naming the previous project during the in-flight window), on combo change (including cleared), on `loadUsers()`, and in neutralisation paths (`showDisabled()`, `showNoAccess()`, `showSessionExpired()`).

## Behavior
- Default (deep link with a selected project): caption reads `Test Project Role (<selected project>)`.
- Changing the Test Project combo updates the header immediately to the new project's name.
- Clearing the combo to `-- select project --` drops the project name (bare `Test Project Role`).
- Locale switch re-renders the localized half in the new language while keeping the project name.
- Project names containing `&` and `<` are correctly escaped (no double-escaping).

## Screenshot
Below: the role column header follows the selected project (`BRAVO-1612 Project`) after changing the combo.
