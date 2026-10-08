# Issue 1877 — casesWithoutTester: Priority badge + column header show raw/unlocalized values

**Issue:** [#1877](https://github.com/sebiboga/testlink-upgraded/issues/1877)
**Branch:** `fix/issue-1877`
**Status:** VERIFIED-FIXED (2026-10-08)

## Symptom

On the modernized **Test Cases Without Tester** report
(`gui/templates/results/casesWithoutTester.html`), the **Priority** column rendered the
raw canonical value `high` / `low` / `medium` as the visible badge text in **every**
locale — including Romanian — and the column **header** itself fell back to the English
literal `Priority` in every locale, while every other user-facing string on the page was
translated. 4/4 rows affected whenever priority management is enabled (100%).

## Repro

1. `php tmp/fixtures_1262.php` → tproject `FTC1262` (id 1, `testPriorityEnabled=1`, 2
   suites × 4 test cases); then `php tmp/fixtures_1874_cwt.php` → testplan id 12 + build,
   4 TCs linked, 0 executions, 0 user_assignments.
2. `curl -b cookies 'http://localhost:8082/api/reports/index.php?action=cases_without_tester&tproject_id=1&tplan_id=12'`
   → rows carry `priority_level: "high" | "low" | "high" | "medium"`.
3. Open `http://localhost:8082/gui/templates/results/casesWithoutTester.html?tproject_id=1&tplan_id=12&locale=ro`.
4. Priority cells read `high`, `low`, `high`, `medium`; column header reads `Priority`
   while the other headers read `Suita de testare` / `Caz de testare` / `Sumar`.

**Expected (legacy parity):** localized priority labels (`Ridicată / Scăzută / Medie`),
matching the legacy `testCasesWithoutTester.php` render via the priority map with
localized labels — and exactly how #1874 fixed the sibling Free Test Cases screen.

## Root cause

Two independent gaps in the same column:

1. **Badge cells** — the BFF (`api/reports/index.php:2912-2915`) maps the DB priority int
   to the canonical tokens `high|medium|low` **on purpose** (same token contract
   `free_testcases` uses at `:3318-3323`; localization belongs to the presentation
   layer). The client `priorityBadge()` (`casesWithoutTester.html:100-106`) used the
   token only to pick the CSS class but rendered it as the visible text (`esc(level)`)
   — the pre-#1874 copy of the function; `freeTestCases.html:139-146` (fixed in #1874,
   commit `a33aecec6`) maps the same tokens through `t()` first.
2. **Column header** — the pre-fix `casesWithoutTester.html:150` looked up the key `priority`,
   which exists in **none** of the 10 locale bundles (verified: `json.load` → MISS in
   en/ro/de/es/fr/it/ja/pt/ru/zh), so `t('priority','Priority')` always fell back to the
   English literal — the secondary gap called out in the issue body.

## Fix (minimal, client-side + i18n keys)

1. `gui/templates/results/casesWithoutTester.html` `priorityBadge()` — the raw token now
   selects only the CSS class; the displayed text maps through the **existing** keys
   `search.highImportance` / `search.mediumImportance` / `search.lowImportance`
   (present and translated in all 10 bundles, already used by other screens):

```js
if (level === 'high')         { cls = 'priority-high';  label = t('search.highImportance',   'High'); }
else if (level === 'medium')  { cls = 'priority-medium'; label = t('search.mediumImportance', 'Medium'); }
else if (level === 'low')     { label = t('search.lowImportance', 'Low'); }
```

2. Header key fixed to the screen-scoped `t('cwt.priority', 'Priority')`, with ONE new
   key `cwt.priority` added to all 10 locale bundles (values mirror the already
   translated `tnrap.priority`: Priority / Prioritate / Priorität / Prioridad / Priorité /
   Priorità / 優先度 / Prioridade / Приоритет / 优先级), inserted textually after
   `cwt.noLinkedTcversions` (alphabetical position — no whole-file rewrite, safe against
   concurrent agent appends).

**Alternatives rejected:** localizing server-side (would change the JSON token contract
shared with the already-fixed #1874 screen and put labels into data instead of the view —
the legacy also localized at render time); reusing another screen's namespace
(`tnrap.priority`) — report screens normally keep their own `<screen>.` prefix for column
headers (`alx.thPriority`, `ato.thPriority`, `rtf.thPriority`, `ta2u.thPriority`,
`tnrap.priority`), and a screen-scoped key avoids coupling this screen to a sibling's
namespace (the `search.*` keys are reused only because #1874 established that pattern for
the badge labels themselves); adding a generic `priority` key (unguessable namespace for
the next screen adopting the same lookup).

## Verification

- **RO** (`&locale=ro`): header `Prioritate`; cells `Ridicată / Scăzută / Ridicată / Medie`.
- **EN**: header `Priority`; cells `High / Low / High / Medium`.
- **DE** (`&locale=de`): header `Priorität`; cells `Hoch / Niedrig / Hoch / Mittel`.
- Badge CSS intact: classes `priority-high/medium/low`, computed backgrounds
  `rgb(245,198,203)` / `rgb(255,236,181)` / `rgb(212,237,218)` (identical to pre-fix).
- `priority_enabled=0` (option flipped in DB, then restored): Priority column hidden,
  table renders Test Suite / Test Case / Summary cleanly, no JS error.
- Shared-key regression: `bash ai/verify_i18n_coverage.sh` PASS (7031 keys × 9 bundles,
  0 missing) — `search.*Importance` (Issue #1874 fix) untouched in all bundles.
- i18n gates: `python3 -m json.tool` PASS ×10 bundles; coverage gate exit 0.
- Console clean (only the pre-existing a11y form-field hint); `events` table: no new
  Error/Warning rows after the fix (max error id unchanged at 31, predating the fix).
- Regression suite `Regression — Issue #1877` (10 cases) in `tmp/TLU_Test_Cases.md`:
  **10 PASS / 0 FAIL**; suite gate `TLU_REQUIRE_SUITE="Issue #1877"` 7 PASS / 0 FAIL.

Screenshots: `docs/screenshots/issue-1877-caseswithouttester-priority-fixed-ro.png`,
`docs/screenshots/issue-1877-caseswithouttester-priority-fixed-en.png` (pre-fix raw:
`issue-1877-caseswithouttester-priority-raw-ro-pre.png`).

## Files changed

| File | Change |
|------|--------|
| `gui/templates/results/casesWithoutTester.html` | `priorityBadge()` maps token → `t('search.*Importance')`; header key `priority` → `cwt.priority` |
| `gui/templates/i18n/{en,ro,de,es,fr,it,ja,pt,ru,zh}.json` | +1 key each: `cwt.priority` |

## Fixture note

`tmp/fixtures_1874_cwt.php` hardcodes project id 1 + tcversion ids 5/7/9/11; on a fresh
DB it must run **after** `tmp/fixtures_1262.php` (which creates project id 1). If any
earlier activity consumed nodes id 1, truncate the fixture tables
(`nodes_hierarchy`, `testprojects`, `testsuites`, `tcversions`, `testplans`, `builds`,
`testplan_tcversions`) to reset `AUTO_INCREMENT` before re-running — otherwise every
hardcoded id in the fixture is off by one.
