# Test Specification — Tree & Editor (editTc) — Modernized Screen

**Path:** ASIDE → Test Specification
**URL:** `gui/templates/testcases/testSpec.html?tproject_id=<id>[&tcase_id=<id>][&locale=<loc>]`
**BFF API:** `api/testcases/index.php` (`action=tree`, `action=get`;
POST `create`, `update`, `delete`, `create_version`, `suite_create`,
`suite_update`, `suite_delete` — session-based auth, JSON I/O)
**Rights:** `mgt_view_tc` to browse; `mgt_modify_tc` for every write action;
editing an executed version additionally requires
`testproject_edit_executed_testcases` (or `canEditExecuted` config), same as
legacy `tcEdit.php`.
**Replaces:** legacy workarea launcher
`lib/general/frmWorkArea.php?feature=editTc` → tree frame +
`lib/testcases/tcEdit.php` editor forms.



---

## What it does

Single-page replacement of the 1.9.20 *Test Specification* two-frame layout:

* **Left pane — test case tree**: full project subtree (suites at any depth +
  test cases) rendered from one BFF call; suite count pills; expand/collapse
  toggles; live name filter box; inactive test cases dimmed.
* **Right pane — context panel**, three states:
  - **Suite selected**: path, sub-suite / test-case counters,
    "New Test Case Here", "New Sub-Suite", "Rename Suite", "Delete Suite".
  - **Test case selected**: identity header (name, version badge, external id
    badge, executed/inactive badges), meta grid (importance, execution type,
    internal id), summary and preconditions blocks, numbered steps table,
    assigned keywords chips and the action bar.
  - **Editor form** (create or edit): name, importance, execution type,
    summary, preconditions, steps editor (add/remove rows, auto renumbering),
    project keywords checkboxes.

## Actions

| Button | Behavior |
|--------|----------|
| New Test Case | opens create form targeted at the selected suite |
| New Test Suite | modal → POST `suite_create` under selection (or project root) |
| Rename Suite | modal prefilled → POST `suite_update` |
| Delete Suite | confirm modal (warns when non-empty) → POST `suite_delete` |
| Edit Test Case | pre-filled form → POST `update` (latest version, legacy doUpdate semantics) |
| Create New Version | confirm modal → POST `create_version`, view reloads on VER n+1 |
| Full Viewer | opens the modernized read-only `tcView.html?tcase_id=…` in a new tab |
| Delete Test Case | confirm modal → POST `delete` (removes ALL versions) |





## Errors & warnings

| Situation | Message |
|-----------|---------|
| Not authenticated | BFF returns HTTP 401 JSON error |
| Missing right on write action | HTTP 403 "Requires permission: modify test cases" |
| Executed latest version without special right | HTTP 403, save blocked (legacy parity) |
| Empty name on save/create/rename | toast "Name is required" |
| Unknown node id (deep link) | panel shows the BFF error text |

## i18n

All labels/toasts/placeholders come from the `tspec.*` block — 61 keys added
to **all 10 locale bundles** (`en, de, es, fr, it, ja, pt, ro, ru, zh`),
verified valid JSON. Runtime switch via `?locale=` verified (e.g. RO).

## Bugs found & fixed while testing

1. Wrong column name `tcexternalid` (real schema: `tc_external_id`) broke the
   tree aggregates and get action — SQL error was captured by the Event Viewer.
2. Create form crashed on `Object.keys(undefined)` when no keywords were
   assigned yet (guard added).
3. TC name/suite parent were read from `tcversions` which has no `name`
   column — get action now joins `nodes_hierarchy` twice.
4. PHP 8 warning "Undefined property: stdClass::$canEditExecuted" logged in
   the Event Viewer on every context/update call — now null-safe.

All four fixed and re-verified; Event Viewer clean afterwards.
