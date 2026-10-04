# Bugfix — Issue #1824: `reqTreeReorder.html` — the rows stayed draggable and the arrow buttons stayed enabled while an Apply/Move request was in flight

**Number:** #1824
**Status:** Fixed (verified — reproduced pre-fix with the DOM read inside the request window, root-caused to two drifted copies of the capability predicate, fixed at the single source of truth, pinned with a 14-case regression suite)
**Component:** `gui/templates/requirements/reqTreeReorder.html`
**Area:** Requirements / Specification Tree Reordering
**Branch:** `fix/issue-1824`
**Fixing commit (code):** *fix(#1824): derive the reqTreeReorder busy-state affordances from one predicate*
**Related:** #1689 (the same defect class — this one was found by the mandatory code review of that fix and deliberately left out of it, as pre-existing), #1688 (DEAD term), #1681 (the screen itself)

## Symptom

While an `Apply order` or `Move requirement` POST is in flight (`BUSY === true`), the reorder
table still advertised **both** reordering affordances and both were inert:

* every row kept `draggable="true"` and kept its grip, so `dragstart` fired (OS drag ghost +
  `.dragging` class) and `dragover` still called `preventDefault()` — the row was painted as a
  legal drop target — while `drop()` returned at the first line of its guard;
* the per-row **Up / Down / To top / To bottom** buttons were still enabled, because
  `nudge()` refuses on `BUSY` but the affordance writer for those buttons did not know about it.

The inconsistency was visible *inside a single loop*: `.rm` **did** carry the `BUSY` term, so the
generic `.rm` write went dark during a save and the arrow-button loop, ten lines later, brought
them back up.

Measured pre-fix, with the DOM read in the same synchronous tick as the confirm click
(`dispatched:1, completed:0, BUSY:true`):

```json
{"BUSY":true,
 "drag":["true","true","true"],
 "arrowsEnabled":[2,4,2],
 "toolbar":["applyBtn=true","moveBtn=true","discardBtn=true"]}
```

and proven inert in the same tick:

```json
{"order":"6,10,8","orderAfterArrowClick":"6,10,8",
 "dragGhostFired":"dragging","orderAfterDrop":"6,10,8"}
```

## Root cause

**The capability predicate was written three times, and the two affordance copies had fewer terms
than the guards they are supposed to mirror.**

### Hop 1 — the drag affordance missed the guard's third term

| | code | terms |
|---|---|---|
| guard | `drop()` → `if (DEAD \|\| !GRANT.modify \|\| BUSY) { return; }` | **3** |
| affordance | `canDrag()` → `return !DEAD && !!GRANT.modify;` | **2** |

`canDrag()` drives `draggable` (both writers: `render()` and `applyRowState()`) and the grip, and
`dragover` called `preventDefault()` unconditionally — which is precisely what makes the browser
paint a row as a legal drop target. #1689 introduced `canDrag()` with the two terms that were
needed *at that time* and left a comment stating that `BUSY` was deliberately not mirrored.

### Hop 2 — the arrow buttons were disabled and re-enabled in the same pass

`btn()` (`:357`) creates every Up/Down/To top/To bottom button as `class="rm" data-mv="<dir>"`, so
the two selectors in `applyRowState()` address **the same elements**:

```js
// :267  - correct: capability AND busy
$r.find('.rm').prop('disabled', BUSY || DEAD || !GRANT.modify);
// :270  - weaker re-derivation of the SAME capability term, BUSY missing
var dis = DEAD || !GRANT.modify;
...
// :275  - this second write wins
$(this).toggleClass('dis', dis).prop('disabled', dis);
```

So the second writer is not merely missing a term, it is **redundant**: it existed only to add the
*position* terms (`up`/`top` on the first row, `down`/`bottom` on the last) but re-derived the
capability term from scratch, and the weaker copy always ran last.

### Why it broke now

It did not — it is pre-existing. The two lines are byte-identical before `4c8b3a62b`
(the #1689 fix); `canDrag()` was reviewed there and `BUSY` was consciously deferred because it is
only observable while a request is in flight.

## The fix

Four hunks in one file, `gui/templates/requirements/reqTreeReorder.html`:

1. **`canDrag()`** carries the guard's third term:
   ```js
   return !DEAD && !BUSY && !!GRANT.modify;
   ```
   One predicate, so `render()` (`:305`, `:309`) and `applyRowState()` (`:284`, `:285`) cannot
   drift apart again.
2. **The `[data-mv]` loop derives its capability term from it** instead of re-deriving it:
   ```js
   var dis = !canDrag();     // was: DEAD || !GRANT.modify
   ```
   The `.rm` write now owns "can this control be used at all", the loop owns only "is this
   direction available on this row". A third term can no longer be added in one place and missed
   in the other, because there is only one place.
3. **`dragstart`** guards itself (`if (!canDrag()) { e.preventDefault(); return; }`) instead of
   trusting the browser to refuse a `draggable="false"` row.
4. **`dragover`** gates its `preventDefault()` on `canDrag()`, so the drop-target painting
   follows the same predicate as the drop itself.

### Two regressions of the *first* version of this patch, caught by the mandatory code review

The code review (rule 16) of the first version found two problems **introduced by the fix itself**:

* **`render()` destroyed the drag handle for good on the error edge.** `render()` gated grip
  *creation* on `canDrag()`, so once `canDrag()` learned `BUSY`, a `render()` that happens while
  busy never creates the span — and `applyRowState()`'s restore is
  `$r.find('.grip').toggle(canDrag())`, a **no-op on an empty set**. `render()` *is* reachable while
  busy (the **Select** button, deliberately guarded only on `DEAD`, and `#refreshBtn` → `load()`
  both call it). After a **failed** save the rows were draggable again but the handle was gone
  until the next full reload.
  Fix: create the span whenever a later settle could still want it (`if (canDrag() || BUSY)`) and
  leave visibility to `applyRowState()`. Rights and `DEAD` stay structural — the span is not
  created at all — so #1689's "zero grips in the DOM" measurement for a view-only user is
  preserved.
* **The toolbar drag hint still advertised drag & drop during a save.** `idleUI()` wrote
  `$('#dragHint').toggle(!DEAD && !!GRANT.modify)` — the same missing third term, on the one control
  whose text literally promises the gesture (*"Drag and drop reorders locally until you apply it"*).
  Fix: `$('#dragHint').toggle(canDrag());`.

Both busy edges already re-run `applyRowState()` (`BUSY = true` on the way in, and
`idleUI(); applyRowState();` on the error edge, with a full `render()` on success), so no call site
had to be added.

### Alternatives rejected

* **Just add `BUSY` to `:270`.** Fixes the symptom in a three-character diff but keeps two
  predicates in one loop — the exact structure that produced #1689 — so the next capability term
  will be missed the same way.
* **Hide the table while busy.** Loses scroll position and context on every save. The defect is
  about affordances, not about data.
* **Fix the entangled `#refreshBtn` → `load()` race here too.** That is a different defect — a
  stale-response race, not an affordance drift — and is filed separately.

### Deliberately left alone

`.pickbtn` (Select) stays enabled while busy: it only *arms* a Move, and `doMove()` already
refuses on `BUSY` (`:486`). Gating it would remove the user's ability to change the selection
without any gain.

## Verification

Fixture `tmp/fixtures_1681.php` (tproject 1, spec 2 = `TR1-SPEC-A` with requirements 6/8/10,
spec 4 = `TR1-SPEC-B`, plus the `tr1681readonly` and `tr1681norights` users). The screen was
opened inside the `mainframe` iframe of `index.php?caller=login&viewer=web` — a top-level tab
navigation of the same URL bounces to `login.php?note=expired` on this build.

Every in-flight case was read in the same synchronous tick as the confirm click, with `$.ajax`
wrapped to count dispatch vs completion, so every snapshot carries the
`dispatched:1, completed:0, BUSY:true` proof that it fell inside the request window.
Full table in the regression suite `Regression — Issue #1824` (`tmp/TLU_Test_Cases.md`); highlights:

| case | before | after |
|---|---|---|
| mid-Apply `draggable` | `["true","true","true"]` | `["false","false","false"]` |
| mid-Apply enabled arrows per row | `[2,4,2]` | `[0,0,0]` |
| grip during a request | visible | `display:none` |
| `dragover` `defaultPrevented` while busy / while idle | `true` / `true` | `false` / `true` |
| `dragstart` while busy | ghost + `.dragging` | `defaultPrevented`, no `.dragging` |
| mid-Move (`?action=move`) | same drift | same as mid-Apply |
| after Apply resolves **ok** | — | order reloaded from the server, affordances restored, chip cleared |
| after Apply resolves **error** (forced 500) | — | `.msg.err`, affordances restored, toolbar live |
| arrow click while idle / real drop while idle | works | identical — order still changes locally, chip still raised |
| read-only user `tr1681readonly` | already fixed by #1689 | identical (banner, no grip, `draggable=false`, arrows off) |
| no-rights `tr1681norights` / DEAD mid-session | already fixed by #1688 | identical (`DEAD:true`, rows forced down) |
| persistence | — | `nodes_hierarchy.node_order` matched the screen after Apply (`Third requirement`=0, `First requirement`=1) |
| `render()` while busy (Select click) then error edge | grip lost for good (first version of the patch) | grips stay in the DOM and come back visible |
| drag hint | shown during a save (first version of the patch) | hidden during a save, restored on both settle edges |

Gates: `node --check` on the extracted inline script → PASS · no i18n key added (no new
user-facing string), so all 10 locale bundles are untouched · browser console → no new errors
(one pre-existing 404 for `dashio-template/img/favicon.png`, present before the change too) ·
Event Viewer / `events` → 4 rows only (1 fixture `CREATE` + 3 `LOGIN` audits, `log_level 16`),
**0 Error/Warning** · suite gate
`TLU_REQUIRE_SUITE="Issue #1824" bash ai/verify_test_suites.sh` → **7 PASS / 0 FAIL**.

## Screenshots

| before | after |
|---|---|
| `docs/screenshots/issue-1824-before.png` — a save in flight: rows still draggable, grips shown, arrow buttons live | `docs/screenshots/issue-1824-after.png` — the same instant after the fix: no grips, all arrow buttons disabled |

## Resume — how to re-test in one minute

```bash
php tmp/fixtures_1681.php          # -> tproject=1 specA=2 reqs=6,8,10
```

Log in as `admin` / `admin`, open
`http://localhost:8082/index.php?caller=login&viewer=web`, and inside the shell run

```js
document.getElementById('mainframe').src =
  '/gui/templates/requirements/reqTreeReorder.html?tproject_id=1&req_spec_id=2';
```

Move the last row up, click **Apply order**, confirm — then read the table while the POST is in
flight (Slow 3G in DevTools, or wrap `$.ajax`):

```js
Array.from(document.querySelectorAll('#ordBody tr')).map(r => r.getAttribute('draggable'))
// -> ["false","false","false"]   (was ["true","true","true"] pre-fix)
```

A second requirement must stay at the same place while the request is in flight — that is the
consistency check this issue was about.

## Related / follow-up

* **#1828** — the `#refreshBtn` handler (`:197`) calls `load(true)` without a `BUSY` guard, so a
  refresh during an in-flight save can resolve after the reorder POST and repaint `ITEMS`/`SAVED`
  from stale data (a stale-response race, not an affordance drift).
* **#1829** — `reqtr.alreadyFirst` / `reqtr.alreadyLast`, used by `btn()` for the "Already the
  first/last requirement" tooltips, exist in none of the ten locale bundles, so those two tooltips
  are always hardcoded English.