# ISTQB Compliance Review — TestLink 2.0.1 Coverage Matrix

**Issue:** [#1054](https://github.com/sebiboga/testlink-upgraded/issues/1054)
**Type:** investigation (documentation + backlog triage — no application code changes)
**Date:** 2026-09-09
**Status:** VERIFIED-FIXED

This review maps the ISTQB (International Software Testing Qualifications Board)
fundamental testing process and supporting concepts against the features actually
implemented in this TestLink 2.0.1 modernization. Every "Full"/"Partial" claim is
grounded in a real repository artifact (API area, `docs/` page, modernized screen)
so a developer can open the cited source to confirm.

---

## 1. ISTQB context

ISTQB Foundation Level describes a **fundamental test process** with the phases:
test planning & control → test analysis & design → test implementation & execution
→ evaluating exit criteria & reporting → test closure activities. Around it sit
supporting concepts: test strategy, test levels, test types, risk-based testing,
static testing (reviews), traceability, and metrics/reporting.

TestLink 1.9.20 (and this modernization of it) was architected primarily as a
**test-case execution + reporting** tool. The review below shows which ISTQB
concepts are fully, partially, or not at all represented in the product's data
model and screens.

---

## 2. ISTQB ↔ TestLink coverage matrix

Legend: **Full** = first-class, dedicated feature · **Partial** = possible but
without a dedicated ISTQB-first model · **Missing** = no representation.

| # | ISTQB concept | Current TestLink support | Coverage | Evidence (repo) | Notes |
|---|---|---|---|---|---|
| 1 | **Test strategy** | No explicit strategy model (no global vs per-level strategy doc/object in schema, no strategy field) | **Missing** | scan of `lib/` + `api/` found no strategy entity | Can be approximated by free-text docs/custom fields only |
| 2 | **Test planning** | Test Plan object with scope, status, active/archive, milestones, assigned builds/platforms | **Partial** | `api/plans/`, `docs/Test-Plan-Management.md`, `WIKI-TEST-PLAN-MILESTONES.md`, `api/milestones/` | Plan scheduling/timeline, owner, resources and effort estimation absent |
| 3 | **Test analysis** | Requirement specs (ReqSpec + Requirement with versions) and review of requirement coverage | **Partial** | `api/requirements/`, `api/reqspec/`, `api/reqcompare/`, `docs/Requirement-Editor-Modernized.md`, `WIKI-REQUIREMENT-SPEC-MANAGEMENT.md` | No dedicated "test conditions" artifact separate from test cases |
| 4 | **Test design** | Test Case editor: steps, preconditions, expected results, importance/urgency (priority), keywords, custom fields | **Partial** | `api/testcasesedit/`, `api/testcases/`, `docs/Test-Case-Editor-Modernized.md`, `docs/Test-Case-Bulk-Operation-Modernized.md` | No support for equivalence partitioning/BVA modelling tooling; TC is the unit |
| 5 | **Test implementation** | Assign TC to plan, execution assignment to testers, builds, platforms, priorities | **Full** | `api/tcassign2tplan/`, `api/execassignment/`, `api/builds/`, `api/platforms/`, `docs/Test-Case-Assign-to-Test-Plan-Modernized.md` | Strong, first-class implementation flow |
| 6 | **Test execution** | Execute test case, record result per step, execution type, notes, attachments, execution history, set batch results | **Full** | `api/execute/`, `api/executionedit/`, `api/execsetresults/`, `api/executeexport/`, `docs/Assign-Test-Case-Execution-Modernized.md` | Core strength of the product |
| 7 | **Test evaluation / reporting** | Results by status/tester/suite/issue, TC-flat, absolute-latest, metrics dashboard, test-result matrix, baselines, charts | **Full** | `api/results/`, `api/metrics/`, `api/reports/`, `docs/Results-By-Status-Modernized.md`, `docs/Reports-Test-Result-Matrix.md`, `docs/General-Test-Plan-Metrics.md`, `docs/Execution-Timeline-Statistics-Modernized.md` | Rich reporting/reporting BFF. **Exit-criteria evaluation** (pass% thresholds) is Manual, not enforced |
| 8 | **Test closure** | No dedicated closure workflow: no lessons-learned, retrospective, closure report or closure checklist | **Missing** | grep of `lib/`+`api/` for `lesson|closure|retrospective` → no entity | Should be a backlog `task` |
| 9 | **Test levels** (component/integration/system/acceptance) | No level taxonomy field on plan/TC; only a top-level suite hierarchy | **Missing** | no `test.level`/`testtype` field in schema | Could be simulated with suite naming/CF |
| 10 | **Test types** (functional / non-functional: performance, security, usability…) | No structured type taxonomy; **keyword** tagging is the only free-form proxy | **Partial** | `api/keywords/`, `docs/WIKI-KEYWORDS-ASSIGN.md`, `docs/WIKI-KEYWORDS-SI-TAGS.md` | Keywords allow classification but not an enforced ISTQB type model |
| 11 | **Risk-based testing** | Priority computed from importance × urgency (`lib/functions/testcase.class.php:4688,6826`), filters per plan | **Partial** | `lib/functions/testcase.class.php` priority calc; plan-level priority filters | No likelihood/impact (product-risk) model, no risk-priority chart, no risk-based selection automation |
| 12 | **Static testing (reviews)** | No review workflow for Test Cases, Requirements or Specs (no reviewer/review-status entity, no review checklist) | **Missing** | scan of `lib/`+`api/` found no review/reviewer entity | Should be a backlog `task` |
| 13 | **Traceability** | Requirement ↔ Test Case coverage (covered/uncovered), requirement version compare, TC ↔ issue-tracker linkage, keyword tagging | **Full** | `api/reqcompare/`, `docs/Requirements-Coverage-Modernized.md`, `docs/Requirement-Management-Systems-Modernized.md`, `lib/requirements/reqView.php`, `lib/results/uncoveredTestCases.php` | Req↔TC traceability is a real strength; **traceability to quality objectives/risks** is the missing axis |
| 14 | **Metrics & reporting** | Metrics dashboard, per-status/per-tester/per-suite/per-build, never-run, without-tester, TCs-created-per-user, timeline statistics, baselines | **Full** | `api/metrics/`, `docs/Test-Cases-Never-Run.md`, `docs/Test-Cases-Without-Tester.md`, `docs/Baselines-L1-L2.md` | Strong; lacks pre-defined ISTQB-style exit-criteria dashboards and defect-density vs risk |
| 15 | **Defect management** | Issue tracker integration (Jira/Bugzilla/Redmine/GitHub…) and "results by issues" report | **Full** | `api/trackers/`, `api/codetracker/`, `docs/Feature-433-Native-GitHub-Code-Tracker.md`, `docs/Results-By-Issues-Modernized.md` | External BTS linkage covers defect tracking |

### Coverage summary

| Coverage | Count | Concepts |
|---|---|---|
| **Full** | 6 | Test implementation, execution, evaluation/reporting, traceability (req↔TC), metrics, defect mgmt |
| **Partial** | 4 | Test planning, analysis, design, risk-based (priority proxy), test types (keywords) |
| **Missing** | 5 | Test strategy, closure, test levels, static testing/review, quality-objective traceability |

---

## 3. Gap list (prioritized backlog candidates)

| Priority | ISTQB concept | Gap | Suggested backlog item |
|---|---|---|---|
| High | **Test closure** | No lessons-learned / closure / retrospective workflow | `task`: Closure report + lessons-learned module |
| High | **Risk-based testing** | No structured risk model (likelihood/impact/product-risk) | `task`: Risk-based testing model + risk-coverage view |
| High | **Traceability to quality objectives/risks** | Req↔TC exists; objective/risk traceability doesn't | `task`: Quality-objective & risk traceability matrix |
| Medium | **Static testing / reviews** | No review workflow for test cases/requirements/specs | `task`: Peer-review workflow for test cases & requirements |
| Medium | **Test strategy** | No strategy artifact | `task`: Test strategy document object linked to plans |
| Medium | **Test levels & types** | No level/type taxonomy | `task`: Enforced test-level & test-type classification |
| Low | **Exit-criteria enforcement** | Pass% thresholds not enforced in evaluation | `task`: Configurable exit-criteria thresholds on test plans |
| Low | **Test planning depth** | No timeline/resource/effort estimation on plans | `task`: Plan scheduling + effort estimation |

---

## 4. Backlog items filed

The gaps above are filed as separate, self-contained `task`-labeled GitHub issues
so the CI factory (`implement-task-*`) can pick them up independently. Each
references this review (`Refs #1054`):

| Issue | Gap addressed |
|---|---|
| [#1277](https://github.com/sebiboga/testlink-upgraded/issues/1277) | Risk-based testing model (likelihood/impact + risk-coverage view) |
| [#1278](https://github.com/sebiboga/testlink-upgraded/issues/1278) | Test closure module (lessons-learned + closure report) |
| [#1279](https://github.com/sebiboga/testlink-upgraded/issues/1279) | Test review / static-testing workflow for test cases & requirements |
| [#1280](https://github.com/sebiboga/testlink-upgraded/issues/1280) | Quality-objective & risk traceability matrix |

The lower-priority gaps (test strategy artifact, test levels/types taxonomy,
exit-criteria thresholds, plan scheduling/estimation) are captured in §3 and may
be filed as further `task` issues when capacity allows.

---

## 5. Recommendation / roadmap

To progress TestLink from an **execution + reporting** tool toward a fuller
ISTQB-aligned platform, prioritize in this order:

1. **Risk-based testing model** (highest value for prioritization) — reuses the
   existing importance×urgency priority hook.
2. **Traceability to quality objectives & risks** — extends the existing strong
   req↔TC traceability.
3. **Test closure / lessons-learned** — completes the ISTQB lifecycle.
4. **Static testing / review workflow** — supports earlier-SDLC activities.
5. **Test levels/types taxonomy + test strategy artifact** — formalizes planning
   vocabulary.
6. **Exit-criteria thresholds** — makes evaluation actionable.

---

## 6. Validation

Because this issue changes no application code, the "regression" check is that
every Full/Partial claim in §2 points to a real repository artifact. Verification
was performed by:
- opening the cited `api/<area>/` directory for each Full/Partial row;
- opening the cited `docs/*.md` page for each Full/Partial row;
- grep-scanning `lib/` + `api/` for the **Missing** concepts (strategy, level,
  type, risk, review, closure) and confirming no dedicated entity exists.

No `Event Viewer` check is applicable (no runtime code executed). No security
vectors introduced.
