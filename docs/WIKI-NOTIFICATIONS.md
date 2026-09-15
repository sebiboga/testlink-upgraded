# Notifications (Refs #896)

GitHub-style per-user **Notifications** center for TestLink 2.0.1, built LIVE
from the data TestLink already stores. Legacy 1.9.20 had **no** notification
subsystem (verified: zero `%notif%` tables, no bell in any modern screen), so
this feature was implemented from scratch as a dedicated modern screen backed
by a new plain-PHP BFF endpoint, following the Dashio bell widget pattern
(`gui/templates/dashio/index.html` lines 172-215).

## Screens

| Screen | File | BFF |
|---|---|---|
| Notifications | `gui/templates/notifications/notifications.html` | `api/notifications/index.php` |
| Dashboard bell badge | `gui/templates/mainpage/mainPage.html` | `GET /api/notifications/count` |

## What you get notified about

The BFF computes, for the **current user**, four notification groups directly
from existing TestLink tables (no new table, no schema migration — the DB is
re-imported on every CI run):

1. **Test case assigned to you** (`type=assignment`) — rows of
   `user_assignments` (`assignment_types.description='testcase_execution'`)
   for the current user from the last 30 days, joined with
   `testplan_tcversions`, `tcversions`, `nodes_hierarchy`, `testplans`,
   `testprojects`, `builds`. Shows the test case (`prefix-id: name`), test
   plan, build, assigner and an optional deadline.
2. **Milestone approaching / overdue** (`type=milestone`) — rows of
   `milestones` whose `target_date` is within [-7, +14] days of today. A past
   target becomes an "overdue" notification (red); an upcoming one is
   "approaching" (amber, with the number of days left).
3. **Test plan fully executed** (`type=plan_completed`) — test plans whose
   linked test cases are all executed (`testplan_tcversions` vs `executions`,
   `HAVING linked>0 AND linked=executed`), finished within the last 14 days,
   e.g. `Test plan PlanA is fully executed (3/3)`.
4. **New bug filed** (`type=bug`) — bug links from `execution_bugs` joined to
   `executions` in the last 14 days, e.g. `New bug #42 was filed for test case
   NT-3: NT-block (plan PlanA)`. Meant to avoid duplicate bug reports.

## BFF API (`/api/notifications/`)

- `GET /` → full list for the current user:
  `{status, notifications[], totals{total,unread,by_type{}}}`
- `GET /count` → lightweight payload for the Dashboard bell badge.
- `POST /read` — JSON body `{"ids":["assignment-1",...]}` (selected) or
  `{"all":true}` (everything) → marks read and returns the refreshed list +
  totals.

Session-based authentication + `bffSameOriginGuard()` (same as every other
BFF). Read/unread state is stored per login in `$_SESSION['tl_notif_read']`
— deliberately not in the DB, because no schema migration is allowed and the
database is re-imported on each CI run.

Each group has a **schema-drift guard** (INFORMATION_SCHEMA probe, `#862`
pattern): if a column used by a group is missing on a not-yet-migrated DB the
group is skipped instead of killing the JSON response.

All four groups are **rights-scoped** (code-review bonus, security): the BFF
pivots through `testproject::get_accessible_for_user()` and only emits
notifications whose `parent testproject` is in the current user's accessible
set (global ADMIN sees all; public/private project rules from the legacy
manager apply). A user without project access gets `AND 1=0` → an empty list.

Each notification object carries: `id`, `type`, `icon` (FontAwesome), `color`,
`time_epoch`, `read`, type-specific fields (`tc_id`, `tcversion_id`, `tc_name`,
`prefix`, `tc_external_id`, `tplan_id`, `tplan_name`, `tproject_id`, `build_name`,
`assigner`, `deadline_epoch`, `milestone_name`, `target_date`, `days_left`,
`overdue`, `bug_id`, `executed`, `total`) and a deep-link `url` to the relevant
screen (execution history / plan milestones / general metrics / dashboard).
The client renders the localized message with TLi18n interpolation
(`notif.msgAssignment` etc.), so no string is hardcoded either side.

## UI behaviour

- **Summary cards** (Assignments / Milestones / Plans completed / New bugs) with
  live counts; clicking a card filters the table to that type, clicking again
  clears the filter.
- **DataTable** with icon, localized message (+ relative time; future-dated
  milestones render the actual date), read/unread badge, per-row checkbox,
  toolbar "select all", "Mark selected read" and "Mark all read" buttons, toast
  feedback, empty state. Rows sort newest-first by a **hidden epoch column**
  (not the relative-time text), and unread-row highlighting resolves via the
  DataTables row index so it stays correct after sorting/filtering.
- Locale switcher + page title/subtitle.
- Dashboard toolbar shows a **bell** with a red unread-count badge linking to
  the Notifications screen; the badge disappears when the count is 0.

## i18n

38 new keys (`notif.*` + `footers.notifications`) added to **all 10 locale
bundles** (`de/en/es/fr/it/ja/pt/ro/ru/zh`), English and Romanian fully
translated; every bundle validated with `python3 -m json.tool`.

## Verification

Browser-tested (admin) with fixture `tmp/fixture_notifications_896.php`
(project NoteTest, 4 TCs, PlanA fully executed with bug #42 + overdue
milestone, PlanB with assignment + approaching milestone): all 4 groups
render, category filter works, select/mark-selected/mark-all-read update the
counts, Romanian locale translates messages/badges. The Dashboard bell shows
the unread badge and links to the screen. No new Event Viewer
Error/Warning rows after the fixed API (the 3 E_WARNING / 1 DB-error rows
present in `events` were generated by intermediate dev-time iterations
already fixed). See `tmp/TLU_Test_Cases.md` suite `Task — Issue #896`.