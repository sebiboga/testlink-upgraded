# Execution History (modernized screen)

## Issue #542

Modernized the **Execution History** view — previously still the legacy
`lib/execute/execHistory.php` popup (Smarty + ExtJS grid) opened from the
Test Case Viewer and the search screens.

## Root cause / scope

Issue #542 is a backlog item: the Execution History was not covered by the
tcView modernization. The modern screens (`tcView.html`, `searchView.html`,
`searchAdvancedView.html`, `searchQuickView.html`) still linked to the legacy
ExtJS popup.

## Approach

- New REST BFF endpoint **`api/execute/index.php`** (`action=history`), plain
  PHP, session auth, JSON I/O. It mirrors legacy access rules exactly:
  - reuses `testcase::getExecutionSet()` filtered by the test plans the current
    user can access (`tlUser::getAccessibleTestPlans()`), with the legacy
    `onlyActiveTestPlans` semantics (`'active' => NULL` when unchecked — passing
    `false` triggers an SQL syntax bug in tlUser, so NULL is mandatory);
  - localized execution status via `lang_get('test_status_' . code_status)`
    (same source as legacy `localize_tc_status`);
  - execution-level custom fields via `get_linked_cfields_at_execution()`,
    attachments via the attachment repository, bugs via `get_bugs_for_exec()`
    (with a graceful raw fallback when no issue tracker is configured);
  - per-test-plan `exec_edit_notes` grants drive the edit icon.
- New standalone Dashio screen **`gui/templates/execute/execHistory.html`**:
  teal header with external id badge, filter panel (build/tester/status/date
  range client-side + only-active-plans server-side), plain Bootstrap table
  with colored status badges, expandable detail rows (notes, custom fields,
  attachments, bugs), print preview + edit-execution actions opening the
  existing legacy popups.
- All four modern entry points repointed to the new screen; the legacy popup
  remains untouched for legacy templates.
- i18n: 34 new `exechist.*` keys added to ALL 10 locale bundles.

## Alternatives rejected

- Keeping ExtJS panel lazy-notes loading (getExecNotes.php) — notes are already
  in the payload; direct display is simpler and read-only.
- DataTables for the table — detail rows are first-class content here; a plain
  rendered table avoids row-pairing hacks.

## Verification

Regression suite 35 in `tmp/TLU_Test_Cases.md`: 9/9 PASS (API both plan modes,
screen render, filters, reset, never-executed warning, entry points, clean
Event Viewer).
