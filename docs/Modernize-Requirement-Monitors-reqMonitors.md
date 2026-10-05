# Requirement Monitors (reqMonitors) — Modernized

## Overview
The Requirement Monitors popup (`reqMonitors`) shows the list of users monitoring a
specific requirement. This was originally a legacy Smarty **include**
(`gui/templates/dashio/requirements/reqMonitors.tpl`, 35 lines, pulled in from
`reqViewVersions.tpl:437`) with **no controller of its own** — the whole feature was
that template plus one 26-line AJAX file, `lib/ajax/requirements/getreqmonitors.php`.
That AJAX file authorized **nothing but a session** (`testlinkInitPage($db)`, no
rights check at all) and read `intval($_REQUEST['item_id'])` straight into
`requirement_mgr::getReqMonitors()` with no project scope — the same class of hole
retired in #1696 (`getrequirementnodes.php`), #1765 (`getreqcoveragenodes.php`) and
#1770 (`gettprojectnodes.php`). The reader is now retired and the feature is a
deep-linkable Dashio popup backed by a dedicated, rights-checked REST BFF.

Refs: #1780. Bugs found by this modernization: **#1841** (scope leak), **#1842**
(broken deep link into the Requirement Viewer).

## Architecture (2.0.1)
- **Modern UI**: `gui/templates/requirements/reqMonitors.html` (Dashio look & feel —
  teal header, dark toolbar, context card, monitor table, TLi18n with locale switcher)
- **BFF API**: `api/reqmonitors/index.php` — `GET|HEAD ?action=init&req_id=N[&tproject_id=P]`
- **Legacy reader retired**: `lib/ajax/requirements/getreqmonitors.php` reduced to a
  **non-mutating** session-guarded shim — 302 to the modern popup for a browser
  navigation, `405 retired_endpoint` for an XHR/crawler/`Accept: application/json`,
  `405 wrong_method` for a write verb, `login.php?note=expired` for an anonymous caller
- **Callers**: `gui/templates/requirements/reqView.html` gained a **Monitor set**
  toolbar button (`openMonitorSet()`) — the legacy screen had no entry point of its own,
  which is why it only ever existed as an include
- **Entry point**: deep-linkable standalone popup
  `/gui/templates/requirements/reqMonitors.html?req_id=N[&tproject_id=P]`

## Behavior / Features
- Renders the monitor set for a requirement: one row per user, each with a **YOU**
  marker when the logged-in caller is monitoring it; localized count badges
  (`X MONITORING`, `YOU ARE MONITORING THIS REQUIREMENT`)
- Context card: requirement doc id + title, test project, prefix, latest requirement
  **version**, the monitor count and the caller's own login; `VERSION n` / `OPEN|FROZEN`
  badges on the card head
- Toolbar: **Refresh** (re-fetches through the BFF, button disabled during the request),
  **Open requirement** (new tab on `reqView.html?id=…&tproject_id=…`), **Close**
- Monitor rows are sorted **alphabetically by login** — a deliberate improvement over the
  legacy DataTable, which had no `ORDER BY` and therefore came back in MySQL order
  (`req_monitor` is keyed on `(req_id, user_id, testproject_id)`, i.e. effectively by id)
- Explicit state cards, each keeping the **stable BFF machine code visible** for
  testability: missing id, empty monitor set, access denied (403), not found (404
  `requirement_not_found` / `project_mismatch`), bad request, server error; a 401
  bounces to `/login.php?note=expired` as on every other modernized screen
- i18n: every label through `TLi18n` — `reqmon.*` (21 keys) + `footers.reqMonitors`,
  plus the reused `logv.testProject` / `logv.version` / `common.*` keys, present in
  **all 10** locale bundles (flat keys, no nested objects)

## Authorization / ownership
- The **owning** test project is derived from `requirements.srs_id →
  req_specs.testproject_id` — authoritative, and cheaper than the `nodes_hierarchy`
  parent walk (the lesson #1660 documented for `req_specs`).
- `mgt_view_req` **OR** `mgt_modify_req` is enforced on that owning project **before**
  any data is returned.
- **No existence oracle** (the #1697 lesson): a caller who cannot read requirements
  anywhere gets `403 no_right` for a real id *and* for a bogus one, so the endpoint
  cannot be used to enumerate requirement ids.
- `tproject_id` is treated as a client-side **assertion**: a mismatch is
  `404 project_mismatch`, never a wrong-project read.
- `grant.monitor` (the `monitor_requirement` right that gated the legacy
  `{$gui->grants->monitor_req == "yes"}` include) travels in the payload so the screen
  can explain an empty list instead of showing a bare empty table.
- Session required (`bffEnforceSession`), same-origin proof (`bffSameOriginGuard`),
  `X-Content-Type-Options: nosniff` and `Cache-Control: no-store` on every answer.

## Bugs found and fixed while modernizing

| Issue | Symptom | Fix |
|---|---|---|
| **#1841** | The BFF proved the owning project, but called `getReqMonitors()` **without** passing it. The helper defaults to `tproject_id = 0`, which means *no project filter*, and `req_monitor` is keyed on `(req_id, user_id, testproject_id)` — so a row carrying a **foreign** `testproject_id` was listed to a reader of the requirement's own project. Fixture `REQ-MON-3`: `total 1 / "monitor_a"` before, `total 0` after. | hand the proven owning `tproject_id` to both reader calls |
| **#1842** | *Open requirement* linked `reqView.html?req_id=`, but the viewer reads `p.get('id') \|\| p.get('requirement_id')` (`reqView.html:337`) — so the viewer opened with **requirement id 0**. `req_id` is the popup/BFF parameter name, not the viewer's. | use the viewer's canonical `id=` |
| (hardening, #1677 lesson) | `doDBConnect()` ran **before** the session gate, so a database failure could answer an anonymous caller with a raw `dbms_msg` carrying host + database name | connect after the gate |
| (documented, left for its own run) | `gui/templates/search/searchMgmt.html:395` links the same viewer with `req_id=` — one screen at a time; recorded in #1842 | — |

## Code review (mandatory subagent pass)
Verdict: **no BLOCKER, no security hole** — the reviewer independently re-verified the
owning-project proof, the no-existence-oracle mitigation, integer-only SQL
interpolation, the `esc()`/`.text()` escaping of every server value and the `HEAD`
contract. Four findings applied: declare `$tprojMgr` in the helper's `global` list
instead of hopping through `$GLOBALS`; read the routing parameter and the request
parameters from `$_GET` **only** (a request body must not steer the routing before the
method check); correct the scope-fix comment's issue number; build the requirement
label from its non-empty parts (no stray space).

## Regression
`tmp/verify_1780.sh` — **73/73 PASS** (the 2026-10-01 run left no executable suite, so
this run built it):

* **F** fixture (`tmp/fixtures_1780.php`, re-runnable on the freshly imported DB)
* **A** auth matrix: anonymous 401, admin 200, role-3 403, and the **no-existence-oracle**
  proof (a no-rights user gets the identical 403 for a real and a bogus id)
* **B** monitor-set payload: 3 owned monitors in alphabetical order, `is_monitoring`,
  context (doc id, owning project, latest version, `has_version`, grants), the empty set,
  and **S2** the #1841 foreign-project-row regression plus its no-over-filtering twin
* **C** status/machine-code matrix: `400 invalid_requirement` (0 / missing / `abc` / `-5`),
  `404 requirement_not_found`, `404 project_mismatch`, `200` on a matching project,
  `400 unknown_action`, `405 wrong_method` (POST with a valid origin proof),
  `403` (POST without one), `HEAD` → 200, `nosniff` + JSON content type
* **D** retired shim: 302 onto the modern popup, `405 retired_endpoint` for a legacy
  XHR with **no** login list in the body, `405 wrong_method`, anonymous bounce to login
* **E** wiring / i18n: the screen, the canonical `id=` viewer link (the #1842
  regression), the locale switcher, **10 bundles × 28 keys** each parsing,
  `$actions->reqMonitors`, and the viewer's *Monitor set* button
* **F** Event Viewer: 0 ERROR rows, 0 WARNING rows from the BFF/screen (the one WARNING
  present is the shim's *intentional* audit trail of a refused write verb — the same
  convention as the #1770 / #1765 shims)
* **G** 11 browser cases (chrome-devtools): list with the YOU marker, *Open requirement*
  now loading the requirement in the viewer, the viewer's Monitor-set button, the empty
  set, the #1841-filtered requirement, the `project_mismatch` card, the no-id error card,
  the role-3 Access Denied card, the RO locale with zero raw keys, the legacy 302, and a
  console with 0 errors / 0 warnings

Suite recorded (append-only) in `tmp/TLU_Test_Cases.md` as
**"Modernize — Issue #1780"**; gate `ai/verify_test_suites.sh` with
`TLU_REQUIRE_SUITE="Issue #1780"` → **7 PASS / 0 FAIL**.

## Screenshots
![Requirement Monitors list](reqmonitors-list.png)
![Empty monitor set](reqmonitors-empty.png)
![Foreign monitor row filtered out after #1841](reqmonitors-mismatch.png)
![Not found state](reqmonitors-notfound.png)
![Forbidden state](reqmonitors-denied.png)
![Romanian locale](reqmonitors-ro.png)
![Monitor set button in reqView](reqview-monitorset-button.png)

— see also https://github.com/sebiboga/testlink-upgraded/issues/1780