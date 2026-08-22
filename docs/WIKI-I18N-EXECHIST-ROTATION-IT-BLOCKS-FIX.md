# i18n Exechist Rotation + Missing it.* Blocks Fix

## Issue #556

Fixed (2026-08-22): two i18n bundle problems of the same contamination class
as #543, found while starting work on #540:

1. The 34-key `exechist.*` block (added by the Execution History
   modernization, #542) landed **shifted by one position** along the locale
   cycle `[en→ro→de→es→fr→it→pt→ru→ja→zh]` in **all 10 bundles**: en.json
   held Romanian text, ro.json German, de.json Spanish … zh.json English.
2. The `it.*` issue-tracker block + `header.issueTrackers*` keys
   (34 keys) were missing entirely from **ja/pt/ru/zh**.


## Symptom

- `python3 tools/lint_i18n.py` reported 60+ language-signature errors:
  Romanian diacritics in `en.json`, Cyrillic in `pt.json`, kana/CJK in
  `ru.json` … — every `exechist.*` value was one locale ahead.
- `grep '"exechist.header"' gui/templates/i18n/en.json` returned
  `"Istoric execuții"` (Romanian text in the English bundle).
- Linter warnings: `ja.json / pt.json / ru.json / zh.json: 34 key(s)
  missing vs en.json` (`header.issueTrackers*`, full `it.*` block).
- On screen: Execution History with English selected rendered
  "Istoric execuții"; Japanese rendered Chinese; Issue Trackers view in
  ja/pt/ru/zh fell back to English for all tracker strings.

## Root Cause

Parallel CI agents appending to the shared locale bundles picked the wrong
target files — each bundle's new `exechist.*` block was written into the
*next* file of the cycle. No lint run happened before those merges. The
missing `it.*` keys were a separate authoring gap: the block was only ever
written for en/ro/de/es/fr/it.

## Fix Approach

**Rotation recovery instead of re-translating:** the mis-placed blocks were
correct translations of their target locales, so they were *moved back*
rather than regenerated:

```
new_file[i] = old_file[i-1]      # en gets zh's old block, ro gets en's, ...
```

The swap is done at text level over whole contiguous 34-line blocks (all
blocks sit at EOF, last line comma-free), so formatting stays byte-identical;
key order inside each file is preserved.

**Missing keys authored fresh** for ja/pt/ru/zh following each bundle's
existing terminology conventions:

| Locale | header.issueTrackers | Convention source |
|---|---|---|
| ja | 課題トラッカー | コードトラッカー (code trackers) |
| pt | Rastreadores de problemas | Rastreadores de código; "gerir …" subheader style |
| ru | Трекеры задач | Трекеры кода |
| zh | 问题跟踪器 | 代码追踪器 |

The 34 lines are inserted immediately before `"header.codeTrackers"` to
mirror en.json ordering exactly.

Alternatives rejected: re-translating all ten exechist blocks from English
(slower, riskier — all ten were already correct, just mis-filed); leaving
the parity gaps untouched (this issue explicitly asks for them).


## Files Changed

* `gui/templates/i18n/{en,ro,de,es,fr,it,pt,ru,ja,zh}.json` — exechist.*
  blocks un-rotated; ja/pt/ru/zh gained the 34 `header.issueTrackers*`
  + `it.*` keys

## Verification

- `python3 tools/lint_i18n.py` → **0 warnings / 0 errors** (was 60+ errors,
  4×34-key warnings); `python3 -m json.tool` ×10 clean.
- Browser: `execHistory.html` renders "Execution History" (EN) and 実行履歴 /
  テストケース / 更新 (JA); `issuetrackerView.html?locale=ja` renders
  課題トラッカー / 名前 / 種別 / サーバーURL / 有効 / 「+ 課題トラッカーを
  作成」/ “0 件の課題トラッカー” end-to-end.
- Event Viewer (`events` table): zero new Error/Warning entries.
- Regression suite 40 in `tmp/TLU_Test_Cases.md`: 8/8 PASS.

## Follow-up

While verifying, three keys referenced by `execHistory.html` were found
missing from **all 10 bundles** (`exechist.colBuild`, `exechist.colStatus`,
`exechist.footer`) — the linter only checks parity vs `en.json`, so keys
absent from en.json itself go undetected. Filed as issue #557.
