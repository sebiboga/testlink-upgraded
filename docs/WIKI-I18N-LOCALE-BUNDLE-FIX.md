# i18n Locale Bundle Contamination Fix

## Issue #543

Fixed: locale bundles contained **wrong-language content** — selecting
*Portuguese* rendered Romanian, *Română* rendered Russian, *Russian*
rendered Chinese on every modernized screen using TLi18n.

## Symptom

On any modernized screen (e.g. Test Case Viewer
`gui/templates/testcases/tcView.html`), the language switcher showed shifted
translations:

| Selected | Rendered |
|---|---|
| Portuguese | Romanian ("Vizualizator de cazuri de test") |
| Română | Russian ("Просмотр тест-кейса") |
| Russian | Chinese ("测试用例查看器") |

The JS layer was correct: dropdown values matched the API locale list and
`TLi18n.loadStrings()` fetched the right file. The bug was **in the bundle
JSON files themselves**.

## Root Cause

During the TC Viewer modernization run, parallel CI agents appended the new
`tcview.*` translation blocks into the wrong files — an off-by-one rotation
in alphabetical bundle order:

| File | Held | Belongs to |
|---|---|---|
| `pt.json` | Romanian block (56 keys) | `ro.json` |
| `ro.json` | Russian block (56 keys) | `ru.json` |
| `ru.json` | Chinese block (56 keys) | `zh.json` (which already had its own correct copy) |
| `pt.json` | *(Portuguese block lost entirely)* | — |

Older blocks (e.g. `search.quickCaption`) were unaffected; only the recently
appended `tcview.*` block was rotated. A full 10-bundle audit confirmed no
other contamination (`de/es/fr/it/ja` were genuine; apparent "Japanese"
kanji-only strings are legitimate Japanese vocabulary).

## Fix Approach

**Rotation recovery instead of re-translating everything:** because the
mis-placed blocks were *correct translations of their target locales*, they
were moved back rather than regenerated:

1. Moved the Romanian block `pt.json → ro.json`.
2. Moved the Russian block `ro.json → ru.json`.
3. Dropped the duplicate Chinese block from `ru.json` (byte-identical to
   `zh.json`'s own).
4. The Portuguese block was destroyed by the rotation, so all 56 `tcview.*`
   keys were **authored fresh in European Portuguese**, matching the
   existing pt.json style ("Gestão de utilizadores", "Guardar",
   "A carregar…").
5. Key order inside each file preserved; all bundles re-validated with
   `python3 -m json.tool`.

Alternatives rejected: re-translating all three blocks from English
(slower, riskier — two blocks were already correct, just mis-filed);
leaving parity gaps (34 pre-existing `it.*` / `header.issueTrackers*` keys
missing from ja/pt/ru/zh) untouched — out of scope for this bug;
TLi18n falls back to `en` for missing keys so they degrade gracefully.

## CI Guard

New linter `tools/lint_i18n.py` (issue suggestion #4):

```bash
python3 tools/lint_i18n.py [--strict]
```

Checks per bundle:
1. valid JSON;
2. key parity vs `en.json` (warnings; fail only with `--strict`);
3. script-based contamination: Cyrillic outside `ru.json`, kana outside
   `ja.json`, CJK outside `ja/zh.json`, Romanian diacritics (ă ș ț)
   outside `ro.json`.

Verified against the pre-fix tree it reports exactly this bug (56 Cyrillic
errors in ro.json, 56 CJK errors in ru.json, 31 diacritic errors in
pt.json); exits 0 on the fixed tree.

## Files Changed

* `gui/templates/i18n/pt.json` — fresh European Portuguese `tcview.*` block
* `gui/templates/i18n/ro.json` — Romanian block restored
* `gui/templates/i18n/ru.json` — Russian block restored, ZH duplicate removed
* `tools/lint_i18n.py` — new contamination guard

## Verification

Browser-tested on `tcView.html?tcase_id=9003&tproject_id=9001` after the
fix: pt/ro/ru each render their own language end-to-end (title, header,
buttons, badges); zh/ja/en unchanged; Event Viewer clean. Regression suite
26 in `tmp/TLU_Test_Cases.md`: 10/10 PASS.




---

## Bundle Validation Guard (wiki: i18n-Localizare §11)

`tools/lint_i18n.py` validates all 10 bundles before any commit touching
`gui/templates/i18n/*.json`: JSON validity, key parity vs en.json
(--strict), and script contamination (Cyrillic outside ru.json, kana
outside ja.json, CJK outside ja/zh.json, Romanian diacritics outside
ro.json).
