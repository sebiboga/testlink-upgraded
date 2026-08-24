# Issue 475 — `addMenuContext()` drops `$gui->countPlans`, hiding Metrics Dashboard / Builds / Platforms / Milestones menu links

**Issue:** [#475](https://github.com/sebiboga/testlink-upgraded/issues/475)
**Branch:** `fix/issue-475` · **Fix commit:** `f61665f4e` (on default branch; message mistakenly references #474 — this page documents the correct tracking issue)
**Status:** FIXED & VERIFIED (2026-08-24)

## Symptom

Four sidebar entries never rendered, even with an active test plan selected:

- **Metrics Dashboard** (Projects section)
- **Builds / Releases** (Test Plan section)
- **Add / Remove Platforms** (Test Plan section)
- **Milestones** (Test Case Execution section)

`gui/templates/dashio/aside.tpl` gates these on `{if $gui->countPlans > 0}`
(plus a fifth gate at line 189 for *Assign Test Plan Roles*). With
`countPlans` unset the Smarty comparison evaluates false on every page that
relies on the shared menu-context helper, regardless of how many test plans
exist.

Reproduced live (fresh DB, project "Issue475 Project" + active plan
"Issue475 Plan", admin logged in) by A/B toggling the single fix token in
the working tree. With `countPlans` dropped from the copy list, DOM
enumeration of the `asideMenu.php` iframe showed none of the four links;
restoring it made all four appear:

```json
{"Metrics Dashboard":true,"Builds / Releases":true,"Add / Remove Platforms":true,"Milestones":true}
```



## Root cause chain

1. `aside.tpl` renders five entries behind `{if $gui->countPlans > 0}`:
   line 121 (Metrics Dashboard), :189 (Assign Test Plan Roles),
   :194–196 (Builds / Releases), :206–208 (Add / Remove Platforms),
   :260–262 (Milestones).
2. `lib/functions/common.php:1663–1678` (`initUserEnv()`) computes
   `$gui->countPlans = count($gui->tplanSet)` from
   `$user->getAccessibleTestPlans()` — the value exists in the freshly
   built context object.
3. Pages that do not call `initUserEnv()` themselves get their menu vars
   filled by `TLSmarty::addMenuContext()`
   (`lib/functions/tlsmarty.inc.php:568`). Before commit f61665f4e its copy
   loop whitelisted only
   `array('showMenu','activeMenu','uri','logo','whoami','access')` —
   **`countPlans` was silently dropped**, so the template saw null.
4. Blast radius: every Smarty-rendered screen drawing `aside.tpl` without
   calling `initUserEnv()` directly. Pages calling `initUserEnv()` already
   had the value computed at common.php:1678 and were unaffected.

## Approach — why this fix

Chosen fix: add `'countPlans'` to the property-copy list at
`lib/functions/tlsmarty.inc.php:606`.

Alternatives rejected:
- *Have every page compute `countPlans` itself* — duplicates logic in many
  controllers, exactly what `addMenuContext()` exists to centralize.
- *Change aside.tpl to a different signal* — `countPlans > 0` is the
  correct semantic gate ("does this user have any accessible test plan?");
  changing templates would touch five sites instead of one token.

The copy loop only fills properties that are unset/null on the page's own
`$gui` (guard at :607–610), so pages computing their own `countPlans`
cannot be overwritten. Minimal, no side effects.

## Verification

Regression matrix executed live against http://localhost:8082
(fixtures: testproject id=99001, active testplan id=99002, login admin/admin):

| # | Case | Expected | Actual | Verdict |
|---|------|----------|--------|---------|
| R1 | fix present, plan exists | 4 gated links visible | DOM query: all four targets present | PASS |
| R2 | fix token reverted (A/B) | links hidden (original bug) | none of the four present | PASS |
| R3 | fix restored + reload | links visible again | all four `true` | PASS |
| S1 | navBar context | project+plan auto-selected | both comboboxes show fixture values | PASS |
| EV | Event Viewer delta | no new Error/Warning | events count stable (33 pre/post) | PASS |

Event Viewer note: during testing, 9 × `E_WARNING unserialize(): Error at
offset 0 of 2 bytes` appeared in `testproject.class.php` — caused by the
test fixture writing JSON `'{}'` into `testprojects.options` instead of the
PHP-serialized `'a:0:{}'` TestLink expects. Fixture repaired; flow
re-exercised with zero new warnings. Not a product bug.

Full suite recorded as `Suite 475` in `tmp/TLU_Test_Cases.md`.

## Files changed

- `lib/functions/tlsmarty.inc.php` — +3 −1 in commit f61665f4e (`'countPlans'` added to the copy list)
