# Set Results — Modernized Screen

Modernization of the legacy "set results" execution popup
(`lib/execute/execSetResults.php`, `level=testcase` mode,
`gui/templates/dashio/execute/execSetResults.tpl`) — GitHub issue
[#817](https://github.com/sebiboga/testlink-upgraded/issues/817).

The legacy Smarty screen is replaced by a standalone Dashio page
(`gui/templates/execute/execSetResults.html`) backed by a plain-PHP REST BFF
API (`api/execsetresults/index.php`). The 5 report screens that still opened
the legacy `execSetResults.php` popup (tcasesWithCF, resultsMatrix,
assignedTcOverview, tcAssignments, and the shared
`gui/javascript/testlink_library.js` `openExecutionWindow()` helper) now open
the modern screen instead.

**Path:** reached from report screens (Results matrix, TC with CF, Assigned TC
overview, TC assignments) — row **execute** icon opens the popup.
**URL:** `gui/templates/execute/execSetResults.html?tplan_id=..&tcase_id=..&tcversion_id=..[&setting_build=..][&setting_platform=..]`
**BFF API:** `GET ?action=init` + `POST ?action=save` on `/api/execsetresults/`
**Rights:** `testplan_execute` (full) or `exec_ro_access` (read-only — Save
disabled and server rejects write).
**Tracking issue:** [#817](https://github.com/sebiboga/testlink-upgraded/issues/817)

---

## Behavioral parity with the legacy controller

The BFF reproduces the `level=testcase` path of `lib/execute/execSetResults.php`:

- **`GET ?action=init`** resolves test plan + owning project (access-checked),
  test case + version (must belong to the case AND be linked to the plan),
  and returns:
  - test case context (external id, name), version (summary, preconditions,
    importance, execution type),
  - steps (sorted by step_number),
  - builds of the plan (executable = active+open; newest active+open wins as
    default),
  - platforms linked to the plan (empty → UI hides the selector),
  - execution status vocabulary (f/b/p/n/x/u — the legacy virtual filter
    status `a`/All is excluded),
  - grants (`testplan_execute` / `exec_ro_access`),
  - prior execution of this version on this build+platform, including recorded
    step-level results (partial-execution feature) so the form resumes the last
    run.
- **`POST ?action=save`** writes the execution with
  `write_execution()` (exec.inc.php), payload shape identical to
  `api/execute?action=save`: status code, notes, optional per-step results
  (step ids validated to belong to the version → forged ids skipped),
  execution duration, platform id. `not_run` results are ignored (legacy
  parity), non-executable builds and unlinked versions are rejected.

## Parameter normalization

Legacy callers used different key names (from `level=testcase` cookie-settings
contract and `openExecutionWindow()`):

| Modern | Legacy aliases |
|--------|----------------|
| `tcase_id` | `id` |
| `tcversion_id` | `version_id` |
| `build_id` | `setting_build` |
| `platform_id` | `setting_platform` |

## Screen layout

| Section | Description |
|---------|-------------|
| **Header** | "Set Results" + TC external id / name / version |
| **Toolbar** | Test Project / Test Plan context + locale switcher |
| **Plan context** | Build selector (**all** builds; closed ones marked `(closed)`) + Platform selector (hidden when plan has none) |
| **Prior execution** | Info box showing last result / tester / timestamp / notes (resumes the run; hidden when the selected build has no execution) |
| **TC content** | Summary, Preconditions, Steps table (per-step status + notes) |
| **Result** | Overall-result status buttons (colored) + Notes + Execution time (minutes) |
| **Actions** | **Save result** (disabled for read-only) and **Cancel** |

## Flow

1. The popup loads and calls `GET ?action=init` with the plan/case/version ids
   (plus optional build/platform). The BFF resolves context and returns it,
   pre-selecting the prior execution status if one exists.
2. The tester sets the overall result, optionally per-step results/notes and a
   note + execution duration, then clicks **Save result**.
3. The front-end `POST ?action=save` (JSON). The BFF writes the execution and
   returns `{status:ok, saved:true, execution_id}`; the toast confirms.

## TC design-info sub-sections (Refs #1404)

The legacy popup showed three design-info blocks under the steps table: the
linked **Requirements** list, the test case **relations** table and the
**Keywords** line. All three are reimplemented in the modern screen, fed by the
same `GET ?action=init` call (`requirements`, `relations`, `keywords`,
`requirements_enabled`).

- **Keywords:** one line `Keywords: kw1, kw2, …` rendered only when the version
  has keywords (legacy `exec_test_spec.inc.tpl`).
- **Linked Requirements:** a list of links `/lib/requirements/reqView.php?showReqSpecTitle=1&requirement_id=..&tproject_id=..`, titled
  `[spec] : REQ-1 : Title [Version n]`; rendered only when the project enables
  requirements (`requirementsEnabled`) AND the user has `mgt_view_req`. Empty
  result collapses the section (matches legacy DF-empty behavior).
- **Test case relations:** table with ID/Type + Test case columns. Each row
  carries the relation id, the localized type label and, for every linked case
  version, three popup icons: **execution history**
  (`execHistory.html?tcase_id=..&tproject_id=..`), **execute**
  (`execSetResults.html?tcase_id=..&version_id=..&level=testcase&id=..&tplan_id=..&setting_build=..&setting_platform=..`) and **design**
  (`tcView.html?tcase_id=..&tcversion_id=..&tproject_id=..`). Relation type
  labels come from `$tlCfg->testcase_cfg->relations->type_labels`; both
  directions (source/destination) are shown per legacy `exec_tc_relations.inc.tpl`.

All new labels are i18n keys (`esr.keywords`, `esr.relations`, `esr.relationIdType`,
`esr.testCase`, `esr.requirements`, `esr.clickToOpen`, `esr.execHistory`,
`esr.executeRel`, `esr.designRel`) present in all 10 locale bundles.

## Read-only access

A user with only `exec_ro_access` sees the full context (steps, prior
execution) but the **Save result** button is disabled client-side AND the
`save` route returns HTTP 403 `Insufficient rights` server-side. `testplan_execute`
is required to write.

## Closed builds (issue [#1403](https://github.com/sebiboga/testlink-upgraded/issues/1403))

The BFF `init` returns every build of the plan (`id/name/active/open/executable/release_date`,
`executable = active AND open`). The legacy popup shows a closed build with a
"Build is closed / Test cases can not be executed" banner and blocks new
execution while still allowing result review — the modern popup mirrors this:

- The build selector lists **all** builds (closed ones suffixed `(closed)`),
  so a closed build never silently disappears from the dropdown.
- Selecting a closed build shows the amber **"Build is closed. Test cases can
  not be executed."** box and switches the popup to review-only: Save, status
  buttons, step selects/notes, Notes and Execution-time are disabled, but the
  build selector stays enabled so the tester can switch back.
- Switching builds re-runs `GET ?action=init` for the new `setting_build`, so
  the banner and the prior-execution box always reflect the ACTUAL selected
  build (a build with no execution hides the prior box).
- `doSave()` defensively re-validates the selected build and aborts with
  `esr.errClosedBuild` ("Selected build is closed - result not saved.") if the
  UI state was somehow bypassed.
- Server-side the `save` route already rejected closed builds (HTTP 400
  "Invalid or non-executable build"), so data was always safe; this change only
  restores the missing user-facing signal.

i18n keys (all 10 locale bundles): `esr.closedBuild`, `esr.buildClosedMsg`,
`esr.errClosedBuild`.

![Closed-build read-only state](screenshots/issue-1403-execSetResults-closed-build.png)
