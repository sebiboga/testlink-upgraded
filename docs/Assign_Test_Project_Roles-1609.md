# Assign Test Project Roles — public/private access-type indicator (Issue #1609)

**Screen:** Test Management → User Management → **Assign Test Project Roles**
(`gui/templates/usermanagement/usersAssignProject.html`)
**Legacy origin:** `lib/usermanagement/usersAssign.php` + `gui/templates/dashio/usermanagement/usersAssign.tpl`
(both deleted under #947; recoverable from git at `ab387af72^`).

## The gap

Legacy rendered an access-type icon right next to the `Test Project` label:

```smarty
{* usersAssign.tpl:159-162 *}
<td class="labelHolder" style="{$styleLH}">{$labels.TestProject}{$gui->accessTypeImg}</td>
```

The controller built `$gui->accessTypeImg` (`usersAssign.php:117-135`) with three states:

| state | when | icon (`tlsmarty.inc.php:379,451`) | tooltip |
|---|---|---|---|
| **public** | selected id is in the assignable set **and** `is_public` | `fa-globe` | `lang_get('access_public')` |
| **private** | selected id is in the assignable set **and** `!is_public` | `fa-lock` | `lang_get('access_private')` |
| **vorsicht** | assignable set is non-empty but the selected id is **not** in it (legacy kept the requested `featureID` regardless — `usersAssign.php:307-315`) | `fa-exclamation-triangle` | `lang_get('access_vorsicht')` |
| *(none)* | assignable set is empty — `$gui->accessTypeImg` stayed `''` | — | — |

The assignable set for the `testproject` context is built by
`getTestProjectEffectiveRoles()` (`usersAssign.php:285-305`): only projects whose
*caller's effective role* may assign roles.

The 2.0.1 project screen rendered `<label data-i18n="assign.testProject">Test Project:</label>`
followed straight by `<select id="projectSelect">` — no indicator element at all, and the
strings `access`/`isPublic` appeared nowhere in the file. The BFF already knew the answer
(`api/roles/index.php` read `testprojects.is_public` and returned it as `isPublic` for the
*effective-role* computation), it was simply never rendered.

**Why it matters:** on a **private** project every non-admin collapses to `<no rights>`
(`getTprojectEffectiveRoleMap()`, `api/roles/index.php:215-232`) — the role a manager is
about to overwrite means something completely different on a public vs a private project.

## What was restored

### BFF — `api/roles/index.php`

1. `getAssignableProjects()` emits `isPublic` per combo option (the `map_of_map_full`
   projection already selects `TPROJ.*`, `testproject.class.php:561`), so the indicator can
   follow the combo with **no extra round-trip**.
2. `GET /meta/tproject-roles` returns a **resolved** tri-state `accessType`:
   `1` public, `0` private, `-1` `vorsicht`, `null` = nothing selected or the assignable set is
   empty. Purely additive — `isPublic` and every other field are unchanged.

   The resolution must stay server-side: `isPublic` alone is read through `get_by_id()`, so a
   requested-but-not-assignable project would be reported public/private where legacy showed
   `vorsicht`.

### Screen — `gui/templates/usermanagement/usersAssignProject.html`

* `.access-icon` CSS states (teal `#4ECDC4` globe / amber `#f0ad4e` lock / red `#e6605e`
  triangle) — the same block as the sibling plan screen (#1643), so both screens match.
* `<span id="projectAccessIcon">` between the label and the combo, exactly where legacy put it.
* `renderAccessIcon(isPublic)` maps the tri-state to the icon + `title`/`aria-label` tooltip.

### Wiring (five points)

| site | behaviour |
|---|---|
| `loadProjects()` entry | clears icon + access map **synchronously**, before the request leaves |
| `loadProjects()` `.done` | rebuilds the map from the combo payload, paints right after `sel.val(pid)` |
| `#projectSelect change` | repaints **synchronously** (globe/lock flips before the XHR leaves); cleared on the placeholder |
| `loadUsers()` `.done` | repaints from the authoritative server-resolved `accessType` |
| `showSessionExpired()` / `showDisabled()` / `showNoAccess()` | `renderAccessIcon(null)` — legacy rendered no icon without an assignable context |

### i18n

No new keys: `assign.accessPublic` / `assign.accessPrivate` / `assign.accessVorsicht` already
ship in **all 10 bundles** (`de`, `en`, `es`, `fr`, `it`, `ja`, `pt`, `ro`, `ru`, `zh`) from
#1643, with wording identical to legacy `$TLS_access_public` ("Public") /
`$TLS_access_private` ("Private - User need specific role assignment"). Switching locale
re-navigates (`gui/templates/i18n/i18n.js:249-253`), so the tooltip re-renders in the new
language too.

## Verification

10-case suite in `tmp/TLU_Test_Cases.md` (10 PASS): private lock, public globe, synchronous flip
on combo change (2→1→2), cleared combo, `vorsicht` for an out-of-set id, `showSessionExpired()`,
deep link without `tproject_id` (session-project precedence, #1613), `ro` tooltip, no console
errors, no new ERROR/WARNING rows in `events`.
Screenshots: `docs/screenshots/issue-1609-before-no-access-indicator.png`,
`docs/screenshots/issue-1609-after-access-indicator.png`.
