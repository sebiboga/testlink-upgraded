# Bugfix Issue #1909 — `planAddTCView.html`: header/body column misalignment when the plan has no platforms

Class of defect: a modern-screen DOM template that hid a table **header** cell but kept
emitting the matching **body** cell, so a plan without platforms rendered one more body
cell than visible header cells. Purely cosmetic — no data-flow impact.

## Symptom

For any test plan **without platforms**, on
`gui/templates/plans/planAddTCView.html` the `#tcTable` header row hides the `Platforms`
column but every body row still renders its platform `<td>`. The table therefore has one
more body cell than visible header cells, so `Exec. order` and `Remove links` are shifted
one column to the left and sit above the wrong cells (the remove chip has no header).

Measured before the fix (fixture `tmp/fixtures_1126.sql`, project `1000` / plan `2000`,
no platforms, priority off):

```json
{"thCountTotal":8,"thVisible":6,"bodyCells":7,"thPlatDisplay":"none",
 "headers":[{"id":"Test Case","x":59},{"id":"Version to a","x":141},{"id":"Status","x":273},
            {"id":"Exec. order","x":382},{"id":"Remove links","x":470}],
 "rowCells":[{"x":59},{"x":141},{"x":273},{"x":382},{"x":470},{"x":650}]}
```
`Exec. order` (x=382) sat above the (empty) platform cell; `Remove links` (x=470) above
the exec-order `<input>`; the remove chip (x=650) had no header.

## Root cause

All in `gui/templates/plans/planAddTCView.html`, function `renderContainer()`:

1. `:378` — `var hasPlat=(r.platforms||[]).length>0;`
2. `:380` — `$('#thPlat').toggle(hasPlat);` hides only the header column.
3. `:468` — `'<td>'+(isLinked?'':platCell)+'</td>'` **always** emits the body platform cell.
4. `:486` — `$('<td>').attr('colspan', 7+(priorityEnabled?1:0))` in the testplan-design
   custom-fields row hard-codes the platform column as always present — same root cause,
   the colspan is one too wide when there are no platforms.

Pre-existing since the screen was modernized; not introduced by #1127 (which added the
Importance column and kept `hasPlat` toggling correctly). It only manifests for plans
without platforms (the common case).

## Fix (minimal, 2 sites)

```js
// :468 — only emit the platform <td> when the plan actually has platforms
(hasPlat?'<td>'+(isLinked?'':platCell)+'</td>':'')+
// :486 — cf-row colspan = number of visible columns
$('<td>').attr('colspan', 6+(priorityEnabled?1:0)+(hasPlat?1:0))
```

Correct visible-column count = `6 + (priorityEnabled?1:0) + (hasPlat?1:0)`
(checkbox · Test Case · version · Status · [Importance] · [Platforms] · Exec. order ·
Remove links).

**Alternative rejected:** hiding the body cell with CSS (`display:none`) would keep the
phantom cell in the DOM and still break the custom-fields row colspan, and would leave
the platform `<select>` values readable by `collectSelection()`; omitting the cell is the
exact inverse of the header toggle.

## Verification (fresh DB, fixture `tmp/fixtures_1126.sql`)

| # | Config | Expected | Observed | Verdict |
|---|--------|----------|----------|---------|
| 1 | no platforms, no priority | 6 headers = 6 cells, cf-colspan 6 | 6=6, aligned; cf-colspan 6 | PASS |
| 2 | +2 platforms | 7 headers = 7 cells, Platforms aligned, cf-colspan 7 | 7=7; Platforms 368↔368; cf-colspan 7 | PASS |
| 3 | platforms + priority | 8 headers = 8 cells, Importance+Platforms aligned, cf-colspan 8 | 8=8; cf-colspan 8 | PASS |
| 4 | priority on, platforms removed | 7 headers = 7 cells, cf-colspan 7 | 7=7; cf-colspan 7 | PASS |
| 5 | unlinked case (add checkbox) | add-checkbox + version select + platform multi-select in the correct cells | unlinked row 8 cells at header X; Linux/Windows offered | PASS |
| 6 | platform-less `collectSelection()` | `add[tc]={"0":vid}` unchanged | code path `:520-522` untouched | PASS |
| 7 | browser console / `events` table | no new errors | only pre-existing a11y notices; only the login audit row | PASS |

Gate: `TLU_REQUIRE_SUITE="Issue #1909" bash ai/verify_test_suites.sh` → 7 PASS / 0 FAIL,
exit 0. Suite: `Regression — Issue #1909` (8 cases) in `tmp/TLU_Test_Cases.md`.

## Files changed

- `gui/templates/plans/planAddTCView.html` — `:468` conditional platform `<td>`, `:486`
  cf-row colspan (landed as `526a902cd`).
- `tmp/TLU_Test_Cases.md` — regression suite (append-only, `bc8efae0f`).
- `docs/WIKI-PLANADDTC.md` + GitHub Wiki `Plan-Add-Remove-Test-Cases.md` — screen page
  section 8 with before/after screenshots.
- `CHANGELOG` — 2.0.1 bugfix line.
