# Modernize-Test-Plan-Navigator-planNav (#1572)

The legacy **Test Plan Navigator** frames — `lib/plan/planTCNavigator.php` +
`lib/plan/planAddTCNavigator.php` (with `gui/templates/dashio/plan/planTCNavigator.tpl`
+ `planAddTCNavigator.tpl`) — are modernized as a single Dashio hub backed by a REST
BFF. The legacy frames were opened from the Test Plan screens' workframe buttons and
showed a navigable tree of the plan's test cases (or its requirement coverage)
together with the plan-action buttons that loaded the workframe controllers. The
modern hub reproduces all of that: plan picker, group-by (Test suites /
Requirement coverage), suite tree with deep linked/total quantities, requirement
coverage tree, build selector, and the five planning-action deep links (incl. an
**Event Viewer** action, legacy `show_ve` parity), forwarding
`testproject_id`/`tplan_id` exactly like the legacy workframe did.

## Deliverables

- **Screen:** `gui/templates/plans/planNav.html` — Dashio hub: teal header
  ("Test Plan Navigator", TLi18n locale switcher), context line
  (`Test Project: <name>`, `Test Plan` select incl. inactive plans, `Group by`
  select, `Refresh` button), a **Plan actions** panel with four cards
  (Add/Remove Test Cases, Update linked TC versions, Test urgency, TC execution
  assignment, Event Viewer), the navigator tree panel and the **Item details** panel.
- **Test suites mode:** one row per suite (deep `linked/total` badge), built from
  `/suites` — includes only suites that carry decorated test cases anywhere in
  their subtree (upstream containers stay closed, so the top level lists real
  suites, not the project id), with per-suite `total_qty` (test case nodes) and
  `linked_qty` (distinct test-case versions linked to the plan) aggregated
  bottom-up so a parent shows the sums of its children.
- **Requirement coverage mode:** one row per req spec (`covered/total` badge)
  with the requirements as children; each requirement shows a `✓` when at least
  one of its linked tcversions is linked to the selected plan, plus the total
  number of linked tcversions after it.
- **Item details panel:** localizes and formats the row of the clicked node —
  suite → Linked / Total, spec → Requirements / Covered / Total, requirement →
  Linked test cases / Covered Yes|No (green `Good` / red `Bad`).
- **Plan switch** re-boots the hub for the newly selected plan: the tree AND the
  four action links both refresh with the new `testplan_id` (regression fixed this
  run — the links kept the original plan id).
- **No-rights degrade:** a user with no accessible test plan gets an empty hub
  (project `-`, empty plan combo) plus the localized `noAccess` toast — the BFF
  answers 403 and the screen degrades gracefully instead of crashing.
- **Build selector:** shown only when the user has `exec_assign_testcases` and the
  plan has active+open builds (from `get_builds_for_html_options(ACTIVE, OPEN)`)
  — legacy `tcExecAssignment` target parity.

## BFF

- **Endpoint:** `api/plannav/index.php` (session auth + `bffSameOriginGuard`),
  routes:
  - `GET /init?tproject_id=N[&tplan_id=M]` — context: `tproject_id/name`,
    `plans[]` (`id/name/active`, `getAccessibleTestPlans(active=null)` so
    inactive plans stay selectable), `builds{}` + `default_build_id`, `rights`
    (`canPlan`, `canUpdateTC`, `canUrgency` → `testplan_planning`;
    `canAssign` → `exec_assign_testcases`; `canViewEvents` → `mgt_view_events`),
    and `actions[]` — the five deep links with
    `params=testproject_id=<tproject>&testplan_id=<tplan>`,
    nulled (locked card) when the right is missing.
  - `GET /suites?tproject_id=&tplan_id=` —
    the suite subtree WITH RECURSIVE (testsuite-typed descendants of the
    project; anchored at the project root — the legacy `container_id` drill
    param is ignored, which also closes a cross-project IDOR), per-suite
    direct totals + plan-linked distinct tcversions,
    aggregated bottom-up into `deep_total_qty` / `deep_linked_qty`.
  - `GET /reqs?tproject_id=&tplan_id=` — `req_specs` of the project, their
    `requirements`, and per requirement `linked_qty` (distinct linked
    tcversions) + `covered_qty` (of those, the ones whose tcversion is linked
    to the target plan, via `testplan_tcversions` LEFT JOIN); spec nodes carry
    `covered_qty`/`total_qty` (count of covered requirements / count in spec).
- **Rights:** no page-level legacy right — any authenticated user with ≥ 1
  accessible test plan may load the hub (the legacy navigators only ran
  `testlinkInitPage`); per-feature flags mirror the modern plan screens' own
  gates. 401 anon / 403 no accessible plans / 404 bad test plan (cross-project
  or nonexistent) / 400 missing params; unknown route → 404 JSON.
- **Error contract:** all errors return JSON; error paths write **no** event rows.

## Wiring

- `$actions->planNav` added in `lib/functions/common.php` (after `usersAssign`):
  both legacy navigator controllers are now **session-guarded 302 shims** →
  `gui/templates/plans/planNav.html?tproject_id=&tplan_id=` (forwarding the
  deep-link params). The modern hub is reached from the plan screens' workframe
  buttons exactly like the legacy frames; `frmWorkArea.php` forwarding was pinned
  to the modern hub.

## i18n

`pnav.*` keys (30: title, subtitle, group-by labels, tree headers, detail-row
labels, action cards, open/no-right buttons, noAccess toast, empty states) plus
the reused helpers `pv.testProject`, `paddtc.testPlan`, `paddtc.build`,
`common.refresh`, `common.error` and `footers.planNav` — in **all 10 locale
bundles** (validated with `python3 -m json.tool`). No hardcoded strings.

## Bugs found & fixed while testing (this run)

1. **Plan switch left the action links on the original plan** — after switching
   the test plan the tree refreshed but all four action-card links still carried
   the first plan's `testplan_id`, so the buttons would have acted on the wrong
   plan. The change handler now re-boots the hub with the selected plan id
   (`boot(parseInt(...))`), which refreshes `INIT` and therefore the actions too.
2. **Code-review fixes (this run):** `/suites` accepted an arbitrary
   `container_id` as the CTE root → cross-project suite-tree disclosure (now
   anchored at the owning project); `/reqs` `covered_qty` could double count a
   tcversion linked under two `req_coverage` req-versions (`COUNT(DISTINCT CASE…)`
   applied); response charset/nosniff headers added; the legacy `show_ve`
   (plan Event Viewer) navigator action is now a fifth hub action card
   (`eventviewer.html?tproject_id=&tplan_id=`, gated on `mgt_view_events`);
   action hrefs HTML-escape `&`; Refresh resets the selection.

## Verification

- Fresh DB + fixture `tmp/fixtures_1572.php` (project **NAV1572**/`NA7`, Suite A
  with child Suite A1, TCs A1/A2/A1x/B1/B2 with revisions, plan **Plan NAV1572**
  linked A1+B1+B2 + second unlinked plan **Plan NAV Alt**, req spec **Navigator
  Spec** + req1/req2 + `req_coverage` req1→A1, req2→B1; no-rights user
  `norights`/`norights` via `tmp/mkuser_norights.php`).
- BFF contract: `/init` 200 (rights all true, all 4 actions with params,
  plans [72,73], builds `{}`), `/suites` + `/reqs` 200 with the expected counts,
  400 missing params, 404 bad plan, 404 unknown route, anon 401.
- Browser (EN + RO): suite tree deep counts (Suite A 1/3, Suite A1 0/1, Suite B
  2/2), coverage mode (spec 2/2 covered; both reqs `✓ 1`), plan switch refreshes
  tree + action links both directions (72 ↔ 73; unlinked plan → 0/N + 0/2 + no ✓),
  detail panel variants, no-rights 403 degrade, legacy-shim 302s, EN↔RO renders.
- Event Viewer: 0 new ERROR/WARNING (audit-only rows). Console: 0 errors.
- Screenshots: `docs/screenshots/issue-1572-plannav-{suites,reqs,ro,reqs-ro}.png`.

## Test suite

Suite 1572 (8/8 PASS) appended to `tmp/TLU_Test_Cases.md`.