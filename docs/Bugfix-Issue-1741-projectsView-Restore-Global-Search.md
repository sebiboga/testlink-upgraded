# Bugfix — Issue #1741: the Test Project Management global search box is never restored from the saved DataTables state

**Issue:** [#1741](https://github.com/sebiboga/testlink-upgraded/issues/1741) — *projectsView.html: the global search box is never restored from the saved DataTables state — a filtered list with an empty #searchInput*
**Branch:** `fix/issue-1741`
**Affected screen:** Test Project Management (`gui/templates/projectsView.html`) — front-end only, no BFF / DB / legacy change
**Commit:** the fix is pushed on `fix/issue-1741`
**Regression suite:** `tmp/TLU_Test_Cases.md` → **Regression — Issue #1741** (10 executed: 9 PASS + 1 by code inspection; 1 N/A)

---

## 1. Symptom

On the modern **Test Project Management** grid, the **global** search box `#searchInput`
("Search projects by name…") is never repopulated from the saved DataTables state. The grid
is built with `stateSave: true`, so DataTables persists the global search **and re-applies it**
on the next visit — but nothing seeds the hand-rolled input from `state.search`. A returning
user therefore sees a *silently filtered* project list behind an *empty* search box, with no
visible affordance to clear the filter.

Measured live (4 fixture projects, DataTables 1.13.7) after typing `retail` and reloading:

```
loaded state search          ->  {"search":"retail","smart":true,"regex":false,"caseInsensitive":true}
DataTable().search()         ->  "retail"
page.info().recordsDisplay   ->  1            (1 of 4 projects shown)
#searchInput.value           ->  ""           (box EMPTY)
status line                  ->  Showing 1 to 1 of 1 entries (filtered from 4 total entries)
```

This is the same class of "invisible filter" as [#1633](https://github.com/sebiboga/testlink-upgraded/issues/1633)
(per-column boxes polluted with `[object Object]`), but it is a **missing** restore for the
**global** term rather than a wrong-shape read of the per-column descriptor.

## 2. Reproduction (pre-fix, measured)

1. `http://localhost:8082/index.php` → log in `admin`/`admin`.
2. Create at least one project (a fresh CI database has none):

   ```
   POST /api/projects/  {"name":"Beta Retail","prefix":"BET"}       -> 200 {"success":true,"id":1}
   POST /api/projects/  {"name":"Alpha Warehouse","prefix":"ALW"}   -> 200 {"success":true,"id":2}
   POST /api/projects/  {"name":"Gamma Supply","prefix":"GAM"}      -> 200 {"success":true,"id":3}
   POST /api/projects/  {"name":"Delta Logistics","prefix":"DEL"}   -> 200 {"success":true,"id":4}
   ```

3. Open `http://localhost:8082/gui/templates/projectsView.html` → 4 rows, `#searchInput` empty.
4. Type `retail` into the **Search projects by name...** box; the 200 ms debounce applies the
   global DataTables search and the list narrows to `Beta Retail`.
5. Wait ~900 ms for the `stateSave` debounce, then **reload** the page.
6. Observe: the list is still filtered to `Beta Retail` (1 of 4), but `#searchInput` is `""`.

Pre-fix measurement from the reload:

```js
const api = jQuery('#projectsTable').DataTable();
document.querySelector('#searchInput').value;   // ""
api.search();                                   // "retail"
api.page.info().recordsDisplay;                 // 1
api.page.info().recordsTotal;                   // 4
```

## 3. Root cause

| # | File:line | Fact |
|---|---|---|
| 1 | `gui/templates/projectsView.html:537` | The grid is built with `stateSave: true`, so DataTables serialises its state to `localStorage["DataTables_projectsTable_/gui/templates/projectsView.html"]` **and re-applies the stored global search at init**. |
| 2 | `gui/templates/projectsView.html:540` | `dom: 'lrtip'` renders the length / processing / info / paging controls but **not** the DataTables `f` (filter) box, so the screen supplies its own box. |
| 3 | `gui/templates/projectsView.html:173` | That box is the hand-rolled `<input id="searchInput">`. |
| 4 | `gui/templates/projectsView.html:930-937` | The only wiring of the box is the **write** direction: `keyup` → (200 ms debounce) → `projectsTbl.search(query).draw()`. There is no read-back. |
| 5 | `gui/templates/projectsView.html:590-612` | `restoreColumnFilterState()` repopulates the **per-column** filter inputs from `state.columns[i].search` (the #1633 fix) but only walks `thead tr.filters th`; it never touches `#searchInput`. |

The screen was written against the wrong assumption that DataTables would repopulate its
built-in `f` box (which is not rendered). The per-column path got its restore in #1633; the
global path was simply never given an equivalent.

**Why it breaks now** — not a regression from a recent change: the restore helper was always
per-column only. It is the **missing counterpart** of the #1633 fix (the #1633 run deliberately
scoped itself to the wrong-shape per-column read and filed this as a separate issue).

## 4. The fix

One new function, called once after the table is initialised — `restoreGlobalSearchState()` in
`gui/templates/projectsView.html`:

```js
function restoreGlobalSearchState(tbl) {
  const state = tbl.state.loaded();
  if (!state) return;
  const gSearch = state.search;
  const value = (gSearch && typeof gSearch === 'object')
    ? String(gSearch.search || '')
    : String(gSearch || '');
  $('#searchInput').val(value);
}
```

and the call is added next to the per-column restore (`:553`):

```js
restoreColumnFilterState(projectsTbl);
restoreGlobalSearchState(projectsTbl);
```

**Why this method**

1. **Minimal** — one function + one call, one file, no change on the clean-state path
   (`tbl.state.loaded()` is `null` on a genuinely first visit, so the helper short-circuits).
2. **Shape-tolerant** — the object / bare-string guard (`gSearch.search` vs `gSearch`) keeps the
   helper correct if the DataTables pin is ever rolled back, or if it is reused against a table
   that stores a string. This is verbatim the guard the sibling stateful screens already ship
   (`usersAssignPlan.html:1175-1176`, `usersAssignProject.html:1004-1005`).
3. **Parity with the per-column behaviour** — the per-column filter boxes restored by #1633 and
   the global box now behave identically: box value and filtered list always agree.
4. **No i18n / BFF / DB change** — no user-facing string, no server call, no legacy code involved.

**Rejected alternative — "do not re-apply the saved global search at all"** (the issue offers
this as a second defensible behaviour): it would regress `stateSave` for the global term, diverge
from the per-column boxes and from every other stateful screen, and throw away a working feature.
Seeding the box is the option that makes the two representations agree.

## 5. Blast radius

```
$ grep -rn "restoreColumnFilterState\|#searchInput" --include=*.html gui/templates/
gui/templates/projectsView.html:173   <- the input
gui/templates/projectsView.html:553, 590, 615  <- the restore helpers
```

`restoreColumnFilterState` and `#searchInput` exist only in `projectsView.html`. The sibling
stateful screens (`usersAssignPlan.html`, `usersAssignProject.html`) use the **built-in**
DataTables search box, which DataTables repopulates itself, so they are unaffected. No
server-side code is touched.

## 6. Verification

Regression suite **Regression — Issue #1741** (`tmp/TLU_Test_Cases.md`): 11 cases — **9 PASS,
0 FAIL, 1 PASS-by-code-inspection, 1 N/A**.

| # | Case | Result |
|---|---|---|
| 1 | Fresh state (`localStorage.clear()`), reload → box empty, 4/4 rows | PASS |
| 2 | Type `retail`, wait for `stateSave` → saved `search.search === "retail"` | PASS |
| 3 | **The exact pre-fix repro** — reload → box shows `retail` (pre-fix: `""`), 1/4 rows | PASS |
| 4 | Clear the box → all rows return, no filter | PASS |
| 5 | Reload after clearing → empty box, 4/4 rows (no stale term) | PASS |
| 6 | Per-column Prefix filter `BET` + global `retail`, reload → **both** boxes restored (#1633 parity) | PASS |
| 7 | Empty project set → empty-state path (`renderProjects` returns before table init), helper unreachable | PASS (code inspection) |
| 8 | `node --check` on the extracted inline script | PASS (`JS-SYNTAX-OK`) |
| 9 | Browser console after all flows | PASS (no errors/warnings) |
| 10 | Event Viewer / `events` after the flow | PASS (only `log_level=16` audit rows; 0 Error/Warning) |
| 11 | i18n coverage gate | N/A (no strings/labels touched) |

**Before / after** (identical saved state, `search.search === "retail"`):

- Before: `#searchInput.value === ""`, `recordsDisplay === 1` — silently filtered list.
- After: `#searchInput.value === "retail"`, `recordsDisplay === 1` — box and list agree.

Also checked: `events` had only `log_level=16` audit rows (login + the 4 fixture projects) and
the browser console was clean. The code path is `value` → jQuery `.val()` only, never an HTML
sink, so no XSS surface is added.

## 7. Re-run this suite in one go

```bash
# terminal 1
cd /home/runner/work/testlink-upgraded/testlink-upgraded
php -S 0.0.0.0:8082 -t .            # or: the CI's PHP built-in server

# terminal 2 — create the fixtures, then drive the browser
mysql -h 127.0.0.1 -utestlink -ptestlink testlink -e "select count(*) from testprojects;"
#   expect 4 after the four POST /api/projects/ calls from §2
```

In the browser at `http://localhost:8082/gui/templates/projectsView.html` (as `admin`/`admin`),
open the console and run:

```js
// T1 - clean slate
localStorage.removeItem('DataTables_projectsTable_/gui/templates/projectsView.html');
location.reload();

// (after reload) type a term, wait past the stateSave debounce, then reload and read
const wait = ms => new Promise(r => setTimeout(r, ms));
const inp = document.querySelector('#searchInput');
inp.value = 'retail';
inp.dispatchEvent(new KeyboardEvent('keyup', {bubbles: true}));
await wait(600);
location.reload();
```

After the second reload `document.querySelector('#searchInput').value` must be `"retail"` and
`jQuery('#projectsTable').DataTable().search()` must be `"retail"` — they must agree.

> **Harness pitfall:** the `stateSave` key is `DataTables_projectsTable_` **+ the page URL**, so
> cache-busting with `?nocache=…` changes the key and makes the save/restore path look broken
> when it is not. Always load exactly `/gui/templates/projectsView.html` (a plain reload keeps
> the key).
