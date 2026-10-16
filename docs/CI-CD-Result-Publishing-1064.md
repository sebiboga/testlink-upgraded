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

## Refs
- Issue #1064
- Screenshots: see wiki page
