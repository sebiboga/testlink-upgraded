# Exploratory Sessions — a project-scoped registry of exploratory charters (Refs #1934, parent #1071)

Net-new for 2.0.1: TestLink 1.9.20 has no first-class exploratory-testing
artefact — charters, time boxes and session outcomes exist only as prose in
notes or external tools. This module delivers the **sessions registry** slice
of parent issue #1071 ("Exploratory Testing" — charters, sessions, and a
session-based coverage report): a project-scoped list where a team records
each exploratory session — name, charter, status, tester, timebox, date,
environment, purpose, live notes and defect references — with metric tiles,
filters and a full CRUD lifecycle. The remaining slices (charter templates,
session-based coverage report) stay open under #1071.

Documentation mirror of the wiki page; screenshots omitted from this file.

## Entry point

- Screen: `gui/templates/exploratory/exploratorySessions.html` (Dashio standalone HTML/JS/CSS)
- BFF API: `api/exploratory/index.php`
- ASIDE menu: section **Exploratory Sessions** (icon `fas fa-compass`),
  single project-scoped item; shown whenever the user can read test cases
  (`mgt_view_tc` **or** `mgt_modify_tc`).
- URL of the action: `$actions->exploratorySessions` (`lib/functions/common.php`).
- Server label: `$TLS_title_exploratory_sessions` in all 19 `locale/*/strings.txt`.
- No legacy controller/template to retire — the module is net-new.

## What the screen does

| Area | Behaviour |
|---|---|
| Metric tiles | Total, planned, in progress, completed and with-defects — recomputed from the list on every load |
| Filters | Status (`planned` / `in_progress` / `completed` / `aborted`) and free-text search (name, charter, tester, environment); explicit no-match empty state |
| Registry table | DataTable with Name / Charter / Status / Tester / Timebox / Date / Environment / Updated / Actions |
| New / Edit | Modal form: name, charter, status, timebox (minutes, min 0), tester, session date, environment, purpose, live notes, defect references (comma-separated). A duplicate name is rejected with `duplicate_name` (409); empty name, bad date and negative timebox are rejected client- and server-side |
| Details | Read-only modal showing every field (defects render `-` when empty) |
| Delete | Bootstrap confirm (`{name}` interpolated); removes the session |
| Locale | `TLi18n` locale switcher; every label/placeholder/message is a key |
| State cards | `expl.noProject` / `expl.noRight` / `expl.errTitle` machine-code states for 400/401/403/404 |

Status lifecycle: `planned` → `in_progress` → `completed` (or `aborted` at any
point). The tiles double as a lightweight burndown: a healthy project shows
sessions flowing to *completed* and the *with-defects* tile feeds the defect
references recorded per session.

## Data model

One table, created lazily with `CREATE TABLE IF NOT EXISTS` so a freshly
imported database works unchanged; the DDL is also mirrored in
`install/sql/mysql/testlink_create_tables.sql` and the name is whitelisted in
`lib/functions/object.class.php`:

- `tc_exploratory_sessions` — one row per session. `testproject_id` scopes the
  row to a test project; `name`, `charter`, `status`, `tester`, `timebox`
  (int minutes), `session_date`, `environment`, `purpose`, `notes` and
  `defect_refs` are the definition; `created_by`, `creation_ts` and
  `update_ts` provide a light audit trail. Indexes:
  `idx_expl_project (testproject_id, status)`.

## BFF API — `api/exploratory/index.php`

Session auth + same-origin CSRF guard (`bffSameOriginGuard`), JSON in/out. Rights
are checked on the **addressed** test project **before** it is resolved (the
#1697 lesson: no existence oracle — an unknown project and a forbidden project
both fail closed):

| Route | Rights |
|---|---|
| `GET ?action=init&tproject_id=N` → `{context, stats, items}` | read |
| `GET ?action=list&tproject_id=N` → `{items}` | read |
| `POST ?action=save {tproject_id, id?, name, charter, status, timebox, tester, session_date, environment, purpose, notes, defect_refs}` | write |
| `POST ?action=delete {tproject_id, id}` | write |

- read = `mgt_view_tc` **or** `mgt_modify_tc` on the project
- write = `mgt_modify_tc` on the project

Machine codes: `not_authenticated` (401), `no_right` (403), `no_testproject`
(400), `wrong_method` (405), `unknown_action` (400), `name_required` (400),
`bad_status` (400), `bad_timebox` (400), `bad_session_date` (400),
`duplicate_name` (409), `session_not_found` (404), `bad_id` (400),
`tproject_not_found` (404). A `tproject_id` of 0 falls back to the session
project (intended).

`save` with no `id` creates a new session (`EXPLORATORY_CREATE`); with an
existing `id` it updates it (`EXPLORATORY_SAVE`). `delete` removes it
(`EXPLORATORY_DELETE`). All three emit `AUDIT` Event Viewer rows (log_level 16).

## i18n

65 `expl.*` keys in **all 10** client bundles (`gui/templates/i18n/*.json`),
plus `$TLS_title_exploratory_sessions` in all 19 server locales
(`locale/*/strings.txt`). `ai/verify_i18n_coverage.sh` passes (8496 keys,
9/9 bundles). The distinct `expl.*` namespace avoids colliding with any
pre-existing key.

## Verification

Suite `Modernize — Issue #1934` in `tmp/TLU_Test_Cases.md` (15/15). Verified in
the browser as admin: screen load (tiles, filters, footer), New-modal
empty-name and duplicate-name (409) validation, create (row + tiles update +
`EXPLORATORY_CREATE` audit), details modal, edit (status in_progress→completed,
`update_ts` bumped, `EXPLORATORY_SAVE` audit), status/search filters plus the
no-match empty state, delete confirm→delete (`EXPLORATORY_DELETE` audit) and
the restored empty state. The 403 no-right state was exercised with a dedicated
right-less user (aside item hidden; direct URL shows the "No permission" state
card). BFF error paths exercised over curl (401/403/404/400/405/409). The
console is clean and the Event Viewer shows only the intended AUDIT rows (no
new Error/Warning).
