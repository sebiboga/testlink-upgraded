# Create Test Cases from Issues (Mantis XML) — Modernized Screen

Modernization of **Create Test Cases from Issue XML (Mantis)** import screen
(`lib/testcases/tcCreateFromIssueMantisXML.php`) — GitHub issue
[#1502](https://github.com/sebiboga/testlink-upgraded/issues/1502).

This was the last standalone legacy import screen in `lib/testcases/` not yet
ported: it uploads a Mantis bug-tracker XML export and creates one test case
per `<issue>`. The legacy Smarty screen is replaced by a standalone Dashio page
(`gui/templates/testcases/tcCreateFromIssues.html`) backed by a plain-PHP REST
BFF (`api/tccreatefromissues/index.php`, session-based auth).

**Path:** `<Test Case Design> → Create Test Cases from Issues XML` in the ASIDE
(visible when `modify_tc` on the active project), link switch
`$actions->tcCreateFromIssues` in `lib/functions/common.php`
**URL:** `gui/templates/testcases/tcCreateFromIssues.html?tproject_id=<id>[&containerID=<suite-or-project id>]`
**BFF API:** `api/tccreatefromissues/index.php` (`GET ?action=init`, `POST ?action=import`)
**Right:** `mgt_modify_tc` on the target test project (403 otherwise)

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
| Entry point | button in `containerView.tpl` (`lib/testcases/tcCreateFromIssueMantisXML.php?containerID=`) | ASIDE sub-item under Test Case Design (gated `modify_tc`) + `$actions->tcCreateFromIssues` link switch |
| Source format | Mantis bug-tracker XML export, root `<mantis>`, one `<issue>` per bug | same; validated (`LIBXML_NONET`, well-formedness, root tag check → 422) |
| Name per issue | `Issue:<id> - <summary>` (`issue_issue` label) | identical server-side build, labels localized via client locale hint |
| Summary | `issue_description` label + description, plus `issue_steps_to_reproduce` / `issue_additional_information` when present, joined with `<p>` | identical |
| External id / type | `tc_external_id` = bug id, execution MANUAL, importance MEDIUM | identical |
| Duplicate external id | skip + "You are hitting an existent Test Case with SAME EXTERNAL ID" + full suite path | identical (path uses `get_path` + `prefix + glue + externalid`) |
| Duplicate name | `check_duplicate_name` on with `action_on_duplicate_name = null` (auto rename path) | identical |
| Name too long | warning block + truncated name | identical (`field_size.testcase_name`, 80% truncation) |
| Upload cap | `import_file_max_size_bytes` config | identical (413 over limit; 400 on missing file) |
| Result feedback | server-side result-map table after submit | live result table (name + message), issues-found count, feedback bar, toast |
| Container | any `containerID` param | validated to belong to the test project (walk to project root), project itself allowed |

## 2. REST API Reference

All routes require an authenticated session (401 when anonymous). `locale`
parameters accept `en`, `ro`, … 5-char codes; project rights are checked
against the requested `tproject_id`.

| Method & path | Purpose | Failure modes |
|---|---|---|
| `GET ?action=init&tproject_id=N[&containerID=N][&locale=xx_YY]` | context: tproject name, resolved container (project or suite), `maxUploadBytes`, grant map, localized `issue_*` labels | 400 invalid/missing tproject or foreign container, 404 unknown project, 401 anon |
| `POST ?action=import&tproject_id=N&containerID=N&locale=…`, multipart field `uploadedFile` | parse `<mantis>` export, create the TCs, return per-row result map | 400 no file, 403 no right, 405 non-POST, 413 over size cap, 422 invalid XML/root, 500 guarded DB error |

## 3. Legacy parity notes

- The legacy controller is fully ported: same `issue_*` label keys, same
  `<p>` joiners, same DUPLICATE external-id owner check (`getInternalID` +
  suite path via `tree_manager->get_path`), same name-length warning block,
  same `testcase::create()` call with `check_duplicate_name` +
  `action_on_duplicate_name = null` + `external_id` in options.
- Labels are generated server-side through `init_labels()` exactly like the
  legacy `saveImportedTCData`; the BFF honours the client `locale` hint the
  same way the legacy used the session locale (verified French labels:
  `Anomalie/Tache:104 - …`, `Étapes pour reproduire`).
- No keywords/custom fields/requirements in a bug-tracker export — the legacy
  branches for those data sources were out of scope of this screen.
- Two documented deviations from the legacy byte-for-byte behavior (both
  improvements): (1) `tc_external_id` is set to the bug id — the legacy
  hardcoded `externalid => null`, so reimporting the same file into another
  suite is now correctly blocked (`hit_with_same_external_ID`) instead of
  silently creating duplicates with null external ids; (2) values are trimmed
  and the `steps_to_reproduce` / `additional_information` sections are omitted
  when empty (the legacy appended the label even for empty elements).

## 4. i18n Keys

All labels/messages use the client-side `TLi18n` module; keys `tcfi.title`,
`tcfi.headerSub`, `tcfi.ctxProject`, `tcfi.ctxContainer`, `tcfi.uploadTitle`,
`tcfi.dropHint`, `tcfi.sizeHint`, `tcfi.mappingTitle`, `tcfi.mappingBody`,
`tcfi.uploadBtn`, `tcfi.resultTitle`, `tcfi.issuesCount`, `tcfi.emptyResult`,
`tcfi.colName`, `tcfi.colMessage`, `tcfi.errNoPermission`, `tcfi.errLoad`,
`tcfi.noFile`, `tcfi.importing`, `tcfi.importFail`, `tcfi.importOk`,
`tcfi.tcRows`, `tcfi.fileSelected`, `tcfi.suite`, `footers.tcCreateFromIssues`
are defined in ALL locale bundles (`gui/templates/i18n/*.json`: de, en, es,
fr, it, ja, pt, ro, ru, zh). The ASIDE label `href_tc_create_from_issues`
was added to `labels.aside.tpl` + all 19 locale `strings.txt` files.

## 5. Security

- Session-based auth (401) + `bffSameOriginGuard()` CSRF check on POST.
- Right check `mgt_modify_tc` on the ACTUAL requested project before any
  write (403).
- Container membership validated against the project tree (no cross-project
  imports).
- XML parsed with `simplexml_load_string` + `LIBXML_NONET` and `libxml`
  internal errors — no network fetches, no entity expansion leaks, 422 on
  malformed input; upload capped at `import_file_max_size_bytes`.
- All ids numeric-cast; result messages written with `text()` (no XSS).

## 6. Testing

Suite `#1502` in `tmp/TLU_Test_Cases.md`: 19/19 PASS — pristine import (name,
summary structure, external id, MANUAL/medium, node order), container =
project, foreign-container 400, unknown-project 404, duplicate external-id
block with the legacy path message, malformed XML 422, wrong root 422,
missing file 400, POST enforcement 405, French server-label localization,
ASIDE link + label, EN↔RO locale switch (plus RO rerender), Event Viewer
clean (0 ERROR/WARNING rows). Fixture: `tmp/fixtures_1502.php`.