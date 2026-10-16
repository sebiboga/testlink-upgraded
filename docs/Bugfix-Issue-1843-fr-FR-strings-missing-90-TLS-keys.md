# Issue #1843 — `locale/fr_FR/strings.txt` missing 90 `$TLS_*` keys (ASIDE `Test Strategy` in English + LOCALIZATION warnings)

**Issue:** [#1843](https://github.com/sebiboga/testlink-upgraded/issues/1843)
**Branch:** `fix/issue-1843`
**Status:** VERIFIED-FIXED — regression suite `Issue #1843` 7/7 PASS, suite gate 7/7

## Symptom

A `fr_FR` user sees the ASIDE menu partly in English: the **Test Strategy**
section header renders as `Test Strategy` (English) while every neighbouring
entry is French (`Tableau de bord`, `Système`, `Projets`, `En révision`), and
expanding the section shows all 28 chapter sub-items in English too
(`Overview`, `Introduction`, …). Additionally the Event Viewer
(`events` table) collects **30 `log_level=32` / `LOCALIZATION` Warning rows per
login session**, one per missing key:

```
string 'title_test_strategy' is not localized for locale 'fr_FR'  - using en_GB
string 'href_test_strategy_bug_lifecycle' is not localized for locale 'fr_FR'  - using en_GB
...
```

This is the **legacy `lang_get()` string layer** (`locale/*/strings.txt`), *not*
the client-side `gui/templates/i18n/*.json` bundles (#1840 — different file,
different fallback: `TLi18n.t()` falls back to the raw key, `lang_get()` falls
back to English and logs a warning).

## Investigation (measured before any code was touched)

Environment: app `http://localhost:8082` (PHP built-in server, docroot = repo
root), DB `testlink` freshly imported (`SELECT COUNT(*) FROM events` = 0),
`admin`/`admin`, `UPDATE users SET locale='fr_FR' WHERE id=1;`, no test
project/plan needed (reproduces at `tproject_id=0`).

| layer | measurement | result |
|---|---|---|
| ASIDE render | a11y snapshot of `gui/templates/aside/aside.html?locale=fr_FR` | header `Test Strategy` English (all 28 sub-items English when expanded); `Tableau de bord`/`Système`/`Projets` French |
| `events` table | after login + ASIDE render | **31 rows** = 1 `LOGIN` + **30 `log_level=32` LOCALIZATION** |
| event text | `SELECT DISTINCT description` | 30 distinct `string '<key>' is not localized for locale 'fr_FR'  - using en_GB` (`title_test_strategy`, the 28 `href_test_strategy_*`, `install_header`) |
| browser console | `list_console_messages` | **no JS errors** (only 1 pre-existing a11y lint) — pure translation gap |
| static key scan | `locale/en_GB/strings.txt` vs `locale/fr_FR/strings.txt` | **en 2796 / fr 2716 / missing 90** (the report said 89/2783 — the default branch moved; today's truth is **90**) |

Refinements over the filed report:

1. **89 → 90 missing keys** on the current default branch.
2. **"`Documentation` is English" is a false positive.** `api/aside/index.php:714`
   uses `$TLS_title_documentation`, which *is* defined in fr
   (`locale/fr_FR/strings.txt:2308 = "Documentation"`) — the French word is
   spelled identically. Only `Test Strategy` is a genuine English fallback.

## Root cause chain

| # | file:line | what happens |
|---|---|---|
| 1 | `api/aside/index.php:402-429` (keys), `:447-451` (`lang_get($lblKey)` loop) | ASIDE builds the Test Strategy section: 28 × `lang_get('href_test_strategy_*')`. |
| 2 | `api/aside/index.php:456` | plus `lang_get('title_test_strategy')` → the visible `Test Strategy`. |
| 3 | `lib/functions/lang_api.php:65-68` | `lang_get('…','fr_FR')`: key absent from `$g_lang_strings['fr_FR']` — never written to `locale/fr_FR/strings.txt`. |
| 4 | `lib/functions/lang_api.php:78-88` | falls back to `en_GB` (`$englishSolutionFound = true`), returns the English text. |
| 5 | `lib/functions/lang_api.php:122-138` | once per key per session → `logL18NWarningEvent("string '…' is not localized for locale 'fr_FR'  - using en_GB", "LOCALIZATION")` → the 30 Warning rows. |

**Why the keys are missing (regression source):**

- `08c334b4a` (2026-09-11, Refs #1423) and `f934433d7` (2026-09-12, Refs
  #1426/#1440-#1458) created the Test Strategy chapter keys. `git show --stat`
  of both commits: locale files touched = **`locale/en_GB`, `locale/en_US`,
  `locale/ro_RO` only — `locale/fr_FR/strings.txt` was never updated.**
- The remaining 61 keys are older en-only additions: `bd0540d87` (alien/platform
  msgbox), `34302f8ee` (print-document errors, Refs #573), `3ec2526f8` (baseline
  guard, Refs #589), `c746eeddc` (ghost/relations, Refs #978), `1c33eb305`
  (alien relation types), … none ever translated into `fr_FR`.

## Blast radius

- **fr_FR**: 90 keys — 30 on the ASIDE (visible), 60 dormant until their code
  path runs: the 29 Test Strategy keys (`title_test_strategy` + 28
  `href_test_strategy_*`) plus `install_header` (System section,
  `api/aside/index.php:349`, GECO-gated `$gm('configuration')`); the remaining
  60 fire only when their path runs: `printDocument.php` error branches, tcView
  relations tooltip (`tc_has_relations`), Alien References table (7 labels),
  `api/severityconfig/index.php:244` (`severityConfig_saved`), tc-edit
  custom-field location labels (`after_life`/`after_seconditore`), navBar
  tooltip (`toggle_navigation`), platform/keyword dialogs, monthly test-growth
  metric titles, …
- **Cross-locale (out of scope, noted)**: the `href_test_strategy_*` family is
  missing from **15 of 19** locales (present only in en_GB/en_US/ro_RO and, after
  this fix, fr_FR). This fix covers `fr_FR` only, as filed.

## The fix (minimal, 1 file, +94/-0)

`locale/fr_FR/strings.txt` — appended one tagged block
(`// ----- Issue #1843: … -----`) with all 90 French translations, grouped:
first the 29 Test Strategy keys, then the other 61. Decisions:

- **Placeholders preserved**: every `%s`, `%i`, `{%1}` from the en_GB source is
  kept verbatim so runtime interpolation cannot print literally.
- **Expression preserved**: `$TLS_severityConfig_saved = $TLS_testproject . " '{%1}' …"` —
  `fr_FR` already defines `$TLS_testproject = "Projet"` at `:197`; the block is
  appended at the end so the variable is already assigned when `require` runs.
- **`$TLS_install_header` as its own statement** — in en_GB it shares a line with
  `$TLS_title_product_mgmt`; `fr_FR` defines `title_product_mgmt` at `:2309`,
  so only `install_header` needed adding.
- **Two multi-line en_GB keys** (`tc_monthly_creation_rate_on_tproj[_hint]`)
  written single-line in fr.
- UTF-8 (fr declares `$TLS_STRINGFILE_CHARSET = "UTF-8"`), double-quoted values
  where an apostrophe appears.

### Why this method (and what was rejected)

- **Backfill the data (minimal).** The issue is missing data in one bundle; the
  other 18 bundles are partial by design. Adding the French strings is exactly
  the documented fix and matches the precedent of #1459, #1563, #1820.
- **Rejected — suppress the fallback warning in `lang_get()`.** Hides genuine
  gaps in every partial locale and changes long-standing TestLink behaviour;
  far beyond the issue scope (#1820 rejected the same alternative).
- **Rejected — translate in `gui/templates/i18n/fr.json`.** That is #1840's
  layer, a different file with a different fallback; touching it would not fix
  the ASIDE.

## After the fix

With `fr_FR` active: the ASIDE header reads **`Stratégie de test`**, all 28
sub-items are French, and a fresh session writes **zero** new
`log_level=32` rows. The other locales are untouched.

## Verification

Regression suite `Issue #1843`, appended to `tmp/TLU_Test_Cases.md`; suite gate
`TLU_REQUIRE_SUITE="Issue #1843" bash ai/verify_test_suites.sh` → 7/7 PASS.

| case | before | after |
|---|---|---|
| `php -l locale/fr_FR/strings.txt` | — | `No syntax errors detected` |
| static key scan `en − fr` | 90 | **0** (`en 2796 / fr 2806`) |
| ASIDE header, fresh `fr_FR` session | `Test Strategy` (English) | `Stratégie de test` |
| expand 28 sub-items | all English | all French (`Vue générale` … `Tests de maintenabilité`) |
| `events` after fresh login + ASIDE + Test Strategy page + Event Viewer | +30 `log_level=32` per session | total 33, **30 warnings** (all pre-fix ids 1-31); fresh session only `LOGOUT`(32) + `LOGIN`(33) |
| files changed | — | 1 file, `+94/-0` (`git diff --stat`) |

Screenshots: `docs/screenshots/issue-1843-fr-aside-before.png` (English) and
`docs/screenshots/issue-1843-fr-aside-after.png` (French).

## How to re-test in one command

```bash
mysql -h 127.0.0.1 -utestlink -ptestlink testlink -e "update users set locale='fr_FR' where id=1;"
# fresh browser session, login admin/admin, open http://localhost:8082/index.php
# ASIDE header must read "Stratégie de test" (not "Test Strategy")
mysql -h 127.0.0.1 -utestlink -ptestlink testlink -e \
  "SELECT count(*) FROM events WHERE log_level=32;"   # must not grow after the fresh login
python3 -c "
import re
def keys(p): return set(m.group(1) for m in (re.match(r'\\\$TLS_([A-Za-z0-9_]+)\\s*=', l) for l in open(p, encoding='utf-8', errors='replace')) if m)
en=keys('locale/en_GB/strings.txt'); fr=keys('locale/fr_FR/strings.txt')
print('missing', len(en-fr))"   # expected: 0
```
