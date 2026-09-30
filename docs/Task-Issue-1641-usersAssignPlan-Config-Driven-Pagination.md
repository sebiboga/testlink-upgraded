# Task — Issue #1641: config-driven `usersAssign` pagination (`enabled` + `lengthMenu`) in `usersAssignPlan.html`

**Status:** implemented and verified (closes #1641)
**Screen:** `gui/templates/usermanagement/usersAssignPlan.html` — *Assign Test Plan Roles*
**BFF:** `api/roles/index.php` — `GET /api/roles/index.php/meta/tplan-roles`
**Commits:** `fa2cece23` (feature) + `87e438419` (modified-marker follow-up), branch `task/issue-1641`

## The gap

Legacy 1.9.20 `gui/templates/dashio/usermanagement/usersAssign.tpl` (deleted in `ab387af72`;
the template was **shared** by the test-project and test-plan contexts) built its user grid
**conditionally on configuration**:

```smarty
{if $tlCfg->gui->usersAssign->pagination->enabled}
  {$ll = $tlCfg->gui->usersAssign->pagination->length}
  <script>
  addToDataTablesConfig = { "columnDefs": [...], "language": { "search": "{$labels.User}" } };
  </script>
  {include file="DataTables.inc.tpl" DataTablesSelector="#item_view" DataTablesLengthMenu=$ll}
{/if}
```

The grid itself is a bare `<table id="item_view">` (`usersAssign.tpl:213`). The `{if}` is the
**only** thing that turns it into a DataTable, so:

| `$tlCfg->gui->usersAssign->pagination` | Legacy result |
|---|---|
| `enabled = true`, `length = '[20, 40, 60, -1], [20, 40, 60, "All"]'` (shipped, `config.inc.php:663-666`) | DataTable: search box, sortable headers, 20/40/60/All menu, paging |
| `enabled = false` | **plain table** — no search, no sort, no length menu, no paging |
| any other `length` | menu and starting page size follow the configured set |

The modern screen honoured **none** of it: `lengthMenu` / `pageLength` were hard-coded and
`renderUsersTable()` unconditionally called `$('#assignTable').DataTable({...})`, so an
administrator who turned pagination off still got a searchable, sortable, paged grid — the
exact opposite of what legacy rendered.

## Root cause

Two hops were broken, and the BFF half turned out to be *already implemented*:

1. `config.inc.php:663-666` → `$tlCfg->gui->usersAssign->pagination`
2. `api/roles/index.php:449-471` `getUsersAssignPaginationConfig()` — parses `enabled` and the
   legacy JS-array `length` string into a numeric `lengthMenu` (added by #930) ✔
3. `api/roles/index.php:820` ships the block on the **tproject** route ✘ — the **tplan**
   route's `out([...])` never called it
4. `usersAssignPlan.html` hard-coded the menu and always built a DataTable ✘

Measured at runtime before any code change: `meta/tplan-roles` returned keys
`["demoMode","items","planIsPublic","plans","projectIsPublic","projects","roleColouring","roles","status","totalPlans"]`
— no `pagination` — while `meta/tproject-roles` returned
`{"enabled":true,"lengthMenu":[[20,40,60,-1],[20,40,60,"All"]]}`.

## The fix

### BFF — `api/roles/index.php`

One line on the `meta/tplan-roles` payload, reusing the existing helper:

```php
'pagination' => getUsersAssignPaginationConfig()]);
```

### Screen — `gui/templates/usermanagement/usersAssignPlan.html`

Modelled on the already-correct sibling `usersAssignProject.html:161/490/632-641/654-672/725`.

| Change | Location | Purpose |
|---|---|---|
| `var paginationCfg = {enabled:true, lengthMenu:[[20,40,60,-1],[20,40,60,'All']]}` | module state | defaults = the shipped `config.inc.php` values, so a payload without the block degrades to the previous behaviour, not to a bare table |
| `applyPaginationCfg(r)` | called from **all three** tplan-roles loaders (bootstrap `tproject_id=0`, plan combo `tplan_id=0`, `loadUsers()`) | a config flip lands on the **first** paint, not only after a project/plan is picked |
| `localizedLengthMenu()` | helper | maps the configured labels, translating the shipped English sentinel `"All"` → `assign.all`; admin-supplied custom labels pass through untouched |
| `defaultPageLength()` | helper | first configured length, `20` only as a no-config fallback |
| `if (!paginationCfg.enabled) { … return; }` | immediately before the `DataTable({…})` construct | the `{if}` of `usersAssign.tpl:111-120`: no construct ⇒ no wrapper ⇒ no search / menu / paging / sortable headers. The authorized-only filter falls back to hiding the `not_authorized_user` `<tr>` (state → DOM), which is exactly what the screen's pre-existing-but-unreachable disabled-fallback code was written for |
| `lengthMenu: localizedLengthMenu()`, `pageLength: defaultPageLength()` | DataTable options | replaces the hard-coded `[20,40,60,-1] / 20` |
| `keep.len \|\| defaultPageLength()` | bulk-"Do" re-render | a custom `[10,30]` config no longer loses the administrator's page size |
| `st.length === defaultPageLength()` | `notifyRestoredState()` | the "is this the default view" baseline is the configured first entry, so a `[10,30]` screen does not fire a bogus "saved view restored" toast on its own untouched default view |
| `changed` class + `common.modified` badge in the row-build loop, gated on `!paginationCfg.enabled` | row builder | see the defect found below |

No new i18n key: the only user-facing label is the pre-existing `assign.all`, already present
in all 10 locale bundles, so **no bundle was modified**.

## The defect this activated (found by testing, fixed in `87e438419`)

The "modified" highlight is normally re-applied by DataTables' `createdRow` hook, which
re-runs on every redraw. With pagination **disabled** there is no DataTable and therefore no
hook — the first measurement of the bulk-"Do" case gave:

```
changedRows: 0, badges: 0, saveDisabled: false
```

i.e. the rows *were* changed and Save *was* enabled, but nothing on screen said which rows had
been touched. The class + badge are now emitted in the row-build loop, gated on
`!paginationCfg.enabled` so the DataTable path keeps its **single** badge (measured: 19 badges,
**0** cells with more than one).

This also **revived previously dead code** in the screen — `.authz-col { display:none }` ("in
the pagination-disabled fallback there is no DataTables at all"), the empty-state note, and
`onAuthzFilterToggle()`'s `else if (users.length) renderUsersTable();` branch — all written for
a branch that had no trigger. #1641 is that trigger.

## Verification

Fixture `tmp/fixtures_1641.php` → test project `TQ1641` (id 7), test plan `TQ1641-P1` (id 8),
25 Tester users `tlu1641_01..25`, plus two global-role-3 users (`tlu1641_nr1` uid 27,
`tlu1641_nr2` uid 28) so `<no rights>` rows exist — a *public* project with only global-Tester
users produces **zero** of them, which would leave the authorized-only filter unobservable.

Suite: `tmp/TLU_Test_Cases.md`, "Task — Issue #1641", **16/16 PASS**.

| Case | Measured |
|---|---|
| payload | `pagination: {enabled:true, lengthMenu:[[20,40,60,-1],[20,40,60,"All"]]}` |
| default config | menu `["20","40","60","-1"]` / labels `["20","40","60","All"]`, `Showing 1 to 20 of 28 entries`, search → `1 of 1 (filtered from 28)`, 5/5 headers sortable |
| custom `length '[10,30,-1]'`, state cleared | menu `["10","30","-1"]`, `pageLength 10`, `Showing 1 to 10 of 28 entries`, **no** restore toast |
| custom length with a saved 20-row page | `stateSave` restores the user's 20 (legacy `DataTables.inc.tpl:100-104` parity) |
| **`enabled = false`** | `isDataTable:false`; no wrapper / search box / length menu / paginate / info; all headers `sorting:false`; **all 28 rows in the DOM** |
| disabled → Save | role 9 persisted, toast `User Roles updated`, Save re-disabled |
| disabled → bulk "Do" | 28 rows, **27 changed + 27 badges** (admin row skipped), no DataTable created |
| disabled → authorized-only filter | 26 visible / 2 hidden, hidden uids exactly `[27,28]`, footer `28 users — 2 unauthorized user(s) hidden`; untick → 28 |
| enabled → authorized-only filter | `26 entries (filtered from 28 total)`, untick → 28 |
| enabled → bulk "Do" | 19 changed, 19 badges, **0 double badges** |
| hygiene | console 0 error/warn; `events` 0 rows at `log_level IN ('ERROR','WARNING')` |
| config hygiene | `git diff config.inc.php` → empty; `config_db.inc.php` never staged |

`config.inc.php` was toggled **per test case** and restored afterwards; no configuration change
is part of the commits.

## Notes / out of scope

- The sibling `usersAssignProject.html` passes the configured menu labels to DataTables **raw**,
  so it renders the English literal `All` instead of the localized word. `localizedLengthMenu()`
  here avoids that. Deliberately not changed by #1641 (it is a separate, cosmetic gap on a
  different screen).
- `config.inc.php` is the administrator-facing switch and is **not** exposed in the modern
  Configuration Management screen; this task ports the *behaviour*, not a new settings UI.
