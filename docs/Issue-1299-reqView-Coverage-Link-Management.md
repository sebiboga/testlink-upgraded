# Issue #1299 — reqView.html: add/remove test case links + TC icons (gap vs legacy)

Modernized screen: **Requirement Viewer** (`gui/templates/requirements/reqView.html`)
backed by `api/requirements/index.php`. Refs
[#1299](https://github.com/sebiboga/testlink-upgraded/issues/1299).

## The gap

The legacy requirement viewer let you manage the *coverage* (requirement ↔ test
case links) straight from the "Coverage" fieldset
(`gui/templates/dashio/requirements/reqViewVersionsViewer.tpl:214-268`):

| Legacy control | Legacy source |
|---|---|
| `tcaseIdentity` input + **Save** (add link) | `reqViewVersionsViewer.tpl:252-267` |
| per-link **remove** button | `reqViewVersionsViewer.tpl:224-228` |
| **obsolete** `heads_up` icon with tooltip | `reqViewVersionsViewer.tpl:230-234` |
| **execution history** icon (`openExecHistoryWindow`) | `reqViewVersionsViewer.tpl:236-238` |
| **design** icon (`openTCaseWindow`) | `reqViewVersionsViewer.tpl:239-241` |
| label `PREFIX-ID : name [version N]` | `reqViewVersionsViewer.tpl:245` |

The modern viewer only ported the **read** projection: a 3-column grid
(`TC external id | Test case | Version`) and an empty state, with no action
column and no write route at all. `can_be_deleted` — the field that gates the
remove button — was already returned by
`requirement_mgr::getActiveForReqVersion()`
(`lib/functions/requirement_mgr.class.php:4643-4666`) but was dropped by the BFF
projection, and the `req_tcase_link_management` grant was transported in the
`/view` payload and never used.

## What was implemented

### BFF (`api/requirements/index.php`)

* `GET /view` now also returns
  * `coverage[].can_be_deleted` — the legacy remove-button gate,
  * `can_manage_coverage` — legacy `$gui->canAddCoverage`
    (`reqCommands.class.php:109-115`): links may only be added on the **latest**
    requirement version,
  * `tcase_prefix` (already there), `glue_char` and `piece_sep` so the client can
    render the legacy label and prefill the add form.
* `POST /coverage` — port of `reqCommands::addTestCase()`
  (`reqCommands.class.php:891-955`): body `{req_id, version_id, tcaseIdentity}`.
  Checks the `req_tcase_link_management` grant on the requirement's own project
  (403), the version ownership (400) and `can_manage_coverage` (409), then
  requires a **full external id**, enforces the project prefix, rejects unknown
  test cases, and — when `testcase_cfg.reqLinkingDisabledAfterExec` is on —
  rejects test cases whose latest version has already been executed. The link is
  created with `requirement_mgr::assign_to_tcase()` (latest active tcversion ↔
  latest requirement version, including the legacy audit event).
* `DELETE /coverage` — port of `reqCommands::removeTestCase()`: body
  `{req_id, version_id, tcversion_id}`. The identity is the **legacy** one,
  `(req_version_id, tcversion_id)`, because a test case can have several linked
  versions. The server re-checks `can_be_deleted` (409) and deletes through
  `requirement_mgr::delReqVersionTCVersionLink()` (audit event included).

All rejections return both the legacy `lang_get` text (`message`) and the i18n
key (`message_key`), so the screen can localize them.

### Screen (`gui/templates/requirements/reqView.html`)

* **Add link to test case** button in the Linked Test Cases card header, gated on
  `grant.req_tcase_link_management && can_manage_coverage` (exactly the legacy
  condition), opening a Bootstrap modal with the `tcaseIdentity` field
  (placeholder = `PREFIX-`), an inline error box and Cancel/Save.
* **Actions** column with three icon links per row: execution history
  (`execHistory.html`), design (`tcEdit.html`) and remove (`fa-unlink`).
  History and design are always rendered (legacy parity); the remove icon is
  rendered only when the grant is present **and** `can_be_deleted` is true, and
  it asks for a localized confirmation.
* Obsolete links get a `heads_up` triangle with the `obsolete` tooltip, and the
  row label is rebuilt as the legacy `PREFIX-ID : name [version N]`.

### i18n

16 new `reqv.*` keys (`actions`, `addLinkToTestCase`, `design`, `executionHistory`,
`obsolete`, `removeLinkToTestCase`, `tcIdentity`, `tcIdentityHelp`,
`removeLinkConfirm`, `linkAdded`, `linkRemoved`, `errFullExternalId`,
`errOtherProject`, `errTcaseMissing`, `errLinkExecuted`, `errNotLatestVersion`)
in **all 10** locale bundles, inserted additively next to the existing `reqv.`
block (no re-sort, because the bundles are shared with parallel CI agents).
`TLi18n.has()` was added to `gui/templates/i18n/i18n.js` so a screen can fall
back to the server-rendered legacy message when a key is missing.

## Verification

Suite 1299 in `tmp/TLU_Test_Cases.md` — 13/13 PASS: add (happy path, empty id,
foreign prefix, unknown TC, bare id, already executed TC, non-latest version),
remove (happy path with audit, `can_be_deleted=false` block), the two icon links,
invalid identifiers, the rights gate (role 3 and role 5 = `mgt_view_req` only),
i18n completeness and Event Viewer hygiene. Only AUDIT events were produced by
the app.

Fixture: `php tmp/fixtures_1299.php` (project `COV1299`, prefix `COV`).

## Code review (subagent) and the fixes it produced

A review of `api/requirements/index.php`, `gui/templates/requirements/reqView.html` and
`gui/templates/i18n/i18n.js` found **no blocker** (no SQL injection - all ids `intval()`ed,
`bffSameOriginGuard()` covers POST+DELETE, the rights check derives the project from the
requirement and ignores the client-supplied `tproject_id`) and confirmed the legacy
identity, the latest-version gate and the add validation line-for-line. Fixed:

| Severity | Finding | Fix |
|---|---|---|
| MAJOR | the test case external id was interpolated into a single-quoted `onclick` argument, where HTML entity escaping does not survive the HTML parser (latent stored XSS, currently unreachable because the column is `int unsigned`) | the icon now carries `data-cov-ext` and the handler takes the element: `onclick="removeCoverageLink(this, 11)"` (`reqView.html:691-699,788`) |
| MAJOR | `assign_to_tcase()` returns 0 when it inserts nothing, but the route always answered `ok` -> a false "link added" toast | the return value is checked; the link is then re-read and only a genuinely missing link fails with `reqv.errLinkFailed` (already-linked stays a silent no-op) (`index.php:900-921`) |
| MAJOR | the four DELETE/403 error paths returned no `message_key` and the client surfaced them with a native `alert()` in English | added `reqv.errLinkNotDeletable`, `reqv.errLinkNotFound`, `reqv.errLinkFailed`, `reqv.errNoPermission` to all 10 bundles; the client renders them with `showToast()` + `covMessage()` (`index.php:801,832,839`, `reqView.html:788-812`) |
| MINOR | duplicated `req_version_id` nesting made the DELETE fallback dead code | collapsed to `$body['version_id'] ?? $body['req_version_id'] ?? 0` |
| MINOR | `intval($lastVersion['id'])` would warn on a requirement without versions; `$lastVersion` was computed for the DELETE path too | `?? 0` guard, and the call moved into the add branch |
| MINOR | the version guard ran after `get_node_hierarchy_info()` | guard hoisted above the call |
| MINOR | `/view` derived `latest_version_id` from `MAX(version)` while the gate uses `get_last_version_info()`, so a version-number tie could show a button the server rejects | `/view` now asks `get_last_version_info()` as well |
| MINOR | `$&` / `` $` `` in a typed value were expanded as replacement patterns in `TLi18n.t()` | `str.replace()` now takes a replacement function (`i18n.js:181-183`) |
| MINOR | dead `#covFeedback` node, unused `tproject_id` in the add body, no in-flight guard on remove, no Enter submit | node removed, field dropped, `covBusy` flag + `disabled` class added, keydown binding added |

Post-review re-verification (5 extra cases, `tmp/TLU_Test_Cases.md` 1299.4) - all PASS;
`php -l`, `node --check` and `python3 -m json.tool` clean; no new Error/Warning in the
Event Viewer.
