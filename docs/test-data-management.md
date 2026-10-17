# Test Data Management — versioned a Test Data Sets registry (Refs #1927, parent #1069)

Net-new for 2.0.1: TestLink 1.9.20 treats test data as an afterthought — no data-set
entity exists anywhere in the schema (see the #1069 investigation), so there is no
legacy twin to port. This module delivers the smallest coherent slice of the parent
feature: a project-scoped registry of **versioned test data sets**, each with a
data-type, a PII classification and masking rule, a sample payload, free notes and an
optional link to a test case so its usage is traceable.

Documentation mirror of the wiki page; screenshots omitted from this file.

## Entry point

- Screen: `gui/templates/testdata/testDataSets.html` (Dashio standalone HTML/JS/CSS)
- BFF API: `api/testdata/index.php`
- ASIDE menu: section **Test Data** (icon `fas fa-database`), single project-scoped
  item; shown whenever the user has `view_tc` **or** `modify_tc`.
- URL of the action: `$actions->testDataSets` (`lib/functions/common.php:2310`).
- Server label: `$TLS_title_test_data_sets` in all 19 `locale/*/strings.txt`.
- No legacy controller/template to retire — the module is net-new.

## What the screen does

| Area | Behaviour |
|---|---|
| Metric tiles | Sets, versions, PII/sensitive sets, test cases linked — recomputed from the list on every load |
| Filters | Data type (`reusable` / `temporary` / `environment`) and classification (`public` / `internal` / `confidential` / `pii`); explicit no-match empty state |
| Registry table | DataTable with Name / Type / Class / Linked test case / Version / Updated / Actions |
| New / Edit | Modal form: name, data type, classification, masking rule, linked test case, sample payload, notes. Editing writes a **new version** and shows a version chip |
| Details | Read-only modal; the sample payload is placed with `.text()` (never HTML) |
| History | Modal listing all versions of the set, newest first, with payload per version |
| Delete | Bootstrap confirm (`{name}` interpolated); deletes the whole set group |
| Locale | `TLi18n` locale switcher; every label/placeholder/message is a key |
| State cards | `noProject` / `noRight` / `errTitle` machine-code states for 400/401/403/404 |

## Data model

One table, created lazily with `CREATE TABLE IF NOT EXISTS` so a freshly imported
database works unchanged; the DDL is also mirrored in
`install/sql/mysql/testlink_create_tables.sql` and the name is whitelisted in
`lib/functions/object.class.php`:

- `tc_test_data_sets` — one row per **version** of a set. `set_group` is the stable
  identity of the set (a UUID-like token generated on create) shared by all its
  versions; `version` increments on every save; `data_type`, `classification`,
  `masking_rule`, `payload`, `notes` and the optional `tcase_id` are stored per
  version. List/`init` return the latest version per `set_group`.

## BFF API — `api/testdata/index.php`

Session auth + same-origin CSRF guard (`bffSameOriginGuard`) + `bffEnforceSession`,
JSON in/out. Rights are checked on the **addressed** test project **before** it is
resolved (the #1697 lesson: no existence oracle):

| Route | Rights |
|---|---|
| `GET ?action=init&tproject_id=N` → `{context, domains, stats, items}` | read |
| `GET ?action=list&tproject_id=N` → `{items}` | read |
| `GET ?action=history&tproject_id=N&set_group=G` → `{name, versions[]}` | read |
| `POST ?action=save {set_group?, name, data_type, classification, masking_rule, payload, notes, tcase_id, tproject_id}` | write |
| `POST ?action=delete {set_group, tproject_id}` | write |

- read = `mgt_view_tc` **or** `mgt_modify_tc` on the project
- write = `mgt_modify_tc` on the project

Machine codes: `not_authenticated` (401), `no_right` (403), `unknown_action`
(400), `bad_data_type` (400), `invalid_tcase` (400 — a `tcase_id` not in the
project), `bad_set_group` (400) / `tproject_not_found` (404); wrong verb → 405;
foreign Origin → 403 (CSRF). A `tproject_id` of 0 falls back to the session
project (intended).

`save` with no `set_group` creates a new set (version 1, `TEST_DATA_CREATE`);
with an existing `set_group` it appends the next version (`TEST_DATA_VERSION`).
`delete` removes the whole group (`TEST_DATA_DELETE`). All three emit `AUDIT`
Event Viewer rows (log_level 16).

## i18n

67 `tds.*` keys (including the inline `tds.footer` — this module has no `footers.*`
object) in **all 10** client bundles (`gui/templates/i18n/*.json`), plus
`$TLS_title_test_data_sets` in all 19 server locales (`locale/*/strings.txt`).
`ai/verify_i18n_coverage.sh` passes (8296 keys).

## Verification

Suite `Modernize — Issue #1927` in `tmp/TLU_Test_Cases.md`. Verified in the browser
as admin: tiles, filters (incl. empty state), create (linked test case resolves to
`R6-1 R6 Login Pass`), details modal, edit → v2 + version chip, history modal
(newest first), delete confirm→delete, toasts; Romanian locale renders translated
labels. BFF error paths exercised over curl (401/403/404/400/405, CSRF). The
`tc_test_data_sets` table is empty after delete, and the Event Viewer shows only the
intended AUDIT rows (no new Error/Warning).
