# Dashboard — Landing Page (Main Page) — Docs (Wiki Mirror)

> Mirror of the GitHub Wiki page [Dashboard](https://github.com/sebiboga/testlink-upgraded.wiki/blob/master/Dashboard.md). Image lines omitted per project convention.

The **Dashboard** is the ASIDE **mainframe landing** — the first screen shown in the
main frame after login and after any project/test-plan change. It gives a visual
overview of the current test plan's execution status and the test project's test
case growth over time. It was re-implemented in 2.0.1 as a standalone Dashio HTML +
JS + CSS screen backed by a plain-PHP REST BFF (replacing the legacy 1.9.20
`lib/general/mainPage.php` + Smarty).

**Path:** Dashboard — main frame landing (not an ASIDE child item)
**URL:** `gui/templates/mainpage/mainPage.html` (loaded inside the main frame)
**BFF API:** `api/mainpage/index.php` — `GET /data?tproject_id=<id>&tplan_id=<plan>`
**Link switch:** `index.php` → `getReturnWorkArea()` default now returns the modernized HTML instead of `lib/general/mainPage.php`

---

## Table of Contents

1. [Screen Layout](#1-screen-layout)
2. [Test Plan Selector & Context](#2-test-plan-selector--context)
3. [Execution Status](#3-execution-status)
4. [Monthly Test Case Growth](#4-monthly-test-case-growth)
5. [Bugs & Open Issues Widgets](#5-bugs--open-issues-widgets)
6. [Localization](#6-localization)
7. [Empty States](#7-empty-states)

---

## 1. Screen Layout

The screen is a single-column Dashio layout with a page header (title + subtitle),
a **locale switcher**, and a **test plan selector** at the top:

| Section | Description |
|---------|-------------|
| **Header** | "Dashboard" title + "home – execution & project overview" subtitle, locale switcher |
| **Test Plan selector** | Dropdown of the accessible test plans for the current project |
| **Execution Status** | Doughnut chart + summary table showing pass/fail/block/not-run distribution |
| **Monthly Test Case Growth** | Bar chart showing new test cases created per month in the current project |
| **Bugs / Open Issues** | Tables of execution-linked bugs and tracker open issues (only when an issue tracker is configured) |
| **Footer/generated-on** | "Generated on <timestamp>" line |

Each data section is **conditionally rendered** — if there is no data, the section
is hidden entirely (no empty tables), matching the legacy behaviour.

---

## 2. Test Plan Selector & Context

The dropdown lists the accessible test plans of the current project. Selecting a
plan reloads the screen with `tplan_id=<id>`, which the BFF reads to compute the
execution status. The `tproject_id`/`tplan_id` may also come from the **session**
when they are not present in the query string.

---

## 3. Execution Status

Displays execution progress for the **currently selected test plan**, computed
server-side from the plan's `executions`.

### Header

| Field | Description |
|-------|-------------|
| **Completed** | Percentage and absolute count: `100.0% (3/3)` |

### Doughnut Chart

A Chart.js v3 doughnut chart showing the four execution statuses:

| Status | Color | Description |
|--------|-------|-------------|
| Passed | Teal | Test cases executed successfully |
| Failed | Red | Test cases that failed |
| Blocked | Amber | Test cases blocked by an issue |
| Not Run | Gray | Test cases not yet executed |

### Summary Table

Side-by-side table with: color swatch, status name, absolute count, and percentage.
A final **Total** row shows the overall count.

---

## 4. Monthly Test Case Growth

A Chart.js v3 bar chart showing how many test cases were created each month within
the current test project (last 12 months, based on latest test case version creation
date).

| Property | Detail |
|----------|--------|
| **Type** | Chart.js v3 `.bar()` |
| **X-axis** | Months |
| **Y-axis** | Number of test cases created |
| **Color** | Teal |
| **Note** | Project-scoped (not plan-specific) |

---

## 5. Bugs & Open Issues Widgets

An **issue tracker** (`tlIssueTracker`) must be configured on the project for these
widgets to appear. When a tracker is configured:

- **Bugs (execution-linked)** — table of bugs that testers linked to test cases
  during execution in the current plan.
- **Open Issues** — the tracker's currently open issues for the plan.

If no tracker is configured, both sections are **hidden entirely**.

---

## 6. Localization

All labels, titles, subtitles and messages use the client-side `TLi18n` module.
Keys are stored under `dsh.*` and `header.dashboard*` in **all** locale bundles
(`en.json`, `ro.json`, …). The locale switcher reloads the screen with the chosen
locale and re-renders every string.

---

## 7. Empty States

| Section | Shown When |
|---------|------------|
| Execution Status | A test plan is selected and has execution data |
| Bugs / Open Issues | An issue tracker is configured and return rows |
| Monthly Test Case Growth | The project has test cases with creation dates |

When no test plan has execution data, the execution-status widget is hidden and the
screen is not polluted with empty tables (no console errors).

---

## Files

| File | Purpose |
|------|---------|
| `gui/templates/mainpage/mainPage.html` | Modernized dashboard screen (HTML + JS + CSS) |
| `api/mainpage/index.php` | Dashboard REST BFF (data, widget context) |
| `index.php` | `getReturnWorkArea()` link switch → main frame defaults to the modernized HTML |
| `gui/templates/i18n/*.json` | `dsh.*` + `header.dashboard*` keys (all bundles) |
| `lib/general/mainPage.php` | Legacy controller (kept for backward compatibility, no longer the main-frame default) |
