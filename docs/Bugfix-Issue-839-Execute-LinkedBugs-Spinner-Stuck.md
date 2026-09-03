# Bugfix Issue #839 — "Loading linked bugs…" spinner stuck forever

## Problem
After loading a test case with linked bugs in Execute Tests, the Linked Bugs
card showed a spinning `Loading linked bugs…` indefinitely. Never resolved.

## Root cause — two independent paths
1. **Client `.fail()` handler was empty** (`loadLinkedBugs()`): on BFF error
   the placeholder rows (empty `title`, `unavailable=false`) left
   `renderLinkedBugsTable()` `pending` check permanently true.
2. **Server returned empty titles when tracker unavailable**: if
   `getInterfaceObject()` threw, `$itsT=null` → bugs returned unchanged
   (empty title, unavailable=false) → same stuck spinner.

## Fix (commit `203c8fcba`)
- `.fail()` handler now marks all bugs `unavailable=true` and re-renders.
- `.done()` handler detects title-less non-unavailable placeholders and marks
  them `unavailable=true`.
- Added 30s `$.get` timeout.

## Verification
- Tracker unavailable → "Unavailable" badges (no spinner).
- Normal tracker → titles/status/labels render correctly.
