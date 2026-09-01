# Execute Tests (execNavigator + execSetResults) — Modernized Screen

Modernization of **Execute Tests** (`lib/execute/execNavigator.php` +
`lib/execute/execSetResults.php`) — GitHub issue
[#662](https://github.com/sebiboga/testlink-upgraded/issues/662).

The legacy tree-navigator + execution-form pair is replaced by a standalone
Dashio two-pane page (`gui/templates/execute/execTest.html`) backed by a
plain-PHP REST BFF (`api/execute/index.php`, extended with four new actions).
The ASIDE **Execute → Execute Tests** entry now points to the new HTML screen
when a test plan is selected (switched in `getActions()` /
`initUserEnv()` in `lib/functions/common.php`).

**Path:** Execute → Execute Tests
**URL:** `gui/templates/execute/execTest.html?tproject_id=<id>&tplan_id=<id>`
**BFF API:** `api/execute/index.php` (same file also serves the Execution
History screen via `?action=history`)
**Rights:** `testplan_execute` to record results, `exec_ro_access` for the
read-only variant — enforced server-side on every BFF route.

![Initial state](images/execTest-initial.png)

---
## Table of Contents
1. [What the screen does](#1-what-the-screen-does)
2. [REST API Reference](#2-rest-api-reference)
3. [Legacy parity notes](#3-legacy-parity-notes)
4. [i18n Keys](#4-i18n-keys)
5. [Security](#5-security)
6. [Testing](#6-testing)

## 1. What the screen does

- **Toolbar:** build selector (only active+open builds are executable; closed
  ones are listed with a "(closed)" hint), platform selector (hidden when the
  plan has no platforms), result filter and live text search over external id,
  name and suite path.
- **Left pane:** all test cases linked to the plan (latest linked version per
  case) with suite path, version, last-execution timestamp on the selected
  build/platform and a colored status badge.
- **Right pane:** the execution form — summary, preconditions, importance,
  manual/automated marker, prior execution recap for this build/platform,
  result-status buttons (Not Run / Passed / Failed / Blocked), execution notes
  and step-level results (per-step status select + notes) with the recorded
  partial-step results pre-filled from the prior execution.
- **Save** records one execution row through the legacy
  `exec.inc.php:write_execution()`, so every side effect keeps working:
  requirement-link freezing, relation closing, step partial-execution rows.
- **Read-only mode:** users with only `exec_ro_access` see everything but
  cannot save (inputs disabled; API rejects writes with 403).

![Case details](images/execTest-case-details.png)

![Saved](images/execTest-saved.png)

## 2. REST API Reference

All endpoints are GET unless stated otherwise; session auth required;
`tplan_id` must be one of the user's accessible test plans.

| Action | Params | Returns |
|---|---|---|
| `init` | `tplan_id` | project/plan context, grants, builds (+executable flag, default build = newest active+open), platforms, localized status vocabulary |
| `tcList` | `tplan_id`, `build_id`, `platform_id`, optional `q` | latest linked version per case: external id, name, suite path, importance, execution type, last execution (status/ts/tester) |
| `tcDetails` | `tplan_id`, `tcase_id`, `tcversion_id`, `build_id`, `platform_id` | version block, ordered steps, prior execution incl. prior step results, `linked_bugs` (consolidated, deduped by bug id: `id`,`url`,`title`,`labels`,`label_colors`,`status`,`color`,`unavailable`,`steps`,`exec_count`) and `exec_history` (all executions of this tcversion in the plan, DESC, with per-row run-mode/version/build/tester/status + `can_edit_notes`/`can_delete` grants) |
| `save` (POST JSON) | `tplan_id,tcase_id,tcversion_id,version_number,build_id,platform_id,status(code),notes,steps{stepId:{status,notes}}` | `saved:true` + execution id/ts, or `reason:'not_run'`; validates executable build + plan link; delegates to `write_execution()` |
| `deleteExecution` (POST JSON) | `tplan_id`, `execution_id` | `deleted:true`; gates: `testplan_execute` + `exec_delete` (public-plan-aware), execution must belong to the plan, build must be open; delegates to `delete_execution()` and logs an AUDIT event **only on success** |
| `updateNotes` (POST JSON) | `tplan_id`, `execution_id`, `notes` | `updated:true`; gates: `testplan_execute` + `exec_edit_notes`, execution must belong to the plan, build must be open; delegates to `updateExecutionNotes()` |

Status codes travel as codes (`n/p/f/b`) exactly as served by `init`
(`statuses[].code`).

## 3. Legacy parity notes

- Only **active+open** builds can receive results (legacy UI offered the same
  set; the BFF additionally rejects violations server-side).
- `Not Run` results are never written as executions (identical to
  `write_execution()` behavior since 1.9.20) — the UI explains this via toast.
- Plans without platforms store `platform_id = 0` on execution rows
  (the client sends `-1`, mapped exactly like legacy `$executedInPlatform`).
- Step results reuse the legacy `execution_tcsteps` storage; steps whose
  status is not_run and whose notes are empty are skipped like legacy.
- The aside entry keeps its `menuGrants` gating (`testplan_execute`,
  `exec_ro_access` fallback label) in `aside.tpl`.

**Deliberate v1 scope cuts** (tracked on #662): step-level attachment upload,
bug linking inside the form, execution custom fields in the form, bulk mode.
Execution-level attachment upload/list + "Copy attachments from latest
execution" are implemented (see `Bugfix-Issue-792-Execute-Tests-Attachments.md`).

## 3b. Linked Bugs table (test-case details)

The test-case details panel renders a **Linked bugs** DataTables card
(Bug ID / Title / Labels / Status / Step), fed **real-time from the GitHub
API** (no caching) via the `tcDetails` `linked_bugs` array:

- Bug ID is a clickable GitHub link (`bug_url`), unless the issue is deleted.
- **Deleted / rejected GitHub issues** (API returns HTTP `410`) set
  `unavailable:true` → the row shows a grey **Deleted** status badge, an
  "Unavailable" title and **no dead link** (`#805`/`#806` are deleted on
  GitHub and demonstrate this; live issues show real title/labels/status).
- **Labels** render as GitHub-colored badges. `githubrestInterface::getIssue()`
  and `listIssues()` expose `label_colors` (name→hex) alongside `labels`;
  `execTest.html renderLinkedBugsTable()` uses the same map; unknown/missing
  color falls back to grey `#6c757d`.
- BFF dedupes by bug id and aggregates the steps each bug is bound to
  (`steps`) plus the number of executions (`exec_count`).
- The same `unavailable` + GitHub label-color handling is mirrored in the
  Dashboard (`mainPage.html` bugs + issues tables, `dash.bugDeleted` /
  `dash.bugUnavailable`).

## 4. i18n Keys

All strings use the `exe.*` prefix, defined in ALL ten client bundles:
`en, de, es, fr, it, ja, pt, ro, ru, zh` (46 keys each after the Linked Bugs
table keys `exe.bugDeleted` / `exe.bugUnavailable`), consumed through
`TLi18n`. Server-side labels (statuses) come from `lang_get('test_status_*')`
like legacy `localize_tc_status()`.

## 5. Security

- Session auth + same-origin guard (`bffSameOriginGuard`) on every request.
- `testplan_resolveContext`: plan must be within the user's accessible plan
  set for the owning project (else 403/404).
- Write route requires `testplan_execute` on (project, plan);
  `exec_ro_access` alone yields HTTP 403.
- All ids cast to int; notes prepared inside legacy `prepare_string()` paths.

## 6. Testing

Suite **63** in `tmp/TLU_Test_Cases.md`: 14/14 PASS — covers init/list/detail
contracts, full UI save flow, filters/search, not-run guard, closed-build
guard, read-only path (banner + disabled UI + 403), locale switch and Event
Viewer cleanliness after fixes.

Fixes landed during testing:
- options blob read instead of vestigial `option_platforms` column
  (E_WARNING in Event Viewer);
- `locale/en_GB/custom_strings.txt` adding `file_upload_step_exec_ok/ko`
  (surfaces #621's missing en_GB keys on every step-level save).

### Issue #621 — L18N "not localized" events on step execution save (closed)

`write_execution()` (lib/functions/exec.inc.php:257-258) evaluates
`lang_get('file_upload_step_exec_ok'/'ko')` on every step-level save. When the
active locale lacks the keys, `lang_get()` falls back to en_GB and writes a
`string '...' is not localized for locale '<loc>' - using en_GB` event
(log_level 32) into the Event Viewer.

**Root cause / coverage:** the keys were added to 16 locales' `custom_strings.txt`
and lived in `strings.txt` for pt_BR/pt_PT, but **ro_RO had neither** (no
`custom_strings.txt`, 0 hits in `strings.txt`) — a 19-locale scan confirmed
ro_RO was the only missing bundle.

**Fix (Refs #621):** created `locale/ro_RO/custom_strings.txt` with both keys
(UTF-8 Romanian translation, same `Fixes #621 / #662` header convention as the
16 existing bundles).

**Verification (regression suite 621, 5/5 PASS):**
- ro_RO save pre-fix → 2 L18N events (reproduced, events id 9/10);
- ro_RO save post-fix → 0 new `file_upload_step_exec` events;
- en_GB + pt_BR saves → unchanged (still 0);
- browser UI save on the modernized Execute Tests screen → "Execution saved."
  with no console errors and no L18N events.

Side-discoveries logged as new issues (out of this fix's scope):
- #773 — `Undefined property: stdClass::$requirementsEnabled` E_WARNING on
  every execution save when `testprojects.options` is empty;
- #774 — other ro_RO server-side keys (`manual`, `automated`,
  `the_format_tc_xml_import`) still missing.

### Issue #796 — inline execution-history table restored (closed)

**Symptom:** opening a test case in the modernized Execute Tests screen showed
no inline execution-history table at all. The legacy 1.9.20 screen had, under
the prior-execution note line, a full history table with per-row edit-notes /
delete / print actions and run-mode icons.

**Root cause:** the modernized `execTest.html` simply never rendered that
table — the BFF `tcDetails` response carried no execution-history payload and
there were no BFF actions for edit-notes / delete, so 1.9.20 functionality was
silently dropped during the rewrite.

**Fix approach (restore 1.9.20 parity, following the legacy code paths):**
- **BFF** (`api/execute/index.php`): `tcDetails` now also returns `exec_history`
  — the full execution set for this tcversion+plan (all builds, DESC), each row
  with `build_name`/`build_is_open`, tester login/name, localized status,
  `execution_ts`/`execution_duration`/`run_type`, `tcversion_number`, plus
  `can_edit_notes` + `can_delete` per-row grants (from `exec_edit_notes` /
  `exec_delete` + open build). Two new write routes:
  - `deleteExecution` — mirrors legacy `execSetResults.php` delete flow
    (`exec_delete` right, open build, execution belongs to the plan); calls
    `delete_execution()` (cleans execution_bugs / cfield_execution_values /
    execution_tcsteps / attachments) and writes an AUDIT event **only on
    success**.
  - `updateNotes` — mirrors legacy `editExecution.php` doUpdate
    (`testplan_execute` + `exec_edit_notes`, open build, plan ownership); calls
    `updateExecutionNotes()`.
- **Frontend** (`gui/templates/execute/execTest.html`): new `renderHistoryHtml()`
  renders the EXECUTION HISTORY table (Date/time, Build+lock icon, Executed by,
  Status badge, Run-mode icon, Version, Actions) plus a per-row notes line.
  Row actions: edit-notes → legacy `editExecution.php` popup; print →
  legacy `execPrint.php` popup; delete → confirm dialog → BFF
  `deleteExecution` → refresh form + list.
- **i18n:** added `exe.executionHistory / actions / deleteExecution /
  confirmDeleteExecution / deletedOk / errDelete` and `exechist.closedBuild` to
  all ten client bundles; also repaired pre-existing corrupted English/Romanian
  values for the `exechist.*` keys the table uses.

**Verification (regression suite 796, 7/7 PASS, see tmp/TLU_Test_Cases.md):**
init/list/detail contracts unchanged; history table renders all runtime rows
with per-row action buttons; edit-notes popup preloads the execution notes;
print popup renders the full execution; delete removes the execution + child
rows and logs an AUDIT delete event; rights/state gates confirmed by code
review (`testplan_execute` + fine-grained right, plan ownership, open build);
Event Viewer clean (only AUDIT events, no new Error/Warning).

Screenshots: `docs/screenshots/issue-796-exec-history-missing-before.png`
(pre-fix, no history table) and `docs/screenshots/issue-796-exec-history-after.png`
(fixed, history table with row actions).

### Issue #795 — "Save Steps Work In Progress Execution" (partial save/resume) restored (closed)

**Symptom:** the modernized Execute Tests screen had no way to save partial
step results for later resumption. Legacy 1.9.20 rendered a
"Save Steps Work In Progress Execution" button under the steps table
(`inc_exec_test_spec.tpl` → `TLS_saveStepsForPartialExec`) that wrote
`execution_tcsteps_wip` rows; the rewrite dropped the button, the BFF action
and the WIP read-back, so mid-run progress could be lost.

**Root cause:** three missing pieces during the #662 rewrite, all present in
legacy:
- no button in the form;
- no BFF route (legacy: `execSetResults.php` → `testcase::saveStepsPartialExec()`);
- `tcDetails` did not merge `execution_tcsteps_wip` into `prior_step_results`,
  so saved WIP rows never pre-filled the form on reopen.

**Fix approach (restore 1.9.20 parity):**
- **BFF** (`api/execute/index.php`):
  - new `POST ?action=savePartialSteps` — gates `testplan_execute` (403),
    active+open build (400), valid `tcversion_id` (400); validates every step id
    against the version (`tcsteps`+`nodes_hierarchy` join, forged ids dropped)
    and every status via `results.code_status` (fallback `not_run`); delegates to
    `testcase::saveStepsPartialExec()` with `testplan_id`,
    `platform_id` (normalized `>0 ? id : 0`), `build_id`, `tester_id` = session
    user. Legacy delete-then-insert semantics preserved (a resave replaces the
    tester's WIP rows for that plan/build/platform).
  - `tcDetails` now merges WIP rows over prior executed steps for the current
    (plan, build, platform, tester) context — the resume pre-fill.
- **Frontend** (`gui/templates/execute/execTest.html`): non-read-only forms
  with steps render the `Save Steps Work In Progress Execution` button + a hint;
  `savePartialSteps()` replicates legacy `checkStepsHaveContent` client-side
  ("nothing to save" toast, no request), POSTs the same step payload shape as
  `saveExecution()`, then toasts on success and re-opens the case so resumed
  values show.
- **i18n:** `exe.partialExecInfo / partialExecNothingToSave / partialExecSaved /
  savePartialSteps` added to all ten bundles; button label reuses the legacy
  English string, translations reuse legacy `locale/*/strings.txt` where present.

**Verification (regression suite 64, 11/11 PASS, see tmp/TLU_Test_Cases.md):**
partial save writes only `execution_tcsteps_wip` (no `executions` row); empty
save is blocked client-side (no POST, DB untouched); resume pre-fill in-session
and across full reloads; full "Save execution" after a partial save clears the
WIP rows (legacy exec.inc.php flow) and writes the execution + step rows;
zero-right user → 403; inactive build → 400; forged step id dropped; Event
Viewer clean (only INFO-level AUDIT rows).

Screenshots: `docs/screenshots/issue-795-execTest-no-partial-save.png` (before)
and `docs/screenshots/issue-795-execTest-partial-save-resume.png` (after/resume,
button + pre-filled steps).

### Issue #793 — "Save & move to next" navigation restored (closed)

**Symptom:** the modernized Execute Tests screen offered only **Save execution**
and **Reset** in the save row. The 1.9.20 toolbar buttons
`btn_save_exec_and_movetonext` ("Save and move to next") and `btn_next`
("Next" — move to the next test case WITHOUT saving) were dropped in the #662
rewrite, and the BFF `init` never surfaced `exec_mode->save_and_move`, so the
configured navigation mode could not be honored.

**Root cause:** three pieces missing during the rewrite, all present in legacy
(`lib/execute/execSetResults.php` `doExecSave()`/`move2next` window at
lines 140-345 + 617-632 and `gui/templates/tl-classic/execute/inc_exec_controls.tpl`
lines 89-97):
- no "Save and move to next" button / BFF save-then-navigate flow;
- no "Next" (navigate, no save) button / BFF `move2next` equivalent;
- no read of `$tlCfg->exec_cfg->exec_mode->save_and_move` (config.inc.php:1119,
  default `'unlimited'`) in the BFF `init` response.

**Fix approach (restore 1.9.20 parity):**
- **BFF** (`api/execute/index.php`): `init` now returns `save_and_move` read from
  `config_get('exec_cfg')->exec_mode->save_and_move` with fallback `'unlimited'`.
- **Frontend** (`gui/templates/execute/execTest.html`):
  - extracted `visibleItems()` — the exact filter predicate `renderList()` uses,
    so navigation always matches what the user sees (search filter included);
  - `saveAndMoveMode()` — resolves the mode from `initData.save_and_move`
    (config fetch + runtime override);
  - `execNextTarget()` — computes the cyclical successor of the currently open
    case inside the visible set; `'unlimited'` wraps across the whole list,
    `'limited'` wraps only among members of the SAME test suite. *Limited* mode
    groups by the displayed suite path (`tsuite_path`) — the flat-list
    equivalent of legacy `getTestCaseNextSibling`, since every direct suite owns
    a distinct path. (Discovery: the BFF `tcList` field named `tsuite_id`
    actually holds the test-case node id, not the suite id; it is unused by the
    frontend, so grouping on `tsuite_path` avoids depending on that semantics.)
  - `moveToNext()` — legacy `move2next` (navigate, no save);
  - `saveExecution('next')` — saves via the existing `action=save` BFF route,
    then `loadList()` + `openCase(execNextTarget())`; the successor is computed
    BEFORE the save (against the pre-save list), matching legacy ordering;
    a `not_run` selection still advances (legacy execSetResults.php:241
    navigates on save_and_next even when nothing is persisted).
  - Save row now renders **Save execution · Save and move to next · Next ·
    Reset**; all four buttons share the existing `roMode` disabled guard.
- **i18n:** `exe.saveAndMoveNext` / `exe.moveToNext` / `exe.noNextCase` added to
  all ten bundles (legacy `locale/*/strings.txt` translations reused where
  present, e.g. en "Save and move to next" / "Next").

**Note — bulk results mode:** the legacy **bulk** results grid is a
testsuite-level feature (`$args->level == 'testsuite'`); the modern flat-list
screen has no testsuite view, so bulk execution is out of scope here (its suite
entry point does not exist in this screen's design).

**Verification (regression suite 69, 12/12 PASS, see tmp/TLU_Test_Cases.md):**
BFF `init` returns `save_and_move:"unlimited"`; four-button save row renders;
Save-and-move writes the execution (executions row: tcversion 5, status p) and
auto-advances to Case A2; move-to-next navigates WITHOUT writing (executions
count unchanged); unlimited cycle A3→B1→B2→A1; limited mode scoped per suite
(A1→A2→A3→A1, B1→B2→B1); search-filtered navigation B1→B2 within the visible
subset; plain Save unchanged; i18n bundles valid 10/10 with the 3 keys; Event
Viewer clean (no new Error/Warning rows).

Screenshots: `docs/screenshots/issue-793-save-and-next-before.png` (before,
only Save execution + Reset) and `docs/screenshots/issue-793-save-and-next-after.png`
(after, four-button save row).
