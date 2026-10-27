# Requirement Spec Coverage Summary (reqSpecCoverage) — Modernized

**Refs:** #1965 (tracking) · Reports area · net-new for 2.0.1
**Screen:** `gui/templates/results/reqSpecCoverage.html`
**BFF:** `api/reqspeccoverage/index.php`
**Wiring:** `cfg/reports.cfg.php` (`req_spec_coverage`) · `lib/functions/common.php` (`$actions->reqSpecCoverage`)

## What the screen does

A plan-scoped coverage rollup **grouped by requirement specification**. Legacy
1.9.20 had the requirement-centric Requirements Traceability Matrix and the
Requirements based Report, but no report that answered "which requirements
specifications are still uncovered in this plan?". #1965 adds that view on top
of the same RTM coverage semantics.

- **Filters** for platform and build; the build filter re-evaluates the latest
  execution of every linked test-case version. `[Any]` keeps the latest result
  across builds/platforms.
- **KPI row**: requirement specs, requirements, coverage %, gap requirements,
  orphan requirements, plan test cases, orphan test cases.
- **Verdict badges**: covered / partial / uncovered / orphan requirements, plus
  the traceability line "{total} requirements · {linked} of {plan} plan test
  cases traceable".
- **DataTable** per requirement specification: document id and title,
  requirements, covered / partial / uncovered / orphan requirement counts, plan
  test cases, executed / passed / failed / blocked, defect count, coverage %
  with progress bar and a gap counter.
- **Gap drill-down modal** per specification: every requirement that is not
  fully covered, with doc id, title, type, expected coverage, linked test
  cases (latest status per test case), coverage verdict and defect count.
- **Orphan test cases panel**: plan test cases with no `req_coverage` link.
- **CSV export** of the specification table (UTF-8 BOM, semicolon separator,
  localized headers and values).
- **Locale switcher** using the shared `TLi18n` module; all labels and messages
  come from the `rscov.*` / `rtm.*` keys in every client bundle.
- **State cards** for requirements-disabled projects, unknown/mismatched plans,
  missing rights, expired sessions and generic failures.

## Coverage semantics

A requirement is:

- **uncovered** when none of its linked test cases is assigned to the plan, or
  none has been executed on the selected build/platform;
- **covered** when every plan-assigned linked test case has been executed and
  its latest result is `pass`;
- **partial** otherwise (executed, but a fail/blocked result is present).

A requirement with zero `req_coverage` links is additionally flagged as
**orphan**. A plan-assigned test case with no `req_coverage` row is listed as an
**orphan test case**. Defect counts follow the same build/platform filter.

## BFF contract

File: `api/reqspeccoverage/index.php` — read-only JSON, no Smarty.

| Method | Route | Purpose |
|---|---|---|
| `GET`/`HEAD` | `?action=context[&tplan_id=N][&tproject_id=M]` | Plan/project names, builds, platforms, requirements-enabled flag |
| `GET`/`HEAD` | `?action=report[&tplan_id=N][&build_id=B][&platform_id=P]` | Summary counters, per-spec rows, gap rows and orphan test cases |
| `GET`/`HEAD` | `?action=matrix…` | Alias of `report` kept for link compatibility |

- Session auth (`bffEnforceSession`) plus the shared `bffSameOriginGuard`.
- The entry point is a plan: the owning project is proved from `tplan_id`.
  `tproject_id` is only a hint and a mismatch answers `404 project_mismatch`
  (no cross-project oracle).
- Requires `testplan_metrics` on the owning project (same gate as the legacy
  Requirement Coverage report and the Results navigator), checked before the
  `tproject_id` assertion (plan existence is resolved first, matching the RTM
  convention).
- Responses: `200` for data and requirements-disabled; `400 invalid_request`;
  `401 not_authenticated` / `session_expired`; `403 no_right`;
  `404 tplan_not_found` / `project_mismatch`; `405 method_not_allowed`.
- Unknown actions are rejected with `400`; write verbs never reach the handler.

## Fixes found during this screen's verification

- `summary.plan_linked_tcs` was overwritten with 0 by a merge artifact and then
  re-counted per requirement link. The BFF now keeps one distinct set
  (`$linkedPlanTcvsSummary`) so the traceability counter is the number of
  distinct plan test-case versions linked to any requirement (5 of 6 in the
  fixture, was 0 and then inflated).
- Per-spec `executed` counted plan assignments with **no execution**. It now
  only counts test cases whose latest execution is non-empty, so it cannot
  exceed `plan_tcversions` (Alpha 3, not 4). At requirement level, executions
  of linked versions no longer assigned to the plan are ignored as well.
- The orphan-test-case query listed one row per platform assignment; it now
  groups by `tcversion_id`, so a version assigned to N platforms is counted
  once and `orphan_tcs` cannot exceed `plan_tcs`.
- `HEAD` is accepted alongside `GET` (write verbs still answer `405`).
- The #1969 distinct-`tcversion_id` dedupe is preserved for shared test cases
  linked to multiple requirements.

## Verification

- Suite `Issue #1965` = 12/12 PASS in `tmp/TLU_Test_Cases.md` (render and
  counters, build filter, gap modal, DataTable controls, CSV, Română,
  requirements-disabled, unknown/mismatch plans, auth/rights, method/CSRF
  contract, counter regressions, wiring/i18n/Event Viewer).
- `bash ai/verify_i18n_coverage.sh`: 9/9 bundles, 0 missing keys (46 `rscov.*`
  keys per bundle).
- BFF matrix: anonymous 401, missing plan 400, unknown plan 404, mismatch 404,
  unknown action 400, POST without same-origin proof 403, POST with origin 405,
  role-3 user 403 `no_right`.
- Event Viewer: only INFO/AUDIT rows after the pass (0 Error/Warning).
- Fixture: `tmp/fixtures_1965.php` (project RSC1965, plan R65 Plan, builds
  R65 Build 1/2, specs RS-R65A/B, 6 test cases incl. one untraced, executions
  and a defect, role-3 user `rscnorights`).
