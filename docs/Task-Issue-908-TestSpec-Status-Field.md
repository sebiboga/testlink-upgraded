# Task 908 — Test case STATUS field in Test Specification editor (gap vs legacy)

**Issue:** [#908](https://github.com/sebiboga/testlink-upgraded/issues/908)
**Status:** IMPLEMENTED (2026-09-14)

## The gap

The legacy design-time editor showed a **Status** select
(`gui/templates/dashio/testcases/include/attributesLinear.inc.tpl:7-14`,
`<select name="tc_status">` bound to `$gui->domainTCStatus`) and persisted it
through the `setStatus` testcase command (`lib/testcases/tcEdit.php:108/784`,
`lib/testcases/testcaseCommands.class.php:1115` → `testcase::setStatus`,
`lib/functions/testcase.class.php:7723`).

The modernized `gui/templates/testcases/testSpec.html` editor had no Status
field: `create`/`update` in `api/testcases/index.php` never wrote `tcversions.status`
and the detail view hid it.

## Legacy source of truth

- Domain: `$tlCfg->testCaseStatus` (`cfg/const.inc.php:941-943`) with keys
  `draft, readyForReview, reviewInProgress, rework, obsolete, future, final`
  → codes 1..7. Labels: locale strings `testCaseStatus_*`.
- `testcase::setStatus($tcversionID,$value)` — `UPDATE tcversions SET status=…
  WHERE id=…`.
- `testcase::create()` supports `options['status']` and `testcase::update()`
  supports `$attrib['status']` (both NULL-safe), and `update()` refreshes
  `updater_id`/`modification_ts` in the same SQL.

## Implementation

**BFF — `api/testcases/index.php`**
- `tcStatusDomain()` + `normalizeTcStatus()` helpers read the configured
  domain (with the standard 1..7 fallback) and validate submitted codes.
- `get` and `keywords` responses now include `statusDomain` (code → key).
- `create`: reads `status` from the body, defaults to Draft (1) when absent
  or invalid, and passes it via `testcase::create(..., array('status' => N))`.
- `update`: reads `status`, falls back to the current `tcversions.status`
  when absent, passes it via `testcase::update(..., $attr = array('status' => N))`.

**HTML — `gui/templates/testcases/testSpec.html`**
- `statusLabel(code)` (i18n `tcview.statusN`) and `statusOptions(selected)`
  (domain-driven `<option>` list; falls back to 1..7).
- Editor form (`formHtml`) gained a **Status** `<select id="tcStatusSel">`
  shown on both create and edit.
- `collectForm()` includes `status`.
- `openCreateTc`/`openEditTc` store `ctx.statusDomain` from the BFF and seed
  the form with the current (or default Draft) status.
- Detail view (`renderTcView`) shows a **Status** meta-item using the workflow
  label (same badge pattern as `tcView.html`).

**i18n**
- New key `tspec.status` added to all 10 bundles
  (`en, de, es, fr, it, ja, pt, ro, ru, zh`); status option labels reuse the
  already-translated `tcview.status1..7` keys. All bundles validated with
  `python3 -m json.tool`.

## Verification

- Browser (chrome-devtools MCP, `http://localhost:8082`, admin/admin):
  - Create form shows the STATUS select (7 options, Draft selected).
  - Created TC with "Ready for review" → `tcversions.status=2`.
  - Detail view: "Status: Ready for review".
  - Edit to "Final" → `tcversions.status=7`, `modification_ts` refreshed,
    detail view shows "Status: Final".
  - `?locale=ro` renders all 7 workflow labels (Ciornă … Final) and the
    localized `Status` label.
- `php -l` clean; Event Viewer (`events` table) shows no new ERROR/WARNING
  rows; browser console clean apart from the pre-existing a11y hint.
- Test suite: `Task — Issue #908` in `tmp/TLU_Test_Cases.md` (PASS).

**Files:** `api/testcases/index.php`, `gui/templates/testcases/testSpec.html`,
10× `gui/templates/i18n/*.json`, docs/wiki page, CHANGELOG.