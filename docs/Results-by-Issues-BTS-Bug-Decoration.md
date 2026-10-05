# Results by Issues — BTS status/summary decoration (Refs #1268)

Screen: `gui/templates/results/resultsBugs.html` · BFF: `api/reports/index.php`
(`GET ?action=results_bugs&tproject_id=P&tplan_id=M&type=0|1`) · Issue: #1268

## Gap closed

Legacy printed each linked bug as **HTML**
(`lib/results/resultsBugs.php:204` + `resultsBugs.tpl:111`, `implode("<br/>", …)`).
That value is produced by
`issueTrackerInterface::buildViewBugLink()`
(`lib/issuetrackerintegration/issueTrackerInterface.class.php:352-443`):

```
<div title="Access issue tracking system" style="display:inline;background:COLOR">
  <a href='{buildViewBugURL}' target='_blank'><b>{id} : </b>[{statusVerbose}] : {summary}</a>
</div>
```

with mantis enabling `addSummary` + `colorByStatus`
(`mantisdbInterface.class.php:60-62`). The modern screen used the bare
`bug_id` as the link text, so the status colour, the tooltip, the status label
and the summary were all dropped (bugs 1-5 showed only `101`, `102`, …).

## What the 2.0.1 screen does now

Each bug in the **Bugs** cell renders as

```
<div class="bug-box [resolved]" style="display:inline;background:COLOR"
     title="Access issue tracking system">
  <a href="http://tracker.local/view.php?id=101" target="_blank" rel="noopener">
    <b>101 : </b><span class="bug-status">[New issue]</span>
    : <span class="bug-summary">Login form rejects valid credentials</span>
  </a>
</div>
```

* the `href` is `buildViewBugURL()` (still filtered by `safeHttpUrl()`, so
  markup can never land in an attribute),
* the status label is translated (`rb.status_<tracker name>`),
* the colour is the tracker's `statusColor`, allow-listed server-side,
* statuses the tracker leaves uncoloured fall back to the green resolved tint.

## BFF contract (`bugs[]`)

| field | source | notes |
|---|---|---|
| `bug_id` | `execution_bugs.bug_id` | escaped client-side |
| `link` | `link_to_bts` | legacy HTML fragment, kept verbatim for echo consumers |
| `url` | `buildViewBugURL()` | bare URL for `<a href>`, allow-listed |
| `is_resolved` | `$issue->isResolved` | drives the `.resolved` tint |
| `status_verbose` | `$issue->statusVerbose` | plain text (`new`, `resolved`, …) |
| `summary` | `$issue->summary` | `strip_tags`ed |
| `status_color` | `$issue->statusColor` | `bugStatusColor()` allow-list |
| `build_name` | `execution_bugs` | unchanged |

## i18n

`rb.accessToBts` and `rb.status_new|feedback|acknowledged|confirmed|assigned|resolved|closed`
in **all 10** bundles (de, en, es, fr, it, ja, pt, ro, ru, zh). Keys are FLAT
dotted strings — `TLi18n.t()` (`gui/templates/i18n/i18n.js:175`) looks the key
up verbatim, a nested `rb:{…}` object is never resolved.

## Verified (live, project 1 / plan 1, type=1)

5 boxes rendered, all 5 hrefs = `http://tracker.local/view.php?id=<n>`,
statuses `New issue` / `Acknowledged` / `Resolved issue` / `Closed issue` /
`Assigned`, colours `#ffa0a0`, `#ffd850`, (tint), (tint), `#c8c8ff`.
Suite: `tmp/TLU_Test_Cases.md` → `## Task — Issue #1268`.

## Fixture gotcha (for maintainers)

`issuetrackers.cfg` is an **XML fragment without its own `<?xml ?>` declaration**
(`issueTrackerInterface::setCfg()` prepends one before `simplexml_load_string()`).
A JSON blob, or an XML blob repeating the declaration, makes
`isConnected()` return 0 and every bug degrade to
`TestLink Internal Message: getIssue(N) FAILURE`.
Also: a report row needs `testplan_tcversions` — `getAllExecutionsWithBugs()`
(`lib/functions/testplan.class.php:7623-7625`) joins on it.
