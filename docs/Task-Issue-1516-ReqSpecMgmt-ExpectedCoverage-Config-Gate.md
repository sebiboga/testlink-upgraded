# Task 1516 — reqSpecMgmt modal expected-coverage configuration gate (gap vs legacy)

**Issue:** [#1516](https://github.com/sebiboga/testlink-upgraded/issues/1516)
**Status:** IMPLEMENTED (2026-09-16) — branch `task/issue-1516-exp-coverage-gate`

## The gap

The Requirement Specification Management screen's Create/Edit Requirement modal
(`gui/templates/requirements/reqSpecMgmt.html`) always renders a fixed
`<input type="number" id="reqExpectedCoverage" min="1" value="1">` and always
sends `expected_coverage` on save. The two legacy configuration gates are ignored:

1. **Global gate** — `req_cfg->expected_coverage_management`
   (`config.inc.php:1666`, default `ENABLED`). When disabled the legacy editor
   does not render the expected-coverage field at all
   (`gui/templates/dashio/requirements/reqEdit.tpl:345`).
2. **Per-type gate** — `req_cfg->type_expected_coverage`
   (`lib/requirements/reqCommands.class.php:33-41`; default
   `cfg/const.inc.php:759` → `type_expected_coverage = [TL_REQ_TYPE_INFO => false]`,
   i.e. type "1"/Informational is disabled out of the box). For a disabled type the
   stored value is forced to 0.

The BFF `api/reqspec/index.php` also stored `max(1, intval(...))` for every create/update,
never 0, and its `options` action did not publish the gates to the client.

This is the exact gap #1376 fixed for the standalone reqEdit screen; it was kept as a
separate task (this one) to respect run scoping.

## Legacy source of truth

- `config.inc.php:1666` — `$tlCfg->req_cfg->expected_coverage_management = ENABLED;`
- `cfg/const.inc.php:759` — `type_expected_coverage = array(TL_REQ_TYPE_INFO => false)` (defaults)
- `lib/requirements/reqCommands.class.php:33-41` — `attrCfg['expected_coverage'][$type_code]`,
  missing type code → enabled (1)
- `gui/templates/dashio/requirements/reqEdit.tpl:345` — `{if $gui->req_cfg->expected_coverage_management}`
- `gui/templates/dashio/requirements/reqEdit.tpl:69-91` — validation + forcing stored 0 for disabled types

## Modern implementation (port)

Pattern mirrored from the verified #1376 fix in `api/reqedit/index.php`.

**Backend `api/reqspec/index.php`**
- Added `boolishConfig()` — tolerant boolean reader for a `req_cfg` knob
  (`ENABLED`/`TRUE`/`1` vs `DISABLED`/`FALSE`/`0`/`''`, default when key absent).
- Added `effectiveExpectedCoverage($type, $posted)` — three-way gate:
  management disabled → `0`; type disabled in `type_expected_coverage` → `0`;
  otherwise `max(1, intval($posted))` (arbitrary positive integers persist).
- `options` action now returns `expectedCoverageManagement` (bool) and
  `expectedCoverageByType` (map type-code → 1|0; absent type → enabled).
- `create_req` and `update_req` now persist through `effectiveExpectedCoverage()`
  instead of the unconditional `max(1, int)`.

**Frontend `gui/templates/requirements/reqSpecMgmt.html`**
- The coverage input group is wrapped in `<div id="expectedCoverageCol">`.
- New JS helpers (mirror reqEdit.html):
  - `coverageManagementEnabled()` — `options.expectedCoverageManagement`
  - `coverageEnabledForType(type)` — type code vs `expectedCoverageByType` (missing → enabled)
  - `refreshCoverageField()` — hides/shows `#expectedCoverageCol`; runs on modal open
    and on requirement-type change (delegated `$(document).on('change','#reqType',...)`,
    the modal is dynamically shown).
  - `currentCoverageEnabled()` — both gates together.
- `openReqCreate()` and `openReqEdit()` call `refreshCoverageField()` so a type-Info
  requirement opens with the field hidden (value kept in the DOM but gated on save).
- `saveReq()` sends `expected_coverage: currentCoverageEnabled() ? parseInt(...) : 0`
  — the legacy force-0 path; hidden fields never leak a stale DB value.

**i18n** — no new keys needed: the modal reuses the existing `rs.expectedCoverageLabel`
in all bundles; no new user-facing strings were introduced.

## Verification (browser, headless Chrome — admin/admin, fresh DB)

Fixture: Test project 1 (CoverageGateProj), spec 2 (CGP-S1), FEAT-001 (type 2, cov 3),
INFO-001 (type 1, cov 5).

| Case | Result |
|---|---|
| Options payload includes `expectedCoverageManagement:true` + `expectedCoverageByType:{1:0,2:1,...,7:1}` | PASS |
| Create modal, default type Feature (2): coverage field VISIBLE | PASS |
| Switch type to Informational (1): field HIDDEN (`coverageEnabled:false`) | PASS |
| Switch back to Feature: field VISIBLE again | PASS |
| Create Informational req via modal (field hidden): DB `expected_coverage=0` | PASS |
| Create Feature req with coverage 7: DB `expected_coverage=7` (arbitrary value) | PASS |
| Edit FEAT-001: field VISIBLE carrying existing value 3 | PASS |
| Edit INFO-001 (field hidden) + change title + save: DB `expected_coverage=0` (was 5) | PASS |
| Global DISABLED (temp override `config.inc.php:1666`): options → false, field hidden even for Feature, save persists 0; default ENABLED restored and re-verified | PASS |
| Event Viewer / `events` table: 0 new Error/Warning rows (log_level 1/2) | PASS |
| Browser console: no JS errors (only pre-existing a11y form-field note) | PASS |

Screenshots:
`docs/screenshots/issue-1516-exp-coverage-create-modal.png` (Feature, field visible),
`docs/screenshots/issue-1516-exp-coverage-hidden-informational.png` (Informational, field hidden).

## Test cases

Suite `Task — Issue #1516` appended to `tmp/TLU_Test_Cases.md` — 12/12 PASS.