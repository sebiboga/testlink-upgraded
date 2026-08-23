# baselinel1l2 Missing $gui Props Fix

## Issue #601

Fixed: every render of the **Baselines L1 & L2** report
(`lib/results/baselinel1l2.php?tplan_id=<plan>&format=0`, Dashio theme) logged
**4 fresh E_WARNING events**, one per undefined `$gui` property read by the
template:

```
E_WARNING Undefined property: stdClass::$baselineSaved      - baselinel1l2.tpl.php line ~72
E_WARNING Undefined property: stdClass::$actionSendMail     - baselinel1l2.tpl.php line ~87
E_WARNING Undefined property: stdClass::$actionSpreadsheet  - baselinel1l2.tpl.php line ~98
E_WARNING Undefined property: stdClass::$mailFeedBack       - baselinel1l2.tpl.php line ~108
```


## Root Cause

`gui/templates/dashio/results/baselinel1l2.tpl` unconditionally reads four
properties that its controller `lib/results/baselinel1l2.php` /
`initializeGui()` never initializes:

| Template usage | Purpose |
|---|---|
| `{if $gui->baselineSaved != null && ...}` | bootbox "baseline saved" confirmation |
| `<form action="{$gui->actionSendMail}">` | send-report-by-email toolbar form |
| `<form action={$gui->actionSpreadsheet}>` | export-as-spreadsheet toolbar form |
| `{$gui->mailFeedBack->msg}` | post-send feedback message |

Sibling controllers initialize exactly these props in their own
`initializeGui()` (`resultsByTSuite.php`, `execTimelineStats.php`) — a pure
template/controller divergence, same pattern as #588. Side effect beyond the
warning spam: both toolbar forms posted to an *empty* `action=""`, so the
email and Excel buttons silently did nothing.

Repro: fixture project + plan + suites, open
`baselinel1l2.php?tplan_id=<id>&format=0`, watch the `events` table gain 4
rows per render.

## The Fix — approach

Minimal initialization in `initializeGui()`
(`lib/results/baselinel1l2.php`), mirroring `resultsByTSuite.php`:

```php
$base = 'lib/results/baselinel1l2.php';
$gui->basehref = $_SESSION['basehref'];
$common = $gui->basehref . $base . "?tplan_id={$gui->tplan_id}" .
          "&tproject_id={$gui->tproject_id}&format=";

$gui->baselineSaved = false;
$gui->actionSendMail = $common . FORMAT_MAIL_HTML;
$gui->actionSpreadsheet = $common . FORMAT_XLS . "&spreadsheet=1";

$gui->mailFeedBack = new stdClass();
$gui->mailFeedBack->msg = '';
```

Why this way:

- `baselineSaved = false` — this controller has no save action of its own; the
  flag only turns true after a save performed from resultsByTSuite, so false
  is the correct steady-state value.
- Real self-referencing action URLs instead of dummy strings — this makes the
  two toolbar buttons actually functional (they post back to the controller,
  which routes through the shared `displayReport()` mail/XLS machinery).
- `mailFeedBack` as empty-msg stdClass — matches what the template expects
  (`null != $gui->mailFeedBack && $gui->mailFeedBack->msg != ""`).

Alternatives rejected: guarding each read in the template with
`isset()/property_exists()` (would fix warnings but leave dead buttons and
diverge further from the sibling-controller convention), or initializing in
the template itself (logic belongs in the controller).

## Verification

- Plain render (`format=0`): zero new events.
- DOM check: `send_by_email_to_me` → `...&format=6`,
  `exportSpreadsheet` → `...&format=3&spreadsheet=1`.
- Email path (`format=6`): renders clean, zero new events.


Regression suite: 4/4 PASS (see `tmp/TLU_Test_Cases.md`, suite #601).

Note discovered while verifying: clicking Export-as-spreadsheet now reaches
the XLS code path and exposes a **pre-existing, unrelated fatal**
(`PhpOffice\` classes not mapped in `vendor/composer/autoload_psr4.php`),
filed separately as #602.

## Files Changed

- `lib/results/baselinel1l2.php` — initializeGui(): init the 4 missing props (+ basehref/common URL builder)
