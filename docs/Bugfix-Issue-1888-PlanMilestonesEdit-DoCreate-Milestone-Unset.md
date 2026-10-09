# Issue #1888 — `planMilestonesEdit.php` failed `doCreate` re-rendered the form with `$gui->milestone` unset (16 E_WARNING rows per submit)

**Issue:** [#1888](https://github.com/sebiboga/testlink-upgraded/issues/1888)
**Branch:** `fix/issue-1888-plandmilestone-docreate-milestone`
**Status:** VERIFIED-FIXED — regression suite `Issue #1888` 8/8 PASS (`tmp/verify_1888.sh` 9/9 PASS)
**Related:** [#1887](Bugfix-Issue-1887-PlanMilestonesEdit-CreateEdit-Warnings.md) (the GET twin, fixed
in `0551d44fa`; this issue was filed there as the remaining failure-path defect),
[#1726](Bugfix-Issue-1726-PlanMilestonesEdit-Blank-200-Default-Render-Branch.md).

## Symptom

Submitting the legacy Plan Milestones **create** form
(`POST lib/plan/planMilestonesEdit.php`, `doAction=doCreate`) with data that fails server-side
validation (e.g. a `target_date` that does not match `date_format`) wrote **16 `log_level=2`
E_WARNING rows** into the Event Viewer for a single failed submit, and re-rendered the form with
empty fields. The action itself is correct — the request reached the validation branch and no row
was inserted — but every failed submit polluted the diagnostic log.

## Investigation (measured before any code was touched)

Environment: app `http://localhost:8082` (PHP built-in server, docroot = repo root), PHP 8.3.35,
DB `testlink` freshly imported (0 projects / 0 plans / 0 milestones), `admin`/`admin`.
`cfg/const.inc.php:271` sets `$tlCfg->date_format = '%d/%m/%Y'`.
Fixture `tmp/fixtures_1888.php` created testproject `MS88` (id=1, priority enabled) and testplan
`MS88 Plan` (id=2) — matching the issue's `tproject_id=1`, `tplan_id=2`.

| probe | result |
|---|---|
| `MAX(id) FROM events` before the POST | 2 |
| `POST doAction=doCreate ... target_date=2099-01-01 ...` | HTTP 200, 17036 bytes |
| `MAX(id) FROM events` after the POST | **18** (+16) |
| `COUNT(*) FROM milestones` before / after | 0 / 0 (no partial insert) |

The 16 rows were the alternating pair `Undefined property: stdClass::$milestone` /
`Trying to access array offset on null`, i.e. the 8 unguarded `{$gui->milestone.*}` reads in
`gui/templates/dashio/plan/planMilestonesEdit.tpl` times two warnings each.

## Root cause chain

| # | file:line | what happens |
|---|---|---|
| 1 | `lib/plan/planMilestonesEdit.php:32` | `$op = $commandMgr->doCreate($args, $_SESSION['basehref']);` |
| 2 | `lib/plan/planMilestonesCommands.class.php:105-109` | `doCreate()` builds `$guiObj` and assigns `main_descr`, `action_descr`, `submit_button_label`, `template=null` — but never `milestone`. |
| 3 | `lib/plan/planMilestonesCommands.class.php:124-128` | `is_valid_date($argsObj->target_date_original, $date_format_cfg)` is false for `2099-01-01` vs `%d/%m/%Y`; `user_feedback = warning_invalid_date`; `template` stays `null`. |
| 4 | `lib/plan/planMilestonesEdit.php:136-140` | `renderGui()` copies `get_object_vars($opObj)` onto `$gui`; `$gui->milestone` therefore remains undefined. |
| 5 | `lib/plan/planMilestonesEdit.php:144` | `is_null($opObj->template)` → renders `templateCfg->default_template` = `planMilestonesEdit.tpl`. |
| 6 | `gui/templates/dashio/plan/planMilestonesEdit.tpl:110,122,133,143,158,175,184,193,206` | 8 unguarded reads of `{$gui->milestone.*}` → 16 PHP 8 E_WARNINGs, persisted by `tLog()`. |

**Why it breaks now:** same family as #1887. Under PHP 5/7 an unset object-property read was an
`E_NOTICE` that Smarty/TestLink suppressed; PHP 8 promoted it to `E_WARNING`, and TestLink persists
every warning into `events`. Only the POST **failure** branch was affected: `create()` (`:47-52`)
and `edit()` (`:68`) set `milestone`, and `doUpdate()` (`:195`) calls `edit()` first, so the
create-failure path was the only one missing the property.

## Blast radius

* Only `doCreate()` in `lib/plan/planMilestonesCommands.class.php`; the two sibling display
  methods already set the property.
* Only `gui/templates/dashio/plan/planMilestonesEdit.tpl` reads `{$gui->milestone.*}` (8 reads).
* No DB write is involved in the failure branch (`milestones` row count stayed 0).

## The fix (minimal, 1 file, +17 lines)

`doCreate()` now initialises `$guiObj->milestone` immediately after `$guiObj = new stdClass()`,
pre-filled from the submitted `$argsObj`, mirroring `create()` and the template's own key mapping:

```php
$guiObj->milestone = array('id' => 0, 'name' => $argsObj->name,
                           'target_date' => htmlspecialchars((string)$argsObj->target_date_original, ENT_QUOTES, 'UTF-8'),
                           'start_date' => htmlspecialchars((string)$argsObj->start_date_original, ENT_QUOTES, 'UTF-8'),
                           'high_percentage' => $argsObj->low_priority_tcases,
                           'medium_percentage' => $argsObj->medium_priority_tcases,
                           'low_percentage' => $argsObj->high_priority_tcases,
                           'testplan_id' => $argsObj->tplan_id,
                           'testplan_name' => $argsObj->tplan_name,);
```

### Why this method (and what was rejected)

* **Initialise the property, not guard the template.** Wrapping the 8 reads in
  `{if isset($gui->milestone)}` / `|default:''` would silence the noise but leave the form blank.
  Initialising in PHP removes the warnings *and* delivers a pre-filled re-render.
* **Pre-fill from `*_original`.** `init_args()` stores the raw submitted strings in
  `target_date_original`/`start_date_original` (the ISO-normalised `target_date`/`start_date` are
  only set when the localised parse succeeds). Echoing the `*_original` fields back gives the user
  exactly what they typed; the template maps `high_percentage` → the `low_priority_tcases` input,
  and so on, which the assignment above mirrors.
* **HTML-escape the date fields.** The template echoes `{$gui->milestone.target_date}` /
  `.start_date` **without** `|escape` (`gui/templates/dashio/plan/planMilestonesEdit.tpl:143,158`),
  so pre-filling them with raw user input would have introduced a reflected XSS (attribute
  injection). Caught in code review and fixed by `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')` at
  assignment; `milestone_name` and the percentage fields already use `|escape` in the template.
  Verified: `target_date=x/" autofocus onfocus="alert(1)` now renders
  `value="x/&quot; autofocus onfocus=&quot;alert(1)"` (quotes contained, no breakout).
* **Do not change the success path.** On success `template` becomes the view URL and `renderGui()`
  issues the 302; the extra array is harmless there.

## After the fix

A failed create submit now re-renders the form with the validation message and the previously
entered values, and writes **zero** Event Viewer rows.

## Verification

Regression suite `Issue #1888`, appended to `tmp/TLU_Test_Cases.md`; harness `tmp/verify_1888.sh`.

| case | before | after |
|---|---|---|
| `doCreate` invalid date `2099-01-01` | 200, 17036 B, **+16 warnings** | 200, 17053 B, **+0 warnings**, form pre-filled |
| `doCreate` target before start | +16 warnings | **+0 warnings** |
| `doCreate` past date | +16 warnings | **+0 warnings** |
| `doCreate` valid `31/12/2099` | 302, +0 | 302 → view, milestone inserted, +0 |
| `GET ?doAction=create` (#1887 guard) | +8 (pre-#1887) | 200, **+0** |
| `GET ?doAction=edit` (#1887 guard) | +8 (pre-#1887) | 200, **+0** |
| `GET ?doAction=bogus` (#1726 guard) | 302 + 1 INFO | 302, **+0 warnings** |
| `doCreate` XSS payload in `target_date` | attribute breakout (new vector) | rendered escaped, no breakout |
| `php -l lib/plan/planMilestonesCommands.class.php` | — | No syntax errors detected |

## Known, deliberately NOT fixed here

* **#1889** — a date value with **no delimiter** (`target_date=aaaaaa`) makes
  `split_localized_date()` (`lib/functions/common.php:1020`) call `explode(null, ...)`, a PHP 8
  `ValueError` → HTTP 500, 0 bytes. Pre-existing, broader than this screen; filed with the `bug`
  label while testing (FIX-ISSUE §4).

## How to re-test in one command

```bash
php tmp/fixtures_1888.php          # tproject=1, testplan=2
bash tmp/verify_1888.sh            # 9/9 PASS, exit 0
```
