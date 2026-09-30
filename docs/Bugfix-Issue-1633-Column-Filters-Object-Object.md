# Bugfix — Issue #1633: column filter boxes polluted with `[object Object]` after a reload

**Issue:** [#1633](https://github.com/sebiboga/testlink-upgraded/issues/1633) — *Column filters polluted with '[object Object]' after re-render (DataTables 1.13 state.search is an object)*
**Branch:** `fix/issue-1633`
**Affected screen:** Test Project Management (`gui/templates/projectsView.html`) — front-end only, no BFF / DB / legacy change
**Regression suite:** `tmp/TLU_Test_Cases.md` → **Suite 1633** (12 cases: 11 PASS, 0 FAIL, 1 N/A)

---

## 1. Symptom

On the modern **Test Project Management** grid, every per-column filter box (the legacy
`{#SMART_SEARCH#}` feature) contained the literal string `[object Object]` from the second
render onwards. Per-column filtering then silently matched **nothing** — no error, no warning,
just an empty result set.

Measured on the live page with 4 fixture projects and DataTables 1.13.7:

```js
// after typing "Alpha" in a filter box and reloading the page
Array.from(document.querySelectorAll('#projectsTable thead tr.filters input'))
        .map(e => e.value)
// == ["[object Object]","[object Object]","[object Object]","[object Object]","[object Object]"]
```

Downstream damage, measured:

```
user appends to a polluted box -> box value        == "[object Object]Beta"
                                  column search    == ""            // smart search splits on space
                                  recordsDisplay  == 0             // "[object" AND "Object]" AND "Beta"
```

So after a single reload, **any** term the user types matches nothing.

The bug is easy to miss on a manual pass, for two independent reasons:

- **the first render is clean** — on a genuinely first visit `tbl.state.loaded()` is `null`, so the
  restore helper short-circuits, and the boxes stay empty;
- **the table still looks correctly filtered right after the pollution appears** — DataTables applies
  the saved state to the grid itself, so the row count is right and only the *boxes* are corrupt;
- and with an **empty `testprojects` table** the filter row is never built at all (the screen shows
  the empty state), so a freshly imported CI database cannot show the bug until a project exists.

## 2. Reproduction (pre-fix, measured)

1. `http://localhost:8082/index.php` → log in `admin`/`admin`.
2. Create at least one project (a fresh CI database has none):

   ```
   POST /api/projects/  {"name":"Alpha Banking","prefix":"ALP"}    -> 200 {"success":true,"id":1}
   POST /api/projects/  {"name":"Beta Retail","prefix":"BET"}      -> 200 {"success":true,"id":2}
   POST /api/projects/  {"name":"Gamma Insurance","prefix":"GAM"}  -> 200 {"success":true,"id":3}
   POST /api/projects/  {"name":"Delta Utilities","prefix":"DEL"}  -> 200 {"success":true,"id":4}
   ```

3. Open `http://localhost:8082/gui/templates/projectsView.html` with a clean profile
   → 5 filter inputs, all `""`.
4. Type `Alpha` into filter box #0 (the box under *Project Name*), wait for the ~500 ms
   `stateSave` debounce.
5. Reload the page.
6. Read the filter inputs → all 5 hold `[object Object]`.

The state shape that causes it, straight out of `localStorage`:

```json
"columns":[
  {"visible":true,"search":{"search":"","smart":true,"regex":false,"caseInsensitive":true}},
  {"visible":true,"search":{"search":"Alpha","smart":true,"regex":false,"caseInsensitive":true}},
  ...]
```

`columns[1].search` is an **object**; the term the user typed is one level deeper, at `.search.search`.

## 3. Root cause

| # | File:line | Fact |
|---|---|---|
| 1 | `gui/templates/projectsView.html:537` | The grid is built with `stateSave: true`, so DataTables serialises its own state to `localStorage["DataTables_projectsTable_/gui/templates/projectsView.html"]`. |
| 2 | `third_party/DataTables-1.10.24/datatables.js:6372` (and `-1.10.4/…:4901`) | `_fnSaveState()` writes `search: _fnSearchToCamel( settings.aoPreSearchCols[i] )`, and `_fnSearchToCamel()` (`:4613-4621`) returns the **object** `{search, smart, regex, caseInsensitive}`. |
| 3 | `gui/templates/projectsView.html:594` (pre-fix) | The restore helper read the descriptor itself: `const value = (state.columns[idx] && state.columns[idx].search) \|\| '';` |
| 4 | `gui/templates/projectsView.html:595` (pre-fix) | `$(this).find('input').val(value)` receives an object with no `value` key, so jQuery falls through to `val + ""` → the literal `"[object Object]"` lands in the input. |
| 5 | `gui/templates/projectsView.html:566-568` | The `keyup` handler pushes the box's value straight into `projectsTbl.column(idx).search(this.value, false, true)`, so the next keystroke searches for `[object Object]<term>`. |

**The real trap is a conflation of two different DataTables APIs:**

- `column(idx).search()` — the **getter** — returns a plain string, in *every* version;
- `state.columns[idx].search` — the **saved state entry** — is the descriptor object, also in
  *every* version.

The 2.0.1 screen was written against the getter while reading the state, so it was broken from the
start: this is **not** a 1.10 → 1.13 regression. Verified: `git show HEAD~1:gui/templates/projectsView.html`
carries the same raw read at line 544.

Legacy 1.9.20 is unaffected: the Smarty `DataTablesColumnFiltering.inc.tpl:40-46` filled the boxes
from the **request** value, not from a DataTables state blob.

## 4. The fix

One function, one expression — `restoreColumnFilterState()` in
`gui/templates/projectsView.html:590-608`:

```js
const colSearch = (state.columns[idx] && state.columns[idx].search) || '';
const value = (typeof colSearch === 'object')
  ? String(colSearch.search || '')
  : String(colSearch);
$(this).find('input').val(value);
```

**Why this method**

1. **Minimal** — 3 lines, one file, no behaviour change on the clean-state path.
2. **Shape-tolerant** — the bare-string branch keeps the helper correct if the DataTables pin is
   ever rolled back, or if the helper is reused against a table that stores strings.
3. **Consistent with the codebase** — this is verbatim the guard the two sibling screens already
   ship for the **global** search (`usersAssignPlan.html:1158-1160`,
   `usersAssignProject.html:734-736`), so the repo now has exactly one way to read a search out of a
   DataTables state blob.
4. **It fixes the cause, not just the symptom** — unwrapping the descriptor means the box holds the
   user's real term, so step 5 of the chain is correct on the first keystroke after a reload too.
   Stripping `[object Object]` out of the box instead would have left the saved state unreadable.

**Rejected alternatives**

- *Read `tbl.column(idx).search()` instead of the state blob* — a live getter, but it returns what
  DataTables already applied and would not restore a box on a freshly rebuilt table.
- *Move the filter row into a DataTables `columns.render` hook* — architecturally cleaner, but a
  rewrite of a working screen, far beyond the minimal-correct-fix rule.
- *Drop `stateSave`* — regresses the feature the report praises.
- *Disable smart search* to dodge the ANDed-term failure — loses legacy behaviour for no gain once
  the box holds the real term.

No user-facing string changed, so **no i18n key was added and no locale bundle was touched**.

## 5. Blast radius

```
$ grep -rn "state.loaded()\|restoreColumnFilterState" --include=*.html gui/templates/
projectsView.html:552, 590                          <- only consumer of state.columns[i].search (FIXED)
usermanagement/usersAssignPlan.html:1149            <- global state.search only, already guarded
usermanagement/usersAssignProject.html:723          <- global state.search only, already guarded

$ grep -rn "state.columns\[" --include=*.html gui/templates/
projectsView.html:594                               <- the single defective line
```

`stateSave: true` exists on exactly those three screens. The other per-column-filter screens build
their inputs themselves and never read a state blob (`results/tplanWithCF.html:258`,
`results/metricsDashboard.html:506` call `column(idx).search(...)` as a **setter**). One file, one
line, one screen.

## 6. Verification

Regression suite **1633** (`tmp/TLU_Test_Cases.md`): 12 cases — **11 PASS, 0 FAIL, 1 N/A**.

| # | Case | Result |
|---|---|---|
| T1 | Clean profile, first render, no saved state → 5 boxes all `""` | PASS |
| T2 | Type `Alpha` in box #0 → `column(1).search() === "Alpha"`, 1 row | PASS |
| T3 | **The exact pre-fix repro** — reload → `["Alpha","","","",""]` | PASS |
| T4 | No box contains `[object Object]` | PASS |
| T5 | Term in a **non-first** box (th#4, Issue Tracker) → restores into its own box | PASS |
| T6 | State present but no column term → all 5 boxes `""` | PASS |
| T7 | Append a char to the restored box → real term, 0 rows | PASS |
| T8 | Replace content with `e` → `term="e"`, 3 rows | PASS |
| T9 | In-page re-render via `loadProjects()` → box keeps the term | PASS |
| T10 | `events` table → 0 new Error/Warning (`log_level=16` only) | PASS |
| T11 | Browser console → 0 errors / warnings | PASS |
| T12 | DataTables 1.10 bare-string state (downgrade guard) | **N/A — unreachable, not claimed as passing** |

**T12 in full.** I hand-wrote a 1.10-shaped state (`columns[1].search = "Alpha"` as a bare string)
and reloaded; the boxes stayed empty, so I did **not** mark it green. A controlled A/B on the same
page with the same terms, only the shape differing, showed why:

```
A  string shape  ("Alpha" / "GAM")         loadProjects() -> ["","","","",""]         restore did nothing
B  object shape  ({search:"Alpha", ...})   loadProjects() -> ["Alpha","","GAM","",""]  PASS
```

DataTables 1.13's own state loader normalises a bare-string column search away, so the string branch
is defensive-only — 1.13 never writes that shape and the app never produces it. I verified the
*expression* is correct by calling `restoreColumnFilterState()` on the pre-normalisation instance,
which restored `["Alpha","","GAM","",""]` from the string state.

**Before / after** (identical saved state, `columns[1].search.search === "Alpha"`):

- Before: `["[object Object]","[object Object]","[object Object]","[object Object]","[object Object]"]`
  — `docs/screenshots/issue-1633-column-filter-object-object.png`
- After: `["Alpha","","","",""]`
  — `docs/screenshots/issue-1633-column-filter-restored.png`

Also checked: `events` (5 rows in the hour, all `log_level=16` audit — the login plus the 4 fixture
project creations; **0 error/warning**), browser console clean, and the code path is `value` → jQuery
`.val()` only, never an HTML sink, so no XSS surface is added.

## 7. Found while testing, filed NOT fixed here

- **[#1741](https://github.com/sebiboga/testlink-upgraded/issues/1741)** — the same screen never
  restores the **global** search into `#searchInput`, while DataTables *does* re-apply it. Measured:
  saved `search: "retail"`, `table.search() === "retail"`, `recordsDisplay = 1` of 4 projects, and
  `#searchInput.value === ""` — a silently filtered list behind an empty box. Same class of
  confusion, but a *missing* restore rather than a *wrong-shape* read, so it needs its own suite.
  Left for a dedicated run per the one-bug-per-run rule.

## 8. Re-run this suite in one go

```bash
# terminal 1
cd /home/runner/work/testlink-upgraded/testlink-upgraded
php -S 0.0.0.0:8082 -t .            # or: the CI's PHP built-in server

# terminal 2 — create fixtures, then drive the browser
mysql -h 127.0.0.1 -utestlink -ptestlink testlink -e "select count(*) from testprojects;"
#   expect 4 after the four POST /api/projects/ calls from §2
```

In the browser at `http://localhost:8082/gui/templates/projectsView.html` (as `admin`/`admin`),
open the console and run:

```js
// T1 - clean slate
localStorage.removeItem('DataTables_projectsTable_/gui/templates/projectsView.html');
location.reload();

// (after reload) T2 - type a term, then T3 - reload and read
const $ = jQuery, wait = ms => new Promise(r => setTimeout(r, ms));
const boxes = () => [...document.querySelectorAll('#projectsTable thead tr.filters input')].map(e => e.value);
const inp = document.querySelectorAll('#projectsTable thead tr.filters input')[0];
inp.value = 'Alpha'; $(inp).trigger('keyup');
await new Promise(r => setTimeout(r, 600));   // T2: past the 500ms stateSave debounce
location.reload();
```

After the second reload `boxes()` must be `["Alpha","","","",""]` and must never contain
`[object Object]`.

> **Harness pitfalls that produced false failures while writing this suite**
> - The `stateSave` key is `DataTables_projectsTable_` **+ the page URL**, so cache-busting with
>   `?p=…` / `?nocache=…` changes the key and makes the save/restore path look broken when it is
>   not. Always load exactly `/gui/templates/projectsView.html`.
> - Input-list index ≠ DataTable column index. The 5 filter inputs sit in `th` 1, 3, 4, 5, 7 (only
>   those carry `data-col-filter`), so input #0 → column 1, input #2 → column 4.
> - `stateSave` is debounced (~500 ms); reading `localStorage` straight after a keystroke returns
>   the previous state.
