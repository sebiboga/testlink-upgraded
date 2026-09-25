# Modernize: Requirements Bulk Assignment (`reqTcBulkAssign`)

**Refs #1595** — the requirements *coverage grid* of the Test Specification, now a Dashio popup with a PHP BFF.

## Context

The ledger's TODO section was empty, so the pick rule was "the smallest coherent
standalone legacy screen that has no modern twin". That is
`lib/requirements/reqTcAssign.php` in **testsuite / bulk mode**: the controller
rendered `gui/templates/dashio/requirements/reqTcBulkAssignment.tpl`, a
requirements grid reachable from the **Test Specification** tree through the
legacy `listTestCases.php?feature=assignReqs` action.

It is **not** a duplicate of the "Test Suite (Bulk)" tab of
`gui/templates/requirements/assignReqs.html` (Refs #1083). That tab is a
*picker*: two multi-selects that hand a set of requirements to one test case
version. This screen is a **per-suite coverage grid**:

* every requirement of the selected requirement spec is listed with its
  `doc_id`, title, scope and a truthful `n/m test cases of this suite already
  linked` chip,
* the whole set (or any subset) can be **assigned to every test case of the
  suite subtree** in one click,
* and — new, legacy only offered assign — the selected requirements can be
  **unassigned** from the suite's test cases again.

Legacy write path: `requirement_mgr::bulkAssignLatestREQVTCV()` (latest
requirement version × latest test case version, idempotent, and by design
**without** an audit trail). All of that behaviour is preserved 1:1.

## What changed

### BFF `api/reqtcbassign/index.php`

| Route | Behaviour |
|---|---|
| `GET ?action=init&tproject_id=&tsuite_id=[&idSRS=]` | context card, `get_testcases_deep()` count, per-requirement `linked_count`, requirement-spec options, `selected_req_spec` (persisted in the session like the legacy `idSRS`) |
| `POST ?action=bulkassign` | `bulkAssignLatestREQVTCV()` + one aggregated `audit_req_assigned_tc` event |
| `POST ?action=unassign` | deletes exactly the open coverage rows the grid created (latest req version × latest TC version) + `audit_req_assignment_removed_tc` |

* session auth (`doSessionStart`) + `bffSameOriginGuard` for the unsafe verbs,
* right `req_tcase_link_management` → 403 with the legacy wording,
* JSON contract: 401 anonymous, 403 rights / CSRF, 404 unknown suite,
  400 no context / no test cases / nothing selected / unknown action,
  405 wrong verb.

**Hardening beyond legacy**

* the test suite is proven to belong to the test project before anything is read
  or written (the legacy controller trusted `idSRS` + the session project),
* the submitted `req_id`s are intersected with the requirements that really live
  on the submitted `idSRS`, so a forged id from another specification or project
  is rejected (`rejected: n`) instead of being linked — this closes the
  cross-spec hole tracked by **#1085** for this route.

### Screen `gui/templates/requirements/reqTcBulkAssign.html`

Dashio popup: teal header + dark toolbar (**Refresh** / **Back to test
specification** / **Close**), the legacy warning bar
(`bulk_req_assign_msg` with the test-case count and suite name, or
`bulk_req_assign_no_test_cases`), a context card (test project, test suite,
test cases *deep*, requirement-specification selector), the grid
(check/uncheck-all, Doc ID, Requirement + coverage chip, Scope), the
**Assign to all test cases of the suite** / **Unassign from the suite test
cases** actions with a Bootstrap confirm dialog, a feedback box
(`bulk_assigment_done` / `bulk_unassing_done`), the empty states and the
localized 401 / 403 / 404 / missing-context cards, and the `TLi18n` locale
switcher.

### Wiring

* `$actions->reqTcBulkAssign` in `lib/functions/common.php`.
* The **Test Specification** suite view gained a **Requirements Bulk
  Assignment** button (`openReqBulkAssign()`) — the modern equivalent of the
  legacy tree action — gated on `requirementsEnabled` +
  `req_tcase_link_management`.
* `lib/requirements/reqTcAssign.php` is now a **session-guarded 302 shim**
  (anonymous → login, `?id=` forwarded as `tsuite_id`).

## Bugs found and fixed while testing

1. **#1596** — `gui/templates/testcases/testSpec.html` gated *both*
   requirement-linking buttons on `ctx.reqEnabled`, a property `init()` never
   sets, so the pre-existing per-test-case **Assign Requirements** button was
   never rendered either. Both now use `ctx.options.requirementsEnabled`.
2. The repo vendors **Bootstrap 3.4.1**, so the confirm dialog loaded a
   non-existent `bootstrap.bundle.min.js` and threw
   `Cannot read properties of undefined (reading 'Modal')`. The screen uses the
   jQuery `modal('show'/'hide')` API, and BS3's data-api needs an explicit
   `data-target` to dismiss a modal (header **×** and **Cancel**).
3. The audit events passed the requirements table as `$objectID`, so the Event
   Viewer showed no object; they are now bound to the test suite
   (`object_id = <suite>`, `object_type = testsuites`) and are no longer written
   for a no-op.

## Verification

* Regression suite **1595: 46/46 PASS** in `tmp/TLU_Test_Cases.md`
  (16 BFF contract + 22 browser states + 3 entry point + 5 Event Viewer).
* Fixtures: `tmp/fixtures_1595.php` (project BULK1595, 2 specs, suite with 3
  deep test cases, empty suite, 1 seeded link) and `tmp/norights_1595.php`
  (user without `req_tcase_link_management`).
* Event Viewer: 0 ERROR/WARNING rows; the two new aggregated audit events
  render with their localized text.

| Screen state | Screenshot |
|---|---|
| Grid with coverage chips and the legacy warning | [[issue-1595-bulkassign-grid.png]] |
| Confirm dialog | [[issue-1595-bulkassign-confirm.png]] |
| Romanian locale (`?locale=ro`) | [[issue-1595-bulkassign-ro.png]] |
| 403 — no `req_tcase_link_management` | [[issue-1595-bulkassign-norights.png]] |
| Entry button in the Test Specification suite view | [[issue-1595-entry-testspec.png]] |
