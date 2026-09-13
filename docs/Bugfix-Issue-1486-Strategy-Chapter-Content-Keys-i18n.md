# Bugfix — Issue #1486: Test Strategy chapter pages show literal i18n keys for de/es/fr/it/ja/pt/ru/zh

## Problem

17 Test Strategy chapter pages (all siblings of the hub `testStrategy.html`,
excluding the already-fixed `intro.html`) rendered **literal i18n keys** as
visible text (document title, header, sub, card titles and bullets) whenever
the active locale was one of de/es/fr/it/ja/pt/ru/zh. English and Romanian were
fine because the `ts.<chapter>*` content keys were only shipped in `en.json` +
`ro.json`.

Affected pages:
`approach.html`, `bugLifecycle.html`, `bugStructure.html`, `changeConfig.html`,
`communication.html`, `defectManagement.html`, `deliverables.html`,
`environments.html`, `metrics.html`, `objectives.html`, `release.html`,
`risks.html`, `roles.html`, `testLevels.html`, `testTypes.html`, `tools.html`,
`training.html`.

Impact (reproduced in headless Chrome):

- `objectives.html?locale=de` → document title `ts.objectivesHeader`, header
  `ts.objectivesHeader`, sub `ts.objectivesHeaderSub`, card titles/bullets
  `ts.objectivesC1Title`, `ts.objectivesC1a/b/c`, `ts.objectivesC2Title`,
  `ts.objectivesC2a/b/c`.
- `training.html?locale=zh` → title `ts.trainingHeader`, body literal keys.
- Hub keys (`ts.chapter*`), the back-link (`ts.backToStrategy`) and footer title
  (`ts.header`) translated fine — only the per-chapter content strings broke.

Measured correction to the report: `scope.html` and `exitCriteria.html` were
listed in the issue but a per-page scan proved they already ship translated
keys in all 8 bundles and render correctly — they were **not** part of this fix.

## Root Cause

Every chapter page defines its visible strings with `data-i18n="ts.<chapter>*"`
attributes. `TLi18n.apply()` (`gui/templates/i18n/i18n.js:148-153`) writes
`TLi18n.t(key)` into each element, and `t()` returns `_strings[key] || key`
(`gui/templates/i18n/i18n.js:134-135`) — a **missing** key renders as the
literal key string (dev-visible by design).

The 179 content keys (`ts.objectivesHeader` … `ts.bugLifecycleC2d`) existed
only in `en.json` (~lines 3364+) and `ro.json`. The 8 other bundles had only
the hub keys (`ts.chapterObjectives`, …) so loading e.g. `de.json` and applying
the translations left the chapter strings as literal keys.

**Blast radius:** the 17 chapter pages above (179 unique keys × 8 bundles).
The identical missing-key defect on the intro page was already fixed in #1440
(the proven reference pattern). No PHP/BFF code involved; pure asset
translation.

## Fix

Branch `fix/issue-1486`, commit `945679c5b`
(`fix(strategy): translate 179 chapter content keys in all 8 non-en/ro bundles
(Refs #1486)`) — **8 files, +179 keys each** (`de`, `es`, `fr`, `it`, `ja`,
`pt`, `ru`, `zh`).

Each bundle received native translations for all 179 keys, grouped per chapter
in the same order as `en.json`/`ro.json`. Header keys were aligned to the
wording already approved in that bundle's hub key (`ts.objectivesHeader` =
`ts.chapterObjectives`, `ts.approachHeader` = `ts.chapterApproach`, …) so the
hub card and the chapter page agree. Example (German):

```json
"ts.objectivesHeader": "Qualitätsziele",
"ts.objectivesHeaderSub": "messbare Qualitätsziele des Releases",
"ts.objectivesC1Title": "Qualitätsziele",
"ts.objectivesC1a": "Funktionale Korrektheit der geforderten Geschäftsprozesse.",
"ts.objectivesC2c": "Vereinbarte Rückverfolgbarkeit von Zielen zu Anforderungen.",
```

**Why this method:** minimal and i18n-complete. The pages already used TLi18n;
nothing in HTML/JS needed to change. Only the 8 bundles that missed the keys
were touched; `en.json`/`ro.json` were already correct and left untouched.
Every bundle was validated with `python3 -m json.tool` before commit.

**Rejected alternatives:** (a) removing `data-i18n` and reverting to hard-coded
English — would drop existing translations; (b) changing `TLi18n.t()` fallback
behavior — a global behavioral change affecting ~100 modernized screens;
(c) translating only a subset of chapters or locales — would leave pages broken.

## Files Changed

- 8× `gui/templates/i18n/{de,es,fr,it,ja,pt,ru,zh}.json` — +179 translated
  `ts.<chapter>*` keys each (1432 lines total).
- `tmp/TLU_Test_Cases.md` — regression suite "Regression — Issue #1486"
  (9 cases, 9/9 PASS).
- `docs/Bugfix-Issue-1486-Strategy-Chapter-Content-Keys-i18n.md` — this
  write-up (mirror, without image lines).
- `docs/screenshots/issue-1486-{objectives-de-before,objectives-de-fixed,training-zh-before,training-zh-fixed,bugstructure-es-fixed}.png`.
- `CHANGELOG` — one line under the 2.0.1 key-bugfix section (Refs #1486).

## Screenshots

Stored in the repo under `docs/screenshots/` (also mirrored in the wiki with
the same filenames):

- `issue-1486-objectives-de-before.png` — `objectives.html?locale=de` pre-fix
  (literal keys), the primary repro.
- `issue-1486-objectives-de-fixed.png` — post-fix `?locale=de`.
- `issue-1486-training-zh-before.png` — `training.html?locale=zh` pre-fix.
- `issue-1486-training-zh-fixed.png` — post-fix `?locale=zh`.
- `issue-1486-bugstructure-es-fixed.png` — post-fix `bugStructure.html?locale=es`.

## Verification

Post-fix: the 17 affected chapter pages each render title, header, sub, cards
and bullets in the target language across de/es/fr/it/ja/pt/ru/zh with **zero**
literal `ts.<chapter>*` keys (verified in headless Chrome for de, zh, ru, es;
automated key scan for all 20 strategy pages vs all 10 bundles = 0 missing).
`en` and `ro` unchanged and still correct. All 10 bundles contain every used
`ts.*` key exactly once and pass `python3 -m json.tool`. `events` table: no new
ERROR/WARNING rows; browser console clean on every locale. Full matrix:
`tmp/TLU_Test_Cases.md` suite 1486 (9/9 PASS).