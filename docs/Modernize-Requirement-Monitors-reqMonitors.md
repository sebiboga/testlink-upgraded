# Requirement Monitors (reqMonitors) — Modernized

## Overview
The Requirement Monitors popup (`reqMonitors`) shows the list of users monitoring a specific requirement. This was originally a legacy Smarty include (`gui/templates/dashio/requirements/reqMonitors.tpl`) pulled in from the Requirement Viewer, backed by `lib/ajax/requirements/getreqmonitors.php` (a thin AJAX endpoint with only session-level protection). The legacy AJAX endpoint has been retired and replaced by a modern Dashio popup backed by a dedicated REST BFF. The modern popup is deep-linkable and integrates with the Requirement Viewer via a new "Monitor set" toolbar button.

Refs: #1780, bug #1781

## Architecture (2.0.1)
- Modern UI: `gui/templates/requirements/reqMonitors.html` (Dashio look & feel, Bootstrap, DataTables-style list, TLi18n with locale switcher)
- BFF API: `api/reqmonitors/index.php?action=init&req_id=N[&tproject_id=P]`
- Legacy AJAX retired: `lib/ajax/requirements/getreqmonitors.php` reduced to a non-mutating session-guarded shim (302 redirect to modern popup on browser navigation; 405 for XHR/AJAX/JSON callers; anonymous treated per legacy session contract)
- Callers: `gui/templates/requirements/reqView.html` adds a *Monitor set* toolbar button opening `requirements/reqMonitors.html?req_id=<id>`
- Entry point: deep-linkable standalone popup (`/gui/templates/requirements/reqMonitors.html?req_id=N[&tproject_id=P]`)

## Behavior / Features
- Renders the monitor set for a requirement: each row shows login and a "YOU" marker when the logged-in user is monitoring it. Shows localized count badge ("X MONITORING", and "YOU ARE MONITORING THIS REQUIREMENT" when applicable).
- Context card shows: test project, prefix, requirement doc id (REQ-...), version/status chips (OPEN/CLOSED/FROZEN as applicable), total monitors.
- Toolbar actions: Refresh (re-fetches via BFF, disables during request to avoid stale responses), *Open requirement* (opens `reqView.html` in new tab), Close.
- Explicit states with stable machine codes: loading, empty ("No monitors yet"), bad request, not authenticated (401), forbidden (403), not found (404 project_mismatch / requirement_not_found), wrong method (405), server error (500). Each state card preserves and displays the machine code for testability.
- i18n: all labels via `TLi18n` (`reqmon.*`) and footer `footers.reqMonitors`; locale switcher present. Keys are flat in all 10 locale bundles (no nested objects).
- Ownership/authorization: owning test project is derived from `requirements.srs_id → req_specs.testproject_id` (authoritative). Caller must have `mgt_view_req` (or equivalent rights) on the owning project. `tproject_id` is treated as a client assertion (mismatches return `404 project_mismatch`). Existence and rights checks are ordered to avoid leaking existence via 403/404 in the correct way (bug #1781 fixed).

## Security
- Session required (`bffEnforceSession`), same-origin guard (`bffSameOriginGuard`). No public anonymous access to monitor listings.
- Rights enforced before resolving requirement details; when a requirement does not exist, callers without any readable rights return `403 no_right` (id-oracle resistant), while entitled callers get truthful `404 requirement_not_found`. Correct assertion of `tproject_id` returns `404 project_mismatch`.
- Only monitor logins and context are returned (no PII beyond login names). No HTML injected; pure JSON from BFF.

## Screenshots
![Requirement Monitors list](reqmonitors-list.png)
![Not found state](reqmonitors-notfound.png)
![Forbidden state](reqmonitors-denied.png)
![Romanian locale](reqmonitors-ro.png)
![Monitor set button in reqView](reqview-monitorset-button.png)

## Regression
Regression Suite 1780 (48 checks) covers: authentication, happy paths (context, counts, "you" marker, version/is_open, empty set), parameter/verb contract (invalid ids, project mismatch, unknown action, POST→405, HEAD→200), authorization (no-rights cases including foreign/bogus, oracle resistance), legacy shim behavior (302 redirect, XHR→405 retired_endpoint, JSON Accept path hygiene, anonymous legacy flow), screen/wiring/i18n validation, and browser passes for admin and no-rights users. All 48/48 PASS.
