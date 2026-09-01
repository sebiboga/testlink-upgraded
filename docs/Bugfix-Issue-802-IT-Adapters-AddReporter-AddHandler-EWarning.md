# Bugfix — Issue #802: IT adapters drop `addReporter`/`addHandler` → E_WARNING on bug-link render

## Problem

Rendering a linked bug for the GitHub issue tracker (e.g. the **Execute Tests**
screen when a bug is linked to an execution, or execution history) flooded the
Event Viewer with two `E_WARNING` entries per rendered link:

```
Undefined array key "addReporter" - lib/issuetrackerintegration/issueTrackerInterface.class.php - Line 382
Undefined array key "addHandler"  - lib/issuetrackerintegration/issueTrackerInterface.class.php - Line 400
```

## Root Cause

`issueTrackerInterface::buildViewBugLink()` reads two per-adapter options at
`lib/issuetrackerintegration/issueTrackerInterface.class.php:382` and `:400`:

```php
$my['opt'] = $this->methodOpt[__FUNCTION__];   // line 336
...
if ($my['opt']['addReporter']) { ... }          // line 382
if ($my['opt']['addHandler'])  { ... }          // line 400
```

The base class defines safe defaults for these keys
(`issueTrackerInterface.class.php:45-49`):

```php
var $methodOpt = array('buildViewBugLink' =>
                       array('addSummary' => false,
                             'colorByStatus' => false,
                             'addReporter' => false,
                             'addHandler' => false));
```

Several IT adapters `overwrite` this with a **plain array** that only sets
`addSummary`/`colorByStatus`, thereby **deleting** the `addReporter` and
`addHandler` keys. On the next linked-bug render, PHP 8 raises
`E_WARNING: Undefined array key` for both options — twice per link.

The originally-reported **GitHub** adapter was already fixed
(commit `7d94ba1cd`): its constructor now uses `array_merge` with the parent
defaults. The issue explicitly noted the same class of bug affected the other
adapters that use plain array assignment. Investigation confirmed **8 more
adapters** were affected:

| Adapter | Location | Overrides dropped `addReporter`/`addHandler` |
|---|---|---|
| `gitlabrestInterface` | `lib/issuetrackerintegration/gitlabrestInterface.class.php:32` | yes |
| `bugzilladbInterface` | `lib/issuetrackerintegration/bugzilladbInterface.class.php:39` | yes |
| `bugzillaxmlrpcInterface` | `lib/issuetrackerintegration/bugzillaxmlrpcInterface.class.php:31` | yes |
| `tracxmlrpcInterface` | `lib/issuetrackerintegration/tracxmlrpcInterface.class.php:56` | yes |
| `redminerestInterface` | `lib/issuetrackerintegration/redminerestInterface.class.php:29` | yes |
| `jiradbInterface` | `lib/issuetrackerintegration/jiradbInterface.class.php:35` | yes |
| `fogbugzdbInterface` | `lib/issuetrackerintegration/fogbugzdbInterface.class.php:25` | yes |
| `mantisdbInterface` | `lib/issuetrackerintegration/mantisdbInterface.class.php:58` | yes |

Already safe (used `array_merge` or included the keys): `githubrestInterface`,
`jirarestInterface`, `kaitenrestInterface`, `trellorestInterface`,
`mantisrestInterface`, `mantissoapInterface`.

## Fix

Apply the same accepted pattern as the GitHub fix (`7d94ba1cd`) to the 8
affected adapters: replace the plain assignment with an `array_merge` against the
parent defaults, so `addReporter`/`addHandler` are preserved while only the
intended `addSummary`/`colorByStatus` overrides are applied. Example:

```php
// before
$this->methodOpt['buildViewBugLink'] = array('addSummary' => true, 'colorByStatus' => false);
// after
$this->methodOpt['buildViewBugLink'] = array_merge(
    $this->methodOpt['buildViewBugLink'],
    array('addSummary' => true, 'colorByStatus' => false));
```

## Files Changed

- `lib/issuetrackerintegration/gitlabrestInterface.class.php` — methodOpt kept via array_merge
- `lib/issuetrackerintegration/bugzilladbInterface.class.php` — same
- `lib/issuetrackerintegration/bugzillaxmlrpcInterface.class.php` — same
- `lib/issuetrackerintegration/tracxmlrpcInterface.class.php` — same
- `lib/issuetrackerintegration/redminerestInterface.class.php` — same
- `lib/issuetrackerintegration/jiradbInterface.class.php` — same
- `lib/issuetrackerintegration/fogbugzdbInterface.class.php` — same
- `lib/issuetrackerintegration/mantisdbInterface.class.php` — same

## Verification

- `php -l` on all 8 changed files + base `issueTrackerInterface`: **no syntax errors** (9/9).
- Static check: all 8 files now contain `addReporter` and `addHandler` (via
  `array_merge`); override values (`addSummary`/`colorByStatus`) unchanged.
- Real-code harness (instantiates `issueTrackerInterface` and runs the real
  `buildViewBugLink()` source under `E_ALL`):
  - pre-fix methodOpt (plain array) → **2** `Undefined array key` warnings (addReporter + addHandler)
  - post-fix methodOpt (array_merge) → **0** warnings
- Event Viewer / `events` table: no new Error/Warning rows introduced (only the
  informational login audit entry remains).
- Code review subagent: **APPROVE** — minimal, matches the accepted reference
  pattern, no logic regressions.
