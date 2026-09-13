# Bugfix — Issue #1456: Test Strategy "Training Plan" chapter verified; General Overview note regression fixed

## Context

#1456 is the tracking issue for the *Test Strategy chapter: Training Plan*
(page `gui/templates/strategy/training.html`, icon `fa-graduation-cap`,
ASIDE sub-item **Training**, General Overview card **17**, `ts.training*`
i18n keys in en.json + ro.json). The implementation already lives on the
default branch (`f934433d7` added all chapter pages + nav sub-items,
`1ee2e0501` fixed the missing jQuery on the chapter pages, `945679c5b`
translated the content keys in the 8 non-en/ro bundles). This run verified the
chapter end-to-end and found one real, user-visible defect in its
presentation area: the **General Overview note was stale and contradicted the
rendered chapter grid**.

## The defect (regression of the already-fixed #1458)

- The info note `ts.navAdded3` on General Overview read *"All 18 chapters of a
  Test Strategy are listed below…"* while the same page rendered **28** chapter
  cards.
- `dcdc97105` (the #1458 fix) had recounted the note to "18 + Severity
  Configuration" in all 10 bundles. Then `5888975934`
  (`feat(strategy): add Bug Structure and Bug Lifecycle chapters`) **reverted
  en.json + ro.json** back to the original "All 18 chapters…" wording while
  appending chapters 20–21 to the BFF map, and `8c28f47af` appended chapters
  22–28. The other 8 bundles kept the old intermediate #1458 text — so the
  bundles also diverged from each other, and the static fallback
  `testStrategy.html:63` still said "18".

## Root cause

Any hardcoded chapter count in a user-facing note is brittle: each chapter
addition silently desynchronizes the note from the grid (this was the second
time it went stale). The safe fix is to drop the count entirely.

## Fix

Branch `fix/issue-1456-training-chapter`:

- `ts.navAdded3` in **all 10** locale bundles now reads *"All chapters of a
  Test Strategy are listed below; the chapters with a dedicated page can be
  opened directly."* (with native equivalents for de/es/fr/it/ja/pt/ro/ru/zh).
  One-line change per file; every bundle passes `python3 -m json.tool`.
- `gui/templates/strategy/testStrategy.html:63` (static pre-i18n fallback)
  updated to the same wording.

## Why this approach

A count-free sentence (a) restores the intent of the #1458 fix, (b) makes the
note immune to future chapter additions, and (c) is a minimal 1-line-per-file
change with zero behavior impact — no BFF, no JS, no logic touched. The
alternative ("All 28 chapters") was rejected because it would go stale the
moment a 29th chapter lands.

## Verification

- Regression suite `Regression — Issue #1456` in `tmp/TLU_Test_Cases.md`:
  **8/8 PASS** — chapter page EN/RO loads with full translation, ASIDE wiring +
  `fa-graduation-cap` icon, overview card 17 opens the chapter, note text EN+RO
  count-free, 28 cards = BFF 28 entries, all 10 bundles valid,
  `events` table clean (no Error/Warning), browser console clean.
- Screenshots: `docs/screenshots/issue-1456-overview-stale-note-before.png`,
  `docs/screenshots/issue-1456-overview-note-after.png`,
  `docs/screenshots/issue-1456-training-ro.png`.