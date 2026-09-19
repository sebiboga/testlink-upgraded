# Test Cases Assigned to User — Modernized (re-recorded) Screen

Re-recorded + fully verified modernization of the **Test Cases Assigned to User**
popup (`lib/testcases/tcAssignedToUser.php`, opened as an 800x600 popup from
**Results by Tester per Build**), covering issue
[#1544](https://github.com/sebiboga/testlink-upgraded/issues/1544). The modern
screen itself (`gui/templates/results/tcAssignedToUser.html` + BFF
`api/tcassigned/index.php`) was built during the #840 run, but its ledger row,
docs/wiki page, recorded test suite and usage write-up were never landed — this
run records everything and fixes two real BFF bugs found while verifying parity.

**URL:** `gui/templates/results/tcAssignedToUser.html?user_id=<id>&build_id=<id>&tplan_id=<id>&tproject_id=<id>`
**BFF API:** `api/tcassigned/index.php` (`GET ?action=init`, `POST ?action=quick_exec`)
**Rights:** `testplan_metrics` on the owning project for `init` (the right that also
gates the ASIDE `assigned_tc_overview` report the popup opens from);
`testplan_execute` (+ `assigned_to_me` assignment check when exec mode is
`assigned_to_me`) for `quick_exec`; 401 anonymous, 400/403/405 JSON error contract.
**Entry points:** clicking a tester name in `resultsByTesterPerBuild.html`
(`assignmentUrl` wiring — `resultsByTesterPerBuild.html` lines 84 + 226-232);
direct URL.

---
## Table of Contents
1. [What the screen does](#1-what-the-screen-does)
2. [REST API Reference](#2-rest-api-reference)
3. [Legacy parity notes](#3-legacy-parity-notes)
4. [i18n Keys](#4-i18n-keys)
5. [Security](#5-security)
6. [Testing](#6-testing)

## 1. What the screen does

| Feature | Legacy behavior | Modern implementation |
|---|---|---|
| Entry point | `fc=assigned_tc_overview` action inside the Results by Tester per Build frameset; 800x600 popup | same popup pattern — `resultsByTesterPerBuild.html` `assignmentUrl` + `userLink()` opens `tcAssignedToUser.html?...` 800x600; header mirrors the legacy one-liner (test project, user, plan) |
| Scope | per-plan groups of test cases whose execution is assigned to ONE user, filtered by test plan + build | identical; BFF `get_assigned_to_user()` (legacy call) grouped per test plan, plan list sorted case-insensitively by name |
| Filters | `initFilters()` — active test plans + open builds, **overridden to 'all' + specific build when `build_id > 0`** (deep link from the report) | mirrored 1:1 in the BFF (lines 117-125): `tplan_status=active`,`build_status=open` by default; `build_id` set ⇒ both become `all` |
| Table | Build, Test Suite (full path), Test Case (id + version + external id), Platform (when plats enabled), Priority, Status, Due Since | same columns in a DataTable; **exec-show**: build name, suite path, tc link (opens modern `tcView` in a window), platform + priority badges, status badge, age in days |
| Quick execution | inline `<img>` icons Passed/Failed/Blocked calling `execsetresults.php` (inline insert; only when exec right and — in `assigned_to_me` mode — the row owner is the session user) | Quick mark icons (Passed/Failed/Blocked) → `bffConfirm` → `POST quick_exec`; same icon visibility rules (Tests 11-13) |
| Execute link | `document.editExec` → full execution form | Execute link opens the modern `execSetResults.html` popup (`tcview` `editExecution` pattern) |
| History | `history` viewer per tcversion/build | exec-history icon (modern `tcHistory` pattern) |
| Status column | last execution status per (tcversion, build, platform) via `get_last_execution` | same `get_last_execution`; `status_code` mapping to `status_label` keys, `not_run` when absent |
| Priority column | `priority_to_level()` render (urgencyImportance thresholds high=6/low=3) | **bug fixed (commit `5e4f9fb36`)**: BFF used `>=HIGH(3)/>=MEDIUM(2)` mapping which rendered weights 4/2 as `high`/`medium` instead of the legacy `medium`/`low`; now delegates to `priority_to_level()` too |
| Due since | creation age in days | `age_days` from `creation_ts` epoch diff |

## 2. REST API Reference

All routes need a session (401 anonymous) and pass `bffSameOriginGuard`
(for POST: `X-Requested-With: XMLHttpRequest` or same-origin Referer).

| Method & path | Purpose | Failure modes |
|---|---|---|
| `GET ?action=init&tproject_id=&tplan_id=&build_id=&user_id=` | per-user assignment rows grouped by test plan (+ last-exec status, exec rights, platform/priority, age) | 400 missing/invalid `tproject_id`; 403 no `testplan_metrics`; `user_id` defaults to session user when 0 |
| `POST ?action=quick_exec` `{tplan_id,platform_id,build_id,tcversion_id,result}` | inline `INSERT INTO executions` for the exact triple; tester = session user | 405 non-POST; 400 missing params; 403 no `testplan_execute`; 400 invalid result (whitelist p/f/b); 403 not the assigned tester (`assigned_to_me`); 400 invalid tcversion |

`GET init` `200` payload (truncated):
```json
{ "status":"ok", "hasData":true, "tproject_id":27, "tproject_name":"TA2U",
  "tplan_id":28, "build_id":0, "user_id":1, "user_login":"admin",
  "glue_char":".", "priority_enabled":true, "tplans":[{
    "id":28, "name":"Plan TA2U", "show_platforms":true,
    "priority_enabled":true, "has_exec_right":true, "rows":[{
      "build_id":4, "build_name":"BUILD-OPEN", "suite_path":"/TA2/...",
      "tc_id":30, "tcversion_id":31, "prefix":"TA2", "tc_external_id":1,
      "name":"Open Admin Case", "version":1, "tplan_id":28,
      "status":"p", "status_key":"passed",
      "creation_ts_epoch":1789838813, "age_days":0,
      "platform_id":4, "platform_name":"Win11",
      "priority":6, "priority_level":"high",
      "can_exec":true }] }] }
```

## 3. Legacy parity notes

- **Row identity / status**: last execution is resolved per `(testcase_id,
  tcversion_id, testplan_id, build_id, platform_id)` using the same
  `testcase::get_last_execution()` the legacy popup used; when a row has no
  execution the status resolves to `not_run` via the `status_code` map.
- **Icon visibility** (Tests 12-13): in `assigned_to_me` exec mode the
  Quick/Execute actions are shown ONLY on the session user's own rows
  (`row.user_id === session user`). An admin opening the popup for another
  tester sees read-only rows (tcView + history links still present) — verified.
- **`initFilters()` deep-link override**: a concrete `build_id` (the case when
  the popup is opened from Results by Tester per Build with a build selected)
  forces `build_status='all'` + `tplan_status='all'` + `build_id`, so the
  BUILD-CLOSED rows do show once you deep-link to that build (Test 7).
- **`priority_to_level()` parity**: legacy renders priority through
  `priority_to_level()` with the `urgencyImportance` thresholds
  (high=6, low=3, config.inc.php). Weight 4 (medium-urgency × medium-importance)
  is `medium`, weight 6 is `high`, weight 2 is `low`. The earlier BFF mapping
  (`>=HIGH(3)` / `>=MEDIUM(2)`) rendered 4→`high`, 2→`medium` — fixed.
- **HTTP status masking bug (commit `60b9f2e62`)**: `out($data, $code=200)`
  reset a previously set `http_response_code(400/401/403)` back to 200, so
  every error branch came back HTTP 200 and the client's `xhr.status===401/403`
  handling never fired. `out()` now only applies a code when explicitly passed.
- **`user_id` scoping** is mandatory (this popup is single-user, unlike the
  overview's TL_USER_ANYBODY); `user_id=0` falls back to the session user.

## 4. i18n Keys

`ta2u.*` (29 keys, all 10 bundles) + `footers.tcAssignedToUser`; shared
`common.*` keys (`refresh`, `close`, `cancel`, `confirm`, `yes`, `no`, ...)
plus `tli18n` labels used by the exec-popup/history wiring already existed.

| Key | Value (en) |
|---|---|
| `ta2u.title` | Test Cases Assigned to User |
| `ta2u.user` / `ta2u.testProject` / `ta2u.testPlan` | User / Test Project / Test Plan |
| `ta2u.build` / `ta2u.platform` / `ta2u.priority` / `ta2u.status` / `ta2u.dueSince` | Build / Platform / Priority / Status / Due Since |
| `ta2u.suite` / `ta2u.testCase` | Test Suite / Test Case |
| `ta2u.priorityHigh` / `ta2u.priorityMedium` / `ta2u.priorityLow` | High / Medium / Low |
| `ta2u.notRun` / `ta2u.noData` | Not executed / No assigned test cases found |
| `ta2u.quickPass` / `ta2u.quickFail` / `ta2u.quickBlocked` | Quick Mark as Passed / Failed / Blocked |
| `ta2u.saveOk` / `ta2u.saveError` / `ta2u.loadError` | Test result saved successfully / ... errors |
| `ta2u.confirmQuick` | Apply quick result to ... |
| `ta2u.execute` / `ta2u.history` | Execute / History |
| `footers.tcAssignedToUser` | TestLink 2.0.1 - Test Cases Assigned to User |

## 5. Security

- Session auth + 401 on anonymous. The screen's `.fail()` handler redirects to
  `login.php` on 401 (verified with an isolated context).
- `bffSameOriginGuard` CSRF check on `POST quick_exec`.
- `testplan_metrics` gate on `init`, `testplan_execute` (+ assignment check)
  on `quick_exec` → 403; verified with a **role-3 no-rights user** (both routes).
- `assigned_to_me` joins `user_assignments` (type=1, feature_id = tptcv.id) to
  the tplan/tcversion/platform/build triple before allowing the insert → 403
  `Execution restricted to assigned tester` otherwise (Test 16).
- All ids are `intval`-coerced; the executions INSERT uses quoted/typed values
  (no prepared-statement dependency on the legacy builder for this hot path).
- `exec_mode.tester === 'assigned_to_me'` is read live from config
  (config.inc.php line 1104), mirroring the legacy exec-mode semantics.

## 6. Testing

Executed in the browser (admin/admin + tester1 + norights sessions,
`http://localhost:8082`, fixture `php tmp/fixtures_1544.php` → project `TA2U`
tproject 27 / plan 28, **BUILD-OPEN**=4 + **BUILD-CLOSED**=5, platforms
**Win11**=4 + **MacOS**=5, TCs with priorities 6/4/2/4, users admin(1) /
tester1(3) / norights(4)):

- BFF init (admin session): project-scoped rows, correct priority levels
  (6=high, 4=medium, 2=low), prior-execution status shown, `can_exec` true.
- BFF init (tester peek, same session): other user's rows load read-only.
- Build-scoped deep links: open build → open rows; closed build → BUILD-CLOSED
  row appears per `initFilters()` all-status override.
- Quick-exex via UI for admin and for the assigned tester (own rows show icons,
  another user's rows do not).
- BFF error contract: 400 invalid result / missing params / invalid tcversion,
  403 no-permission + restricted-to-assigned-tester, 405 non-POST, 401 anon
  (status codes verified AFTER the `out()` masking fix).
- Role-3 no-rights user: `init` and `quick_exec` both → real 403.
- Anonymous isolated-context: fetch 401 → screen redirects to login.
- Entry point: click `tester1` in `resultsByTesterPerBuild.html` → popup opens
  scoped to `user_id=3&build_id=4&tplan_id=28&tproject_id=27`.
- Event Viewer clean (AUDIT-only events); no JS console errors.

See `tmp/TLU_Test_Cases.md` → **Suite 1544 (tcAssignedToUser)** for the
recorded 22/22 PASS run. Screenshots:
`docs/screenshots/issue-1544-screen-admin.png`,
`issue-1544-screen-tester.png`, `issue-1544-screen-empty.png`,
`issue-1544-screen-closedbuild.png`, `issue-1544-screen-tester1-session.png`.