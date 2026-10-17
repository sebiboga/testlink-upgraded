# Release Quality Gates / Go-No-Go Dashboard (Issue #1067)

## Overview
The Release Quality Gates screen provides a consolidated readiness view for a single test plan. It evaluates a set of configurable threshold gates and returns a release verdict: **GO** / **CONDITIONAL GO** / **NO-GO** together with a 0–100 readiness score.

## Scope of Evaluation
- **Execution progress:** executed / assigned (versions assigned to the plan on the selected platform/scope)
- **Pass rate:** passed / executed
- **Failed cases:** count of failed assignments
- **Blocked cases:** count of blocked assignments
- **Unaddressed failures:** failed assignments that have no linked bug evidence in the filtered executions (the "evidence gate")
- **Requirement coverage:** covered requirements / total requirements (only when the test project has requirements enabled; otherwise reported as N/A)

The unit of counting is a test-case version assignment (testplan_tcversions, platform-aware). The "latest result" is the MAX(executions.id) per (tcversion_id, platform_id) on the selected build/platform filter.

## UI Features (Dashio)
- Scope & thresholds panel: Build selector, Platform selector, and numeric thresholds (min progress %, min pass rate %, max failed, max blocked, max unaddressed, min req. coverage %)
- Verdict banner: colored badge/strip with decision and readiness score; gate pass/fail/na counts shown
- Metric tiles: Assigned, Executed, Passed, Failed, Blocked, Not run, Unaddressed failures, Progress, Pass rate
- Report context: Test project, Test plan, Scope, Requirements status
- Gates table: Gate, Value, Target, Status, Blocking (critical gates marked with ◆)
- Requirement coverage panel: total/covered/partial/uncovered and percentage (shown only when enabled)
- Actions: Evaluate (re-runs with current filters/thresholds), Refresh, Export CSV, Close
- Locale-aware: uses TLi18n (keys under `qg.*`) and footer `footers.qualityGate`

## API
- **BFF:** `/api/qualitygate/index.php?action=init&tplan_id=N[&tproject_id=P][&build_id=B][&platform_id=PL][&progress_min=...][&pass_rate_min=...][&max_failed=...][&max_blocked=...][&max_unaddressed=...][&req_coverage_min=...]`
- Security: session auth + `bffSameOriginGuard` + `bffEnforceSession`; right `testplan_metrics` on owning project; all IDs are positive integers. Responses: 200 ok, 400 invalid_request, 401 not_authenticated/session_expired, 403 no_right, 404 tplan_not_found/project_mismatch, 405 method_not_allowed.

## Verification
Verified on `tmp/fixtures_1067.php` (project `QG1067` / plan `QG Release`; platforms Android/iOS; 1 open build; 7 assignments = 3 pass / 2 fail, one addressed through `BUG-1067`, 1 blocked, 1 not run; 3 requirements, 1 covered):
- Default thresholds → **NO-GO**, score 29 (progress 85.7%, pass rate 50%, 1 unaddressed failure, coverage 33.3%).
- Platform = iOS → **CONDITIONAL GO**, score 83 (1 assigned / 1 executed / 1 passed).
- Relaxed thresholds → **GO**, score 100.
- Empty plan → **NO-GO**, score 50 (total 0).
- Guard matrix: 400 `invalid_request`, 404 `tplan_not_found`, 404 `project_mismatch`, 401 `not_authenticated`, 403 `no_right`, 405 `method_not_allowed`.
- Export CSV: UTF-8 BOM, `;`-separated, localized headers, 6 gate rows + verdict/score line.
- Event Viewer clean (no Error/Warning attributed to this screen/BFF).

## Refs
- Issue #1067
- Screenshots: `docs/screenshots/issue-1067-quality-gate-nogo.png`, `issue-1067-quality-gate-go.png`, `issue-1067-quality-gate-ro.png` (also on the wiki page)
