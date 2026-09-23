# Modernize-Print-Document-Options-printDocOptions (#1570)

The legacy **print preferences navigator popup** — `lib/results/printDocOptions.php`
(+ `gui/templates/dashio/results/printDocOptions.tpl`) — is modernized as a standalone
Dashio popup backed by a REST BFF. The popup is opened by every modernized screen's
**Print / Report** links and lets the user pick the document type, output format,
optional build and the per-type option groups before the report is generated.

## Deliverables

- **Screen:** `gui/templates/results/printDocOptions.html` — Dashio popup: teal
  header ("Print Document Options", TLi18n locale switcher), a **Document type**
  select with the 5 `cfg/reports.cfg.php` `DOC_*` types (Test Specification /
  Requirement Specification / Test Plan Design / Test Report / Test Report on Build),
  a **Show as** format select (HTML / Pseudo MS Word, only when the type has
  `show_format=true`), a context-sensitive **Build** select (only for
  testreport_onbuild), an **Only test cases with user assignment** checkbox
  (testreport_onbuild only), the per-type option groups, a **Check/un-check all
  options** toggle, and two actions: **Print document** (opens the modern renderer
  for the selected type) and **Open print configuration screen**.

### Option groups (parity with `lib/functions/printDocOptions.class.php`)

- **DOCUMENT** (all types): Table of contents, Header numbering.
- **TEST SPECIFICATION** (testspec / testplan / testreport / testreport_onbuild):
  Test suite header, Test case summary, Test case body, Test case author,
  Test case keywords, Custom fields, Requirements linked to test cases.
- **REQUIREMENT SPECIFICATION** (reqspec, 14 opts): scope, author, overwritten
  count reqs, type, custom fields per requirement spec + scope/author/status/type/
  custom fields/relations/linked test cases/coverage/version per requirement.
- **EXECUTION** (testreport / testreport_onbuild, 7 opts): execution results by
  CF on execution combination, notes, step execution notes, pass/fail, step
  execution status, build custom fields, test metrics.

### Print targets (modern renderers, opts forwarded as `?…` pairs)

| Type | Renderer |
|---|---|
| testspec | `gui/templates/testcases/printTestDoc.html` (`level=testproject`, `id`, `format`, `toc`…`requirement`) |
| reqspec | `gui/templates/requirements/printDocument.html` (+ `req_spec_*` / `req_*` prefs) |
| testplan | `reportPrint.html?type=testplan` (Test Plan Design) |
| testreport | `gui/templates/results/reportPrint.html?type=testreport` |
| testreport_onbuild | `reportPrint.html?type=testreport_onbuild&build_id=<bid>` |

**Open print configuration screen** opens `printTestSpec.html` (testspec),
`printReqSpec.html` (reqspec), or the reportPrint configuration page.

## BFF

- **Endpoint:** `api/printoptions/index.php` — `GET ?action=init&type=<doc_type>
  [&tproject_id=N][&tplan_id=M]`, session-based auth + `bffSameOriginGuard`
  (X-Requested-With + same-Origin), JSON I/O.
- **Rights:** `testplan_metrics` (testspec / reqspec / testplan) or
  `testplan_metrics` resolved on the **owning project** for the report types;
  `mgt_view_req` additionally for reqspec.
- **Response:** `groups[]` (id, name, options[] with id/name/checked), `formats[]`
  (id/key, from `$tlCfg->reports_formats`: FORMAT_HTML=0, FORMAT_MSWORD=4),
  `show_format`, `builds[]` (id/name/active, via `getBuildsForTestPlan`), and
  `context` (tproject name, tplan id, requirements-enabled flag, needs_plan).
- **Error contract:** 401 anon / 403 no-rights / 405 non-GET / 400 unknown doc
  type / 400 requirements disabled / 400 no active test plan / 500 guarded.

## Wiring

- `$actions->printDocOptions` in `lib/functions/common.php` → the modern popup
  (`?type=testspec&{ctx}`), used by every modernized screen's Print/Report links.
- Legacy `lib/results/printDocOptions.php` kept as a **session-guarded 302 shim**
  (anon → login, like `bugDelete`/`eventinfo`): forwards `type`/`tplan_id`/`format`
  to the modern popup; `activity=addTC` (legacy navigator mode) →
  `planAddTCView.html?tplan_id=N`.

## i18n

`pdo.*` keys (title, groups, options, build, formats, buttons, footer) +
`pdo.format_html` / `pdo.format_pseudo_msword` (legacy 'HTML' / 'Pseudo MS Word',
ja: 'MS Word 形式') + `footers.printDocOptions` in **all 10 locale bundles**
(validated with `python3 -m json.tool`). No hardcoded strings.

## Bugs found & fixed while testing (this run)

1. **Stray `on=n` in `opts`** — `collectPrefs()` / `toggleAll` used
   `.opt-item input[type=checkbox]` and caught the *Only test cases with user
   assignment* checkbox; scoped to `.opt-group input[type=checkbox]` (`ec0873be1`).
2. **Malformed `activity=addTC` shim URL** (`planAddTCView.html&tplan_id=25`,
   missing `?`) and **no session guard** (anon forwarded straight to the modern
   screen instead of the login) — both fixed (`ae73de468`).
3. **Format select rendered raw keys** (`format_html` / `format_pseudo_msword`)
   because `renderFormats()` called `TLi18n.t(f.key)` on the legacy server-side
   labels; added namespaced `pdo.format_*` keys to all 10 bundles + prefixed the
   lookup (`2714790a3`).

## Verification

- Fresh DB + fixture `tmp/fixtures_1570.php` (project **PDO** id 24, plan
  **PDO Plan** id 25, req spec PRS-PDO id 31 / RQ-PDO-1 id 33, TCs PDO-1/PDO-2,
  builds PDO Build 1 open + PDO Build 2 closed, `norights` role-3 user).
- BFF contract: all 5 doc types 200 + correct option groups; 401 anon /
  405 POST / 403 no-rights (testplan_metrics + mgt_view_req) / 400 bad type,
  req-disabled, no-active-plan.
- Browser (EN + RO): renders, toggle-all both directions, format visibility,
  build row for testreport_onbuild, print opens the modern renderers with the
  applied opts (testspec summary included / reqspec scope shown / on-build with
  build context), config links open printTestSpec/printReqSpec.html.
- Event Viewer: 0 new ERROR/WARNING; console: 0 errors.
- Legacy shim authed 302s + anon → login verified.

## Test suite

Suite 1570 (7/7 PASS) appended to `tmp/TLU_Test_Cases.md`.

## Screenshots

Normal state (testspec) and test-report-on-build variant — PNG capture pending in
this run (MCP screenshot tool timed out). Target files:
`docs/screenshots/issue-1570-printdocoptions-testspec.png` and
`docs/screenshots/issue-1570-printdocoptions-onbuild.png`.