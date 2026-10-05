# Bugfix — Issue #1840: `gui/templates/i18n/fr.json` missing 26 `en.json` keys — `fr_FR` users saw raw dotted keys on screen

- **Issue**: [#1840](https://github.com/sebiboga/testlink-upgraded/issues/1840)
- **Branch**: `fix/issue-1840`
- **Commit**: `cca8a6d01` — *fix(i18n): add the 26 keys en.json defines and fr.json lacked (Refs #1840)*
- **Files changed**: `gui/templates/i18n/fr.json` (+26/-0), `CHANGELOG` (1 entry)
- **Severity**: minor — cosmetic for `de/es/it` peers, but for `fr_FR` the leaked text is garbage (`role.noRights`), not English
- **Regression suite**: `tmp/TLU_Test_Cases.md` → `## Regression — Issue #1840: …` (M1–M7, all PASS)
- **Related**: the ASIDE half of the report is a *different* defect — [#1843](https://github.com/sebiboga/testlink-upgraded/issues/1843). The missing CI guard-rail — [#1844](https://github.com/sebiboga/testlink-upgraded/issues/1844)

## Symptom

`gui/templates/i18n/fr.json` defined 6700 of the 6726 keys in `en.json`. The 26
absent ones belong to four shipped features (Requirements Monitor Overview grid
toolbar, Roles Management dialogs, Test Strategy bug chapters, requirement-table
counters) whose authors added the `en.json` key and stopped there.

The user-visible result is **worse than untranslated English**: because
`TLi18n.t()` has no per-key fallback, the module returns the key itself and the
raw dotted key is written into the DOM.

Measured pre-fix, `rolesView.html?locale=fr_FR`:

```js
TLi18n.t('role.noRights')    // => "role.noRights"     <-- raw key, not a label
TLi18n.t('role.editLocked')  // => "role.editLocked"   <-- raw key
```

Static confirmation:

```bash
python3 -c "
import json
en=json.load(open('gui/templates/i18n/en.json'))
fr=json.load(open('gui/templates/i18n/fr.json'))
print(len(en), len(fr), len([k for k in en if k not in fr]))"
# 6726 6700 26
```

## Root cause

Two hops, both read from source:

1. `gui/templates/i18n/i18n.js:165` — `t(key)` is `var str = _strings[key] || key;`.
   `_strings` is whatever single bundle was fetched at
   `gui/templates/i18n/i18n.js:131` (`/gui/templates/i18n/<locale>.json`). There
   is no English merge, so an absent key resolves to itself.
2. `gui/templates/i18n/i18n.js:187` — `apply()` writes `t(key)` into
   `textContent` for every `[data-i18n]` node, unconditionally. The English
   literal an author put in the HTML as a fallback is therefore **overwritten**
   by the raw key.

Why the keys went missing — four independent feature batches, each verified with
`git log -S` against `gui/templates/i18n/en.json`:

| commit | feature | keys |
|---|---|---|
| `588897593` | Test Strategy Bug Structure / Bug Lifecycle chapters | `ts.chapterBug*` (4) |
| `a381901e3` | Req. Monitor Overview group-by-spec + toolbar | `rmo.grid.*` (7), `rmo.*` (3) |
| `9d380b9dc` | Create Test Cases counters | `rctc.*` (6) |
| `4bcf10e07` | Roles Management dialogs (a *restore* of gutted i18n) | `role.*` (6) |

Nothing in the pipeline compares key **sets** across bundles — CI only runs
`python3 -m json.tool`, which a bundle missing 26 keys passes trivially. That
missing guard-rail is now [#1844](https://github.com/sebiboga/testlink-upgraded/issues/1844).

### A commit message that lied

`9d380b9dc` is titled *"i18n(reqCreateTestCases): rctc.\* keys in all 10 locale
bundles"*. Measured today:

```bash
grep -c rctc.countFilled gui/templates/i18n/*.json
# en 1, ja 1, pt 1, ro 1, ru 1, zh 1, de 0, es 0, fr 0, it 0
```

6 of 10, not 10. Reviewing the message instead of the diff is what let the gap
through.

## Blast radius (measured)

* **`fr.json` — 26 keys**, every one referenced by live code, not dead weight:
  * `rctc.*` → `gui/templates/requirements/reqCreateTestCases.html:257`
    (`TLi18n.t()` inside a toast)
  * `role.*` → `gui/templates/usermanagement/rolesView.html:101,508` and
    `api/roles/index.php:642` (`messageKey` returned to the client)
  * `ts.*` → `api/strategy/index.php:77,78` (chapter list, `key`/`descKey` pairs)
  * `rmo.grid.*` / `rmo.*` → `gui/templates/requirements/reqMonitorOverview.html:55`
    and its siblings
* **Same 26 keys, same four-feature origin: `de.json`, `es.json`, `it.json`.**
  `set(missing(de)) == set(missing(fr)) == set(missing(es)) == set(missing(it))`
  measured `True`. Not fixed here — tracked with #1844.
* **Different gaps elsewhere**: `ja`/`pt`/`ru` 20 each, `ro` 6, `zh` 40.
* **A second, unrelated i18n layer.** The report's ASIDE symptom (`Test Strategy`
  / `Documentation` in English inside an otherwise French menu) is **not** this
  bug. The ASIDE is rendered by `lang_get()` from `locale/fr_FR/strings.txt`,
  which lacks 89 of the 2783 `$TLS_*` keys in `locale/en_GB/strings.txt`,
  including `title_test_strategy` and the whole `href_test_strategy_*` family.
  The Event Viewer says so in as many words:
  `string 'title_test_strategy' is not localized for locale 'fr_FR' - using en_GB`.
  Filed separately as [#1843](https://github.com/sebiboga/testlink-upgraded/issues/1843).

## The fix

`gui/templates/i18n/fr.json` — 26 lines added, **0 removed, `en.json`
untouched**, each key inserted at its alphabetical position inside its own group
so the diff reads as ordinary maintenance:

```json
"rctc.countFilled": "Nombres de cas de test remplis d'après la couverture requise",
"rctc.countInfo": "Exigences",
"rctc.countReset": "Nombres de cas de test réinitialisés à 1",
"rctc.createdOk": "Cas de test créés",
"rctc.toggleAll": "Sélectionner / désélectionner toutes les exigences",
"rctc.toggleCount": "Remplir automatiquement le nombre de cas de test d'après la couverture requise",
"rmo.grid.expandCollapseGroups": "Déplier / Replier les groupes",
"rmo.grid.groupItem": "({count} élément)",
"rmo.grid.groupItems": "({count} éléments)",
"rmo.grid.groupsCollapsed": "Groupes repliés",
"rmo.grid.groupsExpanded": "Groupes dépliés",
"rmo.grid.hideGroupedColumn": "Masquer la colonne groupée",
"rmo.grid.showAllColumns": "Afficher toutes les colonnes",
"rmo.multiSort": "Tri multi-critères",
"rmo.resetFilters": "Réinitialiser les filtres",
"rmo.resetToDefaultState": "Réinitialiser l'état par défaut",
"role.duplicateRole": "Dupliquer",
"role.editLocked": "Ce rôle est défini par le système et ne peut pas être modifié.",
"role.error.duplicate": "Erreur lors de la duplication du rôle",
"role.noRights": "Vous n'avez pas les droits requis pour gérer les rôles (droit role_management requis).",
"role.systemDeleteDenied": "Les rôles système ne peuvent pas être supprimés.",
"role.warningUsersWillBeReset": "Les utilisateurs concernés recevront le rôle",
"ts.chapterBugLifecycle": "Cycle de Vie du Bug",
"ts.chapterBugLifecycleDesc": "Les états traversés par un défaut, avec un exemple de mapping GitHub : statut, assignés et labels.",
"ts.chapterBugStructure": "Structure du Bug",
"ts.chapterBugStructureDesc": "Les sections obligatoires d'un rapport de défaut : ENVIRONMENT, STEPS TO REPRODUCE, EXPECTED RESULTS, ACTUAL RESULTS."
```

Three decisions worth recording:

1. **Terminology reuse instead of invention.** The same two chapter titles were
   *already* translated in `fr.json` under sibling keys:
   `ts.bugLifecycleHeader` = `"Cycle de Vie du Bug"`,
   `ts.bugStructureHeader` = `"Structure du Bug"`. Those exact strings are
   reused, so one file does not end up with two competing renderings of the
   same chapter name.
2. **Placeholders asserted, not eyeballed.** `rmo.grid.groupItem` and
   `groupItems` are interpolated with `{count}`; the insert script verifies
   every `{token}` in the `en.json` source value still exists in the
   translation before writing, so a dropped `{}` cannot print literally.
3. **Rejected alternatives.**
   * *A CI key-set gate* (the report's option (b)) — this is build
     infrastructure, it would fail immediately on the `de/es/it/ja/pt/ru/zh`
     backlog, and it is not needed to stop the user-visible defect. Filed as
     #1844 rather than smuggled into a data fix.
   * *Merging `en.json` per key inside `i18n.js`* — would heal every locale and
     every future gap, but it changes shared runtime behaviour for every screen
     in the product (extra bundle fetch, new merge semantics) to paper over
     missing data. That is the "architectural" branch of the rulebook; the data
     is the defect.

## Verification

| # | Case | Measured |
|---|---|---|
| M1 | key-set diff `fr` vs `en` | `en 6726 / fr 6726 / missing 0` (was `6700 / 26`) |
| M2 | `python3 -m json.tool gui/templates/i18n/fr.json` | exit 0 |
| M3 | nothing overwritten | `git diff --numstat` → `26  0  fr.json`; `en.json` absent from the diff |
| M4 | placeholders interpolate | `groupItem.format(count=3)` → `(3 élément)`; `groupItems.format(count=7)` → `(7 éléments)`; in-browser `TLi18n.t('rmo.grid.groupItem',{count:4})` → `(4 élément)` |
| M5 | runtime `fr_FR` | 26-key unresolved probe → `[]` (was 6/6 raw keys); `role.duplicateRole`→`Dupliquer`, `rmo.grid.expandCollapseGroups`→`Déplier / Replier les groupes`, `ts.chapterBugLifecycle`→`Cycle de Vie du Bug` |
| M6 | runtime `en_GB` unaffected | `role.duplicateRole`→`Duplicate`, `rmo.grid.expandCollapseGroups`→`Expand/Collapse Groups`, `rctc.countInfo`→`Requirements` |
| M7 | Event Viewer | fresh logout+login: `events` 32→63 rows but `count(distinct description)` unchanged at **32**; the 60 `log_level=32` rows still collapse to the same 30 legacy strings; `log_level=16` rows are `audit_login_success`; **no ERROR row**. Console: no errors/warnings (2 pre-existing a11y hints on `rolesView.html`) |

M7 is the decisive one for the "did I fix the right thing" question: every
remaining warning comes from `lang_get()` / `locale/fr_FR/strings.txt`, while
the `gui/templates/i18n/*.json` path fires **no** event at all — so the fix can
only ever move the first symptom, and it does.

Screenshots: `docs/screenshots/issue-1840-fr-bundle-missing-keys-before.png`
(raw keys visible), `docs/screenshots/issue-1840-fr-bundle-missing-keys-after.png`
(French).

## Re-test in one command

```bash
mysql -h 127.0.0.1 -utestlink -ptestlink testlink \
  -e "update users set locale='fr_FR' where id=1;"
# open http://localhost:8082/gui/templates/usermanagement/rolesView.html?locale=fr_FR
# devtools console:
#   ['role.noRights','role.editLocked','role.duplicateRole'].map(k => [k, TLi18n.t(k)])
# expected: French strings, none equal to their own key
python3 -c "
import json
en=json.load(open('gui/templates/i18n/en.json'))
fr=json.load(open('gui/templates/i18n/fr.json'))
print('missing', len([k for k in en if k not in fr]))"   # expected: 0
```