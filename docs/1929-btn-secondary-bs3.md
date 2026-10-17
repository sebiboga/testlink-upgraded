# Issue #1929 — Dashio screens use Bootstrap 4/5 class btn-secondary while repo ships Bootstrap 3.4.1

## Symptom
Modal Cancel/Close and other secondary buttons rendered unstyled across modern Dashio screens because templates used BS4/5 class `btn-secondary` but the theme ships Bootstrap 3.4.1 (which has no `.btn-secondary`; BS3 equivalent is `.btn-default`).

## Root cause
- Dashio loads `gui/templates/dashio/lib/bootstrap/css/bootstrap.min.css` (Bootstrap v3.4.1, BS3)
- BS3 lacks `.btn-secondary` (introduced in BS4); BS3 uses `.btn-default` for grey/secondary styling
- Modernized HTML templates used `btn-secondary` across 37 files (69 occurrences)

## Fix
Replaced all occurrences of `btn-secondary` with `btn-default` in the 37 affected HTML templates under `gui/templates/`. Class name substitution only; no functional changes.

## Files changed
37 HTML template files (69 class occurrences total). No CSS, JS, or backend PHP modified.

## Verification
- `grep -rn 'btn-secondary' gui/templates` returns 0 after fix
- Secondary buttons in modals (testEnvironments, buildsView, reqView, tcView, etc.) now use BS3 `btn-default` styling
- No new console errors; Event Viewer shows no new Error/Warning entries
- Regression test suite added to `tmp/TLU_Test_Cases.md` (Regression — Issue #1929)
