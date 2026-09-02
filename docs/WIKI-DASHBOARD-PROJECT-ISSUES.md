# Dashboard — "Project open issues" Widget (Refs #772)

The **Project open issues** widget is the fifth card of the modernized Dashboard
(`gui/templates/mainpage/mainPage.html`). It shows **all open issues on the
project's linked issue tracker**, independent of the selected test plan, and is
backed by the Dashboard BFF (`api/mainpage/index.php` → `getProjectIssuesData()`).

**In short:** the legacy *Test case bugs* widget (`getBugsTestedData`) only lists
bugs that were actually attached to an execution via `execution_bugs` — an
empty / never-executed plan shows nothing. This widget closes that blind spot by
listing the project's real open issues straight from the tracker.

---

## Table of Contents

1. [Behavior](#1-behavior)
2. [Data Flow](#2-data-flow)
3. [Rendering & Columns](#3-rendering--columns)
4. [Visibility Rules](#4-visibility-rules)
5. [Error Handling & Event Viewer](#5-error-handling--event-viewer)
6. [Localization](#6-localization)
7. [Acceptance Evidence](#7-acceptance-evidence)

---

## 1. Behavior

- Triggered by the **project's linked issue tracker** (the same one used by
  *Issue Tracker Management* / `testproject_issuetracker`), so it is
  **project-scoped and plan-independent**.
- Rows: bug id (link to the tracker URL), summary/title, status badge, plus the
  issue's **GitHub labels** with GitHub's own label colors.
- Uses a DataTable: `25` rows/page, client-side search and column sorting
  (GitHub returns up to 100 issues per page; the BFF currently requests page 1).

## 2. Data Flow

1. `mainPage.html` → `renderIssues(d.projectIssues)`
2. BFF `api/mainpage/index.php` → `getProjectIssuesData($db, $tprojectID)`
   (line 325; mirrors `lib/general/mainPage.php::getProjectIssuesData()`)
3. `tlIssueTracker::getInterfaceObject($tprojectID)` resolves the linked tracker
4. `githubrestInterface::listIssues($state='open', $perPage=100, $page=1,
   $sort='created')` → `github::getIssues($filters)` →
   `GET /repos/{owner}/{repo}/issues?state=open&per_page=100&page=1
   &sort=created&direction=desc`
5. GitHub pull requests are filtered out (`pull_request` property) so only
   real issues/bugs are listed.
6. Each row maps `number → id`, `html_url → url`, `state → status`, `labels[]`
   and `label_colors{name → hex}` straight from the GitHub payload.

Status colors use the palette of the existing widgets: `open` = red `#e6605e`,
`closed` = green `#5cb85c`, default grey `#8f8f8f`.

## 3. Rendering & Columns

| Column | Source | Notes |
|--------|--------|-------|
| **Bug id** | `issue.number` / `issue.html_url` | Linked `<a target="_blank" rel="noopener">` to the tracker issue |
| **Summary** | `issue.title` | |
| **Labels** | `issue.labels[]` + `label_colors` | One badge per label with GitHub's hex color (`enhancement` → `#a2eeef`, `bug` → `#d73a4a`); fallback grey `#6c757d` |
| **Status** | `issue.state` | Colored badge (`open` red / `closed` green) |

DataTable options: `pageLength: 25`, default sort by id ascending.

## 4. Visibility Rules

The widget **only** appears when the project has an issue tracker enabled AND
the tracker interface supports issue listing AND there is at least one open
issue:

| Condition | Result |
|-----------|--------|
| No project selected (`tproject_id=0`) | Widget absent (`getProjectIssuesData` returns `null`) |
| Project `issue_tracker_enabled=0` | Widget absent |
| Tracker interface without `listIssues()` | Widget absent |
| Tracker configured but unreachable / wrong credentials | Widget absent (graceful degradation, no fatal) |
| Tracker reachable, zero open issues | Widget absent |
| Tracker reachable, open issues exist | Widget shows the DataTable |

## 5. Error Handling & Event Viewer

- GitHub failures are caught inside the interface (`listIssues` returns `null`,
  logs `tLog(..., 'ERROR')` on the **not-connected** path only — same convention
  as `getIssue`). The widget simply hides.
- Verification on `sebiboga@907816717`: success-path loads generated **zero**
  new `Error`/`Warning` rows in the Event Viewer (`events` table).

## 6. Localization

- `dash.openIssues`, `dash.openIssuesHint`, `dash.labels`, `dash.bugId`,
  `dash.issueSummary`, `dash.status` — present and valid in **all 10** bundles
  (`gui/templates/i18n/{en,ro,de,fr,es,it,ja,pt,ru,zh}.json`).
- Legacy Dashio template `gui/templates/dashio/mainPage.tpl` carries the same
  widget with `project_open_issues` / `project_open_issues_hint` /
  `project_issue_labels` keys in all 18 `locale/*/strings.txt` files.

## 7. Acceptance Evidence

All acceptance criteria of #772 verified live (admin session, project `TLU` id
30 with GitHub tracker on `sebiboga/testlink-upgraded`):

1. Widget appears, listing **49 real open issues** (503…818) with labels/status. ✅
2. Widget absent for a tracker-less project and for an unreachable tracker. ✅
3. No Event Viewer errors, no JS console errors. ✅

Regression suite: **Suite 820** in `tmp/TLU_Test_Cases.md` — 16/16 PASS.

---

## Files

| File | Purpose |
|------|---------|
| `gui/templates/mainpage/mainPage.html` | `renderIssues()` + `#secIssues` card |
| `api/mainpage/index.php` | BFF `getProjectIssuesData()` provider |
| `lib/general/mainPage.php` | Legacy provider (Smarty dashboard, kept for backward compatibility) |
| `lib/issuetrackerintegration/githubrestInterface.class.php` | `listIssues()` |
| `third_party/github-rest-api/lib/github-rest-api.php` | `getIssues($filters)` forwarding |
| `gui/templates/dashio/mainPage.tpl` | Legacy Dashio template widget |
| `gui/templates/i18n/*.json` | `dash.*` keys |
| `locale/*/strings.txt` | Legacy `project_*` keys |