# Bugfix — Issue #565: reqView E_WARNING "Undefined property: stdClass::$tproject_id"

## Problem

Opening any requirement view page (`lib/requirements/reqView.php`) generated 3
E_WARNING entries per request in the Event Viewer / `events` table:

```
E_WARNING Undefined property: stdClass::$tproject_id
  - in gui/templates_c/...reqViewVersionsViewer.tpl.php
```

The page rendered correctly, but form action URLs got an empty
`tproject_id=` parameter, falling back to the session project.

## Root Cause

`lib/requirements/reqView.php` → `initialize_gui()` (line 75) computes
`$target_id` from the requirement's actual project (lines 95-99) but only
passes it to `getGrants()` — never to `$gui->tproject_id`.

The template `reqViewVersionsViewer.tpl` references `{$gui->tproject_id}` in
4 form action URLs (lines 52, 128, 204, 241), all pointing to
`reqEdit.php?tproject_id={$gui->tproject_id}`.

## Fix

**File:** `lib/requirements/reqView.php` — 1 line added after line 99:

```php
$gui->tproject_id = $target_id;
```

This assigns the already-computed `$target_id` (which handles both normal and
"alien requirement" cases) to the gui bean, making it available to the template.

## Verification

- 0 E_WARNING entries in events table after fix (was 3 before)
- All 4 form action URLs contain `tproject_id=1` (was empty before)
- Code review: APPROVED (no side effects, correct placement)

## Files Changed

- `lib/requirements/reqView.php` (+1 line)
