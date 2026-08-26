# Bugfix Issue #551 — DataTablesLengthMenu Include-Param Typo (PHP8 Warnings)

## Problem

Three Smarty templates passed `DataTablesLengthMenu` (lowercase `l`) as an include
parameter to `DataTables.inc.tpl`, which reads `$DataTablesLengthMenu` (uppercase `L`).
Smarty variable names are case-sensitive, so the variable was undefined/null on every
render, causing PHP 8.1+ E_WARNING:

- `Undefined array key "DataTablesLengthMenu"`
- `Attempt to read property "value" on null`

## Root Cause

Copy-paste typo — `lengthMenu` vs `LengthMenu`. The same bug was already fixed in
`tcAssign2Tplan.tpl` by issue #541, but not propagated to the three remaining files.

## Fix

One-character capitalization (`l` → `L`) in each file:

| File | Line |
|---|---|
| `gui/templates/dashio/plan/buildView.tpl` | 43 |
| `gui/templates/dashio/testcases/containerMoveTC.tpl` | 28 |
| `gui/templates/dashio/cfields/cfieldsTprojectAssign.tpl` | 26 |

## Verification

- Compiled templates now pass `DataTablesLengthMenu` (uppercase L) — confirmed via
  grep of `gui/templates_c/`.
- DataTable renders with correct `lengthMenu` dropdown (20, 40, 60, All).
- Event Viewer: zero new E_WARNING entries.
- Console: zero JavaScript errors.

## Affected Pages

- Build Management (`buildView.php`)
- Move/Copy Test Cases dialog (`containerMoveTC.tpl`)
- Assign Custom Fields to Test Project (`cfieldsTprojectAssign.tpl`)
