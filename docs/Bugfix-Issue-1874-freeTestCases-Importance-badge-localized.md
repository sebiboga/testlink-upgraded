# Issue 1874 — freeTestCases: Importance badge shows the raw value instead of the localized label

**Issue:** [#1874](https://github.com/sebiboga/testlink-upgraded/issues/1874)
**Branch:** `fix/issue-1874`
**Status:** VERIFIED-FIXED (2026-10-08)

## Symptom

On the modernized **Free Test Cases** report (`gui/templates/results/freeTestCases.html`), the
Importance cell rendered the **raw canonical value** `high` / `low` / `medium` as plain text in
**every** locale — including Romanian, where the column header (`Importanță`) and the footer
filter options (`Scăzută / Medie / Ridicată`) are properly translated. 4/4 rows affected whenever
priority management is enabled for the test project (100%).

## Repro

1. `php tmp/fixtures_1262.php` → tproject `FTC1262` (id 1, `testPriorityEnabled=1`, 2 suites × 2
   free test cases, mixed importance).
2. Open `http://localhost:8082/gui/templates/results/freeTestCases.html?tproject_id=1`.
3. Look at the Importance column: cells read `high`, `low`, `high`, `medium` while the footer
   filter already reads `Low / Medium / High`.
4. Switch to Romanian (`&locale=ro`): cells still read `high`/`low`/`medium` while header and
   filter are translated.

**Expected (legacy parity):** localized importance labels, as `lib/results/freeTestCases.php:53-62`
did via `$l18n['low_importance'|'medium_importance'|'high_importance']`.

## Root cause

1. The BFF (`api/reports/index.php:3316-3323`) maps the DB numeric importance to the canonical
   tokens `high|medium|low` **on purpose**: the client ranks them for sorting
   (`freeTestCases.html:187` `impRank` → `:216` sort branch) and the footer LIST filter
   regex-matches `^high$`/`^medium$`/`^low$` against the orthogonal *filter* datum
   (`freeTestCases.html:313`, option values `:308-310`). The token must stay in the JSON payload.
2. The client badge renderer `priorityBadge()` (`freeTestCases.html:139-145`) used the token not
   only as the CSS class selector but **also as the visible text** (`esc(level)`) — the display
   branch never went through `t()`.
3. The localized labels already existed (`search.lowImportance|mediumImportance|highImportance` in
   all 10 locale bundles) and were already used by the same screen's footer filter, but were never
   wired into the badge — the modern screen dropped the `lang_get()` hop the legacy had.

## Fix (minimal, client-side only)

In `gui/templates/results/freeTestCases.html` `priorityBadge()`: the raw token now selects only the
CSS class; the displayed text maps through the existing i18n keys:

```js
if (level === 'high')         { cls = 'priority-high';  label = t('search.highImportance',   'High'); }
else if (level === 'medium')  { cls = 'priority-medium'; label = t('search.mediumImportance', 'Medium'); }
else if (level === 'low')     { label = t('search.lowImportance', 'Low'); }
```

Deliberately **not** localized server-side: sorting (`impRank`) and the `^low|medium|high$` LIST
filter depend on the raw token, so localizing had to happen at render time on the client. The
`sort` and `filter` render branches were left untouched. No i18n bundle changes were needed (keys
already exist in every locale).

## Verification

- EN: cells `High`(red) / `Low`(green) / `High`(red) / `Medium`(amber); default sort (suite ASC,
  importance DESC) unchanged.
- RO (`&locale=ro`): cells `Ridicată / Scăzută / Ridicată / Medie`; header `Importanță`; filter
  `Toate/Scăzută/Medie/Ridicată`.
- LIST filter `low` still keeps only the `low` row (regex vs raw datum); reset restores 4 rows.
- Expand/Collapse Groups + Refresh re-render with localized badges.
- `node --check` on the inline script → OK; browser console clean; `events` table unchanged
  (no new Error/Warning).

Screenshots: `docs/screenshots/issue-1874-freetestcases-importance-fixed-en.png`,
`docs/screenshots/issue-1874-freetestcases-importance-fixed-ro.png` (pre-fix raw variants:
`-raw-en.png`, `-raw-ro.png`).

## Files changed

| File | Change |
|------|--------|
| `gui/templates/results/freeTestCases.html` | `priorityBadge()` maps token → `t('search.*Importance')` for the visible text |

## Out-of-scope sibling found (not fixed here)

`gui/templates/results/casesWithoutTester.html:100-106` has the identical raw-badge pattern
(row key `priority_level`, `api/reports/index.php:2907-2912`) — filed as a separate bug.