# Exit Criteria Definitions (modernized) — Issue #1936 / feature #1088

Screen: `gui/templates/strategy/exitCriteria.html`
BFF: `api/exitcriteria/index.php`
ASIDE: Test Strategy → *Exit criteria* (reuses `$TLS_href_test_strategy_exit_criteria`, present in all 19 server locales).

## Purpose

Per-test-level **entry / exit criteria registry** for a test project — slice 1 of feature
issue #1088 (*Implement definition of EXIT CRITERIA*). Each criterion belongs to one test
project, is scoped to a test level (unit / integration / system / acceptance / regression /
release), carries free-text entry + exit conditions, and may define one **measurable metric**
with a target value (progress %, pass rate %, failed count, blocked count, requirement
coverage %). The Release-Candidate evaluation report and dashboard threshold indicators of
#1088 are documented follow-ups, not in this slice.

## Screen features

- Teal Dashio header with subtitle + client-side `TLi18n` locale switcher (10 locales).
- Four metric tiles: total criteria / with entry text / with exit text / measurable.
- Level filter (`All levels` + the six levels) and a free-text search box (name / entry /
  exit / metric), both driving the DataTable client-side; explicit *no match* state card
  when the filters exclude everything, *empty* card when the project has no criteria.
- DataTable columns: Name (clickable → details modal), Level, Entry criteria, Exit criteria,
  Metric, Target, Updated, Actions (View / Edit / Delete buttons).
- Create / Edit modal: name, level select, entry textarea (with hint), exit textarea (with
  hint), metric select; choosing a metric reveals the target input (0–100 spinbutton for
  percentage metrics, plain integer for count metrics). Client validation: entry-or-exit
  mandatory, target required (and range-checked) when a metric is selected.
- Details modal: read-only view of all fields including the Updated timestamp.
- Delete confirm modal interpolates the criterion name into the message.
- Success toasts on save/delete; error toasts for server failures and the 409 duplicate name.
- State cards for: no project in context, no rights (role 3), load error.

## BFF contract (`api/exitcriteria/index.php`)

| Method + action | Right | Behaviour |
|---|---|---|
| `GET\|HEAD ?action=init` | `mgt_view_tc` | project context + criteria list + tiles |
| `GET\|HEAD ?action=list` | `mgt_view_tc` | criteria list only |
| `POST ?action=save` | `mgt_modify_tc` | create (no id) / update (id); 409 on duplicate name per (project, level) |
| `POST ?action=delete` | `mgt_modify_tc` | delete by id |

- Session auth + `bffSameOriginGuard` + `bffEnforceSession`.
- Rights are checked on the **ADDRESSED project BEFORE it is resolved** (no project-id
  existence oracle, the #1697 rule): an unknown project and a foreign project both answer
  the same opaque `403 no_right` / `404`.
- Verb whitelist is enforced **before** parameter probing → `405 method_not_allowed` with
  an `Allow` header.
- Codes: `400 invalid_request`, `401 not_authenticated|session_expired`, `403 no_right`,
  `404 not_found`, `405 method_not_allowed`, `409 duplicate_name`.
- AUDIT events: `EXIT_CRITERIA_CREATE` / `EXIT_CRITERIA_SAVE` / `EXIT_CRITERIA_DELETE`
  (verified in the Event Viewer, 0 ERROR/WARNING rows from the full browser pass).

## Data

New table `tc_exit_criteria` (id, testproject_id, name, test_level, entry_criteria,
exit_criteria, metric, target_value, created_by, creation_ts, update_ts):
created lazily by the BFF on first use, **mirrored in the installer SQL**
(`install/sql/mysql/testlink_create_tables.sql`) and whitelisted in
`lib/functions/object.class.php` (`getDBTables`).

## i18n

`ec.*` (70 keys) + `footers.exitCriteria` in **all 10** client bundles
(`ai/verify_i18n_coverage.sh` PASS). Server locales reused the existing
`$TLS_href_test_strategy_exit_criteria` label — no new server keys were needed.

## Verification

- BFF curl matrix: init/list OK, save → id, duplicate name → 409, target 120 → 400,
  `PUT` → 405 + `Allow`, unknown id → 404, no Origin → 403, anonymous → 401,
  role-3 (`ecnorights`) read+write → 403.
- Browser (admin): full CRUD, client validations, 409 duplicate toast, details modal,
  level-filter no-match card, Romanian locale (no raw keys), role-3 *Access denied* card,
  console clean (only non-blocking a11y label warnings).
- Test suite: `Modernize — Issue #1936` = 15/15 PASS (`tmp/TLU_Test_Cases.md`),
  gate `TLU_REQUIRE_SUITE="Issue #1936"` 34 PASS / 0 FAIL.
- Event Viewer: 11 rows, all AUDIT (`log_level=16`), 0 ERROR / 0 WARNING.

## Follow-ups (feature #1088, not this slice)

- Release-Candidate evaluation report applying the criteria to a candidate build.
- Dashboard threshold indicators (per-level pass/fail against the targets).
