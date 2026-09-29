# Issue #1707 — *show only authorized users* row filter in the Assign Roles screens (gap vs legacy)

**Status:** implemented and verified (closes #1707)
**Screens:** `gui/templates/usermanagement/usersAssignPlan.html` (Assign Test Plan Roles) and
`gui/templates/usermanagement/usersAssignProject.html` (Assign Test Project Roles) — the legacy
template was **shared**, so one legacy checkbox governed both grids
**BFF:** `api/roles/index.php` — `GET /api/roles/index.php/meta/tplan-roles` and
`.../meta/tproject-roles` (**no change needed**, see below)
**Branch / commit:** `task/issue-1707` — `4ebd33fe7`

## The gap

The modern screens had restored the `not_authorized_user` row marker (#1645 for the plan screen,
#926 for the project screen) but not the control that consumed it.

Legacy 1.9.20 `gui/templates/dashio/usermanagement/usersAssign.tpl` (deleted in `ab387af72`):

* `:12` fetched the label:
  ```smarty
  {lang_get var="labels"
            s='TestProject,TestPlan,btn_change,title_user_mgmt,set_roles_to,show_only_authorized_users,
               warn_demo,User,btn_upd_user_data,btn_do,title_assign_roles'}
  ```
* `:46` defined the helper:
  ```js
  function toggleRowByClass(oid,className,displayCheckOn,displayCheckOff,displayValue) {
    var trTags = document.getElementsByTagName("tr");
    var cbox = document.getElementById(oid);
    for( idx=0; idx < trTags.length; idx++ ) {
      if( trTags[idx].className == className ) {          // exact compare (legacy)
        if( displayValue == undefined ) {
          trTags[idx].style.display = cbox.checked ? displayCheckOn : displayCheckOff;
        } else { trTags[idx].style.display = displayValue; }
      }
    }
  }
  ```
* `:234-237` wrote the marker it filtered on:
  ```smarty
  {$user_row_class=''}
  {if $effective_role_id == $smarty.const.TL_ROLES_NO_RIGHTS}
    {$user_row_class='class="not_authorized_user"'}
  {/if}
  ```

The `tl-classic` twin carried the identical trio (`:7`, `:42`, `:196`). A whole-repo grep at the
deletion commit shows the label was *fetched* and the helper *defined*, but no checkbox markup and no
call site existed any more — the pair was already vestigial in 1.9.20. The capability is what this
issue restores; the semantics are fully determined by the three artefacts above.

Modern state before the fix (`grep`, measured on `30aeecd0d`):

```
usersAssignProject.html:33   .assign-table tr.not_authorized_user td { color: #999; }
usersAssignProject.html:462  var rowClass = (u.effectiveRoleID == NO_RIGHTS_ROLE_ID) ? ' not_authorized_user' : '';
usersAssignPlan.html:47      .assign-table tr.not_authorized_user td { color: #999; }
usersAssignPlan.html:617     var rowClass = (u.effectiveRoleID == NO_RIGHTS_ROLE_ID) ? ' not_authorized_user' : '';
$ grep -rn "showOnlyAuthorized\|show_only_authorized" gui/templates/ api/   ->  no match
```

## Why a verbatim port is impossible

1. **The exact class compare never matches.** The rows are rendered as
   `class="not_authorized_user"` (or `… changed`), and DataTables 1.13.7 appends its own striping
   classes additively. A live `<no rights>` row therefore reads
   `class="not_authorized_user odd"` or `"not_authorized_user changed even"`, and
   `tr.className == 'not_authorized_user'` is always false. The port must use
   `classList.contains()` semantics (here: the model, `effectiveRoleID == 3`).
2. **`style.display = 'none'` is wrong for a paginated grid.** The hidden rows would still be
   counted by DataTables (`Showing 1 to 20 of 20 entries`), the zero-records message could never
   fire, and a page could render as a column of blanks.

## The fix

### Toolbar

A Dashio checkbox `#onlyAuthorizedChk` labelled `assign.showOnlyAuthorized` is added to `.toolbar`
in both screens. Because the disabled/no-access states call `$('.toolbar').hide()`, the checkbox is
suppressed exactly like the rest of the assign form.

### Filtering — through the DataTables pipeline

* A trailing **hidden marker column** (`AUTHZ_COL` = 5 on the plan grid, 4 on the project grid)
  carries `1` (authorized) / `0` (`<no rights>`), declared
  `{ visible:false, searchable:false, orderable:false }`. Measured: DataTables removes the cells of a
  hidden column from the rendered DOM, so the visible row markup is byte-identical to before.
* **One `$.fn.dataTable.ext.search.push()` predicate** (`registerAuthzFilter()`, registered once,
  scoped by `settings.nTable.id !== 'assignTable'`):
  ```js
  if (!showOnlyAuthorized) return true;          // box unticked -> transparent
  return String(row[AUTHZ_COL]) === '1';         // ANDed with the built-in column search
  ```
  While unticked it is a no-op, so the normal search box keeps working unchanged.
* `onAuthzFilterToggle()` swaps `oLanguage.sZeroRecords` between `assign.noAuthorizedUsers` (on) and
  `assign.noUsers` (off) and calls `page(0).draw(false)`.

This is what makes the interaction survive **paging / sort / search redraws**, keep the paging counts
truthful and allow the localized “nothing matched” message — none of which `display:none` can do.

### Pagination-disabled fallback

When `$tlCfg->gui->usersAssign->pagination->enabled` is false (`config.inc.php:665`, overridable via
`custom_config.inc.php`) the project screen builds no DataTable at all, so `buildBodyHtml()` emits
`style="display:none"` for the mismatching rows — the legacy mechanism, rendered from state so
in-screen re-renders keep the filter.

### Bulk “Do” / Save coherence (required by the issue)

`authzExcluded(u)` = filter ticked **and** `u.effectiveRoleID == TL_ROLES_NO_RIGHTS`. While the box
is ticked such a row is:

* skipped by `applyBulkRole()` (bulk “Set roles to” + Do),
* omitted from the Save payload,
* ignored by `isDirty()` / `updateSaveBtn()` so Save is never enabled by an out-of-scope edit.

Unticking the box restores the rows with their untouched values.

### Footer

`updateAuthzFooter()` replaces the raw footer write and appends
`— {n} unauthorized user(s) hidden` (`assign.unauthorizedHidden`) while the filter is on.

### BFF

**No change.** `api/roles/index.php` already emits `effectiveRoleID` for both payloads, and both
screens already derived the row marker from it. The gap was purely front-end + i18n.

### i18n

Three keys, added to all 10 locale bundles (`en ro de es fr it ja pt ru zh`):

| Key | en |
|---|---|
| `assign.showOnlyAuthorized` | Show only authorized users |
| `assign.noAuthorizedUsers` | No authorized user matches the current filter. |
| `assign.unauthorizedHidden` | `{n}` unauthorized user(s) hidden |

## Verification (fixture `AN1707`, private project #7 / private plan #9, 5 users of which 3 are `<no rights>`)

| Check | Result |
|---|---|
| plan grid, box ticked | 2 rows visible; `Showing 1 to 2 of 2 entries (filtered from 5 total entries)`; footer `5 users — 3 unauthorized user(s) hidden` |
| search box + filter | `search('an1707designer')` → 0 rows + `No authorized user matches the current filter.`; `search('admin')` → 1 row |
| sort redraw | `order([1,'desc']).draw()` keeps 2 rows |
| bulk Do (role 7) with filter ON | admin + the 3 `<no rights>` rows untouched; only the authorized uid 5 changed |
| Save payload (plan) | `{"tplan_id":12,"assignments":{"5":7}}` — hidden rows absent; DB `5/12/6 → 5/12/7` |
| project grid, box ticked | 2 rows visible; footer `5 users — 3 unauthorized user(s) hidden` |
| Save payload (project) | `{"tproject_id":10,"assignments":{"5":7}}`; DB `5/10 → 5/10/7` |
| untick | all 5 rows restored, footer `5 users` |
| pagination-disabled fallback | off: all visible; on: the 3 `<no rights>` rows `display:none` |
| control: PUBLIC project `AN1707PUB` #13, box ticked | 5 rows, `Showing 1 to 5 of 5 entries`, footer `5 users` (nothing hidden) |
| ro locale | `Afișează doar utilizatorii autorizați` / `2 utilizator(i) neautorizați ascunși` |
| `node --check` both scripts, `python3 -m json.tool` all bundles | clean |
| browser console / Event Viewer | 0 errors, 0 warnings; `events` has no `log_level` 1/2 rows |

## Files

| File | Purpose |
|---|---|
| `gui/templates/usermanagement/usersAssignPlan.html` | toolbar checkbox, hidden marker column, `ext.search` predicate, toggle handler, bulk/Save gating, footer |
| `gui/templates/usermanagement/usersAssignProject.html` | same, plus the pagination-disabled `buildBodyHtml()` fallback |
| `gui/templates/i18n/*.json` (10) | `assign.showOnlyAuthorized`, `assign.noAuthorizedUsers`, `assign.unauthorizedHidden` |
| `docs/screenshots/issue-1707-*.png` | normal + filtered states |

## Resume / re-test

```bash
php tmp/fixtures_1707.php        # private AN1707 (#10) plans #11/#12 + public AN1707PUB (#13) plan #14
# login admin/admin, then open:
#   http://localhost:8082/gui/templates/usermanagement/usersAssignPlan.html?tproject_id=10&tplan_id=12
#   http://localhost:8082/gui/templates/usermanagement/usersAssignProject.html?tproject_id=10
#   http://localhost:8082/gui/templates/usermanagement/usersAssignPlan.html?tproject_id=13&tplan_id=14  (control)
# tick "Show only authorized users" -> 2 rows; footer reports 3 hidden; Save excludes them.
```
