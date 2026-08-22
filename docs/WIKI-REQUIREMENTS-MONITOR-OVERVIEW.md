# Requirements Monitoring Overview — Modernized Screen

**Path:** ASIDE menu > Requirements Design > *Requirement Monitoring Overview*
**URL:** `gui/templates/requirements/reqMonitorOverview.html?tproject_id=<id>&tplan_id=<id>`
**BFF API:** `api/requirements/index.php` (session-based auth, JSON I/O; shared
router that also serves the Print Requirement Specification navigator)
**Prerequisite:** `mgt_view_req` grant on the current test project (legacy
`checkRights()` enforced `rightsAnd = ['mgt_view_req']`)
**Replaces:** legacy `lib/requirements/reqMonitorOverview.php` +
`gui/templates/dashio/requirements/reqMonitorOverview.tpl` (ExtJS grid)


---

## What it does

Lists **every requirement of the current test project** (latest version /
revision, same source as TestLink 1.9.20's
`getByIDBulkLatestVersionRevision()`) and lets the logged-in user
**subscribe / unsubscribe** to change-notification emails for each requirement.
This is the modernized "Monitor Requirements Overview" screen originally
contributed by Leon Jordans in TL 1.9.20.

## Table

| Column | Content |
|--------|---------|
| Req. Spec | full requirement-specification path (`System Requirements / …`) |
| Title | `REQ_DOC_ID` + edit icon + title; both link to the legacy requirement view popup |
| Created On | localized creation timestamp of the latest version + author display name |
| Monitor | Yes / No badge showing whether the current user monitors this requirement |
| Action | bell toggle: 🔔 = subscribe, 🔕 = unsubscribe (titles localized) |

* sortable columns (default: Req. Spec ascending), page length selector,
  global search box;
* footer line: `{n} requirement(s), {m} monitored | Generated on <date>`;
* empty project → "This test project has no requirements." empty state.


## Subscribe / unsubscribe

Clicking the bell calls `POST /api/requirements/index.php/monitor`
(`{reqId, action:'on'|'off', tprojectId}`), which maps to
`requirement_mgr::monitorOn()/monitorOff()` on the `req_monitor`
table — identical data flow to the legacy inline POST forms. The row flips
instantly (no reload) and the footer counter is refreshed.

The title / pen links open
`lib/requirements/reqView.php?showReqSpecTitle=1&requirement_id=…&req_version_id=…&tproject_id=…`
— the same popup the legacy `openLinkedReqVersionWindow()` JavaScript helper
produced.

## BFF API

| Endpoint | Method | Right | Purpose |
|----------|--------|-------|---------|
| `/api/requirements/index.php/monitor-overview?tprojectId=N` | GET | `mgt_view_req` | all project requirements + `monitored` flag of session user |
| `/api/requirements/index.php/monitor` | POST | `mgt_view_req` | `{reqId, action:'on'\|'off'}` → subscribe/unsubscribe |

Both endpoints fall back to the session test project when `tprojectId` is
omitted and validate that the target requirement really belongs to the given
project (`requirements ⋈ req_specs` join).

## Permissions

| Role context | Behavior |
|--------------|----------|
| user with `mgt_view_req` on the project | screen renders, toggles work |
| user WITHOUT `mgt_view_req` (e.g. tester role) | error modal "No permission"; API returns HTTP 403 |

## i18n

All labels come from the client-side `TLi18n` module; keys added to ALL ten
locale bundles (`en de es fr it ja pt ro ru zh`): `header.reqMonitorOverview`,
`header.reqMonitorOverviewSub`, `rmo.refresh`, `rmo.colSpecPath`,
`rmo.colTitle`, `rmo.colCreatedOn`, `rmo.colMonitor`, `rmo.colAction`,
`rmo.noRequirements`, `rmo.editRequirement`, `rmo.turnOn`, `rmo.turnOff`,
`rmo.itemsCount`, `rmo.msg.errorLoad`, `rmo.msg.errorToggle`.

## Testing

Full suite recorded in `tmp/TLU_Test_Cases.md`, Suite 41 (Suite ID 48):
12/12 PASS — list/toggle/persistence/deep-link/filter/i18n/permissions/aside
integration/Event Viewer clean.
