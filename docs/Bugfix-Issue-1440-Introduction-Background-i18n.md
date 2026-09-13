# Bugfix — Issue #1440: Test Strategy "Introduction & Background" shows literal i18n keys outside en/ro

## Problem

The **Introduction & Background** chapter page of the Test Strategy
(`gui/templates/strategy/intro.html`) is fully functional — it opens from the
ASIDE sub-menu ("Test Strategy → Introduction"), from the hub
`testStrategy.html` (chapter 1 card, **Open chapter** button) and renders the
two content cards (Purpose of the document / Background) — **but** for the
8 locales other than English and Romanian it displayed raw i18n keys instead
of text.

Impact (reproduced in browser):

- `?locale=de` rendered document title `ts.introHeader`, header `ts.introHeader`,
  sub `ts.introHeaderSub`, card titles/bullets `ts.introC1Title`,
  `ts.introC1a/b/c`, `ts.introC2Title`, `ts.introC2a/b/c`.
- The hub keys (`ts.chapterIntro`), the back-link (`ts.backToStrategy`) and the
  footer title (`ts.header`) translated fine in German — only the intro
  content strings broke.
- English and Romanian rendered correctly.

Repro (browser):

1. `http://localhost:8082/login.php`, sign in `admin/admin`.
2. ASIDE → Test Strategy → **Introduction** (or hub → chapter 1 → **Open
   chapter**).
3. In the mainframe, switch the locale switcher to **German** (or open
   `intro.html?locale=de` directly).

## Root Cause

`gui/templates/strategy/intro.html` defines every visible string with
`data-i18n="ts.intro*"` (lines 7, 29-30, 42, 44-46, 50, 52-54, 59).
`TLi18n.apply()` (`gui/templates/i18n/i18n.js:148-153`) writes
`TLi18n.t(key)` into each element, and `t()` returns `_strings[key] || key`
(`i18n.js:134-135`) — for a **missing** key it returns the literal key string
(dev-visible by design).

The content keys `ts.introHeader`, `ts.introHeaderSub`, `ts.introC1Title`,
`ts.introC1a/b/c`, `ts.introC2Title`, `ts.introC2a/b/c` existed only in
`en.json` (lines 3364-3373) and `ro.json` (same lines). The other eight
bundles (`de/es/fr/it/ja/pt/ru/zh.json`) had only the hub keys
(`ts.chapterIntro` / `ts.chapterIntroDesc`), so loading `de.json` and applying
the translations left the intro strings as literal keys.

**Blast radius:** the Introduction & Background page (issue #1440). The
identical missing-key defect exists on the 19 sibling chapter pages — tracked
separately in **#1486** (bug) so each chapter issue closes only its own keys;
the fix pattern here is the reference implementation for it.

## Fix

Branch `fix/issue-1440`, commit `cbb2920f5`
(`fix(strategy): translate Introduction & Background content keys in all 8
non-en/ro bundles (Refs #1440)`) — **8 files, +10 lines each** (`de`, `es`,
`fr`, `it`, `ja`, `pt`, `ru`, `zh`).

For every bundle, the 10 `ts.intro*` content keys were inserted immediately
after the existing `ts.chapterIntroDesc` entry, with native translations that
reuse the wording already approved in that bundle's hub key
(`ts.chapterIntro`/`ts.chapterIntroDesc`) so the hub card and the chapter page
agree. Example (German):

```json
"ts.introHeader": "Einleitung & Hintergrund",
"ts.introHeaderSub": "Kontext und Zweck des Teststrategie-Dokuments",
"ts.introC1Title": "Zweck des Dokuments",
"ts.introC1a": "Definiert den allgemeinen Qualitäts- und Testansatz für das Produkt.",
...
```

**Why this method:** minimal and i18n-complete. The page already used TLi18n;
nothing in HTML/JS needed to change. Only the 8 bundles that missed the keys
were touched; `en.json`/`ro.json` were already correct and left untouched.
Every bundle was validated with `python3 -m json.tool` before commit.

**Rejected alternatives:** (a) removing `data-i18n` and reverting to hard-coded
English — would drop the translations that do exist; (b) changing
`TLi18n.t()` to fall back to the element's HTML default — a global behavioral
change affecting ~100 modernized screens; (c) translating only German — would
leave 7 locales broken.

## Files Changed

- 8× `gui/templates/i18n/{de,es,fr,it,ja,pt,ru,zh}.json` — +10 translated
  `ts.intro*` keys each (80 lines total).
- `tmp/TLU_Test_Cases.md` — regression suite "Regression — Issue #1440"
  (15 cases, 15/15 PASS).
- `docs/Bugfix-Issue-1440-Introduction-Background-i18n.md` — this write-up
  (mirror, without image lines).
- `CHANGELOG` — one line under the 2.0.1 key-bugfix section (Refs #1440).

## Screenshots

Stored in the repo under `docs/screenshots/` (also mirrored in the wiki with
the same filenames):

- `issue-1440-intro-en.png` — `intro.html` English, pre-fix render (unchanged).
- `issue-1440-intro-ro.png` — `intro.html?locale=ro` Romanian, pre-fix render (unchanged).
- `issue-1440-intro-de-fixed.png` — post-fix `?locale=de` (was the literal-key repro).
- `issue-1440-intro-zh-fixed.png` — post-fix `?locale=zh`.

## Verification

Post-fix: `?locale=<de,es,fr,it,ja,pt,ru,zh>` each render the title, header,
sub, both cards and all 6 bullets in the target language with **zero** literal
`ts.intro*` keys (verified in headless Chrome); `en` and `ro` unchanged and
still correct. All 10 bundles contain all 10 `ts.intro*` keys exactly once and
pass `python3 -m json.tool`. `events` table: no new ERROR/WARNING rows; browser
console clean on every locale. Full matrix: `tmp/TLU_Test_Cases.md` suite 1440
(15/15 PASS).