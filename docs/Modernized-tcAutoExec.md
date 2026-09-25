# Modernized: Remote Test Automation Execution (tcAutoExec)

Issue: [Refs #1587](https://github.com/sebiboga/testlink-upgraded/issues/1587)
Legacy: `lib/testcases/tcExecute.php` + `gui/templates/dashio/testcases/tcExecute.tpl`
Modern: `gui/templates/testcases/tcAutoExec.html` + BFF `api/tcautoexec/index.php`

## What was modernized

"Remote Test Automation Execution" lets a test designer hand a test case — or a
whole test suite, or the entire test project — to a remote automation server
over XML-RPC and see what came back, without leaving TestLink. It was the last
standalone `lib/testcases/*` controller without a modern twin, so it is now a
Dashio standalone page with three cards:

- **Execution context** — test project (read-only, with id), test plan, build
  and platform selects. These are the values forwarded to the automation
  server as the XML-RPC context. Changing the test plan reloads the build
  (project-scoped) and the platform (plan-scoped) lists, because a platform
  belongs to a plan and a build belongs to a project.
- **Execution target** — `LEVEL` (Single test case / Test suite / Whole test
  project) plus the matching node select. Every entry of the select is
  prefixed with its resolved automation server and its source (test case
  custom fields vs. suite custom fields), so the target that has no server at
  all is visible *before* running anything. The panel under the select states
  which custom fields would be used.
- **Remote execution results** — summary counters (test cases / answered /
  not passed / config problems / connection failure) and a per-test-case table
  with the test case (deep link to the case view), the server that answered,
  the execution status and the result, plus the XML-RPC `message` and `notes`
  with the newlines the server sent. Statuses mirror the legacy controller:
  `ANSWERED`, `CONFIG PROBLEMS`, `CONNECTION FAILURE`.

The old controller became a session-guarded 302 shim that forwards to the
modern page (the pattern used by every other modernized screen), and the ASIDE
got the entry in *Test Case Design*, right after *Test Automation
Specification* (both gated on `view_tc`).

## BFF (`api/tcautoexec/index.php`)

- `GET ?action=init&tproject_id=N[&tplan_id=][&build_id=][&platform_id=]` →
  context (project, plans, builds, platforms) + the runnable node list with the
  resolved automation server of every node, at the three levels.
- `POST ?action=run` (form-encoded body) `{level, node_id, tproject_id,
  tplan_id, build_id, platform_id}` → per test case
  `{system.status, system.msg, result, resultVerbose, notes, message,
  scheduled, timestampISO}` + summary counters + `truncated` flag.
- Automation server resolution keeps the legacy cascade: the three test
  case-level custom fields (`tc_server_host` / `_port` / `_path`), then the
  test suite-level trio (`tsuite_server_host` / `_port` / `_path`) from the
  case and its suite ancestors, then nothing → `CONFIG PROBLEMS`. The
  `tplan_api_key` is resolved **server-side** only and never leaves the BFF.
- Execution is delegated to the legacy `executeTestCase()` in
  `lib/functions/remote_exec.php`, so the XML-RPC payload, the system status
  handling and the audit trail are identical to 1.9.20. The run is capped at
  **200 test cases per request**; when the target has more, the response sets
  `truncated` and the page shows a warning bar.
- Security: session auth (401), `mgt_view_tc` on the **owning** test project
  (403, fail closed — the legacy controller had no rights check at all), the
  shared `bffSameOriginGuard()` CSRF proof for the POST (403), 404 unknown
  node/project, 400 bad level / node / project, 405 on wrong method.

## Rights / authz

- anonymous → 401 (the page redirects to the login form)
- logged-in user without `view test cases` on the project → 403, rendered as
  an "Access denied" card, not a blank page
- unknown node or unknown project → 404; bad level / node id → 400

## i18n

`tae.*` (46 keys) + `tae.status_*` (7 execution-status labels: answered is
`tae.answered`, the remote result domains are passed / failed / blocked / not run /
not available / unknown / all) +
`footers.tcAutoExec` added to all 10 locale JSON bundles
(en/ro/de/es/fr/it/ja/pt/ru/zh) — no hardcoded strings on the page, and the
locale switcher works like on every modern screen. The ASIDE label
`href_tc_auto_exec` was added to all 19 `locale/*/strings.txt` bundles.

## Legacy gaps fixed while testing

- **#1588** — `lib/functions/remote_exec.php:125` looked the XML-RPC result code
  up in `$code_status` without a guard: an unknown or missing code produced a
  PHP 8 `E_WARNING` ("Undefined array key") in the Event Viewer and a `null`
  label. The lookup is now guarded, the code is still displayed verbatim and
  the outcome is classified as failed.
- **Result vocabulary** — the legacy page only ever compared the result against
  `passed` / `failed`, so a `b` (blocked) answer was mis-counted as passed. The
  BFF resolves the code through the same `code_status` map
  (`taeResolveResultStatus()`) and returns `result_status` + `result_label`,
  which is what the counters and the badges use.
- **#1589** — `tree::_get_subtree()` walks into the `testcase_step` children of
  a test case and asks for a node table that does not exist → one
  `Undefined array key "testcase_step"` `E_WARNING` per test case in the Event
  Viewer. The BFF now passes the same `exclude_children_of => testcase` filter
  that `testsuite::get_subtree()` uses, so a 210-case suite run is warning-free;
  the latent core bug is filed as #1589.
- **No access-denied state** — the legacy page happily rendered the form for
  users without the right and then failed on submit.
- **Unbounded remote call** — `IXR_Client` was built without a timeout, so a hung
  automation server blocked `fsockopen()` for the OS default. The BFF now sets
  20s per call, which surfaces as the legacy `CONNECTION FAILURE` status.
- **Foreign context ids** — the plan / build / platform are only forwarded as
  XML-RPC context, but nothing verified that they belong to the project the run
  was authorised for. They are now validated server-side (`taeOwnsPlan()` /
  `taeOwnsBuild()` / `taeOwnsPlatform()`) and silently dropped otherwise.
- **Non-array XML-RPC answer** — a server answering with a scalar (or with a map
  without `result`) used to make the legacy controller read an array offset on a
  non-array. The answer is now validated and reported as a config problem.

## Test coverage

Suite appended to `tmp/TLU_Test_Cases.md` — **24/24 PASS**: the three
execution levels, the custom-field cascade (test case, suite inheritance,
nothing configured), pass / fail / config-problem / connection-failure
rendering, the 200-case truncation bar, plan → build/platform re-init, the
`400/401/403/404/405` + CSRF contract, the ASIDE entry, the legacy shim
redirect, the `ro` locale and Event Viewer hygiene (no new Error/Warning).

## Fixtures and screenshots

- `tmp/fixtures_1587.php` — project `AutoExec Demo` (107), plans
  `AutoExec Plan` (108) and `AutoExec Plan 2` (109), build 13, platforms
  10/11, suites `AX1587-A` / `AX1587-A1` / `AX1587-B`, test cases
  `AX1587-1..3` (ids change on every re-run), the six automation custom
  fields and the role-less `norights` user.
- `tmp/fixtures_1587_bulk.php` — adds `AX1587-BULK` (210 test cases over a
  suite and its child) to exercise the 200-case cap.
- `docs/screenshots/issue-1587-tcautoexec-{1-default,2-passed,3-failed,
  4-configproblem,5-connectionfailure,6-accessdenied,7-truncated200}.png`
- The XML-RPC double used for the run tests is a throwaway script outside the
  repository (`/tmp/xmlrpc_mock.php`, port 9999) — it answers
  `executeTestCase` with a configurable `p` / `f` code.
