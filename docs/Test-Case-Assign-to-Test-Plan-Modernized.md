# Assign Test Case to Test Plan — Modernized Screen

Modernization of the legacy "assign test case version to multiple ACTIVE test
plans" screen (`lib/testcases/tcAssign2Tplan.php`,
`gui/templates/dashio/testcases/tcAssign2Tplan.tpl`) — GitHub issue
[#810](https://github.com/sebiboga/testlink-upgraded/issues/810).

The legacy Smarty popup is replaced by a standalone Dashio page
(`gui/templates/testcases/tcAssign2Tplan.html`) backed by a plain-PHP REST BFF
API (`api/tcassign2tplan/index.php`). The test case viewer toolbar **Add to Test
Plan** button (`openAdd2Tplan`) now opens the modern screen instead of the legacy
`tcAssign2Tplan.php` redirect (this was the last remaining HIGH-priority legacy
redirect from issue #753, along with `tcEdit.php` — `tcExport.php` and
`tcCompareVersions.php` were already converted).

**Path:** Test Spec → Test Case Viewer → toolbar **Add to Test Plan**
**URL:** `gui/templates/testcases/tcAssign2Tplan.html?tcase_id=..&tcversion_id=..&tproject_id=..`
**BFF API:** `GET /api/tcassign2tplan/?action=init` + `POST /api/tcassign2tplan/?action=add`
**Rights:** any authenticated session (parity with legacy — the standalone
screen performs no extra menu-right check; the test case must belong to the
given project context).
**Tracking issue:** [#810](https://github.com/sebiboga/testlink-upgraded/issues/810)

---

## Behavioral parity with the legacy controller

The BFF reproduces the exact decision logic of `tcAssign2Tplan.php` for each
ACTIVE test plan (status 1) of the project:

- It computes the **linked** test-case-version/platform pairs from
  `testcase::get_linked_versions()`.
- For each plan it reads the plan's linked platforms
  (`testplan::getPlatforms()` → `getLinkedToTestplanAsMap`).
- A platform is rendered **read-only (already linked)** when the plan already
  has *any* version of this test case linked to that platform. Because TestLink
  does not allow *different* versions of one test case linked to the **same**
  plan, when the plan is already linked to a different version, the remaining
  platforms are hidden (`doAdd=false`).
- When the plan is already linked to the **same** version, the remaining
  platforms are selectable.
- `can_do` (whether the Add button shows) is true when at least one platform is
  selectable.

## Screen layout

| Section | Description |
|---------|-------------|
| **Heading** | "Assign Test Case to Test Plan" + test-case identity (`PREFIX-<id>:<name>`) |
| **Toolbar** | Test Case name (+id +version) and locale switcher |
| **Plan usage card** | DataTables-style grid: checkbox · Version · Test Plan · Platform |
| **Read-only rows** | Already-linked pairs render a checked+disabled checkbox with an "(already linked)" note |
| **Actions** | **Add** (visible only when `can_do`) and **Cancel** |

## Flow

1. On load the page calls `GET /api/tcassign2tplan/?action=init` with
   `tcase_id`, `tcversion_id`, `tproject_id`. The BFF resolves the test case
   (validating it belongs to the project), its current version number + identity,
   and builds the plan/platform grid.
2. The user ticks the (version, platform) pairs to link for the desired plans.
3. Clicking **Add** issues `POST /api/tcassign2tplan/?action=add` with
   `add2tplanid:{tplan_id:{platform_id:true}}`. The BFF calls
   `testplan::link_tcversions()` per plan (audited, `ASSIGN`).
4. On success the grid reloads; the newly linked rows become read-only and the
   success banner shows "Added to N test plan(s)".

## Error & edge states

| Case | Result |
|------|--------|
| Missing `tcase_id`/`tcversion_id` | fatal banner `ta2p.errNoContext` |
| Test case not in project context | HTTP 404 `Test case not found in project context` |
| No test plans in project | "No test plans." message, no table |
| No plan selected on Add | client toast `ta2p.warnSelect`, no request |
| Empty `add2tplanid` on Add | HTTP 400 `No test plan selected` |
| Not authenticated | HTTP 401 `{"status":"error","message":"Not authenticated"}` |

## API endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/tcassign2tplan/?action=init` | tc context + active plans grid with per-platform checkbox/linked state |
| POST | `/api/tcassign2tplan/?action=add` | link the TC version to the selected plan/platform pairs (`link_tcversions`) |

Both routes require a valid session (401 otherwise).

## Backend notes

- The BFF reuses the **exact legacy grid logic** from `tcAssign2Tplan.php`
  (linked-versions → per-plan target version → per-platform
  `draw_checkbox`/`doAdd`), so the read-only/already-linked behavior matches
  1.9.20.
- Context validation walks `nodes_hierarchy` from the test case up to the project
  id, rejecting cases that do not belong to the requested project.
- `testcase::$tables` is `protected`, so the BFF uses the literal
  `nodes_hierarchy` table name for that walk.

## i18n

All labels, titles, headings, messages and placeholders use client-side `TLi18n`
keys under the `ta2p.*` namespace (17 keys) present in all 10 locale bundles
(`gui/templates/i18n/{en,ro,de,es,fr,it,ja,pt,ru,zh}.json`). Bundle consistency is
enforced by `tools/lint_i18n.py`; every bundle passes `python3 -m json.tool`.

## Screenshots

Initial state (selectable grid) — `ta2p-initial.png`:

![initial](../screenshots/ta2p-initial.png)

After linking all pairs (read-only rows + success banner) — `ta2p-linked.png`:

![linked](../screenshots/ta2p-linked.png)

Test project with no active test plans — `ta2p-noplans.png`:

![noplans](../screenshots/ta2p-noplans.png)

---

_TestLink 2.0.1 · Assign Test Case to Test Plan · Refs #810_
