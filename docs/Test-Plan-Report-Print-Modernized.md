# Test Plan Report Print (reportPrint) — Modernized Screen

Modernization of the **Test Plan Report print** output — the standalone
entry point that `testPlanReport.html` (the modernized navigator) previously
opened via the legacy controller `/lib/results/printDocument.php` in a new
window, and which per-build report links pointed at through `lnl.php`. It is
replaced by a new Dashio print popup
(`gui/templates/results/reportPrint.html`) backed by a plain-PHP REST BFF
(`api/reportsprint/index.php`), reusing the exact same battle-tested legacy
document generator so every printed document stays byte-for-byte the classic
TestLink report. GitHub issue
[#845](https://github.com/sebiboga/testlink-upgraded/issues/845).

**URL:** `gui/templates/results/reportPrint.html?type=<testplan|testreport|testreport_onbuild>&level=<testproject|testsuite>&id=<id>&tproject_id=<id>&tplan_id=<id>&format=<n>&build_id=<id>&opts=<urlencoded print opts>`
**BFF API:** `api/reportsprint/index.php?action=print&...` / `?action=download&...`
**Rights:** `testplan_metrics` enforced server-side (same gate as legacy
`checkRights()` in `printDocument.php`). Anonymous/direct public links use the
legacy `apikey` parameters (32-char remote-user script key, 64-char anonymous
testplan/testproject key) — see below.

---
## Table of Contents
1. [What the screen does](#1-what-the-screen-does)
2. [REST API Reference](#2-rest-api-reference)
3. [Anonymous & remote-user apikey access](#3-anonymous--remote-user-apikey-access)
4. [Legacy parity notes](#4-legacy-parity-notes)
5. [i18n Keys](#5-i18n-keys)
6. [Security](#6-security)
7. [Testing](#7-testing)

## 1. What the screen does

| Feature | Legacy behavior | Modern implementation |
|---|---|---|
| Entry point | `testPlanReport.html` opened `/lib/results/printDocument.php` in a new window (full standalone page) | opens `reportPrint.html` (new tab) with the same semantics (type / level / id / tproject_id / tplan_id / format / build_id + print options) |
| Per-build report links | navigator per-build `report_url` used `lnl.php?apikey=...` | `report_url` re-points to `reportPrint.html?type=testreport_onbuild&...&build_id=<bid>&format=0` |
| Document generation | Smarty/PHP rendered a standalone page via `printDocument.php` | BFF `action=print` re-includes the untouched legacy generator and returns the body as JSON; the screen renders it into the popup |
| Print | browser print of the standalone page | **Print** toolbar button issues `window.print()` on the generated document |
| Download | (legacy n/a — opened copies of the standalone page) | **Download** opens the BFF `action=download` endpoint, streaming the generated document as an attachment (`Content-Disposition: attachment; filename="RP8-test_plan-….html"`) |
| Close | close the window | **Close** closes the popup |
| Locale | PHP `$g_lang` | client-side `TLi18n` switcher; keys in `rpt.*` namespace |
| Errors | legacy pages errored out / anonymous apikey path | i18n error banners (not authorized / invalid plan / generation failed) with Print/Download disabled |
| **Public links** | `lnl.php` redirected `printDocument.php?apikey=...` (anonymous & remote-user) to the generated report | **restored**: `lnl.php` now redirects the same URLs to `reportPrint.html?...&apikey=...` and the BFF handles `apikey` — anonymous and remote-user documents generate again (Refs #1408) |

## 2. REST API Reference

The BFF is session-authenticated JSON (safe verb GET, so no Origin guard
needed) **and** — since #1408 — also accepts the legacy `apikey` query
parameter for direct/public access (see section 3).

| Method | Route | Query | Returns |
|---|---|---|---|
| GET | `?action=print` | `type` (`testplan\|testreport\|testreport_onbuild`), `level` (`testproject\|testsuite`), `id`, `tproject_id`, `tplan_id`, `format` (0=HTML), `build_id` (for onbuild), plus print options (`toc`, `headerNumbering`, `header`, `summary`, `body`, `author`, `keyword`, `cfields`, `requirement`) — optional `apikey` | `{status, level, id, doc_type, body_html}` |
| GET | `?action=download` | same params as `print` — optional `apikey` | streamed attachment: `Content-Type: text/html`, `Content-Disposition: attachment; filename="RP8-<doc>-<date>.html"` |

### Error conditions
- Missing/invalid session **and** missing/invalid `apikey` → HTTP 401.
- Unknown `apikey` (64-char anonymous / 32-char remote-user lookup fails) →
  HTTP 401 `Unknown api key`.
- Anonymous `apikey` whose bound context (anonymous testplan/testproject) does
  not match the request's `tproject_id`/`tplan_id` → HTTP 400 `Invalid context
  for api key`.
- No `testplan_metrics` right on the project/plan (remote-user 32-char key) →
  HTTP 403 `No permission`.
- Plan that does not belong to the given test project → HTTP 400 `Invalid test
  plan for this test project`.
- Unknown `action` → HTTP 404 `Unknown action`.

## 3. Anonymous & remote-user apikey access

The legacy apikey flows are fully restored (Refs #1408, mirrors the pattern
already used by `api/reports` #1220 and `api/reportsexport` #1246):

- **Remote-user (32 chars — user `script_key`):** the BFF maps the key to the
  user, applies the same `checkRights`/`testplan_metrics` gate as a logged-in
  user and generates the document in that user's context.
- **Anonymous (64 chars — testplan `api_key`, project `api_key` fallback):** the
  BFF resolves the testplan/the owning testproject by api key, binds the
  document to that context (a mismatched `tproject_id`/`tplan_id` → 400), skips
  the rights check entirely (`isAnon`) and generates the document. Print options
  not supplied by the caller default to the legacy public-link set (header,
  summary, toc, body, passfail, cfields, metrics, notes, numbering, …), exactly
  like `lnl.php?type=test_plan` / `?type=test_report&build_id=` did.
- **Forwarding:** the BFF forwards the `apikey` into the included legacy
  controller, so its own `init_args()` re-runs
  `setUpEnvForRemoteAccess()` / `setUpEnvForAnonymousAccess()` natively.
- **`lnl.php` re-pointing:** `test_plan`, `test_report` and
  `testreport_onbuild` cases now redirect to
  `reportPrint.html?type=...&level=testproject&id=<tpid>&tproject_id=<tpid>&tplan_id=<tplan>&format=0&apikey=...&opts=<urlencoded full flag set>`
  instead of the legacy `lib/results/printDocument.php`.
- **`cfg/reports.cfg.php` re-pointing:** `test_plan`/`test_report` `directLink`
  entries were updated with the numbered sprintf placeholders `%1$s` (basehref),
  `%2$s` (apikey), `%3$s` (tproject_id), `%4$s` (tplan_id) so self-generated
  legacy links keep working.
- The `reportPrint.html` popup reads the `apikey` query parameter
  (URLSearchParams) and forwards it on every `action=print`/`action=download`
  query it builds, so the popup works sessionless after a public-link redirect.

## 4. Legacy parity notes

- The BFF re-includes the **untouched** legacy generator
  `lib/results/printDocument.php` inside an output buffer and returns the
  produced body as JSON — the same strategy used by `api/executionprint`
  (Refs #844) and `api/reqdoc` (Refs #755), so every report format (test plan,
  test report, per-build report) renders exactly as it did in 1.9.20/2.0.1.
- Two scoping/loading gotchas had to be handled (documented in the BFF header):
  - The legacy controller must be `require`d at **TOP-LEVEL script scope** after
    `chdir('lib/results')` (its nested relative requires resolve against CWD, and
    `$tlCfg` must be the global). Including it from a helper function scope makes
    its `cfg/reports.cfg.php` include run where global `$tlCfg` is invisible
    (`Attempt to assign property on null`).
  - The BFF does NOT pre-load `cfg/reports.cfg.php` (the legacy `require`s it
    itself); pre-loading caused `Constant FORMAT_* already defined` E_WARNINGs.
    The BFF uses literal values: `FORMAT_HTML`=0 and plan-based doc types
    (`testplan`, `testreport`, `testreport_onbuild`).
- Extra scoping detail for the apikey branch: for an apikey caller, the legacy
  `init_args()` requires `$db` and session-independent env, so the remote-user
  path calls `setPaths()` before the legacy include when `basehref` is not set,
  and `CHECK_LEVEL`/rights checks are skipped for the anonymous path (the legacy
  controller performs its own rights gate for 32-char keys).

## 5. i18n Keys

All labels are client-side via `TLi18n`; keys under the `rpt.` namespace
(`rpt.header`, `rpt.project`, `rpt.btnPrint`, `rpt.btnDownload`,
`rpt.btnClose`, `rpt.errLoad`, `rpt.empty`). Present in all 10 bundles
(`en ro de es fr it ja pt ru zh`). No new keys were required for the apikey
feature — the BFF error responses are plain-English JSON (`Unknown api key`,
`Invalid context for api key`), consistent with the other report BFFs.

## 6. Security

- Session-authenticated BFF plus legacy apikey support (public links):
  - anonymous 64-char key is bound to its testplan/testproject and the request
    context must match (400 otherwise);
  - remote-user 32-char key inherits that user's rights (`testplan_metrics`
    enforced; a no-rights user → 403);
  - an unknown/invalid apikey → 401 `Unknown api key`.
- Server-side right re-check on every session-authenticated request
  (`testplan_metrics` on the context project/plan); a user without that right
  gets 403 even with a valid session.
- The `id` / `tproject_id` / `tplan_id` / `build_id` values are int-cast and the
  plan is validated as belonging to the project before generation, so a
  hand-crafted bad id returns a clean 400 instead of a fatal.
- Supported `type`/`level` values are white-listed; anything unexpected is
  rejected before generation.

## 7. Testing

See **Suite 845 — Test Plan Report print endpoint** in
`tmp/TLU_Test_Cases.md` (15/15 PASS): whole-plan / one-suite /
testreport_onbuild per-build / testreport generation; download headers; bogus
plan 400; unknown action 404; no-rights user 403; guest-with-right user 200;
navigator "Print whole test plan report" button opens the popup with the proper
`opts`; per-build `report_url` re-pointing; print popup render; locale loads;
Event Viewer clean; console clean.

See **Suite 1408 — reportPrint apikey / public-link anonymous access** in
`tmp/TLU_Test_Cases.md` for the apikey coverage: anonymous 64-char print +
download + onbuild public links render in a sessionless browser context; the
32-char remote-user key renders (admin) and 403s (no-rights user); mismatched
anonymous context → 400; invalid key → 401; `lnl.php` redirects arrive at the
modern popup with the full legacy opts; authenticated navigator flows still
work after the re-pointing.