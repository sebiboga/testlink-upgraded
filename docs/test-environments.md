# Test Environments — a project-scoped Test Environment registry (Refs #1928, parent #1070)

Net-new for 2.0.1: TestLink 1.9.20 manages test plans, builds and platforms, but it
has **no first-class test-environment entity** anywhere in the schema. There is
therefore no legacy twin to port and no orphan controller to retire. This module
delivers the smallest coherent slice of the parent "Test Environment Management"
feature: a project-scoped registry of environment definitions — name, type,
availability status, base URL, target platform, application version/build, owner and
contact, service dependencies and free notes — so a test team can record *which*
environments exist and their state.

Documentation mirror of the wiki page; screenshots omitted from this file.

## Entry point

- Screen: `gui/templates/environments/testEnvironments.html` (Dashio standalone HTML/JS/CSS)
- BFF API: `api/environments/index.php`
- ASIDE menu: section **Test Environments** (icon `fas fa-server`), single
  project-scoped item; shown whenever the user can read environments
  (`mgt_view_tc` **or** `mgt_modify_tc`).
- URL of the action: `$actions->testEnvironments` (`lib/functions/common.php`).
- Server label: `$TLS_title_test_environments` in all 19 `locale/*/strings.txt`.
- No legacy controller/template to retire — the module is net-new.

## What the screen does

| Area | Behaviour |
|---|---|
| Metric tiles | Total, available, unavailable, unstable and maintenance — recomputed from the list on every load |
| Filters | Type (`dev` / `qa` / `staging` / `preprod` / `prod_like`) and status (`available` / `unavailable` / `unstable` / `maintenance`); explicit no-match empty state |
| Registry table | DataTable with Name / Type / Status / Base URL / Platform / Version / Owner / Updated / Actions |
| New / Edit | Modal form: name, type, status, base URL, platform (browser/device/OS), app version/build, owner, contact, dependencies, notes. New defaults to `qa` / `available`; a duplicate name is rejected with `duplicate_name` (409) |
| Details | Read-only modal showing every field |
| Delete | Bootstrap confirm (`{name}` interpolated); removes the environment |
| Locale | `TLi18n` locale switcher; every label/placeholder/message is a key |
| State cards | `tenv.noProject` / `tenv.noRight` / `tenv.errTitle` machine-code states for 400/401/403/404 |

## Data model

One table, created lazily with `CREATE TABLE IF NOT EXISTS` so a freshly imported
database works unchanged; the DDL is also mirrored in
`install/sql/mysql/testlink_create_tables.sql` and the name is whitelisted in
`lib/functions/object.class.php`:

- `tc_test_environments` — one row per environment. `testproject_id` scopes the row
  to a test project; `name`, `env_type`, `base_url`, `platform`, `app_version`,
  `owner`, `contact`, `dependencies`, `notes` and `status` are the definition;
  `created_by`, `creation_ts` and `update_ts` provide a light audit trail. Indexes:
  `idx_tenv_project (testproject_id)` and `idx_tenv_status (testproject_id, status)`.

## BFF API — `api/environments/index.php`

Session auth + same-origin CSRF guard (`bffSameOriginGuard`), JSON in/out. Rights are
checked on the **addressed** test project **before** it is resolved (the #1697
lesson: no existence oracle — an unknown project and a forbidden project both fail
closed):

| Route | Rights |
|---|---|
| `GET ?action=init&tproject_id=N` → `{context, domains, stats, items}` | read |
| `GET ?action=list&tproject_id=N` → `{items}` | read |
| `POST ?action=save {tproject_id, id?, name, env_type, base_url, platform, app_version, owner, contact, dependencies, notes, status}` | write |
| `POST ?action=delete {tproject_id, id}` | write |

- read = `mgt_view_tc` **or** `mgt_modify_tc` on the project
- write = `mgt_modify_tc` on the project

Machine codes: `not_authenticated` (401), `no_right` (403), `no_testproject` (400),
`wrong_method` (405), `unknown_action` (400), `name_required` (400), `bad_env_type`
(400), `bad_status` (400), `duplicate_name` (409), `env_not_found` (404), `bad_id`
(400), `insert_failed` (500), `tproject_not_found` (404). A `tproject_id` of 0 falls
back to the session project (intended).

`save` with no `id` creates a new environment (`TEST_ENV_CREATE`); with an existing
`id` it updates it (`TEST_ENV_SAVE`). `delete` removes it (`TEST_ENV_DELETE`). All
three emit `AUDIT` Event Viewer rows (log_level 16).

## i18n

61 `tenv.*` keys plus `footers.testEnvironments` in **all 10** client bundles
(`gui/templates/i18n/*.json`), plus `$TLS_title_test_environments` in all 19 server
locales (`locale/*/strings.txt`). `ai/verify_i18n_coverage.sh` passes (8358 keys,
9/9 bundles).

## Verification

Suite `Modernize — Issue #1928` in `tmp/TLU_Test_Cases.md` (15/15). Verified in the
browser as admin: tiles, type + status filters and the empty state, create, the
New-modal defaults (qa / available) plus empty-name and duplicate-name (409)
validation, details modal, edit (status maintenance→available, tiles and `update_ts`
updated), delete confirm→delete. The no-project (404) state and the 403 no-right
state were exercised with a dedicated right-less user (title *Zugriff verweigert*,
main area hidden, no New button), and the German locale renders translated labels
(*Testumgebungen*, *Neue Umgebung*, …). BFF error paths exercised over curl
(401/403/404/400/405/409). The console is clean and the Event Viewer shows only the
intended AUDIT rows (no new Error/Warning).
