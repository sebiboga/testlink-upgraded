# projectView.php Zero-Test-Projects E_WARNING Fix

## Issue #550

Fixed: opening `lib/project/projectView.php` on an installation with **zero
test projects** wrote **three E_WARNING entries per render** into the Event
Viewer and rendered the template's error branch instead of the create form.

## Symptom

Event Viewer gained three entries per render (`log_level=2`, source `PHP`):

```
E_WARNING Undefined property: stdClass::$mgt_view_events - ...file.projectEdit.tpl.php - Line 126
E_WARNING Undefined property: stdClass::$found            - ...file.projectEdit.tpl.php - Line 147
E_WARNING Undefined property: stdClass::$tprojectName     - ...file.projectEdit.tpl.php - Line 422
```

The page itself showed only the *Test Project Management* heading plus the
error-branch placeholder text (`<<<projectEdit.tpl>>>` / "Error:") — **no
create form**, although the code explicitly intends to launch the create form
(`$gui->doAction = 'create'`).

## Repro steps

1. Fresh DB import (`testprojects` table empty), log in as admin/admin.
2. Open `lib/project/projectView.php`.
3. Event Viewer shows the three E_WARNING entries above; the page shows no
   form.

## Root Cause

When `$gui->itemQty == 0`, `projectView.php` switched its template to
`projectEdit.tpl` — but its own `initializeGui()` builds only the properties
needed by `projectView.tpl`. The compiled `projectEdit.tpl` unconditionally
reads:

* `$gui->mgt_view_events` (tpl line 92),
* `$gui->found == "yes"` (tpl line 107) — undefined ⇒ falsy ⇒ the `{else}`
  error branch rendered instead of the form,
* and inside the branches: `$gui->tprojectName` plus ~a dozen further
  properties (`projectOptions`, `issueTrackers`, `tcasePrefix`, …) that only
  `projectEdit.php::initializeGui()/create()` ever set.

So launching the edit/create template from the view controller was broken by
design: warning spam guaranteed on PHP 8.x, and the visible output was the
template's error fallback.

## Fix Approach — delegate instead of duplicating

Two options were considered:

1. **Initialize the missing properties** in `projectView.php::initializeGui()`
   (the issue reporter's suggestion). Downside: silencing only
   `mgt_view_events/found/tprojectName` still renders the error branch; making
   the *form* render would require replicating ~15 properties from
   `projectEdit.php`'s create flow — duplicated logic prone to drift.
2. **Hand over to the regular create screen** (chosen): when there are zero
   accessible test projects, `projectView.php` now redirects to the canonical,
   battle-tested controller which builds a complete gui itself:

```php
if( $gui->itemQty == 0 ) {
    redirect($_SESSION['basehref'] . 'lib/project/projectEdit.php?doAction=create');
}
```

`redirect()` is the standard TestLink helper (JS-based, works inside the
mainframe iframe); the created form posts back into `projectEdit.php`
(`doCreate`) exactly like the normal menu-driven flow. Smallest diff, zero
logic duplication, restores the intended UX.

### Files changed

* `lib/project/projectView.php` — replaced the deficient
  `projectEdit.tpl`-launch with a redirect to
  `lib/project/projectEdit.php?doAction=create`.

## Verification

| # | Check | Result |
|---|-------|--------|
| 1 | Pre-fix repro → 3 E_WARNINGs + error-branch garbage | reproduced |
| 2 | Post-fix render → redirect to create form, complete UI | PASS |
| 3 | Events table → zero E_WARNING entries | PASS |
| 4 | Create test project through redirected form → list view shows it, audit entry only | PASS |
| 5 | Fixture removed → re-render → still clean | PASS |


Regression suite: `tmp/TLU_Test_Cases.md`, Suite 32 (Suite ID 39).
