# Set Test Urgency (testUrgency) — Modernized Screen

Refs: issue #605 · Suite 58 in `tmp/TLU_Test_Cases.md` · Branch `sebiboga`

## What was modernized

The legacy **Set Test Urgency** screen (`lib/plan/planUrgency.php` +
`gui/templates/dashio/plan/planUrgency.tpl`, launched via the
`test_urgency` frmWorkArea feature) is replaced by a standalone Dashio page:

- Screen: `gui/templates/plans/testUrgency.html`
- BFF: `api/plans/index.php` routes:
  - `GET /urgency?tproject_id=&tplan_id=` — context: accessible plans selector,
    active plan, suites of the plan holding DIRECTLY linked test cases with TC
    counts and urgency summary (`mixed` when urgencies differ)
  - `GET /urgency/suite?tplan_id=&tsuite_id=` — test cases under one suite with
    tcprefix + external id, assigned testers, importance, urgency radios state
    and computed priority level (priority = importance × urgency, thresholds
    from `urgencyImportance` config via legacy `priority_to_level()`)
  - `POST /urgency/suite {tplan_id, tsuite_id, urgency}` — suite-level set
    (legacy `setSuiteUrgency()`, direct children only)
  - `POST /urgency/tcases {tplan_id, items:{tcversion_id:urgency}}` — per-testcase
    set loop (legacy `setTestUrgency()`)

## Behavior parity

| Legacy | Modern |
|---|---|
| rights `testplan_planning` (rightsAnd) on page + mutations | enforced server-side on every BFF route |
| menu grant `testplan_set_urgent_testcases` | aside.tpl menuGrants gate unchanged |
| suite urgency High/Medium/Low buttons | same buttons in the pane header |
| per-TC urgency radio table + save button | same, plus unsaved-changes hint |
| priority column (importance × urgency → HIGH/MEDIUM/LOW) | colored priority tag with numeric value |
| exec-history + design popup icons per row | links to modern `execHistory.html` / `tcView.html` (new tab) |
| tester assignment resolved from execution-tree session build | latest ACTIVE build of the plan used instead (no ExtJS tree) |
| ExtJS tree navigation into a suite | left-hand suites pane with urgency badges |

## Files

- `gui/templates/plans/testUrgency.html` — the screen (HTML+JS+CSS, TLi18n)
- `api/plans/index.php` — urgency routes appended
- `lib/functions/common.php` — `$actions->setTestUrgency` switched to the HTML page
- `gui/templates/i18n/*.json` — `turg.*` keys in all 10 bundles

## Testing

See suite 58 in `tmp/TLU_Test_Cases.md` (12/12 PASS). Screenshots live in the
GitHub Wiki page “Set Test Urgency”.
