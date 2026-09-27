# Task — Issue #966: Delete legacy issuetrackerView (Refs #959)

## Context

Row 8 of `docs/SCREEN-COMPARE-STATUS.md` tracks
`gui/templates/issuetracker/issuetrackerView.html` (backed by
`api/issuetracker/index.php`) against its legacy 1.9.20 equivalent. The
screen-compare process found 7 feature gaps, which were filed and closed
individually:

| Gap | Subject | State |
|---|---|---|
| #959 | `issuetracker_view` read-right check | CLOSED / COMPLETED |
| #960 | `issuetracker_management` write gating | CLOSED / COMPLETED |
| #961 | Per-tracker environment check column | CLOSED / COMPLETED |
| #962 | Issue tracker connection check | CLOSED / COMPLETED |
| #963 | Link-count delete gating | CLOSED / COMPLETED |
| #964 | "Used on test project" display | CLOSED / COMPLETED |
| #965 | Per-type configuration template loader | CLOSED / COMPLETED |

All 7 were re-verified as `CLOSED` before any file was touched. With the
gating gaps closed, the legacy screen is superseded and this task retires it.

Follows the convention established by #947 (`ab387af72`).

## Correction to the issue premise

The issue listed 5 files and stated that the legacy cluster was reached only
through `common.php:1879` and `aside.tpl:82` — both of which already point
at the modern screen. Both halves are incomplete.

### 1. `issueTrackerCommands.class.php` was not listed, and is orphaned

```
git grep -ln issueTrackerCommands
  → lib/issuetrackers/issueTrackerEdit.php:89   (new issueTrackerCommands($dbHandler))
  → lib/issuetrackers/issueTrackerCommands.class.php:17   (the class itself)
```

`api/issuetracker/index.php:107` uses `new tlIssueTracker($db)` directly, not
the commands class. So `issueTrackerEdit.php:89` was the only instantiator in
the whole tree, and deleting it orphans the class.

That matters because the class holds the three post-mutation template
assignments that the legacy edit controller turned into HTTP redirects:

| Function | Line | Condition | `$guiObj->template` |
|---|---|---|---|
| `doCreate()` | 140 | `$op['status_ok']` — create succeeded | `"issueTrackerView.php"` |
| `doUpdate()` | 204 | `$op['status_ok']` — save succeeded | `"issueTrackerView.php"` |
| `doDelete()` | 244 | **unconditional** | `"issueTrackerView.php?"` |

`issueTrackerEdit.php:58-64` inspects the value:

```php
$tpl = is_null($opObj->template) ? $templateCfg->default_template : $opObj->template;
$pos = strpos($tpl, '.php');
if($pos === false) {           // → treated as a Smarty template
  $tpl = $tplDir . $tpl;
  $renderType = 'template';
}                             // → else $renderType stays 'redirect'
```

…then `issueTrackerEdit.php:75-77` does `header("Location: {$tpl}")`.

So the legacy cluster was a **closed loop**: the view template linked back
into the edit controller, and the edit controller redirected back to the view.

| Direction | Sites |
|---|---|
| view template → edit controller | `issueTrackerView.tpl:23` (`del_action`), `:68` (edit link), `:93` (form `action`) |
| edit templates → view controller | `issueTrackerEdit.tpl:234`, `.new.tpl:214`, `.ori.tpl:165` (Cancel) |
| edit controller → view controller | `issueTrackerCommands.class.php:140,204,244` |

Neither direction is reachable from the modern UI. `common.php:1973` and
`aside.tpl:82` both already point at the modern screen, so the loop is only
self-reachable — by typing the legacy URL.

> Note on `doDelete()`: it sets the redirect template **unconditionally**,
> discarding `$op['status_ok']`. A rejected delete (e.g. a linked tracker,
> which the model blocks) still redirects to the list and the rejection
> message is lost — the message-building block above it is commented out.
> Pre-existing, not introduced here, and moot once the controller is gone.

### 2. `issueTrackerEdit.new.tpl` / `.ori.tpl` are dead snapshots

They are in the issue's file list, but they were never reachable.
`templateConfiguration()` (`common.php:932-949`) selects the variant from
config:

```php
$custom_templates = config_get('tpl');
$tcfg->default_template = isset($custom_templates[$access_key])
                        ? $custom_templates[$access_key]
                        : ($access_key . '.tpl');
```

`$tlCfg->tpl` is not assigned anywhere in the tree, so `config_get('tpl')`
returns the `''` default, the `isset()` is always false, and
`default_template` is unconditionally `issueTrackerEdit.tpl`. The `.new` and
`.ori` files are 1.9.20-porting snapshots kept for diffing.

## What is not lost

Two of the deleted files carried behaviour that could look load-bearing.
Neither is:

| Deleted | Modern replacement |
|---|---|
| `issueTrackerCommands::checkConnection()` (`:253-281`) | BFF route, `api/issuetracker/index.php:222` (#962) — edit-form "Check Connection" button |
| `lib/ajax/getissuetrackercfgtemplate.php` (49 lines) | `GET /cfg-template?type=N`, `api/issuetracker/index.php:161` (#965) — the eye icon next to the Configuration field |

`getissuetrackercfgtemplate.php`'s only callers were the three legacy edit
templates (dashio + tl-classic) via
`url: fRoot+'lib/ajax/getissuetrackercfgtemplate.php'`, all of which are
deleted here.

## Rights parity

| | Legacy | Modern |
|---|---|---|
| Read | `checkRights()` = `issuetracker_view` **OR** `issuetracker_management` (`issueTrackerView.php:64-66`) | `api/issuetracker/index.php:31-51` (#959) |
| Write | `checkRights()` = `issuetracker_management` (`issueTrackerEdit.php:164-166`) | `api/issuetracker/index.php:49-54` (#960) |

## Files removed (10 files, 1 612 lines)

| File | Lines | Listed in issue |
|---|---|---|
| `lib/issuetrackers/issueTrackerView.php` | 66 | yes |
| `lib/issuetrackers/issueTrackerEdit.php` | 166 | yes |
| `lib/issuetrackers/issueTrackerCommands.class.php` | 310 | **no — orphaned** |
| `lib/ajax/getissuetrackercfgtemplate.php` | 49 | yes |
| `gui/templates/dashio/issuetrackers/issueTrackerView.tpl` | 108 | yes |
| `gui/templates/dashio/issuetrackers/issueTrackerEdit.tpl` | 250 | yes |
| `gui/templates/dashio/issuetrackers/issueTrackerEdit.new.tpl` | 230 | yes (dead snapshot) |
| `gui/templates/dashio/issuetrackers/issueTrackerEdit.ori.tpl` | 173 | yes (dead snapshot) |
| `gui/templates/tl-classic/issuetrackers/issueTrackerView.tpl` | 97 | no (dead theme) |
| `gui/templates/tl-classic/issuetrackers/issueTrackerEdit.tpl` | 163 | no (dead theme) |

`lib/issuetrackers/` contained exactly those 3 files, so the directory is gone
entirely.

The two `tl-classic` templates are in a provably dead theme:
`lib/functions/tlsmarty.inc.php:101` hardcodes `template_dir` to
`gui/templates/dashio/`, so the `tl-classic` tree is never loaded, and
`$tlCfg->gui->ux = 'tl-classic'` (`config.inc.php:161`) has no reader anywhere
in `lib/`. They were still deleted so they cannot drift out of sync with a
screen that no longer exists — the same treatment #947 gave its two
`tl-classic` templates. `gui/templates/tl-classic/**` as a whole remains a
separate cleanup.

## Entry points retargeted

`gui/templates/tl-classic/mainPageLeft.tpl:80-81` — the only surviving link.
It carried **two** dangling paths, not one:

```smarty
{$cfieldsView="lib/cfields/cfieldsView.php?tproject_id="}          ← orphaned by #957
{$issueTrackerView="lib/issuetrackers/issueTrackerView.php?..."}   ← orphaned by #966
```

Both now point at the modern HTML screens, keeping the trailing `=` because
the project id is appended by the caller at `:111`.

`lib/reqmgrsystems/reqMgrSystemEdit.php:6` — `@filesource
issueTrackerEdit.php` was a copy-paste of the wrong controller (it is the
Requirement Management System edit screen). Corrected to
`reqMgrSystemEdit.php`, since the old value now names a deleted file.

## Audit trail kept

The "Legacy … parity" comments are **deliberately retained**, as in #947:

| File | Comment lines |
|---|---|
| `api/issuetracker/index.php` | 16 |
| `gui/templates/issuetracker/issuetrackerView.html` | 9 |
| `lib/functions/tlIssueTracker.class.php` | 2 (`:789`, `:791`) |

They record which legacy line each modern branch was ported from — the whole
reason the #959-#965 audit is reproducible.

## Verification

- `php -l` clean on the edited PHP file.
- **Real Smarty 4.5.7 compile** of `gui/templates/tl-classic/mainPageLeft.tpl`
  (not a file check): 17 184 bytes generated, `cfieldsView.html` and
  `issuetrackerView.html` present in the compiled output, no legacy `.php`
  path. A lint would not catch this — `{lang_get}` and the `labels.*` access
  are custom-plugin territory.
- Repo-wide grep for the deleted paths across `lib/`, `gui/`, `api/`,
  `config*.php`, `cfg/`, `install/` leaves **no live reference** outside the
  audit comments above and `locale/*/strings.txt` section headers.
- No BFF, DB or i18n change.

## Deliberately not changed — follow-up candidates

| Artifact | Why it is now dead |
|---|---|
| `config.inc.php:686-689` — `$tlCfg->gui->issueTrackerView->pagination` | Added by #1591 for `issueTrackerView.tpl:27`. Read by nobody: the modern screen hardcodes `pageLength: 25` (`issuetrackerView.html:300`). |
| `gui/templates/conf/input_dimensions.conf:312,334` — `[issueTrackerEdit]` / `[issueTrackerView]` | Loaded only by `{config_load file="input_dimensions.conf" section=$cfg_section}` in the deleted templates. |
| 3 `lang_get` keys: `th_issuetracker_type`, `title_issuetracker_mgmt`, `th_issuetracker_env` | Referenced only by the deleted templates. The modern screen uses its own flat `it.*` namespace in `gui/templates/i18n/*.json` (all 10 bundles). |

These are reported rather than removed: config and i18n edits across 12 locale
files are their own change with their own risk, and the pagination block has a
plausible future consumer if the modern screen ever makes page size
configurable.

## Test suites

`tmp/TLU_Test_Cases.md` holds 4 historical regression suites that exercise the
legacy URL (`#1590`, `#1591`, `#1592`, `#1617`) and 5 that cover the modern
screen (`#960`–`#965`). The historical ones are past-fix records of bugs that
existed in the legacy screen, not a suite to re-run — the screen is gone by
design and re-running them is not possible. The modern screen's coverage is
`#960`–`#965`, all PASS.
