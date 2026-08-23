# Issue 644 — LOCALIZATION warnings on every tcView render (`remove_plat_msgbox_*` / `executed_me_and_also` missing from all locales)

**Issue:** [#644](https://github.com/sebiboga/testlink-upgraded/issues/644)
**Branch:** `fix/issue-644-localization-warnings` · **Commits:** `7430ce208` (fix), `ffebe68e4` (regression suite)
**Status:** FIXED & VERIFIED (2026-08-23)

## Symptom

Every test case view / compare render that includes
`gui/templates/dashio/testcases/include/platforms.inc.tpl` (and its tl-classic twin) wrote three
level-32 LOCALIZATION warning events into `events`:

```
string 'remove_plat_msgbox_title' is not localized for locale 'en_GB'
string 'remove_plat_msgbox_msg' is not localized for locale 'en_GB'
string 'executed_me_and_also' is not localized for locale 'en_GB'
```

Measured during reproduction: events #25/#34/#35, all fired at 2026-08-23 20:35:18 by a single
render of `/lib/testcases/archiveData.php?edit=testcase&id=28&show_mode=show`. The warnings fired
even with zero test case relations, because the tcView add-relation control localizes **all**
configured relation labels unconditionally. Users also saw raw untranslated key names in the UI
(the relations table rendered `<id> / executed_me_and_also`).

## Root cause chain

1. `gui/templates/dashio/testcases/include/platforms.inc.tpl:11-12` calls
   `{lang_get s='remove_plat_msgbox_msg'}` / `{lang_get s='remove_plat_msgbox_title'}`
   unconditionally on every render.
2. `config.inc.php:1308,1318` registers `'executed_me_and_also'` as label + description for
   `TL_REL_TYPE_EXECUTE_TOGETHER`; localized via
   `lib/functions/testcase.class.php:8077-8085 getRelationLabels()` → `init_labels()` → `lang_get()`.
3. `lib/functions/lang_api.php:129-139`: a key absent from the active bundle logs a level-32
   LOCALIZATION event (once per user session via `$_SESSION['missingL18N']`) and returns the raw key.
4. Commit `1c33eb305` ("feature: alien relation type", 2020-05-13) rewrote
   `locale/en_GB/strings.txt` (-122 lines) and accidentally deleted:
   `$TLS_remove_plat_msgbox_title`, `$TLS_remove_plat_msgbox_msg`, `$TLS_executed_me_and_also`.
   Only pt_BR/pt_PT still carried the two remove_plat keys; **no** bundle carried
   `executed_me_and_also`.

## Blast radius

- Every screen including `platforms.inc.tpl` → 2 warnings per test case view render.
- Every tcView render → +1 warning via the relations UI label lookup.
- 19 locale bundles affected (17 fully, pt_BR/pt_PT partially).
- For non-en users a 4th warning came from the same `{lang_get}` list:
  `img_title_remove_platform` (missing from 15 bundles).

## Approach — restore keys everywhere, translate where confident

Chosen fix: **add the missing keys to all 19 bundles**.

- The keys ARE consumed (confirm dialog on platform removal; EXECUTE_TOGETHER relation labels), so
  deletion-style fixes (as used for #639's dead labels) were not applicable.
- en_GB/en_US got the restored English texts; other bundles got real translations
  (`Plattform entfernen`, `Удалить платформу`, `Elimină platforma`, `プラットフォームを削除`, …).
- cs_CZ is legacy ISO-8859-2 encoded → ASCII-only English fallback appended to avoid any encoding
  conversion risk.
- pt_BR/pt_PT kept their existing remove_plat translations; only `executed_me_and_also`
  (`é executado em conjunto com`) was added there.
- `$TLS_img_title_remove_platform` was added to the same 15 bundles (same template, same symptom
  class) so non-en users don't trade 3 warnings for 1.

Label wording deviation: the issue suggested `"Executed also on these platforms"` for
`executed_me_and_also`; usage verification showed it is a **test-case-to-test-case relation type**
label rendered bidirectionally next to siblings `parent_of`/`child_of`/`blocks`/`depends`
(`relations.inc.tpl:166`), so the grammatical equivalent `is executed together with` was used.

Rejected alternatives:
- Fixing only en_GB/en_US: would leave the warnings for users of the other 17 locales (the issue is
  explicitly "not localized (any locale)").
- Suppressing the lang_api warning path: hides future localization gaps and diverges from upstream.

## Files changed

| File | Change |
|---|---|
| `locale/*/strings.txt` (19 files) | +4 keys each (pt_BR/pt_PT: +1), placed before final `?>` or at EOF, EOL convention preserved per file |

## Verification

- `php -l` PASS on all 19 bundles (strings.txt are PHP scripts); UTF-8 validity re-checked per file.
- Coverage gate: 19 bundles × 4 keys = 76/76 present exactly once.
- Live en_GB render (fresh session to defeat the per-session warning dedupe): HTTP 200,
  **0 new level-32 events**, HTML contains `var remove_plat_msgbox_title = 'Remove Platform'`,
  the full confirmation message, and `is executed together with` ×2 in the relations block.
- Live de_DE render: localized texts shown (`Plattform entfernen`, `zusammen ausgeführt mit`),
  0 new level-32 events referencing any of the fixed keys.
- Event Viewer across the whole matrix: only pre-existing unrelated warnings (#520/#529 scope).


Regression suite: `Suite 644` in `tmp/TLU_Test_Cases.md` — 7/7 PASS.
