# Execution Notes Picker (execNotesPicker) — Modernized

## Overview

The Execution Notes Picker is the ASIDE-reachable entry point of the execution-notes
workflow. It lists the executions of the current test plan — highlighting which of
them actually carry notes — and deep-links the read-only viewer
(`Execution-Notes-readonly-execNotesReadonly-Modernized.md`) on the chosen row.

It exists because the #1807 viewer without an `exec_id` could only render a dead-end
card: with no picker, there was no way to reach that screen from the menu at all.

Deep link: `gui/templates/execute/execNotesPicker.html[?tplan_id=N]`
(no `tplan_id` → the session's selected plan is used)

Refs: #1809 (screen), #1807 (viewer it feeds), #1551 (editable popup it does not touch)

## Architecture (2.0.1)

- Modern UI: `gui/templates/execute/execNotesPicker.html` — standalone Dashio page,
  no Smarty, DataTables-free plain table (row count is small and both filters are
  client-side).
- BFF API: `api/execnotespicker/index.php`
  - `GET ?action=init[&tplan_id=N]` → plan/project context + `total_executions` /
    `with_notes` counters + `rights` (`can_read`, `can_edit`).
  - `GET ?action=executions[&tplan_id=N][&with_notes=0|1]` → execution rows
    (`id`, `status_char`, `execution_ts`, test case + `#PREFIX-N`, `suite_path`,
    `build_name`, `platform_name`, `has_notes`, `notes_preview`, `viewer_url`),
    capped at 500 rows with a `truncated` flag.
- Canonical action: `$actions->execNotesPicker` in `lib/functions/common.php`.
- ASIDE: leaf in section **9. Test Case Execution** (`api/aside/index.php`), label
  `title_execution_notes` ("Execution notes" / "Note de execuție").
- i18n: 34 `enp.*` keys + `footers.execNotesPicker` + `enro.pickExecution` (viewer
  hand-off button) in all ten bundles `gui/templates/i18n/{en,ro,de,fr,es,it,pt,ru,ja,zh}.json`.

## Behavior / Features

- **Context strip** — test project, test plan, total executions, executions with
  notes; the plan name is also appended to `document.title`.
- **"Only executions with notes"** filter, **on by default** (the picker's whole
  point), and a free-text filter matching test case name / external id, suite path,
  build, platform, status label and execution id, with a live `shown / total`
  counter and a distinct `noMatch` empty state.
- **Table** — `#id`, test case + `#PREFIX-N` chip, suite path, build (+ platform),
  status badge (`Passed` / `Failed` / `Blocked` / `Not Run` / `Unknown`, never a raw
  machine char), executed-on timestamp, escaped notes preview (`No notes` when the
  execution carries none) and an **Open** button that deep-links
  `execNotesReadonly.html?exec_id=N`.
- **Toolbar** — locale switcher (TLi18n), **Refresh** (spinner → repaint, one
  `init` + one `executions` round trip), **Execution Navigator** (carries
  `?tplan_id=&tproject_id=` from the loaded context — without them the navigator
  400s), **Close** (`window.opener` → `history.back()` → `/index.php`, because a
  menu-opened tab cannot close itself).
- **State cards** (keyed off the BFF `code`, never off an English message):
  session expired / not authenticated (401), test plan not found (404), no test
  plan selected (400 `no_testplan`), bad request (400), server error (500).

## Permissions / Edge Cases

- Right enforced on the plan's own test project:
  **`exec_ro_access` OR `exec_edit_notes` OR `testplan_execute`** — byte-for-byte
  the grant of `api/execnotesreadonly` (#1807) and `api/execnotes` (#1551), so the
  picker, the viewer and the editable popup can never disagree.
- **Oracle rule (#1697 / #1792):** an unknown test plan and a plan the caller may
  not read answer the **byte-identical** `404 plan_not_found` body, so the endpoint
  is not a test-plan existence oracle; every refusal is logged as `AUDIT`, never
  `WARNING`.
- No plan in the URL and none in the session → `400 no_testplan` (the screen shows
  a "select a test plan" card, not an access-denied one — nothing was denied yet).
- Non-safe verbs are refused with `405 method_not_allowed` + `Allow: GET, HEAD`
  *before* the shared origin guard; anonymous callers get `401 not_authenticated`.
- Non-scalar parameters (`tplan_id[]=`, `action[]=`) are refused with `400`
  before any cast, so no E_WARNING can reach the Event Viewer.
- **ASIDE gate:** the menu item is only built when the session user holds one of
  the three grants above — a rightless role never sees a link the endpoint would
  refuse.
- **Viewer hand-off:** `execNotesReadonly.html` without an `exec_id` now renders a
  **Pick an execution** button pointing back at this picker instead of dead-ending.

## Screenshots

Normal state — notes-only filter on, 3 of 5 executions:

All executions after unchecking the filter:

Text filter on the status column (`blocked` → 1 row):

No match for the current filters:

Open → the read-only viewer for execution #3:

The viewer without an `exec_id` hands over to the picker:

Anonymous caller (isolated context) → `401 not_authenticated` card:

Role 3 `<no rights>` → card byte-identical to an unknown plan:

Unknown plan id → `404 plan_not_found` card:

Romanian locale (no raw i18n keys, localized title/columns/footer):

Shell: ASIDE entry "Execution notes" + the screen in the main frame:

## Test results

- Suite `## Issue #1809` in `tmp/TLU_Test_Cases.md` — **22/22 PASS** (load, both
  filters, status search, viewer deep link, XSS probe inert, Refresh single round
  trip, Close fallback, navigator context, `Not Run`/`Unknown` labels, unknown plan,
  session fallback, anonymous 401, role-3 oracle denial + AUDIT row, ASIDE gates for
  both roles, viewer hand-off, `locale=ro`, 9 curl contracts, i18n gate, console,
  Event Viewer, code review). Gate `TLU_REQUIRE_SUITE="Issue #1809"
  bash ai/verify_test_suites.sh` → 7 PASS / 0 FAIL (exit 0).
- i18n gate `bash ai/verify_i18n_coverage.sh` → 9/9 PASS, 6901 keys per bundle,
  36 insertions / 0 deletions per bundle.
- Browser console clean on the screen; `events` table shows **0 new
  ERROR/WARNING** (the role-3 refusal lands as `log_level 16` AUDIT).
- Mandatory code review (rule 16) returned 4 required fixes, all applied before
  the commit: navigator context href, `statusLabel` `n`/unknown + `.badge-n` style,
  `fa-sticky-note-o` → `fa-sticky-note` (dropped in FA6), and the triple round trip
  collapsed to one `executions` request whose failure now calls `handleFail`
  instead of silently rendering a subset.

## Known limitations

- The 500-row cap sets `truncated` in the BFF answer; the screen's counter shows
  the capped row count while the context strip shows the real total (surfacing
  "showing first 500" is a possible follow-up).

## Related pages

- `Execution-Notes-readonly-execNotesReadonly-Modernized.md` — the viewer this
  screen feeds (#1807).
- `Modernize-Execution-Notes-execNotes.md` — the editable notes popup (#1551).
