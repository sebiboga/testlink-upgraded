# Requirement Management Systems — Modernized Screen

Modernization of the **Requirement Management Systems** screen (the legacy
`lib/reqmgrsystems/reqMgrSystemView.php` list + `reqMgrSystemEdit.php` create/
edit modal, plus the `lib/ajax/getreqmgrsystemcfgtemplate.php` config-template
helper) — GitHub issue
[#980](https://github.com/sebiboga/testlink-upgraded/issues/980).

The legacy admin-only list is replaced by a standalone Dashio page
(`gui/templates/reqmgrsystems/reqMgrSystemView.html`) backed by a plain-PHP REST
BFF (`api/reqmgrsystems/index.php`, session-based auth, JSON I/O). The ASIDE
"System — Req. Management Systems" menu entry (label
`Req. Management System Management`) now points at the new screen.

**Screen:** `gui/templates/reqmgrsystems/reqMgrSystemView.html`
**BFF API:** `api/reqmgrsystems/index.php` (`GET /`, `GET /meta/types`,
`GET /meta/cfg_template?type=<n>`, `GET /{id}`, `POST /`, `PUT /{id}`,
`POST /{id}/check_connection`, `DELETE /{id}`)
**Rights:** `reqmgrsystem_management` (manage) and `reqmgrsystem_view` (view).
Both rights sit on the same role(s) via `$r2cSame` — the BFF reports
`canManage` so the screen can hide the create/edit/delete buttons.

Screenshots: `docs/screenshots/ReqMgrSystemView-list.png`,
`ReqMgrSystemView-create-modal.png`, `ReqMgrSystemView-edit-modal.png`.

---

## Table of Contents
1. [What the screen does](#1-what-the-screen-does)
2. [REST API Reference](#2-rest-api-reference)
3. [Legacy parity notes](#3-legacy-parity-notes)
4. [i18n Keys](#4-i18n-keys)
5. [Security](#5-security)
6. [Testing](#6-testing)

## 1. What the screen does

- **List card ("Requirement Management Systems"):** a DataTable of every
  `reqmgrsystems` row showing Name, Type (`contour (Interface: soap)`),
  Environment (green check when `contoursoapInterface.checkEnv()` passes —
  green stat icon otherwise), and Actions.
- **Row actions:**
  - name link / wrench icon → live connection probe
    (`contoursoapInterface.isConnected()`); when the interface class is not
    shipped/implemented a clean "Interface contoursoapInterface not
    implemented" failure is shown (the legacy controller fatal-errors here).
  - edit icon → prefilled **Edit Requirement Management System** modal.
  - delete icon → confirm() then delete; when the system is linked to a test
    project the trash is disabled with "Cannot delete - still linked to test
    projects" (legacy `getLinks()` gating).
- **Create / Edit modal:** Name (required, leading/trailing whitespace
  trimmed), Type dropdown, Configuration textarea (stored as-is,
  "Configuration is type-specific and stored as-is"), and a
  **show configuration example** link that AJAX-fetches the interface's
  config template (`getCfgTemplate()`). In edit mode an optional
  **Used on test project(s)** box lists every linked test project.
- **Footer:** `1 requirement management systems | Generated on <client
  locale DateTime>` and `TestLink 2.0.1 - Requirement Management Systems`.
- **i18n:** locale switcher (list from `api/userinfo/locales`), labels from
  `rms.*` keys in all 10 bundles.

## 2. REST API Reference

All routes require a valid session cookie; mutating calls (`POST`/`PUT`/
`DELETE`) must also send `X-Requested-With: XMLHttpRequest` (same-origin
guard).

| Method & path | Rights | Description |
|---|---|---|
| `GET /` | view | List all systems, `link_count`, env probe (`env_check_ok`); returns `canManage` |
| `GET /meta/types` | view | Registered system types (`{code,type,api,enabled,label}`) |
| `GET /meta/cfg_template?type=<n>` | view | Config template example; `available:false` + message when the interface class file is missing |
| `GET /{id}` | view | Single system (incl. `cfg`) + `testprojects[]` of link targets; dead links purged first (legacy edit-path parity) |
| `POST /` | manage | Create; 400 empty name, 409 duplicate name |
| `PUT /{id}` | manage | Update; 400 empty name, 409 duplicate name (blocked when name belongs to another id) |
| `POST /{id}/check_connection` | view | `isConnected()` probe; graceful "interface not implemented" when the class is absent |
| `DELETE /{id}` | manage | Delete; 409 when linked to a test project (message lists project names/ids) |

## 3. Legacy parity notes

- The legacy view passes `checkEnv => true` to `getAll()`; `method_exists()`
  autoloads the missing interface and floods the Event Viewer with
  `E_WARNING include_once(...contoursoapInterface.class.php)` rows. The BFF
  runs the env probe itself behind `@class_exists()` so a missing implementation
  costs **zero** event rows while a present one still runs `checkEnv()`
  (Event Viewer stays clean — see Testing).
- `checkConnection()` instantiates `contoursoapInterface` (`new $class(...)`),
  which fatals on the legacy screen when the class file is not deployed; the
  BFF returns a JSON error instead.
- Dead-link cleanup on `GET /{id}` mirrors the legacy `initializeGui()`
  behavior (stale `testproject_reqmgrsystem` rows are unlinked before the link
  list is read).
- The modals accept the same fields as `reqMgrSystemEdit.php`; the "Check
  connection" checkbox from the legacy edit form is not carried over — the
  connection probe lives on the list row (wrench icon / name link), matching
  the legacy view behavior.

## 3a. Deviations from legacy

- **Event history icon not ported.** The legacy edit modal exposes a
  "Show event history" icon (right `mgt_view_events`) linking to the Event
  Viewer. The modernized edit modal does not render it — event history for
  requirement management systems remains fully available from the modernized
  **Event Viewer** screen (`eventviewer/eventviewer.html`), which is the ASIDE
  item right above this entry.
- **`cfg` withheld from view-only users.** The legacy edit page (and thus the
  stored XML configuration, which can contain WSDL/credentials) was gated by
  `reqmgrsystem_management`. The BFF returns `cfg` only on manage-gated routes
  and in the manage-scoped parts of the single-item response — a user holding
  only `reqmgrsystem_view` cannot read stored configurations.

## 4. i18n Keys

New keys in ALL 10 locale bundles (`gui/templates/i18n/{en,de,es,fr,it,ja,pt,ro,ru,zh}.json`):

`rms.header`, `rms.headerSub`, `rms.createSystem`, `rms.editSystem`,
`rms.name`, `rms.nameHelp`, `rms.type`, `rms.cfg`, `rms.cfgHelp`,
`rms.cfgExample`, `rms.showCfgExample`, `rms.env`, `rms.usedOnTestProject`,
`rms.systemCount`, `rms.connOk`, `rms.connKo`, `rms.connNotImplemented`,
`rms.validation.required`, `rms.msg.confirmDelete`,
`rms.msg.errorSave`, `rms.msg.errorDelete`, `rms.msg.cannotDeleteLinked`,
`footers.reqMgrSystemView`. Reused existing `common.*` keys for buttons and
table chrome.

## 5. Security

- Same-origin guard (`_guard.php` `bffSameOriginGuard()`) on every route;
  mutating verbs additionally required to carry `X-Requested-With`.
- View rights = `reqmgrsystem_view` **or** `reqmgrsystem_management`;
  manage rights = `reqmgrsystem_management` (verified against legacy
  `$rights['reqmgrsystem_management']`/`'reqmgrsystem_view'` — ids 33/34,
  admin-only role 8).
- All ids cast with `intval()`, names quoted via `db->prepare_string()` in the
  legacy model; SQL is the legacy model's own parameterless statements.

## 6. Testing

Exercised via browser (create/edit/delete/locale/validation/duplicate/delete
gating/connection probe) and direct BFF curl probes (401 unauth, 400, 403,
404, 409, all verbs). Regression suite appended to `tmp/TLU_Test_Cases.md`
(issue #980). Event Viewer check after the whole session confirmed **zero** new
Error/Warning rows from the modernized screen (the autoload `include_once`
warnings seen during development were eliminated by the `@class_exists()`
guard and do not recur).