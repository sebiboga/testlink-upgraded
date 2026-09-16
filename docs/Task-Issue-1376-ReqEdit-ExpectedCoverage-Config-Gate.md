# Task 1376 — reqEdit expected-coverage configuration gates + free numeric input (gap vs legacy)

**Issue:** [#1376](https://github.com/sebiboga/testlink-upgraded/issues/1376)
**Status:** IMPLEMENTED (2026-09-16) — branch `task/issue-1376`

## The gap

The modern Requirement Editor (`gui/templates/requirements/reqEdit.html`, BFF
`api/reqedit`) dropped three legacy expected-coverage behaviours:

1. The legacy screen is gated by the global config
   `req_cfg->expected_coverage_management`
   (`config.inc.php:1666`, default `ENABLED`). When disabled, the whole
   Expected Coverage field is not rendered (`gui/templates/dashio/requirements/reqEdit.tpl:345`)
   and legacy forms use whatever the DOM carries (effectively the 0 default).
2. The field is additionally gated per requirement type by
   `req_cfg->type_expected_coverage`
   (`lib/requirements/reqCommands.class.php:33-41`; default import lives in
   `cfg/const.inc.php:759` as `type_expected_coverage = [TL_REQ_TYPE_INFO => false]`,
   i.e. type Informational/"1" is disabled out of the box). For a disabled type the
   stored value is forced to 0 (`reqEdit.tpl:69-91`).
3. The legacy field is a free text/number input that accepts **any positive
   integer** (e.g. 7, 12), persisted as-is (`reqEdit.tpl:353-355`). The modern
   screen offered a fixed dropdown `[1,2,3,5,10]` and `save()` collapsed the value
   to `max(1, intval(...))`, so 7/12/… could never be expressed.

The type-change toggle (`configure_attr`, `reqEdit.tpl:188-213`) was also absent.

## Legacy source of truth

- `config.inc.php:1666` — `$tlCfg->req_cfg->expected_coverage_management = ENABLED;`
- `cfg/const.inc.php:759` — `type_expected_coverage = array(TL_REQ_TYPE_INFO => false)` (defaults)
- `lib/requirements/reqCommands.class.php:33-41` — `attrCfg['expected_coverage'][$type_code]`,
  missing type code → enabled (1)
- `gui/templates/dashio/requirements/reqEdit.tpl:345` — `{if $gui->req_cfg->expected_coverage_management}`
- `gui/templates/dashio/requirements/reqEdit.tpl:69-91` — validation + forcing stored 0 for disabled types
- `gui/templates/dashio/requirements/reqEdit.tpl:188-213` — `configure_attr` show/hide on type change
- `gui/templates/dashio/requirements/reqEdit.tpl:353-355` — free `input` for the value

## Modern implementation (port)

**Backend `api/reqedit/index.php`**
- `reqOptions()` now returns:
  - `expectedCoverageManagement` — tolerant boolean (`boolishConfig()`): accepts
    `ENABLED`/`TRUE`/`1` vs `DISABLED`/`FALSE`/`0`/`''`, default `false` when the key is
    absent (matches `api/requirements` `buildMeta()` and legacy-if-absent semantics).
  - `expectedCoverageByType` — map type-code → 1|0 from `req_cfg->type_expected_coverage`;
    a type absent from the map is enabled (legacy `reqCommands.class.php:39` parity).
- `effectiveExpectedCoverage($type, $posted)` implements the three-way gate for save:
  management disabled → `0`; type disabled in the map → `0`; otherwise the posted value
  as-is (`max(1, intval(...))`), so arbitrary positive integers persist unchanged.
- Both `create`/`update` call sites unchanged in signature — they still receive an int.
  `php -l` clean.

**Frontend `gui/templates/requirements/reqEdit.html`**
- The fixed `<select id="reqExpectedCoverage">` was replaced by a free numeric text
  `<input id="reqExpectedCoverage" maxlength="6">`; no preset options anymore.
- The column is wrapped in `<div id="expectedCoverageCol" class="col-md-3">`.
- New helpers mirror the legacy gates:
  - `coverageManagementEnabled()` — options.expectedCoverageManagement
  - `coverageEnabledForType()` — type code from `#reqType` vs `expectedCoverageByType`
    (missing → enabled)
  - `currentCoverageEnabled()` — both gates together
  - `refreshCoverageField()` — hides/shows `#expectedCoverageCol`; runs on load and on
    `#reqType` change (legacy `configure_attr` parity).
- `loadForm()` sets the input value directly and calls `refreshCoverageField()` (a
  type-1/Informational requirement loads with the field hidden from the start).
- `save()` revalidates: when the field is visible the value must be a number > 0
  (`reqe.warningExpectedCoverage` / `reqe.warningExpectedCoverageRange`); when hidden
  (either gate off) it sends **0** — the legacy force-0 path, not `max(1,...)`.
- Read-only/no-rights mode keeps working: `setEditable()` disables all `.form-control`
  including the new input.

**i18n** — new keys in all 10 locale bundles (en/ro/de/es/fr/it/ja/pt/ru/zh):
`reqe.warningExpectedCoverage` ("Expected coverage must be a number.") and
`reqe.warningExpectedCoverageRange` ("Expected coverage must be greater than 0.").

## Verification (browser, headless Chrome — admin/admin, fresh DB)

| Case | Result |
|---|---|
| Options payload: `expectedCoverageManagement:true`, `expectedCoverageByType:{1:0,2:1,..,7:1}` | PASS |
| Edit mode: field is a free text input carrying value 7 | PASS |
| Arbitrary edit value 12 → DB `expected_coverage=12` | PASS |
| Create mode `?spec_id=2`: coverage 15 → new req id=12 stored `15` | PASS |
| Type gate: edit type-Info req → field hidden on load | PASS |
| Type switch Feature↔Info toggles visibility (configure_attr) | PASS |
| Hidden-type save persists **0** | PASS |
| Validation `abc` → "must be a number."; `0` → "must be greater than 0."; no row | PASS |
| Management DISABLED (temp override) → options false, field hidden, save=0; default ENABLED restored & re-verified | PASS |
| Regression: general save keeps data; Create New Version copies coverage (v2 `12`); source frozen | PASS |
| Event Viewer clean (0 new Error/Warning from feature work) | PASS |

Screenshots: `docs/screenshots/issue-1376-reqedit-coverage-numeric-input.png`,
`docs/screenshots/issue-1376-reqedit-coverage-type-gate-hidden.png`,
`docs/screenshots/issue-1376-reqedit-coverage-validation.png`.

## Follow-up discovered during testing

The reqSpecMgmt management modal still renders a fixed `min=1` number coverage input and
ignores both config gates — out of scope for #1376 (standalone reqEdit screen). Filed as
[#1516](https://github.com/sebiboga/testlink-upgraded/issues/1516) (task) for a future run.