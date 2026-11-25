# Create Requirements from Mantis Issue XML — Modernized (TestLink 2.0.1, Refs #1960)

The orphaned 1.9.20 renderer `lib/requirements/reqCreateFromIssueMantisXML.php`
(and `gui/templates/dashio/requirements/reqCreateFromIssueMantisXML.tpl`) has
been replaced by a standalone Dashio screen and a session-guarded REST BFF.
Tracking issue: [#1960](https://github.com/sebiboga/testlink-upgraded/issues/1960).

## Why this screen

The requirement-side Mantis importer was still a full Smarty page reachable
only by direct URL / stale bookmarks; unlike the sibling test-case importer it
had no modern twin. It also instantiated `dBug()` twice per request and omitted
`lib/functions/requirements.inc.php`, which fatals once internal links are
enabled. The modern screen keeps the feature (one requirement per `<issue>`)
and closes those defects.

## Modern stack

| Layer | File |
|---|---|
| Screen | `gui/templates/requirements/reqMantisImport.html` (Dashio HTML + JS + CSS) |
| BFF | `api/reqmantisimport/index.php` — stable alias delegating in-process to the shared engine `api/reqfromissues/index.php` (Refs #1503) |
| Engine | `requirement_mgr::createFromMap()` with the legacy Mantis mapping and duplicate handling |
| Entry point | `createFromMantisLink` on `gui/templates/requirements/reqSpecView.html`, gated on `mgt_modify_req`; `$actions->reqMantisImport` in `lib/functions/common.php` |
| Legacy shim | `lib/requirements/reqCreateFromIssueMantisXML.php` — GET/HEAD → HTTP 302 to the modern screen, write verbs → 405 `modern_endpoint_only` |
| i18n | `rqmi.*` + `footers.reqMantisImport` in all 10 client bundles; server-side `issue_*`, `TestProject` and requirement-import result labels in the Romanian bundle |

## Behaviour

- **Init** (`GET /api/reqmantisimport/index.php?action=init&req_spec_id=N`):
  resolves the specification node and its owning project, returns the project
  / spec path, upload limit, field-size limits, grant map and localized issue
  field labels.
- **Import** (`POST ?action=import`, multipart `uploadedFile`): accepts a
  Mantis `<mantis>`/`<issue>` XML export, enforces the configured upload cap,
  and creates one requirement per issue directly under the target spec:
  `docid = Mantis Task ID:<id>`, `title = Issue/Task:<id> - <summary>`,
  description = description + steps to reproduce + additional information
  joined with `<p>`. Duplicate names/docids are handled by the legacy engine
  (same-branch skip, other-branch message, FROZEN requirement skip).
- **Screen**: project/spec context, locale switcher, max-size hint, dropzone /
  file picker, mapping note, import button with busy state, result table
  (doc id / requirement / result) and localized success/failure feedback.
  Error cards cover 400 / 401 / 403 / 404 / 405 without rendering the form.

## Security

`bffSameOriginGuard()` and `bffEnforceSession()` run before any action; rights
`mgt_view_req` **and** `mgt_modify_req` are checked on the project that owns
the resolved spec, not on a client-supplied `tproject_id`. XML is parsed with
`LIBXML_NONET`, the root element must be `<mantis>`, uploads use
`is_uploaded_file()` and a size cap, and the client renders all server strings
with `.text()`. The legacy shim is non-mutating and sends a real HTTP 302 with
a sanitized sub-directory base path.

## Verification

Fixture `tmp/fixtures_1960.php` creates the RQMI1960 project, two specs and a
role-3 user; `tmp/fixtures_1960_mantis.xml` is the two-issue sample export.
Suite `Issue #1960` in `tmp/TLU_Test_Cases.md` covers init, first import,
duplicate/FROZEN handling, EN/RO rendering, no-right and anonymous paths, the
BFF error contract, the legacy shim's 302/405 contract, idle-session expiry,
Event Viewer hygiene and the i18n/test-suite gates. Three defects found in the
resumption pass were fixed and closed: #2064 (JS-only shim redirect), #2065
(missing idle-session guard) and #2066 (missing Romanian server labels).
