# Test Closure — ISTQB closure phase (Refs #1278)

Net-new module implementing the **test closure** phase of the ISTQB testing process
(review #1054): finalize archive, lessons learned and a closure report — the last
fundamental-process activity of a test cycle.

TestLink 1.9.20 has **no** closure twin (no `lib/plan/planClosure*.php`, no Smarty
closure template, no `$actions->*closure*` entry), so this module is built from
scratch with the same architecture as the other merged ISTQB additions
(`api/reviews`, Refs #1279; quality objectives, Refs #1280).

## Entry point

- Screen: `gui/templates/plans/testClosure.html`
- ASIDE menu: section **Test Closure** (icon `fa-clipboard-check`), shown whenever a
  test plan is selected and the user has `testplan_planning`, `exec_testcases` or
  `exec_ro_access`.
- URL of the action: `$actions->testClosure` (`lib/functions/common.php`).

## What the screen does

| Area | Behaviour |
|---|---|
| Outcome tiles | Assigned test cases, % executed, passed, failed, bugs linked, lessons recorded — live metrics of the plan |
| Lessons Learned | DataTable with 5 categories (What went well / What needs improvement / Repeat next time / Avoid next time / Other), add / edit / delete, author + timestamp |
| Closure checklist | Archive finalized, results frozen, lessons recorded, open issues handed over, feedback to stakeholders |
| Closure data | Archive reference + closure summary, saved with **Save Closure Data** |
| Closure Report | Printable report (print CSS + `window.print()`): outcome metrics, checklist, lessons-learned table, closure summary and closure state |
| Close / Reopen | Closing **freezes** the plan (see below); reopening undoes it |

## The freeze (closure state)

TestLink has no dedicated "archive" flag, so closure is implemented as an explicit
state on the plan:

1. `closure_close` snapshots the live outcome metrics into
   `test_closure.metrics_snapshot` and stamps `closed_by` / `closed_ts`.
2. While the plan is frozen, the **closure report keeps the numbers as of closure**
   even if executions change afterwards (verified: live 2 executed / 1 failed vs
   frozen snapshot 1 executed / 0 failed).
3. While frozen, the lessons-learned register is locked — `lesson_save` and
   `lesson_delete` answer **HTTP 409 `closure_frozen`** server-side, and the screen
   disables "Add Lesson Learned" and hides the per-row edit/delete buttons.
4. `closure_reopen` clears the snapshot and re-opens the register.

## BFF API — `api/testclosure/index.php`

Session auth + same-origin CSRF guard, JSON in/out. The test plan's project is always
resolved **through the plan**, so a forged `tplan_id` can never reach another
project's closure data.

| Route | Rights |
|---|---|
| `GET ?action=summary` | read |
| `GET ?action=lessons` | read |
| `POST ?action=lesson_save` | write |
| `POST ?action=lesson_delete` | write |
| `POST ?action=closure_save` | write |
| `POST ?action=closure_close` | write |
| `POST ?action=closure_reopen` | write |

- read = `testplan_planning` **or** `exec_testcases` **or** `exec_ro_access`
- write = `testplan_planning` **or** `exec_testcases`

## Data model

Two tables, created lazily with `CREATE TABLE IF NOT EXISTS` so a freshly imported
database works unchanged (`api/testclosure/index.php:tcEnsureSchema`):

- `test_closure` — one row per test plan (`closure_status`, `closure_summary`,
  `archive_ref`, `checklist` JSON, `metrics_snapshot` JSON, `closed_by`, `closed_ts`).
- `lessons_learned` — N rows per test plan (`lesson_category`, `title`, `description`,
  `author_id`).

`lib/functions/object.class.php` whitelists both table names.

## Audit trail

Every write emits an `AUDIT` event into the Event Viewer:
`CLOSURE_LESSON_CREATE`, `CLOSURE_LESSON_SAVE`, `CLOSURE_LESSON_DELETE`,
`CLOSURE_SAVE`, `CLOSURE_CLOSE`, `CLOSURE_REOPEN`.

## i18n

82 `closure.*` keys in all 10 client bundles (`gui/templates/i18n/*.json`) plus
`$TLS_title_test_closure` in all 19 server locales (`locale/*/strings.txt`).

## Verification

Test suite: `tmp/TLU_Test_Cases.md`, suite `Task — Issue #1278` (14 cases, all PASS) —
API validation/freeze matrix plus browser checks of tiles, CRUD, filters, checklist,
close/reopen and the print report. Event Viewer shows no new Error/Warning entry.
