# Issue 836 — L18N warning `simplexml_load_file_wrapper_error is not localized` on malformed XML import (key missing from most locale bundles)

**Issue:** [#836](https://github.com/sebiboga/testlink-upgraded/issues/836)
**Branch:** `fix/issue-836` · **Commits:** `b9c8d7c29` (fix), `9a09ad891` (regression suite)
**Status:** FIXED & VERIFIED (2026-09-02)

## Symptom

Importing a malformed XML file (e.g. an unclosed `<testcase>` tag) through the legacy import
paths triggers an Event Viewer L18N warning: `'simplexml_load_file_wrapper_error' is not localized
for locale 'en_GB'`. The same warning fires under every user locale except `pt_BR` / `pt_PT`.

## Reproduction (measured)

- PHP CLI scan of all 19 `locale/*/strings.txt` bundles: the key `$TLS_simplexml_load_file_wrapper_error`
  existed in only **2 of 19** (`pt_BR`, `pt_PT`). The other **17** — `en_GB`, `en_US`, `cs_CZ`, `de_DE`,
  `es_AR`, `es_ES`, `fi_FI`, `fr_FR`, `id_ID`, `it_IT`, `ja_JP`, `ko_KR`, `nl_NL`, `pl_PL`, `ro_RO`,
  `ru_RU`, `zh_CN` — were missing it.
- Because `en_GB` itself lacked the key, `lang_get()`'s English fallback found no solution, so it wrapped
  the key in `TL_LOCALIZE_TAG` and fired `logL18NWarningEvent(...,"LOCALIZATION")` (once per session).

## Root cause chain

1. `lib/functions/xml.inc.php:37` — `simplexml_load_file_wrapper()` calls
   `echo lang_get("simplexml_load_file_wrapper_error")` when `simplexml_load_string()` returns `false`.
2. `lib/functions/lang_api.php:51-54` — `lang_get()` resolves the active locale from `$_SESSION["locale"]`.
3. `lib/functions/lang_api.php` string lookup — key absent in target locale AND in the `en_GB` fallback.
4. `lib/functions/lang_api.php:110-137` — L18N miss → `logL18NWarningEvent(...)` + returns the
   `TL_LOCALIZE_TAG`-wrapped key.

**Why it breaks:** the key never existed in any locale outside `pt_BR`/`pt_PT`. The legacy import
paths (`lib/testcases/tcImport.php:135,556`, `lib/platforms/platformsImport.php:125`,
`lib/requirements/reqImport.php:84`, `lib/requirements/reqCreateFromIssueMantisXML.php:172`,
`lib/results/resultsImport.php:81,575`, `lib/plan/planImport.php:245`) all funnel through
`simplexml_load_file_wrapper()`. The modernized BFF path (`api/testcasesimport/index.php`) validates
XML with a safe parser and returns JSON 422, so it never reaches this function — the modernized screen
is unaffected.

## Approach — add the missing key to every bundle

Chosen fix: append `$TLS_simplexml_load_file_wrapper_error = '<per-language message>';` to all **17**
missing `locale/*/strings.txt` bundles. Alternatives rejected:

- *Changing the code to fall back to another existing string* — hides the gap, no per-locale copy.
- *Adding only to en_GB* — violates the repo's i18n policy (all bundles, no hardcoded English leaks).

Implementation notes:

- `.txt` string files are loaded via PHP `require` (`lang_api.php:235`), so where a closing `?>` exists,
  the new line was inserted **before** the tag; otherwise appended at end-of-file.
- Each file's line-ending convention (LF vs CRLF — de_DE/es_ES/pl_PL use CRLF) was detected from the raw
  bytes and the inserted block matches it, so the diff is purely additive (`3 +++` per file) with no
  line-ending churn.
- cs_CZ contains a pre-existing invalid UTF-8 byte; bytes were preserved byte-for-byte (pure byte-level
  insertion, no decode/re-encode) — untouched by this fix. An earlier decode/re-encode attempt corrupted
  cs_CZ (4493 lines churned) and was caught and reverted.
- Wording authored fresh per language (en/de/es/fi/fr/id/it/ja/ko/nl/pl/ro/ru/zh + cs), consistent with
  the existing pt text "Falhou o carregamento do XML".
- ja/ko/zh/ru/fi use native script (matching each file's existing convention, e.g. the #653 keys);
  cs_CZ keeps ASCII because the file contains a pre-existing invalid UTF-8 byte (see integrity note).
- Client-side i18n JSON bundles were left alone: this legacy Smarty path does not consume them.

## Verification

| Check | Result |
|---|---|
| Repro before fix | `simplexml_load_file_wrapper_error` empty in 17/19 bundles → L18N warning + `TL_LOCALIZE_TAG` wrapper |
| Key coverage | `grep -c simplexml_load_file_wrapper_error locale/*/strings.txt` → 1 in all 19/19 |
| After fix, `lang_get(...)` en_GB | "Please give this text to your TestLink Administrator<br> - Failed to load XML<br>" (no wrapper) |
| After fix, `lang_get(...)` de_DE | "Bitte geben Sie diesen Text an Ihren TestLink-Administrator<br> - Laden der XML-Datei fehlgeschlagen<br>" |
| After fix, `lang_get(...)` fr_FR | "Veuillez transmettre ce texte a votre administrateur TestLink<br> - Echec du chargement du XML<br>" |
| After fix, `lang_get(...)` ro_RO | "Va rugam sa transmiteti acest text administratorului TestLink<br> - Incarcarea XML a esuat<br>" |
| Syntax gate | `php -l` on all 17 touched bundles → PASS |
| Event Viewer | 0 new Error/Warning rows after resolving the key |
| Diff hygiene | `git diff --stat locale/*/strings.txt` → 17 files × `3 +++` (additive, CRLF preserved) |

Regression suite: Suite 836 in `tmp/TLU_Test_Cases.md` — 8/8 PASS.

## How to re-test in one command

```bash
grep -c simplexml_load_file_wrapper_error locale/*/strings.txt   # expect: 1 for every bundle (19/19)
php -l locale/en_GB/strings.txt                                  # expect: No syntax errors detected
```
