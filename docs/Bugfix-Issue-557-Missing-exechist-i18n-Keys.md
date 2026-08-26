# Bugfix — Issue #557: Missing i18n Keys exechist.colBuild / exechist.colStatus / exechist.footer

**Date:** 2026-08-26 · **Branch:** `fix/issue-557` · **Commit:** `2ceca4c79`

## Summary

Three i18n keys referenced by `gui/templates/execute/execHistory.html` were missing from all 10 locale bundles, causing the raw key names to render as visible text in the Execution History screen.

## Symptom

- Footer at `execHistory.html:137` rendered literal string `exechist.footer` instead of a translated tagline
- Table headers at lines 123 and 126 (`colBuild` / `colStatus`) would render raw key names when a test case with execution data is loaded

## Root Cause

The Execution History modernization (#542) added `data-i18n` attributes referencing these 3 keys but never added the corresponding entries to any locale bundle. `tools/lint_i18n.py` only checks parity vs `en.json`, so keys absent from `en.json` itself went undetected.

## Fix

Added `exechist.colBuild`, `exechist.colStatus`, and `exechist.footer` to all 10 locale JSON files (`en/ro/de/es/fr/it/pt/ru/ja/zh.json`) with proper translations derived from existing `exe.footer` (same footer pattern) and `exechist.status`/`exechist.build` (same column semantics).

## Files Changed

- `gui/templates/i18n/en.json` — 3 keys added
- `gui/templates/i18n/ro.json` — 3 keys added
- `gui/templates/i18n/de.json` — 3 keys added
- `gui/templates/i18n/es.json` — 3 keys added
- `gui/templates/i18n/fr.json` — 3 keys added
- `gui/templates/i18n/it.json` — 3 keys added
- `gui/templates/i18n/pt.json` — 3 keys added
- `gui/templates/i18n/ru.json` — 3 keys added
- `gui/templates/i18n/ja.json` — 3 keys added
- `gui/templates/i18n/zh.json` — 3 keys added

## Verification

- All 10 JSON files pass `python3 -m json.tool` validation
- Footer renders correctly in English, Romanian, and Japanese (verified in headless Chrome)
- Language switching works correctly for all 3 new keys
- Event Viewer shows no new Error/Warning entries after the fix

## Follow-up

Consider extending `tools/lint_i18n.py` to cross-check keys referenced by `data-i18n` / `TLi18n.t()` in `gui/templates/**/*.html` to catch missing keys that are absent from `en.json` itself.
