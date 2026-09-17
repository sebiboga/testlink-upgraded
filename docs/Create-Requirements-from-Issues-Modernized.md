# Create Requirements from Issues (Mantis XML) — Modernized Screen

Modernization of **Create Requirements from Issues XML (Mantis)** import screen
(`lib/requirements/reqCreateFromIssueMantisXML.php` + Dashio
`reqCreateFromIssueMantisXML.tpl`) — GitHub issue
[#1503](https://github.com/sebiboga/testlink-upgraded/issues/1503).

This was the last standalone legacy import screen in `lib/requirements/`: it
uploads a Mantis bug-tracker XML export and creates one requirement per
`<issue>` under the target requirement spec. It is the REQ sibling of the
test-case importer from **#1502** and it closes the documented **gap #1349**
(the "Create From Issues (XML)" button of the legacy `reqSpecView` Requirement
Operations fieldset had no equivalent on the modern `reqSpecView.html`). The
Smarty screen is replaced by a standalone Dashio page
(`gui/templates/requirements/reqFromIssues.html`) backed by a plain-PHP REST
BFF (`api/reqfromissues/index.php`, session-based auth).

**Entry:** the modern Requirement Specification Viewer toolbar gains the
**Create Requirements from Issues** button (shown when the user holds
`mgt_modify_req` on the owning project, e.g. the admin)
**URL:** `gui/templates/requirements/reqFromIssues.html?req_spec_id=<id>&tproject_id=<id>`
**BFF API:** `api/reqfromissues/index.php` (`GET ?action=init`, `POST ?action=import`)
**Right:** `mgt_view_req` **AND** `mgt_modify_req` on the OWNING project (403 otherwise)

---
## Table of Contents
1. [What the screen does](#1-what-the-screen-does)
2. [REST API Reference](#2-rest-api-reference)
3. [Legacy parity notes](#3-legacy-parity-notes)
4. [i18n Keys](#4-i18n-keys)
5. [Security](#5-security)
6. [Testing](#6-testing)

## 1. What the screen does

| Feature | Legacy behavior | Modern implementation |
|---|---|---|
| Entry point | "Create From Issues (XML)" button in the legacy `reqSpecView` Requirement Operations fieldset (`reqSpecViewButtons.inc.tpl`), `reqCreateFromIssueMantisXML.php?req_spec_id=`) | toolbar button on modern `reqSpecView.html` (testable link `reqFromIssues.html?req_spec_id=&tproject_id=`), shown on `r.rights.manage` |
| Source format | Mantis bug-tracker XML export, root `<mantis>`, one `<issue>` per bug, fields id / summary / description / steps_to_reproduce / additional_information | identical; validated (`LIBXML_NONET`, well-formedness, root tag check → 422) |
| Requirements created | one per issue via `requirement_mgr::createFromMap()` | identical server-side build (reused the exact legacy class, no reimplementation) |
| Doc ID | `Mantis Task ID:<id>` (legacy `reqCreateFromIssueMantisXML.php` docid template) | identical |
| Title | `Issue:<id> - <summary>` (`issue_issue` label) | identical, labels localized via the client locale hint |
| Description | `issue_description` label + description, plus `issue_steps_to_reproduce` / `issue_additional_information` when present, joined with `<p>` | identical |
| Attributes | status/type empty, `node_order` = document order, `expected_coverage = 1` | identical |
| Duplicate handling | inside `createFromMap()`: same-spec docid hit → skip + "is FROZEN"; cross-branch hit → "Already exists on other branch"; over-length → skip "length exceeded" | identical (both branches live in the legacy class) |
| Upload cap | `import_file_max_size_bytes` config | identical (413 over limit; 400 on missing file / PHP error code 1) |
| Result feedback | server-side result-map table after submit | live result table (doc ID / requirement / result), issues-found count, green "Created" vs amber "Skipped" rows, feedback bar, toast |
| Target | the given `req_spec_id` | validated to belong to the project (each created requirement gets the spec's project as owner) |

## 2. REST API Reference

All routes require an authenticated session (401 when anonymous). `locale`
parameters accept `en`, `ro`, … both 2-char and 5-char codes (the TLi18n
switcher uses the 2-char form); project rights are always checked against the
requested `tproject_id`.

| Method & path | Purpose | Failure modes |
|---|---|---|
| `GET ?action=init&tproject_id=N&req_spec_id=N[&locale=xx]` | context: tproject name, spec path (`get_path`), `import_file_max_size_bytes` → KB hint, `field_size` req_title / req_docid, grant map, localized `issue_*` labels | 400 invalid/missing params, 404 unknown project or spec, 401 anon, 500 guarded |
| `POST ?action=import&tproject_id=N&req_spec_id=N[&locale=xx]`, multipart field `uploadedFile` | parse `<mantis>` export, `createFromMap()` each issue under the spec, return per-row result map | 400 no file, 403 no right, 405 non-POST, 413 over size cap, 422 invalid XML/root, 500 guarded DB error |

The BFF restores the previous session locale after an import (exception path
included) and answers 405 for non-GET `init` and 400 for a missing upload even
when the token-type check is skipped — all states verified with curl.

## 3. Legacy parity notes

- The import itself is **not reimplemented**: the BFF calls the legacy
  `requirement_mgr::createFromMap()` exactly like `reqCreateFromIssueMantisXML.php`
  did, so document order, duplicate/FROZEN/cross-branch semantics and the
  length-exceeded skips are byte-for-byte the legacy behavior.
- Labels are generated server-side through `init_labels()` and localized via the
  same 5-char session-locale mechanism (`$_SESSION['locale']`) the legacy used,
  with the client hint translated into the session locale for the request
  (verified German: `Wurde übersprungen - Anforderung…`).
- The result map reuses the legacy `createFromMap()` return format
  (`req_doc_id`, `req_status`/`id_status`, `message`), reshaped into a row-per-issue
  UI table; a row whose message does not start with "Created"/"Updated" is
  rendered as an amber (warning) row.
- Two pre-existing legacy bugs were found while exercising the import and were
  fixed in the shared class (both benefit every import path — BFF reqfromissues,
  BFF reqimport, legacy reqImport CSV/DocBook/XML):
  - **#1505** `requirement_mgr.class.php:1624` emitted `E_WARNING Trying to
    access array offset on null` into the Event Viewer on every docid hit that
    owns NO `req_versions` row; now guarded (`is_array` + `is_open == 1`), so a
    version-less hit falls through to the clean "is FROZEN" skip.
  - **#1504** the over-length skips read misspelled keys
    `req_title_lenght_exceeded` / `req_docid_lenght_exceeded` (typos) → "not
    localized" events + raw keys in the UI; renamed to
    `req_title_length_exceeded` / `req_docid_length_exceeded` and the missing
    `req_docid_length_exceeded` translation was added to the 16 `strings.txt`
    bundles that lacked it (ro_RO is the short partial bundle — falls back to
    English like the legacy).

## 4. i18n Keys

All labels/messages use the client-side `TLi18n` module; keys `reqfi.title`,
`reqfi.headerSub`, `reqfi.ctxProject`, `reqfi.ctxSpec`, `reqfi.uploadTitle`,
`reqfi.dropHint`, `reqfi.sizeHint`, `reqfi.mappingTitle`, `reqfi.mappingBody`,
`reqfi.importBtn`, `reqfi.resultTitle`, `reqfi.issuesCount`, `reqfi.emptyResult`,
`reqfi.colDocId`, `reqfi.colReq`, `reqfi.colResult`, `reqfi.errNoPermission`,
`reqfi.errLoad`, `reqfi.noFile`, `reqfi.importing`, `reqfi.importFail`,
`reqfi.importOk`, `reqfi.reqRows`, `reqfi.fileSelected`, `reqfi.spec`,
`footers.reqFromIssues` are defined in ALL locale bundles
(`gui/templates/i18n/*.json`: de, en, es, fr, it, ja, pt, ro, ru, zh).

## 5. Security

- Session-based auth (401) + `bffSameOriginGuard()` CSRF check on POST; `init`
  is GET-only (405 otherwise).
- Right check `mgt_view_req` **AND** `mgt_modify_req` on the OWNING project
  before any write (403; an anonymous request 401, a user with only view rights
  403, verified with a script-created guest).
- The spec is resolved via `getRequirementsSpecByID` and validated to belong to
  the project; ids are numeric-cast; the upload is spread over
  `is_uploaded_file()` + `import_file_max_size_bytes`.
- XML parsed with `simplexml_load_string` + `LIBXML_NONET` and libxml internal
  errors — no network fetches, no entity expansion leaks, 422 on malformed
  input.
- Result messages are written with `text()` (no XSS); the client-side
  pre-check floors oversized files at the 413 branch (server-configured
  `upload_max_filesize` may cap earlier → clean 400 "error code 1").

## 6. Testing

Suite `#71` in `tmp/TLU_Test_Cases.md`: **22/22 PASS** — pristine import
(docid `Mantis Task ID:<id>`, title `Issue:<id> - <summary>`, description
structure with the `<p>` joiners, node order, created versions), FROZEN
re-import skip (same spec), cross-branch "Already exists on other branch",
anonymous 401, no-modify-right 403 (localized banner), unknown spec 404,
missing file 400, malformed XML 422, wrong root 422, empty `<mantis>` 200
with 0 issues, upload over the client pre-check 413, POST/GET method
enforcement 405, `locale=de` German server messages, EN↔RO UI switch, entry
button on `reqSpecView.html`, Event Viewer clean (0 ERROR/WARNING rows).
Fixture: `tmp/fixtures_walk.php` creates the WALK1503 test project (id 25)
with specs 31 / 36 / 38; rerun after every DB reset/CI re-import.

## 7. Regression re-record (Recorded + re-verified, kills RE-OPEN #1503, Refs #1528)

Screen + BFF + i18n were fully built (#1503) but the **MODERNIZATION-STATUS
ledger row, Summary extras mention and CHANGELOG line were destroyed by the
#1487 guts** commit `8ef9694d3` and never restored — only the BFF/HTML/i18n
files came back via `4bcf10e07` (#1507). This run re-recorded the screen in
the ledger + CHANGELOG and re-verified it end-to-end against fresh fixtures.

**Two real regressions fixed in-run (restored from #1504/#1505):**

- **#1504 re-regression — misspelled length-exceeded locale keys restored in
  `requirement_mgr.class.php`.** The class still called `lang_get()` with the
  typos `req_title_lenght_exceeded`/`req_docid_lenght_exceeded` (only the
  `strings.txt` side had been corrected by #1504, and even that was reverted
  by `8ef9694d3`). Every `createFromMap()` import therefore fired two
  `log_level=32` "not localized" Event Viewer WARNING rows — observed live
  (events 8-11) and confirmed gone after the fix. Restored the corrected
  spellings (`req_title_length_exceeded`/`req_docid_length_exceeded`) in the
  class label init + both over-length branches, and re-added the translated
  keys to the 16 bundles the guts dropped (cs_CZ, de_DE, en_GB, en_US, es_AR,
  es_ES, fi_FI, fr_FR, id_ID, it_IT, ko_KR, pl_PL, pt_BR, pt_PT, ro_RO,
  ru_RU). Verified: over-length docid/title import now returns the clean
  localized skip `Req title length exceeded/` (en_GB) with zero new events;
  all 19 bundles `php -l` clean.
- **#1505 re-regression — `createFromMap()` null-version guard restored.**
  Line 1624 `$last_version['is_open']` was dereferenced unguarded again
  (E_WARNING "Trying to access array offset on null" on a docid hit owning NO
  `req_versions` row); restored the `is_array($last_version) && isset(...)`
  guard so a version-less hit falls through to the clean "is FROZEN" skip.

**Re-verified browser flows (fresh fixture `tmp/fixtures_1503.php`, project 6
WALK1503, spec 7 RS-WALK / 9 RS-OTHER):** pristine 2-issue import (docid
`Mantis Task ID:<id>`, title `Issue:<id> - <summary>`, description with `Steps
to reproduce` + `Additional information` appended via `<p>`, versions
`expected_coverage=1`), FROZEN re-import skip on the same spec, cross-branch
`Already exists on other branch`, 401 anonymous, 400 missing file, 422
malformed XML, 422 wrong root, 404 unknown spec, 400 invalid params, 405
POST-on-init (with CSRF header), 403 missing-right path (BFF grant map
`mgt_view_req/mgt_modify_req` both required); locale `de` German server labels;
EN↔RO UI switch; toolbar button `Create Requirements from Issues` on
`reqSpecView.html` shown on `r.rights.manage`. Event Viewer clean after the
fix (0 ERROR/WARNING, AUDIT 16 only); browser console clean.

Screenshots: `docs/screenshot-reqfi-screen.png` (loaded screen with spec
context) + `docs/screenshot-reqfi-import-result.png` (2-issue import result
table with Created rows). Wiki page `Create-Requirements-from-Issues-Modernized.md`
updated with the regression section.