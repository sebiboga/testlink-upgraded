# Mantis Issue XML import — Modernized (TestLink 2.0.1, Refs #1948)

Modern full-featured **Mantis Issue XML import** screen for TestLink 2.0.1,
replacing the orphaned 1.9.20 renderer
`lib/testcases/tcCreateFromIssueMantisXML.php`. The legacy controller still
rendered its Smarty template in 2.0.1, but the dashio UI stopped reaching it —
`gui/templates/dashio/testcases/containerView.tpl:63` re-points
`$tcMantisXMLAction` to the #1502 screen
(`gui/templates/testcases/tcCreateFromIssues.html`) — so only tl-classic and
direct URLs still hit it. Tracking issue
[#1948](https://github.com/sebiboga/testlink-upgraded/issues/1948).

## Why this screen

- The ledger's TODO section is empty (every ASIDE entry already maps to a modern
  `.html` + BFF), so the sweep looked past the menu for the last orphaned legacy
  import renderer still shipping in 2.0.1.
- The feature is a genuine 1.9.20 capability (create test cases from a Mantis BT
  bug-tracker XML export) with a distinct format (`<mantis version=...><issue>`)
  that the modern TestLink-XML importer `api/tccreatefromissue` (#1896) refuses
  (it accepts only `<testcases|testsuite>` roots).
- Sibling **#1502** (`api/tccreatefromissues` + `tcCreateFromIssues.html`) covers
  the *same* `<mantis>` format with a container **fixed by the URL**. This screen
  (#1948) is the **full-featured twin**: it adds an in-screen target-suite picker
  and exposes the classic 1.9.20 duplicate criteria/actions through the verbatim
  `saveImportedTCData()` engine. The overlap is deliberate and documented here.

## Legacy defects closed (not ported)

- The legacy controller checked **no right at all** (`testlinkInitPage` was a
  session check only): any authenticated user could import into **any**
  project/suite (sibling class of #1696/#1679).
- The write was a plain cross-site-postable form POST with **no CSRF proof**.
- `container_id` was **never validated** against the addressed project.

The BFF now checks `mgt_modify_tc` on the **addressed** project **before** the
container is resolved (the #1697 no-oracle lesson) behind
`bffSameOriginGuard()` + `bffEnforceSession()`.

## Modern stack

| Layer | File |
|---|---|
| Screen | `gui/templates/testcases/tcCreateFromMantis.html` (Dashio standalone HTML/JS/CSS) |
| BFF | `api/tcmantisimport/index.php` (`GET\|HEAD ?action=init\|tree`, `POST ?action=import` multipart `uploadedFile`; session auth + same-origin guard + session-inactivity check) |
| Engine | `lib/testcases/tcCreateFromIssue.functions.php` → `saveImportedTCData()` (the verbatim 1.9.20 save path extracted in #1896) |
| Wiring | `$actions->tcCreateFromMantis` in `lib/functions/common.php`, consumed by `api/aside/index.php` (ASIDE **Test Case Design**, gated on `modify_tc`) |
| i18n | 33 `tcfm.*` keys (incl. `footers.tcCreateFromMantis`) in **all 10** client bundles; `$TLS_href_tc_mantis_import` in **all 19** server locales |
| Legacy shim | `lib/testcases/tcCreateFromIssueMantisXML.php` — GET/HEAD → **302** to the modern screen (`tproject_id`+`containerID` forwarded); any write verb → **405** `modern_endpoint_only` |

## Content

- **Context** — resolved test project name, locale switcher, mapping note.
- **Target test suite** — dotted tree of the project's suites plus the project
  root, sourced from `GET ?action=tree` (`init` also returns the list).
- **File picker** — Mantis BT XML only, with the configured maximum file size
  hint; a note points TestLink-XML users to *Test Specification Import*.
- **Duplicate options** — criteria (Same name / Same internal ID / Same external
  ID) and action (Skip / Update last version / Generate new / Create new version),
  passed to the engine as `hit_criteria` / `action_on_duplicated_name`.
- **Import result** — `Processed: N` counter and a per-entry table (Test case /
  Detail), plus an *Import another file* reset.

## Mapping (legacy parity)

Per `<issue>`:

- **name** = `<issue_issue label>:<id> - <summary>` — the label is the **server
  locale** string `$TLS_issue_issue` (e.g. `Issue/Task:21 - Login fails …`).
- **summary** = description, then (when present) the `issue_steps_to_reproduce`
  and `issue_additional_information` sections, each prefixed with its
  server-locale label. `lang_get()` falls back to `en_GB` when a locale bundle
  lacks the key (legacy behaviour, e.g. `ro_RO`).
- **externalid** = `null` — verified against line 476 of the pre-shim controller
  (`tcCreateFromIssueMantisXML.php`), i.e. the #1502 header's "external id = the
  bug id" is a **deviation** from legacy, not part of this port.

## BFF error contract

`400` `invalid_request` / `unknown_action` / `invalid_parameter` /
`container_not_in_project` · `401` `session_expired` (enforced) ·
`403` `no_right` · `404` `tproject_not_found` · `405` `method_not_allowed` and
`modern_endpoint_only` (shim) · `413` `file_too_large` · `422` `invalid_xml` /
`invalid_format` (non-`<mantis>` root) · `500` `server_error`.

## Verification

- `php tmp/fixtures_1948.php` (projects `MNT1`/`MNT2`, suites, `mnorights` user,
  `tmp/mantis_1948.xml`) then `php tmp/verify_1948.php` → **23/23 PASS**.
- Browser: EN / RO / DE (title, header, labels all switch), successful import of
  2 issues (report shows `Processed: 2`, both `ok`), *Import another file* reset,
  no-right user (403 card "No permission", empty picker, disabled Import),
  ASIDE **Mantis Issue Import** entry navigates to the screen.
- Legacy shim: GET → **302** to `tcCreateFromMantis.html?tproject_id=…&containerID=…`;
  POST → **405** `modern_endpoint_only` (the unsafe legacy write never executes).
- Console clean; Event Viewer no new Error/Warning rows.
- Suite `Issue #1948` in `tmp/TLU_Test_Cases.md` = 17/17 PASS (gate 34 PASS / 0 FAIL).

## Screenshots

- `1948-mantis-import-normal.png` — initial screen with context + suite picker.
- `1948-mantis-import-result.png` — successful import of 2 issues.
- `1948-mantis-import-noright.png` — no-right (403) state.
