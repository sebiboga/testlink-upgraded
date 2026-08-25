# Assigned Test Case Overview — Modernized Screen

The **Assigned Test Case Overview** report (ASIDE → Reports → *Assigned Test Case Overview*) shows every test case whose execution is assigned to a user, grouped per test plan. Each row carries build, test suite, test case (with quick-execution, history and edit icons), platform (when the plan has platforms), priority, last execution status, and assignment age. It replaces the legacy 1.9.20 `lib/testcases/tcAssignedToUser.php` HTML view (in its `show_all_users=1` Reports entry variant) with a modern Dashio-styled page backed by a plain-PHP REST BFF.

**Path:** ASIDE menu → Reports → *Assigned Test Case Overview*
**URL:** `gui/templates/results/assignedTcOverview.html?tproject_id=<id>&show_all_users=1&show_inactive_and_closed=1`
**BFF API:** `api/reports/index.php` — `GET ?action=assigned_tc_overview&tproject_id=&show_all_users=&show_inactive_and_closed=&show_closed_builds=`
**Rights:** `testplan_metrics`
**Tracking issue:** [#684](https://github.com/sebiboga/testlink-upgraded/issues/684)

---

## Overview

The header carries the test project context. The toolbar shows the test project name, a "Show closed builds" checkbox (session-persisted via sessionStorage), and a Refresh button.

For each test plan with assigned test cases, a collapsible section renders:

| Column | Content |
|--------|---------|
| Build | build name |
| Test Suite | full suite path (legacy `tcase_full_path`) |
| Test Case | prefix-glue-extid: name (v.N) + icon links |
| Platform | platform name (when the plan has linked platforms) |
| Priority | High / Medium / Low (when `testPriorityEnabled` in project options) |
| Status | last execution result badge (PASSED/FAILED/BLOCKED/NOT RUN) |
| Due Since | days since test case creation |

### Test case cell icons

Each test case cell contains:

* **Execution History** — opens `execHistory.php` popup for the TC
* **Quick Pass / Fail / Block** — inline execution buttons (if user has `testplan_execute` right on that plan). Click confirms, then POSTs to the BFF `quick_exec` action which inserts an execution row.
* **Execute** — opens `execSetResults.php` popup for the full execution flow

### Quick execution (quick_exec)

The quick-exec icons write an `executions` row directly via BFF POST:

```
POST /api/reports/index.php?action=quick_exec
{
  "tplan_id": <int>,
  "platform_id": <int>,
  "build_id": <int>,
  "tcversion_id": <int>,
  "result": "p" | "f" | "b"
}
```

Guards:
* `testplan_execute` right on (tproject, tplan) context
* Status whitelist: only `p`, `f`, `b` are accepted
* When `exec_cfg->exec_mode->tester == 'assigned_to_me'`: checks `user_assignments` via JOIN to `testplan_tcversions` to confirm the clicking user is the assigned tester
* `tester_id` is always the session user (not the filter user, which collapses to TL_USER_ANYBODY=0 in the overview variant)

### Empty state

A test plan with no assigned test cases is omitted entirely. A project with zero assignments shows "No records found."

## BFF API — assigned_tc_overview

**GET** `/api/reports/index.php?action=assigned_tc_overview`

Parameters:
* `tproject_id` (required) — test project context
* `show_all_users` (default 1) — show user column for all users
* `show_inactive_and_closed` (default 0) — include inactive/closed TC versions
* `show_closed_builds` (default 0) — include closed builds (persisted in session)
* `user_id` (optional) — filter by specific user
* `build_id` (optional) — filter by specific build

Response shape:
```json
{
  "status": "ok",
  "hasData": true,
  "tproject_id": 901,
  "tproject_name": "ATO Demo Project",
  "glue_char": "-",
  "tplans": [
    {
      "id": 951,
      "name": "ATO Plan Alpha",
      "show_platforms": true,
      "priority_enabled": true,
      "has_exec_right": true,
      "rows": [
        {
          "user_login": "",
          "user_id": 1,
          "build_name": "b1",
          "suite_path": "ATO Root Suite",
          "tc_id": 921,
          "tcversion_id": 923,
          "prefix": "ATOD",
          "tc_external_id": 1,
          "name": "TC one",
          "version": 1,
          "tplan_id": 951,
          "status": "p",
          "status_key": "passed",
          "creation_ts_epoch": 1787630936,
          "age_days": 0,
          "can_exec": true,
          "platform_id": 971,
          "platform_name": "Desktop",
          "priority": 3,
          "priority_level": "high"
        }
      ]
    }
  ]
}
```

## Legacy controller reference

The legacy screen (`lib/testcases/tcAssignedToUser.php`) uses:
* `testcase::get_assigned_to_user()` with `mode=full_path`
* `get_last_execution()` for status per build/platform
* `extTable` for rendering (grouped by first column)
* Raw SQL INSERT for quick-execution (same `executions` table)
* Session-based `show_closed_builds` persistence

The modern screen preserves all legacy behavior with Dashio visual patterns.
