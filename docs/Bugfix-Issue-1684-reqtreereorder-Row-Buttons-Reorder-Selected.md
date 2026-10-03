# Bugfix — Issue #1684: `reqTreeReorder.html` — the Up/Down/To top/To bottom buttons reordered the **SELECTED** requirement, not the row they belong to

**Issue** — [#1684](https://github.com/sebiboga/testlink-upgraded/issues/1684) (label `bug`, opened
`2026-09-28T05:33:26Z`, the OLDEST open issue without a work-type label at the time of this run —
this run's operator selected the **oldest** eligible bug instead of the newest one `ai/FIX-ISSUE.md` §1
normally mandates; the deviation was ordered explicitly and the issue was picked by the documented
`sort_by(.createdAt)` triage query)
**Screen** — `gui/templates/requirements/reqTreeReorder.html`
**Component** — Requirement Specification Tree move / reorder screen (ported in #1681)
**Fix commit** — `f60e8965d fix(reqtreereorder): row reorder buttons act on their OWN row, not on the selected one (Refs #1681)`
**Verification branch** — `fix/issue-1684`
**Status** — **the fix was already on the default branch and is verified error-free; the ticket was simply never closed.**
This page records the investigation, the root cause, the grep that must be run whenever a modernized
screen mixes a per-row control with a shared selection, and the 9-case regression matrix.



---

## 1. Symptom

The four per-row reorder controls — **Up**, **Down**, **To top**, **To bottom** — acted on the requirement
that was currently **selected** (the one picked for the *Move to another specification* card) instead of the
row whose button was clicked.

As soon as any row was selected, clicking a reorder button on a *different* row reordered the wrong
requirement, or did nothing at all. Two observable variants, both **silent**:

| # | Action | Pre-fix result | Expected |
|---|---|---|---|
| 1 | select row 2 (`TR1-3`) → **To top** on row 3 (`TR1-2`) | `TR1-3, TR1-1, TR1-2` — the *selected* row jumped to the top, **row 3 untouched** | `TR1-2, TR1-1, TR1-3` |
| 2 | select row 1 → **Up** on row 3 | `TR1-1, TR1-3, TR1-2` — **no change at all** (silent no-op) | `TR1-1, TR1-2, TR1-3` |

**Impact.** The user builds a pending order that does not match what they clicked; the `Unsaved changes` chip
lights up and *Apply* writes the wrong order into the database. In variant 1 the chip actively **endorsed** a
corrupting reorder. No exception, no warning, no error card — the corruption is invisible until someone
compares the requirement sequence with the specification document.

## 2. Repro steps

1. `php tmp/fixtures_1681.php` — a public test project with requirements enabled, two specs
   (`TR1-SPEC-A` with 3 requirements, `TR1-SPEC-B` empty) and the permission-path users. On a **freshly
   imported** database the ids are `tproject=1 specA=2 specB=4 reqs=6,8,10` (the original report's
   `13` / `16` came from a database imported earlier).
2. Force the specification into the order the report needs (`TR1-1, TR1-3, TR1-2`):
   ```bash
   mysql -h 127.0.0.1 -utestlink -ptestlink testlink -e \
     "UPDATE nodes_hierarchy SET node_order=0 WHERE id=6;
      UPDATE nodes_hierarchy SET node_order=1 WHERE id=10;
      UPDATE nodes_hierarchy SET node_order=2 WHERE id=8;"
   ```
   (`nodes_hierarchy` is where the order lives — `requirements` only holds `id, srs_id, req_doc_id`.)
3. Login `admin` / `admin` at `http://localhost:8082/index.php`.
4. Open `http://localhost:8082/gui/templates/requirements/reqTreeReorder.html?tproject_id=1&req_spec_id=2`.
5. Click **Select** on **row 2**.
6. Click **To top** on **row 3**.
7. **Pre-fix:** the list becomes `TR1-3, TR1-1, TR1-2`.
   **Post-fix:** the list becomes `TR1-2, TR1-1, TR1-3`.

To see the pre-fix build again: `git show f60e8965d^:gui/templates/requirements/reqTreeReorder.html > gui/templates/requirements/reqTreeReorder.html`.

## 3. Measured evidence

### Pre-fix (state at `f60e8965d^`)

```js
// render(): the row loop knows `i`, the buttons do not
act.append(btn('fa-arrow-up',   t('reqtr.moveUp'),    'up'));      // <- no i
act.append(btn('fa-arrow-down', t('reqtr.moveDown'),  'down'));    // <- no i
act.append(btn('fa-chevron-up', t('reqtr.moveTop'),   'top'));     // <- no i
act.append(btn('fa-chevron-down',t('reqtr.moveBottom'),'bottom')); // <- no i

function btn(icon, label, dir) {
  var b = $('<span class="rm" data-mv="' + dir + '">…</span>');
  b.on('click', function () { nudge(dir); });                     // <- row identity dropped
  return b;
}

function nudge(dir) {
  if (BUSY || !GRANT.modify) { return; }
  var i = ITEMS.findIndex(function (x) { return x.id === PICKED; });   // <- the MOVE-CARD SELECTION
  if (i < 0) { /* "first enabled button" fallback — correct, but only when nothing is selected */ }
  var j = i;
  if (dir === 'up') { j = i - 1; } else if (dir === 'down') { j = i + 1; }
  …
  if (j < 0 || j >= ITEMS.length) { return; }    // <- silent no-op, no message
}
```

Live DOM readings (scripted clicks on the real page, `#ordBody tr .rdid`):

```
start                      : TR1-1, TR1-3, TR1-2
after Select row 2 + To top on row 3  : TR1-3, TR1-1, TR1-2     <-- WRONG
after Select row 1 + Up    on row 3    : TR1-1, TR1-3, TR1-2     <-- unchanged
```

### Why the fault is invisible — layer by layer

* **Server / access log:** only the single `GET …/reqTreeReorder.html?tproject_id=1&req_spec_id=2` → `[200]`
  plus the login round-trip. **Zero** XHRs fire during the faulty clicks — the reorder is pure client-side
  array work, so `api/requirements/index.php` is never asked to reorder anything. The BFF is innocent.
* **Browser console:** filtered to `error` + `warn` → *no console messages found*. No exception, no warning.
* **Network:** no `reorder` / `move` request at any point until `Apply` — the wrong row is what gets queued.
* **`events` table:** nothing. The corruption is data-level and silent; there is no log trail to follow.

## 4. Root cause

A **shared-state collision**, not merely a missing argument. One variable carried two meanings:

| Hop | Location | What happens |
|---|---|---|
| 1 | `reqTreeReorder.html:168` *(pre-fix)* / `:173` *(now)* | `var PICKED = 0; // requirement id chosen in the Move card` — the selection state of the **Move a requirement** card. |
| 2 | `:255-258` *(pre-fix)* / `:293-296` *(now)* | the four `btn(...)` calls omit the row index `i` that the surrounding `ITEMS.forEach(function (it, i))` loop provides. |
| 3 | `:283-285` *(pre-fix)* | `b.on('click', function () { nudge(dir); });` — forwards only the direction. |
| 4 | `:289-291` *(pre-fix)* | `function nudge(dir)` never receives a row, and resolves it with `ITEMS.findIndex(… x.id === PICKED)` — the **move-card selection**. |
| 5 | `:292-297` *(pre-fix)* | the `if (i < 0)` fallback ("first enabled button") was accidentally correct, but only while **nothing** was selected — which is why the bug is invisible until you pick a row. |
| 6 | `:300` *(pre-fix)* | `if (j < 0 || j >= ITEMS.length) { return; }` — with the *selected* row already at the boundary, the handler bails out with no feedback: the silent no-op. |

**The smoking gun is in the source, no runtime needed.** `applyRowState()` — `:225-228` *(pre-fix)* / `:256-259` *(now)* — already
disabled the controls **per row**:

```js
if (d === 'up'    && i === 0)     { dis = true; }
if (d === 'down'  && i === n - 1) { dis = true; }
if (d === 'top'   && i === 0)     { dis = true; }
if (d === 'bottom'&& i === n - 1) { dis = true; }
```

So the markup advertised **row-scoped** controls while the handler was **selection-scoped**. Any screen where
the disabled-state logic and the click handler disagree about what a button acts on is the smell to look for.

**Regression source** — introduced by `dcd23815a` *"Dashio screen for requirement-spec tree move/reorder"*
(Refs #1681): the row-scoped buttons and the selection-scoped handler were written in one pass and never
shared a source of truth.

## 5. The fix (as landed in `f60e8965d`)

Diff: 1 file, **+10 / −14**. Bind the row into the button; delete the shared-state path entirely.

```js
function btn(icon, label, dir, idx) {
  var b = $('<span class="rm" data-mv="' + dir + '">…</span>');
  // The row index is bound per button: a reorder control must always act on
  // the row it lives in, never on the currently selected requirement.
  b.on('click', function () { nudge(idx, dir); });
  return b;
}

function nudge(i, dir) {
  if (BUSY || !GRANT.modify) { return; }
  if (i < 0 || i >= ITEMS.length) { return; }     // bounds-validate the captured index
  var j = i;
  if (dir === 'up')    { j = i - 1; } else if (dir === 'down') { j = i + 1; }
  if (j < 0 || j >= ITEMS.length) { return; }
  if (dir === 'top')    { j = 0; }
  if (dir === 'bottom') { j = ITEMS.length - 1; }
  var mv = ITEMS.splice(i, 1)[0];
  ITEMS.splice(j, 0, mv);
  render();
  markDirty();
}
```

**Why this method (and what was rejected):**

* **Rejected — read the index from the DOM at click time** (`tr.index()`): it would work, but it re-introduces
  an implicit "which row is this really?" lookup, the very ambiguity that caused the bug, and it silently
  depends on the table's DOM shape.
* **Rejected — keep the "first enabled button" fallback**: it is the code that made the pre-fix behaviour
  *look* right in the unselected case and wrong in every other case. Removing it is what makes the invariant
  provable: a reorder control **can no longer reach** `PICKED` at all.
* **Chosen — capture the index at render time**, because it is the only approach where the invariant is a
  property of the *type signature* (`nudge(i, dir)` can only be called with a row), not of a runtime lookup.

**Why stale indices are impossible.** `nudge()` ends with `render()` (`:342`), which rebuilds every button with
fresh indices — so a captured index can never outlive the row it was bound to. Drag & drop (`:307-318`) also
ends in `render()`, and it carries its own `from` index in `dataTransfer`, so it was never affected.

`PICKED` keeps its one legitimate job: `renderSel()` (`:351-360`), the row highlight (`:283`) and the
**Move requirement** action (`doMove()` `:439-451`, `sendMove()` `:453-478`).

## 6. Blast radius

| scope | result |
|---|---|
| direct | 1 file, one function pair — `btn()` / `nudge()` in `reqTreeReorder.html` |
| `PICKED` references | 17 occurrences on 13 lines; **after** the fix every one of them belongs to the Move card / row highlight, **none** to reordering |
| server side | unaffected — the reorder is applied client-side and pushed by the existing `Apply` to **`api/reqtreereorder/index.php`** (`?action=reorder`, `:478`; wired at `reqTreeReorder.html:169`). That file needed **no change**, and `api/requirements/index.php` does not even expose a `reorder` action |
| other screens | grepped every `gui/templates/requirements/*.html` for the same shape. The only other `findIndex` uses are `reqOverview.html:267-268`, which resolve a **DataTable column index** (`'coverage'` / `'status'`) — a different concern, not a bug |
| `events` / Event Viewer | no new Error/Warning rows; no log-trail signal ever existed for this bug |

## 7. Verification

`tmp/TLU_Test_Cases.md` → `## Regression — Issue #1684: …` — **9 cases, 9 PASS**.
Dataset: `php tmp/fixtures_1681.php`, spec 2 forced to `TR1-1, TR1-3, TR1-2`.

| # | Case | Expected | Measured | Verdict |
|---|---|---|---|---|
| 1 | select row 2 → **To top** on row 3 (reported repro) | `TR1-2, TR1-1, TR1-3` | `TR1-2, TR1-1, TR1-3` | **PASS** |
| 2 | select row 1 → **Up** on row 3 (silent-no-op variant) | `TR1-1, TR1-2, TR1-3` | `TR1-1, TR1-2, TR1-3` | **PASS** |
| 3 | **no selection** → **Down** on row 1 | `TR1-3, TR1-1, TR1-2` | `TR1-3, TR1-1, TR1-2` | **PASS** |
| 4 | **Bottom** on row 1 of 3 | `TR1-3, TR1-2, TR1-1` | `TR1-3, TR1-2, TR1-1` | **PASS** |
| 5 | boundary controls disabled per row | `up`/`top` on row 1 `.dis`; `down` on last `.dis` | all three `true` | **PASS** |
| 6 | selection survives a reorder | `#selBox` + `.sel` keep the picked requirement | `#selBox` = `TR1-3 Third requirement`; `tr.sel .rdid` = `TR1-3` | **PASS** |
| 7 | reorder → **Apply** → confirm (`#cmOk`) → DB | `nodes_hierarchy` = the UI order | UI `TR1-2, TR1-1, TR1-3`; DB `0=TR1-2 1=TR1-1 2=TR1-3`; chip cleared to `none` | **PASS** |
| 8 | browser console | 0 error / 0 warning | `<no console messages found>` | **PASS** |
| 9 | Event Viewer / `events` | no new Error/Warning | 2 rows only — fixture `CREATE` + my `LOGIN`, both `log_level 16` audit | **PASS** |

Case 7 is the decisive end-to-end proof: with row 2 selected — the exact state that triggered the bug — the
**clicked** row moved and the database received `TR1-2, TR1-1, TR1-3`. Pre-fix the same sequence wrote
`TR1-3, TR1-1, TR1-2`.

## 8. The grep — run it whenever a modernized screen mixes a per-row control with a shared selection

```bash
# 1. every click handler on the screen
grep -nE "\.on\('click'" gui/templates/requirements/reqTreeReorder.html

# 2. any handler that resolves its target row from a shared selection variable
grep -nE "findIndex\(function \(x\) \{ return x\.id === [A-Z_]+; \}\)" gui/templates/<screen>.html

# 3. the mismatch that proves the bug: disabled-state logic is per row…
grep -nE "if \(d === '(up|down|top|bottom)' *&& *i ===" gui/templates/<screen>.html
#    …so any handler for the same control must take `i` too
```

If (3) matches but the corresponding handler in (1) does not receive `i`, the screen has this bug.

## 9. Residual risk / found while testing

* None introduced by `f60e8965d`.
* Found during this run and filed as **[#1804](https://github.com/sebiboga/testlink-upgraded/issues/1804)**, **not** fixed here: the disabled reorder controls are non-interactive `<span class="rm dis">` elements rather than real `<button disabled>`, so they carry no keyboard/`aria-disabled` semantics, no `pointer-events:none`, and no `title` explaining *why* they are disabled — and because there is no `pointer-events:none`, a click on a `.dis` control still dispatches (harmless today because `nudge()` bounds-checks). Functionally correct; an a11y / polish item.

## 10. Files

| file | role |
|---|---|
| `gui/templates/requirements/reqTreeReorder.html` | the screen — `PICKED` (`:173`), `applyRowState()` per-row disabling (`:255-260`), `render()` + the `btn(...)` calls (`:293-296`), `btn()` (`:324-330`), `nudge()` (`:332-344`), `renderSel()` (`:351-360`) |
| `api/reqtreereorder/index.php` | the BFF behind **Apply order** (`?action=reorder` at `:478`), wired at `reqTreeReorder.html:169` — verified **unchanged**, not part of the fault |
| `tmp/TLU_Test_Cases.md` | `## Regression — Issue #1684: …` — 9 cases, 9 PASS |
| `tmp/fixtures_1681.php` | fixture (tproject 1, specs 2/4, requirements 6/8/10, permission-path users) |
| `docs/screenshots/issue-1684-prefix-wrong-row.png` | pre-fix evidence, committed to the repo (without image lines in the `docs/` mirror) |
| `docs/screenshots/issue-1684-fixed-row-scope.png` | post-fix evidence |
