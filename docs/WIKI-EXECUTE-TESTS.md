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
| `tcDetails` | `tplan_id`, `tcase_id`, `tcversion_id`, `build_id`, `platform_id` | version block, ordered steps, prior execution incl. prior step results |
| `save` (POST JSON) | `tplan_id,tcase_id,tcversion_id,version_number,build_id,platform_id,status(code),notes,steps{stepId:{status,notes}}` | `saved:true` + execution id/ts, or `reason:'not_run'`; validates executable build + plan link; delegates to `write_execution()` |

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

**Deliberate v1 scope cuts** (tracked on #662): attachments-at-execution and
bug linking inside the form, execution custom fields in the form, bulk mode.

## 4. i18n Keys

All strings use the `exe.*` prefix, defined in ALL ten client bundles:
`en, de, es, fr, it, ja, pt, ro, ru, zh` (44 keys each), consumed through
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
