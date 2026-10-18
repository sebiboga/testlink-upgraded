# CI/CD Automated Result Publishing (Issue #1064)

## Overview
The CI/CD Result Publishing screen accepts the result artifact produced by an external CI/CD pipeline (JUnit XML, or a flat JSON equivalent) and publishes it into the TestLink execution store for a chosen test plan / build / platform. It is the automated counterpart of the "Import Results" screen: instead of hand-authored TestLink-XML dialect files, the ubiquitous JUnit XML that GitHub Actions / GitLab CI / Jenkins / Azure DevOps generate is ingested directly.

## Scope of Behaviour
- **Artifact formats:** `junit` (JUnit XML, accepted roots `testsuites` / `testsuite`, `//testcase` selection, `failure` / `error` / `skipped` -> status tokens) and `json` (flat list of `{name, classname, external_id, status, duration, message}` under keys `tests` / `testcases` / `results` or a bare array).
- **Resolution:** each test is matched to a test-case version assigned to the plan on the owning project - by the external id first (numeric suffix of `PREFIX-123` or bare number, joined through `testplan_tcversions` + `tcversions.tc_external_id`), then by the test-case name via the plan assignment's parent node.
- **Write:** one `executions` row per resolved test (status mapped from `cfg/results/status_code`, execution_type from the assignment), with optional execution duration from the JUnit `time` attribute.
- **Skip / error:** unresolvable tests are reported with outcome `not_found` (skipped, no execution); insert failures are outcome `error` (errors count).

## UI Features (Dashio)
- Context/scope panel: Test plan selector, Build selector, Platform selector, Test project context line
- Publish panel: Format selector (JUnit XML / JSON), results-file picker, Publish results button, publish spinner, max file size hint
- Pipeline integration guide: per-connector cards (GitHub Actions, GitLab CI, Jenkins, Azure DevOps, Generic CLI/API) with copy-paste snippets and the supported report format chips
- Result tiles: total / published / skipped / errors
- Result table: Test, Class / suite, External ID, Result (status badge), Outcome (badge: published / not_found / error)
- State cards: not_authenticated, session_expired, no_right, tplan_not_found, project_mismatch, invalid_request, unsupported_format, missing_file, file_too_large, parse_error, server_error - each with the localized title/body and raw message code.

## API
- **BFF:** `/api/cicdpublish/index.php?action=init[&tproject_id=P][&tplan_id=N]` and `POST ?action=publish` (multipart: `tplan_id`, `build_id`, optional `platform_id`, `format`, `uploadedFile`).
- Security: session auth + `bffSameOriginGuard` + `bffEnforceSession`; right `testplan_execute` on the owning project (write gate, same as the execution screens); asserting `tproject_id` is compared against the owning project only (no project-existence oracle); all IDs strictly validated positive integers; upload size-capped; XML parsed with `LIBXML_NONET`.

## Session 2 — finishing pass (tracking issue #1940, 2026-10-18)
- **Bugfix `53a3065c3`:** every `no_right` denial was written to the Event Viewer as an ERROR row; both `tLog(..., 'ERROR')` calls now log `AUDIT` (`log_level=16`). Post-fix denial events: ids 11/12 (curl), 15 (browser); no new Error/Warning rows after the fix.
- **Mandatory code review (1 BLOCKER + 4 SHOULD-FIX + 3 NIT), applied in `109c3865f`:**
  - BLOCKER — all five pipeline snippets were copy-paste broken: they sent no `Origin`/`Referer`/`X-Requested-With`, so `bffSameOriginGuard` answered 403 on every real pipeline call. Each snippet now carries `-H "X-Requested-With: XMLHttpRequest"` (properly indented).
  - DataTables filter label was hardcoded English — now `common.search` / `common.noData` via `language:`, and the search input gets `id/name=tblSearch`.
  - A JSON entry with no `status` silently published a *pass* — default is now `n` (not run).
  - `cfg/results/status_code` can map `unknown` → `u`, outside the `p/f/b/n` executions domain — mapped codes are now clamped with an `n` fallback.
  - `copySnippet()` toasted "Snippet copied" even when `navigator.clipboard.writeText` rejected — it now falls back to `execCommand` and toasts `common.error` only on real failure.
  - Publish success clears the file input (no accidental double-publish) and writes an AUDIT `events` row (`published N of M … build/plan`).
- **Browser-pass fixes:** all 5 labels gained `for=` and the selects/file input `name=` (both Chrome a11y issues gone — console now zero messages); Refresh no longer leaves stale tiles/rows in the DOM (`load()` destroys the table and empties `#tiles`/`#tbl tbody`); `clearHeaderAria()` was dead code and is now wired at both call sites.
- **Verification:** JUnit publish via screen 4/3/1/0; JSON publish 1/1/0/0; edge JSON (`status:"unknown"` + missing status) both clamp/default to `n`; client guards (`Select a build first.` / `Choose a results file to publish.`); copy toast; EN↔RO; anonymous → `login.php?note=expired`; role-3 → 403 `no_right` state card; Event Viewer clean post-fix; gates: suite `TLU_REQUIRE_SUITE="Issue #1064"` 34/34 PASS, i18n 9/9 PASS (8714 keys).

## Refs
- Issue #1064 (parent feature), tracking issue #1940
- Screenshots: see wiki page
