# Bugfix — Issue #1875: 7 freeTestCases `ftc.*` keys stayed English in 8 locale bundles

**Branch** `fix/issue-1875-ftc-i18n` · **Commits** `29cc40397` (bundles),
`719dcdaa0` (regression suite) · **Refs** #1262, #1265, #1844

## Symptom

On the modernized Free Test Cases screen (`gui/templates/results/freeTestCases.html`)
7 keys of the `ftc.*` group rendered their raw English text in 8 of the 10 locale
bundles (de/es/fr/it/ja/pt/ru/zh): `ftc.allAssigned`, `ftc.elapsedSeconds`,
`ftc.forProject`, `ftc.header`, `ftc.matchCount`, `ftc.testCases`, `ftc.testProject`.
Every other `ftc.*` key (and `ro.json` entirely) was already translated.

## Root cause

1. The screen binds the keys via `data-i18n` (`freeTestCases.html:84-90`) and
   `TLi18n.t()`/`t()` (`:154`, `:180`, `:228-229`, `:249`).
2. `gui/templates/i18n/i18n.js:175` — `var str = _strings[key] || key;` — a *present*
   key always renders its bundle value; there is no "is this English?" detection.
3. The #1262/#1265 translation batches created the 7 keys in the 8 bundles with
   values **byte-identical** to `en.json` — they were never translated, only copied.
4. `ai/verify_i18n_coverage.sh` (the #1844 gate) compares **key sets** only: a key
   that exists with an English value is coverage-green, so the drift shipped silently.

## Why this method

The fix is value-only, no code change: translate the 7 keys in the 8 bundles,
reusing **each locale's own established terminology** already present in the same
bundles and in the legacy `locale/*/strings.txt` files, so the new values are
consistent with every other screen in that language:

| anchor | where it comes from |
|---|---|
| `Test Project` | `ato.testProject` / `assign.testProject` / `cfa.testProject` in the same bundle |
| `for test project` | `ato.forProject` in the same bundle |
| `test cases` | `dsh.testCases` / `cwt.testCases` in the same bundle |
| `Match count` | legacy `$TLS_match_count` / `reqsearch.matchCount` / `cwt.matchCount` |
| `Elapsed seconds` | `reqcov.elapsedSeconds` / `rbtb.elapsedSeconds` / `cwt.elapsedSeconds` |
| screen header | legacy `$TLS_report_free_testcases_on_testproject` (official de/es/ja/pt/zh; fr/it/ru crafted to match) |

Alternatives rejected: (a) re-translating from scratch — risks diverging from the
vocabulary every translated control already uses on the same screen; (b) a runtime
"identical-to-en" fallback — wrong layer, would mask future gaps instead of fixing
them; (c) extending the coverage gate to byte-compare values — larger scope, belongs
to a separate issue, and would false-positive on intentional identity like the
`ftc.groupHeader` placeholder template (`{label}: {value} ({count})`).

## Files changed

- `gui/templates/i18n/de.json`, `es.json`, `fr.json`, `it.json`, `ja.json`,
  `pt.json`, `ru.json`, `zh.json` — 7 keys each, +56/−56 total.
- `tmp/TLU_Test_Cases.md` — appended regression suite (8/8 PASS).

`ftc.groupHeader` is intentionally left identical everywhere (format template with
placeholders, no translatable words).

## Verification

- `python3 -m json.tool` → OK on all 8 touched bundles.
- `bash ai/verify_i18n_coverage.sh` → **PASS 9/9 bundles** (exit 0).
- Cross-bundle diff → 0 `ftc.*` keys still identical to `en.json` outside en.
- Browser `?tproject_id=1&locale=de`: header "Testfälle die keinem Testplan
  zugewiesen sind", "für Testprojekt", "Testprojekt", "Anzahl Treffer: 1",
  "Vergangene Sekunden: 0" (see `docs/screenshots/issue-1875-freetestcases-de.png`).
- Browser `?tproject_id=1&locale=zh`: "没有关联到任何测试计划的测试用例", "匹配数量: 1",
  "已用秒数: 0" (see `docs/screenshots/issue-1875-freetestcases-zh.png`).
- Console: no new Errors/Warnings; `events` table unchanged (only 2 pre-existing
  AUDIT `log_level=16` rows).

## Note on the suite gate

`verify_test_suites.sh` reported 1 FAIL ("line removed … existing-but-denied
writes.") that is an **EOL false positive**: the merge-base `25815085e` committed
`tmp/TLU_Test_Cases.md` WITHOUT a trailing newline, so any append shows that
unchanged line as removed. Diff between the EOL-normalized base and the candidate
is pure additions (0 deletions), and the heading set is identical (41/41) — no
content was lost.