# Bugfix — Issue #1689: `reqTreeReorder.html` — read-only rows were still `draggable="true"` and every drop was silently ignored

**Number:** #1689
**Status:** Fixed (verified — the #1681 branch had fixed the *visible* parts of this report but the core assertion was regressed again by the #1688 fix; this run reproduced it, closed the last hole, pinned it with a 10-case regression suite and closed the issue)
**Component:** `gui/templates/requirements/reqTreeReorder.html`
**Area:** Requirements / Specification Tree Reordering
**Fixing commit (code):** `4c8b3a62b` — *fix(#1689): derive the reqTreeReorder drag affordance from one predicate*
**Regression-suite commit:** `6392ffbb4`
**Regression commit that introduced the regression:** `13b53dd94` — *fix(#1688): fold DEAD into the row controls too - residual live-state hole*

## Symptom

A user with `mgt_view_req` but **not** `mgt_modify_req` opened
`gui/templates/requirements/reqTreeReorder.html` and saw a screen that looked fully capable of
drag-reordering while nothing could be reordered:

```
roBannerShown: true                 // correct
dragHintVisible: false              // correct (#1681)
grips: 0                            // correct (#1681)
rmDisabled: true                    // correct - Up/Down/To top/To bottom all disabled
draggable: ["true","true","true"]   // <-- THE BUG: the rows are draggable anyway
```

Worse, the gesture was not inert at the browser level: `dragstart` fired (the browser painted
the OS drag ghost and the row got its `.dragging` class) and `dragover` was
`defaultPrevented === true`, so the row highlighted as a legal drop target — and then `drop`
returned without doing anything, because its guard requires modify rights.

## Root cause

**Two functions wrote the same attribute with two different predicates, and the weaker one ran
last.**

| where | code | predicate |
|---|---|---|
| `reqTreeReorder.html:302` (`render()`) | `if (canDrag()) { tr.attr('draggable','true'); }` | `!DEAD && GRANT.modify` |
| `reqTreeReorder.html:281` (`applyRowState()`) | `$r.attr('draggable', canDrag() ? 'true' : 'false')` | `!DEAD && GRANT.modify` |

**Before the fix** the second line read:

```js
$r.attr('draggable', DEAD ? 'false' : 'true');   // DEAD only - no !GRANT.modify term
```

and `render()` ends by calling `applyRowState()` (`:341`), so the correct gate was overwritten
48 lines later, in the same pass, on every single successful render.

The authority on the capability is the `drop` handler:

```js
// reqTreeReorder.html:329
if (DEAD || !GRANT.modify || BUSY) { return; }
```

An affordance must exist exactly when that guard lets the gesture through. It did not.

### Why it was still open

`13b53dd94` (the fix for **#1688**) added the `DEAD` term to the row controls and, in the same
hunk, wrote the new `draggable` line **without the `!GRANT.modify` term** that the #1681 branch
had been carrying in `render()`. So #1689 was *fixed*, then *regressed* the moment #1688 landed.
Verified with `git log -L 270,273:gui/templates/requirements/reqTreeReorder.html` (single
introducing commit) and `git show 13b53dd94^:…` (the pre-#1688 file has **no** `draggable`
write in `applyRowState()` at all).

That commit's own message documents the blind spot: *"Non-regression measured explicitly: on the
success path … rows stay draggable=true with a visible grip"* — the non-regression was measured
**only for a user with modify rights**, never for the view-only user, so the regression passed
review unnoticed.

## The fix — one predicate, derived from the guard

```js
// gui/templates/requirements/reqTreeReorder.html:256
// Single source of truth for the drag affordance. render() and applyRowState()
// both write `draggable`, so the predicate must live in ONE place and must mirror
// the drop() guard: a row that is draggable while drop() returns early is an
// affordance that silently does nothing. Refs #1689.
function canDrag() {
  return !DEAD && !!GRANT.modify;
}
```

Both writers now call it — `render()` for the initial `draggable` and the `.grip` icon,
`applyRowState()` for the attribute and for hiding/showing the grip on every later transition
(busy start/end, `dragend`, and the success → dead transition that #1688 was about). The
duplicated predicate that allowed the regression is gone, so the next rights term added to the
`drop` guard cannot be missed here.

The misleading comment that shipped with the `DEAD`-only line ("*render() already refuses to set
draggable on a dead page*") was replaced with one that states the invariant rather than
restating a single case of it.

No server-side, BFF or DB change: the `drop` handler was already correct, and no request was ever
emitted. **No i18n key was added** — the fix removes no string and introduces none, so
`gui/templates/i18n/*.json` is untouched.

### Alternatives rejected

* **Delete the two lines from `applyRowState()`.** Tempting, since `render()` already applied the
  correct gate — but `applyRowState()` must be able to switch the affordance **off** when the
  page goes DEAD after a successful render (rows survive that transition and stay in the DOM).
  That is exactly what #1688 fixed; deleting the write re-opens #1688.
* **Just add `!GRANT.modify` to line 272.** Fixes today's symptom in the smallest possible diff,
  but keeps the duplicated predicate — i.e. keeps the exact structure that caused this
  regression, and the next rights term will be missed the same way.

## Verification

Reproduced with fixture `tmp/fixtures_1681.php` (tproject 1, spec 2, requirements 6/8/10) as
`tr1681readonly` (view-only role) and as `admin`. Full case table in the regression suite
`Regression — Issue #1689` (suite file `tmp/TLU_Test_Cases.md`); highlights:

| case | before | after |
|---|---|---|
| view-only rows | `draggable` `["true","true","true"]` | `["false","false","false"]` |
| view-only drag row 0 → 2 | ghost + highlighted drop target, nothing happens | no gesture is offered at all |
| view-only controls | banner, hint hidden, 0 grips, `.rm` disabled, Select enabled | identical (unchanged) |
| admin rows | `draggable="true"`, 3 grips, hint visible | identical |
| admin drag + Apply | order `["6","8","10"]` → `["8","6","10"]`, persisted | identical — `action=init` returns `serverOrder [8,6,10]` = `TR1-2, TR1-1, TR1-3` |
| admin Discard | saved order restored, grip/hint return | identical |
| fresh DEAD page (404) | no rows, no grips, no banner | identical |
| **success → dead transition** (the #1688 case) | 3 surviving rows forced to `draggable="false"`, grips hidden | identical — #1688 still fixed |

Gates: `node --check` on the extracted script → PASS · browser console → no errors or warnings ·
Event Viewer / `events` → **0** Error/Warning rows (only 5 `log_level 16` INFO/audit rows from
the fixture and the two logins) · suite gate
`TLU_REQUIRE_SUITE="Issue #1689" bash ai/verify_test_suites.sh` → **7 PASS / 0 FAIL**.

## Screenshots

| before | after |
|---|---|
| `docs/screenshots/issue-1689-readonly-drag-before.png` — view-only page, rows still draggable | `docs/screenshots/issue-1689-readonly-no-drag-after.png` — view-only page, no drag affordance |

## Resume — how to re-test in one minute

```bash
php tmp/fixtures_1681.php
H=$(php -r 'echo password_hash("ro1689pass", PASSWORD_DEFAULT);')
mysql -h 127.0.0.1 -utestlink -ptestlink testlink -e "UPDATE users SET password='$H' WHERE login='tr1681readonly';"
```

Log in as `tr1681readonly` / `ro1689pass`, open
`http://localhost:8082/gui/templates/requirements/reqTreeReorder.html?tproject_id=1&req_spec_id=2`
and evaluate in the console:

```js
[...document.querySelectorAll('#ordBody tr')].map(r => r.getAttribute('draggable'))
// must be ["false","false","false"]
```

Then log in as `admin` / `admin` on the same URL and confirm the same expression returns
`["true","true","true"]` with three visible `.grip` icons.
