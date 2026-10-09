# Issue #1726 — `planMilestonesEdit.php` answered HTTP 200 with a blank body for a bare URL (and every unlisted `doAction`)

**Issue:** [#1726](https://github.com/sebiboga/testlink-upgraded/issues/1726)
**Branch:** `fix/issue-1726-plan-milestones-blank-200`
**Status:** VERIFIED-FIXED — regression suite `Issue #1726` 10/10 PASS
**Direct successor of:** [#1627](Bugfix-Issue-1627-ReqMgrSystemEdit-DoAction-500.md) and
[#1722](Bugfix-Issue-1722-ReqMgrSystemEdit-Blank-200-Default-Render-Branch.md), which established
the graceful-302 sink for `reqMgrSystemEdit.php`; this is the same defect in the Plan Milestones
editor, but **worse**: it needs no crafted URL at all.

## Symptom

`lib/plan/planMilestonesEdit.php` answers **HTTP 200 with a 0-byte body** — a completely blank
page, title = raw URL, no markup, no error, no way back — for a bare request (no `doAction`) and
for any `doAction` the `switch` does not list. Because `init_args()` has **no whitelist** at all,
the blank 200 is the *default* outcome for a plain bookmark, not a crafted-URL corner case.
It produced **no Event Viewer row**, so the failure was undiagnosable in the field.

## Investigation (measured before any code was touched)

| URL | HTTP | bytes | `Location` | new `events` rows |
|---|---|---|---|---|
| `/lib/plan/planMilestonesEdit.php` (bare) | **200** | **0** | — | **0** |
| `/lib/plan/planMilestonesEdit.php?doAction=bogus` | **200** | **0** | — | **0** |
| `/lib/reqmgrsystems/reqMgrSystemEdit.php` (already fixed) | 302 | 0 | `…/reqmgrsystems/reqMgrSystemEdit.html` | 0 (its bare path has no `tLog`) |

`SELECT COUNT(*) FROM events` = 1 before and after both blank requests (the only row was the
`audit_login_succeeded` from the login) — the symptom is completely silent. The already-fixed
sibling proves the expected shape: a **302** to the modernized screen. No test project/plan was
needed to reproduce: the blank 200 happens before any project is resolved.

## Root cause chain

| # | file:line | what happens |
|---|---|---|
| 1 | `lib/plan/planMilestonesEdit.php:80` | `$args->doAction = isset($_REQUEST['doAction']) ? $_REQUEST['doAction'] : null;` — no `guiOpWhiteList` (the only one in `lib/` is `reqmgrsystems/reqMgrSystemCommands.class.php:38`), so an absent key yields `null` and any scalar passes through |
| 2 | `lib/plan/planMilestonesEdit.php:28-33` | `method_exists($commandMgr, $pFn)` is **false** for `null` (measured: `php -r 'var_dump(method_exists(new stdClass(), null));'` → `bool(false)`) ⇒ `$op` stays `null`; no work, no log |
| 3 | `lib/plan/planMilestonesEdit.php:127` | `$renderType = 'none'` |
| 4 | `lib/plan/planMilestonesEdit.php:128-156` | `switch($argsObj->doAction)` lists `edit/create/doDelete/doCreate/doUpdate` **only** and has **no `default`**, so an unlisted/absent action leaves `$renderType` at `'none'` |
| 5 | `lib/plan/planMilestonesEdit.php:158-172` (pre-fix) | `switch($renderType)` ends with `default: break;` ⇒ **HTTP 200, 0 bytes, nothing logged** |

**Why it breaks now:** step 5 is a 1.9.20 defect inherited as-is. What 2.0.1 changed is the
*contract*: #1627/#1722 established the house rule that "this controller can not do what you
asked" is answered with a graceful **302 back to the list** plus one ERROR row. This controller
was the remaining way to answer the same situation **without** that rule.

## Blast radius (measured)

| query | result |
|---|---|
| `grep -rn "guiOpWhiteList" lib/` | 1 hit, all in `reqmgrsystems` ⇒ planMilestones is one of several legacy editors with the missing-default sink |
| `lib/plan/planMilestonesView.php:93` + `gui/templates/dashio/plan/planMilestonesView.tpl:19-21` | all live legacy links carry an explicit `&doAction=…`; only direct/bookmarked/bare hits (or a future link that forgets `doAction`) reach the blank sink |
| modern screen | the aside menu now points at `gui/templates/plans/planMilestones.html`; unaffected by the legacy controller |
| i18n | **no** new user-facing string ⇒ **no** locale bundle touched |

## The fix (minimal, 1 file, +19 / −1)

`lib/plan/planMilestonesEdit.php` — the `default:` branch of the **`$renderType`** switch (the sink
of step 5) now does what #1722 did for the sibling controller:

```php
        default:
            // Refs #1726: $renderType is still 'none' here, so this controller can not
            // render the requested doAction (or there is none at all). init_args() has
            // no whitelist, so a bare URL reaches this sink, and until now it ended the
            // request with HTTP 200 and a 0-byte body: a silent blank page with no
            // Event Viewer row. Apply the same graceful 302 to the modernized Plan
            // Milestones screen that #1722 established for reqMgrSystemEdit.php, and
            // write one ERROR row so the next occurrence is greppable. The modern page
            // needs the test-plan context, so carry it forward when we have it.
            // The logged value is scalar-only, control chars stripped and length-capped
            // so a crafted query string can not inject multi-line / unbounded log text
            // (the hardening rationale of the sibling's #1731 follow-up).
            $rawAction = is_scalar($argsObj->doAction) ? (string)$argsObj->doAction : '';
            $rawAction = substr(preg_replace('/[[:cntrl:]]/', '?', $rawAction), 0, 200);
            tLog('planMilestonesEdit.php - requested action is not renderable - Value:' .
                 ($rawAction === '' ? '(none)' : $rawAction) . ' - File: ' . basename(__FILE__) .
                 ' - Function: ' . __FUNCTION__, 'ERROR');
            $base = isset($_SESSION['basehref']) ? $_SESSION['basehref'] : '/';
            $url = $base . 'gui/templates/plans/planMilestones.html';
            if (isset($argsObj->tplan_id) && intval($argsObj->tplan_id) > 0)
            {
                $url .= '?tplan_id=' . intval($argsObj->tplan_id);
            }
            header('Location: ' . $url, true, 302);
            exit();
```

### Why this method (and what was rejected)

* **The `$renderType` switch, not the `doAction` switch.** It is the single choke point every
  unrenderable path funnels through, so one branch closes every non-renderable input at once.
* **302 to the modernized list**, matching #1722. The modern page needs `tplan_id`
  (`api/milestones/index.php:needTplanId()`), so the context is carried forward when known.
* **One `tLog` ERROR row** — the blank page was otherwise undiagnosable.
* **The logged value is sanitized** (scalar-only, `[[:cntrl:]]` → `?`, capped at 200 chars) so a
  crafted query string can not inject multi-line or unbounded text into the Event Viewer, matching
  the hardening rationale of the sibling's #1731 follow-up. Measured: `?doAction=x%0AInjectedLine`
  logs the single row `Value:x?InjectedLine`.
* *Rejected — add a `guiOpWhiteList` and reject in `init_args()` (the #1627 shape).* It cannot
  distinguish "no `doAction`" from "unknown `doAction`" without risking the legacy create form
  (the shipped legacy edit form's hidden `doAction` is filled only by JS `onclick`,
  `gui/templates/dashio/plan/planMilestonesEdit.tpl:214,218`), and it is a larger change than this
  defect requires. The sink fix covers the same set of inputs with two lines.

## Verification

Regression suite `Issue #1726`, appended to `tmp/TLU_Test_Cases.md` — **10/10 PASS**
(`php -l` clean, suite gate 7/7).

| before | after |
|---|---|
| bare URL → 200 / 0 B / **0 events** | **302 → `planMilestones.html`**, 0 B, **1 ERROR event** |
| `?doAction=bogus` → 200 / 0 B / 0 events | **302 → the list**, 1 ERROR event |
| `?doAction=setAuditContext` → 200 / 0 B | **302 → the list** (real method, no `switch` case) |
| `?doAction=create&tplan_id=2` → 200, form | **unchanged** (HTTP 200, 15711 B) |
| `reqMgrSystemEdit.php` (sibling) → 302 | **unchanged**, no regression |
| Event Viewer path | exactly one `log_level=1` per refusal; **0** new `log_level=2` rows |
| browser: bare/bogus URL | lands on the modern list, title "Iteration 1 - Test Plan Milestones" |

## Known, deliberately NOT fixed here

* **#1886** — `?doAction[]=x` (array-shaped) is a *different* defect: `method_exists($commandMgr, array)`
  raises an uncaught `TypeError` ⇒ HTTP 500, 0 bytes, no `events` row. Twin of #1731 for this
  controller; filed with the `bug` label.
* **#1887** — the legacy create/edit form emits 8 `E_WARNING` rows per render because
  `initialize_gui()` (`lib/plan/planMilestonesEdit.php:202-216`) never sets the `managerURL`,
  `tplan_id`, `tproject_id`, `tprojOpt`, `cancelActionJS` properties the compiled template reads.
  Pre-existing, on a path this fix does not touch; filed with the `bug` label.
* **`checkRights()` return value discarded** at `lib/plan/planMilestonesEdit.php:23` (noted by the
  issue; report-only here).

## How to re-test in one command

```bash
# bare URL must be 302, not 200/0, and must add exactly one ERROR row
curl -s -b tl_cookie.txt -D - -o /dev/null http://localhost:8082/lib/plan/planMilestonesEdit.php | grep -i "^HTTP\|^location"
```
