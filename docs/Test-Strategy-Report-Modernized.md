# Test Strategy Report — Modernized Screen

A net-new, project-scoped **Test Strategy Report**: a generated strategy document
assembled entirely from the project's existing artifacts (scope, test types &
levels, environments, tooling, team, related plans and computed readiness), rendered
in the Dashio look & feel and exportable to Markdown, HTML or paper.

1.9.20 shipped only a *static* Test Strategy reference hub (the `strategy/*.html`
chapters) — it never generated a project-specific strategy document. This screen is
the requested enhancement (`#1061`), implemented as a standalone HTML+JS page backed
by a plain-PHP REST BFF.

**Path:** ASIDE menu → Reports → *Test Strategy Report*
**URL:** `gui/templates/results/strategyReport.html?tproject_id=<id>[&tplan_id=<id>]`
**BFF API:** `api/strategyreport/index.php?action=init&tproject_id=<id>`
**Rights:** `testplan_metrics` on the **owning** project (opaque 404 for a missing
project, before the right probe).
**Tracking issue:** [#1925](https://github.com/sebiboga/testlink-upgraded/issues/1925)
(enhancement [#1061](https://github.com/sebiboga/testlink-upgraded/issues/1061)).

---

## Overview

The screen is a single report document with a context header (project name + prefix +
generation timestamp) and ten panels:

| # | Panel | Content |
|---|---|---|
| — | **Project context** | status, visibility, notes, enabled features (requirements / priority / automation / issue tracker / code tracker) |
| 1 | **Quality objectives & scope** | test cases, suites, top-level suites, requirements, requirement specs, test plans, builds, platforms, keywords, custom fields + a generated scope sentence |
| 2 | **Test types & approaches** | manual vs automated cases, automation coverage %, high/medium/low priority counts; the automation paragraph only renders when automation is enabled |
| 3 | **Test levels & planned activities** | DataTable: top-level suite → linked test-case count |
| 4 | **Entry, exit & completion criteria** | generated entry / exit / completion sentences derived from the live scope and automation target |
| 5 | **Test environments & data** | DataTable: platform → enabled state |
| 6 | **Tooling & integrations** | configured issue tracker / code tracker, or *None configured* when empty |
| 7 | **Team & responsibilities** | DataTable: login, name, project-role, derived from `user_testproject_roles` |
| 8 | **Related test plans & artifacts** | DataTable: plan id, name, active state |
| 9 | **Assumptions, constraints & dependencies** | static, project-generic assumptions list |

## Actions

* **Refresh** — reloads the report from the BFF.
* **Export Markdown** — downloads `strategy-report-<project>.md`.
* **Export HTML** — downloads a standalone `strategy-report-<project>.html`.
* **Print** — opens the browser print dialog over the report.
* **Close** — returns to the previous page.
* **Locale switcher** — header language selector (i18n via `TLi18n`, persisted per
  `?locale=` / `localStorage`).

## BFF contract

`GET|HEAD api/strategyreport/index.php?action=init&tproject_id=<id>`

Success (`200`): `{ status, code: "ok", context, scope, types, levels[],
environments[], tooling {issue_trackers[], code_trackers[]}, team[], related
{test_plans[]}, signals {covered_requirements, automation_pct} }`.

Error codes: `400 invalid_request` (missing/invalid `tproject_id`, unknown action),
`401 not_authenticated`, `403 no_right` (no `testplan_metrics` on the project),
`404 tproject_not_found`, `405 method_not_allowed`. All ids are strictly validated
positive integers. `action` defaults to `init`.

## Empty & error states

* No `tproject_id` → "no project selected" state card, report hidden.
* Unknown project → "test project not found" state card.
* Anonymous → session-expired state card.
* Missing right → "insufficient rights" state card.
* Tracker not configured → *None configured* text.
* No team rows → DataTable empty message.

## i18n

86 + 7 `strat.*` keys and `footers.strategyReport` in **all 10** client bundles
(validated with `ai/verify_i18n_coverage.sh`, 9/9 PASS), plus
`$TLS_link_report_strategy_report` in all 19 server locale bundles. No hardcoded
strings.

## Wiring

* `cfg/reports.cfg.php` → `reports_list['strategy_report']`
  (`link_report_strategy_report`, `enabled => 'all'`, `format => 'format_html'`).
* `api/aside/index.php` → Reports branch building the screen href with
  `tproject_id` + `tplan_id`.
* `lib/functions/common.php` → `$actions->strategyReport`.
