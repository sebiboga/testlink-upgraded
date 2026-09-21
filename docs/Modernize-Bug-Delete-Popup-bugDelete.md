# Modernize-Bug-Delete-Popup-bugDelete (#1559)

The standalone **Bug Delete popup** — `lib/execute/bugDelete.php` (+
`gui/templates/dashio/execute/bugDelete.tpl`, an 81-line action popup) — is
modernized as a standalone Dashio screen backed by a REST BFF. It unlinks a bug
from a test execution and is opened from the Execute Tests / Set Results bug
list via `deleteBug()`.

## Background

The **TODO section of `docs/MODERNIZATION-STATUS.md` is empty**: every ASIDE
entry was already verified to map to a modern `gui/templates/**/*.html` screen +
BFF. Per the `modernize.yml` guidance ("pick the smallest coherent one"), the
Bug Delete popup was chosen — it was the only `lib/execute/*` controller with no
modern twin (the modern screens inline bug-link/unlink in execTest /
execSetResults but expose no dedicated unlink screen, and `bugAdd.php`,
`bugLink.php`, `bugCreate.php` still serve the add/create flows).

## Deliverables

- **Screen:** `gui/templates/execute/bugDelete.html` — Dashio popup: teal header
  ("Bug Delete"), Close button, context card `Execution #<id> — <test case>`
  (test case / test plan / test project / build / platform / status badge /
  executed-on), a **Bugs linked to this execution** bug list (bug id, `Step
  #N` label for step-level links, per-row **Delete** button). The Delete button
  opens a Bootstrap confirm modal ("Unlink this bug from the execution?" with
  the bug id); confirming POSTs to the BFF and re-renders: success box + empty
  state. Dedicated state boxes for load errors (missing/invalid id), execution
  not found, and permission denied. TLi18n locale switcher + localized footer.
- **BFF:** `api/bugdelete/index.php` — `GET ?action=init&exec_id=N
  [&tcstep_id=N][&bug_id=X]` returns the execution context + linked bugs; when
  `bug_id` is present the row is deleted **on the way in** — exact legacy parity
  (`lib/execute/bugDelete.php:44` `write_execution_bug($db, 'delete', ...)`
  deletes on load). `POST ?action=delete {exec_id, tcstep_id, bug_id}` deletes
  the row and writes the `audit_executionbug_deleted` /
  `audit_executionbug_deleted_no_platform` events. Rights: `testplan_execute` on
  the **owning project** (not the session-context preview) → 403; anon → 401;
  bad/missing exec or bug → 400; unknown execution → 404; non-GET → 405;
  unknown action → 400; CSRF `bffSameOriginGuard`.
- **Link switch:** `deleteBug()` in `gui/javascript/testlink_library.js` now
  opens `gui/templates/execute/bugDelete.html?exec_id=&tproject_id=&tplan_id=&bug_id=`.
- **Shim:** `lib/execute/bugDelete.php` is kept as a session-guarded 302
  redirect onto the modern screen (anon → login), so legacy deep links still
  resolve.
- **i18n:** `bugdel.*` (24 keys) + `footers.bugDelete` in all 10 bundles
  (en/ro/de/es/fr/it/ja/pt/ru/zh), validated with `python3 -m json.tool`.

## Verification

- Browser (admin session): list view renders the context card + BUG-101 row;
  step-level link renders `BUG-202` + `Step #1`; Delete → confirm modal → POST →
  success box "The bug was successfully deleted!" + empty state; `?exec_id=99999`
  → "Execution not found"; no `exec_id` → "Missing or invalid execution id.";
  locale switch to `ro` re-renders fully in Romanian (Ștergere Bug / Buguri
  legate de această execuție / Șterge); screen also opens with `?exec_id=` alone
  (the BFF resolves the project from the execution).
- BFF curl contract: 401 anon, 403 role-3 no-rights user (`norights`), 405
  wrong method, 400 missing/invalid exec + missing bug_id on POST, 404 unknown
  execution, 403 CSRF (no `X-Requested-With`), 200 init / auto-delete / delete.
- Legacy on-load auto-delete parity verified (GET with `bug_id` deletes).
- Event Viewer clean after the run (only audit/login rows, no Error/Warning);
  console clean; `php -l` clean.

## Bug found and fixed

- Schema parity: `execution_bugs` has the composite PK
  `(execution_id, bug_id, tcstep_id)` and **no `id` column**, so the initial
  post-delete re-check `SELECT id …` threw SQL error 1054 → HTTP 500. Fixed to
  `SELECT execution_id` (commit `5b4172bf4`).

## Test cases

Suite **1559 (Screen — Bug Delete popup)** in `tmp/TLU_Test_Cases.md` —
10/10 PASS.

Screenshots: `docs/screenshots/issue-1559-bugdelete-list.png`,
`docs/screenshots/issue-1559-bugdelete-modal.png`,
`docs/screenshots/issue-1559-bugdelete-success-empty.png`,
`docs/screenshots/issue-1559-bugdelete-404.png`.

Fixture: `tmp/fixtures_1559.php` (project B1559, TC "BUG Login Check", 3
executions: with a linked bug / step-level bug / empty). No-rights user:
`tmp/mkuser_norights.php` (role 3).