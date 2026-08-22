# Assign Requirements to Test Cases — Modernized Screen

**Path:** ASIDE menu > Requirement Specification > *Assign Requirements to the Test Cases*
**URL:** `gui/templates/requirements/assignReqs.html?tproject_id=<id>&tplan_id=<id>`
**BFF API:** `api/requirements/index.php` (shared requirements router) — routes:
`/context`, `/assign-reqspecs`, `/assign-testcases`, `/assign-testsuites`,
`/assign-tcase-info`, `/assign-reqs` (GET read model / POST assign),
`/unassign-reqs` (POST), `/assign-suite-tcases`, `/assign-bulk` (POST).
Session-based auth, JSON I/O.
**Replaces:** legacy `lib/requirements/reqTcAssign.php`
(`reqTcAssign.tpl` + `reqTcBulkAssignment.tpl`) behind the
`listTestCases.php?feature=assignReqs` tree launcher.

---

## What it does

Links requirement versions to test case versions (coverage at design time).
The screen implements **both legacy modes** in one page:

- **Test Case mode** (legacy `edit=testcase`)
  - Left picker: flat searchable list of all project test cases with suite
    path and full external id (`RP-1`) — replaces the ExtJS tree launcher.
  - Right panel: selected TC title + latest version badge; red *executed*
    badge + warning box when the current version already has executions
    (legacy `tcaseHasBeenExecuted` parity).
  - Requirement Specification combo (`genComboReqSpec()` dotted naming,
    `&nbsp;` indent stripped).
  - Two multi-selects: **Free Requirements** vs **Assigned Requirements**
    (same data contract as legacy `processTestCase()`: all reqs of the spec,
    assigned = `getReqsOnSpecForLatestTCV()` filtered to link statuses
    OPEN/CLOSED_BY_EXEC, free = `array_diff_byId()`).
  - `>> Assign` links each selected requirement's latest version to the test
    case's latest version (`requirement_mgr::assign_to_tcase()`); failures
    are reported per requirement id.
  - `<< Unassign` removes coverage links by link id
    (`delReqVersionTCVersionLinkByID()`).
- **Test Suite (Bulk) mode** (legacy `edit=testsuite` / bulk template)
  - Suite picker (deep list), affected-test-case preview with counts
    (`testsuite::get_testcases_deep('only_id')`), warning message identical
    in meaning to `bulk_req_assign_msg`.
  - Requirements multi-select for the chosen spec.
  - *Bulk Assign* asks for confirmation, then calls
    `requirement_mgr::bulkAssignLatestREQVTCV()`; toast reports how many
    links were created (insert counter semantics, like legacy).

## Permissions

| State | Behaviour |
|-------|-----------|
| user has `req_tcase_link_management` on the project | full access (legacy `checkRights()` used `rightsAnd = ['req_tcase_link_management']`) |
| missing right | red banner "You need the 'req_tcase_link_management' permission…", work area hidden; BFF returns 403 on every route |

The shared router additionally accepts `mgt_view_req` holders so the other
requirements screens (overview/monitor/print) keep working.

## i18n

37 keys under the `reqAssign.*` namespace in **all** locale bundles
(en, de, es, fr, it, ja, pt, ro, ru, zh) — labels, mode tabs, picker titles,
toasts, warnings, confirm dialog. Client-side rendering via `TLi18n`.

## Bugs fixed while testing

- Shared requirements router emitted an unconditional `http_response_code(404)`
  before its final fallback → poisoned every successful response body with a
  404 status; moved into the unmatched-route branch only.
- `tcversions.open` does not exist in this schema (column is `is_open`);
  latest-version lookup no longer selects it.
- `req_versions.req_id` does not exist in this schema — requirement id derived
  via `nodes_hierarchy.parent_id` of the req_version node.
- `req_coverage.testcase_id` was misread as `tcase_id` in two queries.
- Latent fatal in the shared router: `needTprojectId()` was called by the
  `/reqspec-context` and `/reqspec-search` routes but never defined there;
  now guarded with `function_exists`.

## Test coverage

Suite 43 in `tmp/TLU_Test_Cases.md` — 21 checks covering both modes, rights,
i18n runtime (EN/DE), bundle validity, DB-level verification of assign /
unassign / bulk counters and executed-badge state: 21/21 PASS after fixes.
