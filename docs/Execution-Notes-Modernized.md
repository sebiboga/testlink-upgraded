# Execution Notes popup — execNotes (#1551)

> Mirror of the GitHub Wiki page `Modernize-Execution-Notes-execNotes.md` (2026-09-20).

The legacy two-screen Execution Notes flow — `lib/execute/execNotes.php`
(edit) and `lib/execute/getExecNotes.php` (readonly view) — is modernized as a
single standalone Dashio screen backed by a REST BFF.

## Background

The **TODO section of `docs/MODERNIZATION-STATUS.md` is empty**: every ASIDE
entry was already verified to map to a modern `gui/templates/**/*.html` screen
+ BFF (DONE-row file sweep, `$actions` href audit in
`lib/functions/common.php`, ASIDE BFF tree dump, open-issue survey). Per the
`modernize.yml` guidance ("if the TODO section is empty, say so in one line,
then pick the smallest coherent one"), the Execution Notes flow was chosen — it
was the last `lib/execute/*` controller pair with no dedicated modern screen
(the modern execution screens only handle notes inline).

## Deliverables

- **Screen:** `gui/templates/execute/execNotes.html` — Dashio popup with teal
  header, execution meta card (test case / test plan / build / platform /
  localized status / executed-on), readonly notes box with an Edit toggle, a
  textarea with Save/Cancel, an empty state ("No execution notes recorded."),
  a missing-`exec_id` access-denied card, and toast feedback.
- **BFF:** `api/execnotes/index.php` — session auth + `bffSameOriginGuard`:
  - `GET /{exec_id}` — the execution row via `get_execution()` (raw) plus the
    `audit` variant for build/testplan/testcase/testproject/platform names;
    status char `p/f/b` mapped client-side to
    `execprint.statusPassed/Failed/Blocked`.
  - `PUT /{exec_id}` — persists `notes` mirroring the legacy `doUpdate()`
    (`UPDATE executions SET notes`), with `prepare_string`.
  - JSON contract 401 (anon) / 403 (CSRF) / 404 (unknown exec) / 400.
  - **Server-side rights (IDOR guard, code-review #1551):** each route resolves
    the execution's `testplan_id` → `testproject_id` and enforces legacy rights
    via `tlUser::hasRight` — view = `exec_edit_notes` OR `exec_ro_access` OR
    `testplan_execute`; edit = `exec_edit_notes`. Denied → 403.
- **i18n:** `execnotes.*` (15 keys) in all 10 locale bundles.
- **Link switch:** `$actions->execNotesView` in `lib/functions/common.php`
  (execute area, after `execExport`, `tplan_id > 0`).

## Verification

- Browser (admin session): view with existing notes, Edit → modify → Save
  (toast "Notes saved." + reload), empty-notes state, missing id →
  access-denied card. Console clean (0 error/warn).
- BFF curl contract: 401 anon GET/PUT, 403 CSRF without same-origin proof,
  403 rights (role-3 user on GET and PUT), 404 unknown exec, 200 + persistence
  round-trip.
- Event Viewer clean after the run (AUDIT only, 0 ERROR/WARNING).

## Test cases

Suite **1551 (Screen — Execution Notes)** in `tmp/TLU_Test_Cases.md`.

Screenshots: `docs/screenshots/issue-1551-execnotes-{populated,empty,edit}.png`.