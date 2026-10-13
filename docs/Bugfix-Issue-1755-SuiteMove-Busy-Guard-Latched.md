# Issue 1755 — Move/Reorder Test Suites: a failed action leaves the screen permanently disabled

**Issue:** [#1755](https://github.com/sebiboga/testlink-upgraded/issues/1755)
**Branch:** `fix/issue-1755` · **Fix commit:** `13f2ca183` (landed in the `#1740` code-review commit)
**Screen:** `gui/templates/testcases/suiteMove.html` · **BFF:** `api/suitemove/index.php`
**Status:** VERIFIED FIXED (2026-10-13) — the issue was a stale report of a fix that had already landed

## Symptom

After an action that failed (a move refused with `403`/`404`, a network hiccup, or — the exact path
named by the issue — a write that **succeeded** but whose follow-up `load(true)` was rejected),
**every** button on the screen stayed greyed out and the screen could no longer be used until it was
reloaded.

## Root cause chain

`postAction()` is the only mutating path of the screen: Move up / Move down / Move to…
(`suiteMove.html`, `doMove` / `confirmModal`). It sets the in-flight guard once
(`setBusy(true)`) and every row button is rendered with a `BUSY` term:

| # | Site | Role |
|---|---|---|
| 1 | `suiteMove.html:351-355` (`(first \|\| BUSY ? ' disabled' : '')` …) | the guard is what disables the buttons |
| 2 | `suiteMove.html:375-378` (`setBusy()` → `renderRows()`) | the only thing that re-enables them |
| 3 | `suiteMove.html:380-382` (`postAction()` sets `BUSY`) | guard armed once per write |

Pre-fix the release lived in the per-branch handlers only: the **success** branch called
`load(true)` and relied on `load()`'s own `done` to release the guard, while the **failure**
branches released it inline. A write that succeeded and then had its follow-up `load(true)` rejected
(or any other unhandled path) therefore returned with `BUSY === true` forever → **all** buttons
stayed `disabled` until a manual reload.

**Fix already present:** `13f2ca183` ("#1740: apply code-review fixes to Move/Reorder Test Suites")
collapsed the release into a single continuation:

```js
}).done(...).fail(...).always(function () {
  setBusy(false);          // suiteMove.html:414-418
});
```

No code path can latch the guard.

### Why the report was stale

The defect was introduced with the screen (`0674bbdd5`) and fixed during the mandatory code review
of #1740 (`13f2ca183`; authored `2026-09-30T13:30:30Z`, committed/landed `2026-09-30T13:35:13Z`).
Issue #1755 was filed at `2026-09-30T13:30:59Z` — **after** the fix was authored but **before** it
landed — describing the then-current code. `git merge-base --is-ancestor 13f2ca183 origin/sebiboga`
is true, so no build carries the reported behaviour today.

## Fix

No new change was required: the single `.always()` already releases the guard on every path of the
write. This run verified it, added the `Regression — Issue #1755` suite, mirrored this documentation
and, with the fix confirmed on the default branch, closed the tracking issue.

### Alternatives considered (and why the chosen fix is right)

| Option | Verdict |
|---|---|
| Release `BUSY` in each individual handler (pre-fix) | Rejected: every new branch must remember to release it, which is exactly how the latch appeared. |
| A timeout that force-releases after N seconds | Rejected: a slow-but-successful write would re-enable the buttons mid-flight. |
| **One `$.ajax(...).always()`** (shipped) | Chosen: jQuery guarantees the continuation runs on success, error and abort, so the guard and its release are colocated and total. |

## Verification (measured)

* Shipped code, POST ok + follow-up `load(true)` → 404: `BUSY=false`, buttons
  `[true,false,false,false,false,true,false,false]`, `anyEnabled=true` — the screen stays usable.
* Pre-fix `postAction()` reconstructed verbatim from `git show 13f2ca183^`, same scenario:
  `BUSY=true`, **all 8 buttons `disabled`** — the reported symptom, proving the suite exercises the
  right path.
* Release site: `gui/templates/testcases/suiteMove.html:414-418`.
* `git merge-base --is-ancestor 13f2ca183 origin/sebiboga` → true.
* The same `.always()` guard is present in the sibling screens that carry a `BUSY` flag
  (`tcReorder.html:415-416`, `tcStepReorder.html:589-590`) — no second latch.
  (`containerMoveTC.html` uses a `#loadingOverlay`/`lock()` scheme, not `BUSY`, so it is not
  affected by this defect.)
* `events` table after the run: only audit rows (login/session), no Error/Warning; browser console
  clean.

Regression suite `Issue #1755` in `tmp/TLU_Test_Cases.md`: **9/9 PASS** (8 verifications + 1
pre-fix control). Fixture: `tmp/fixtures_1755.php` (project `SM1755`, container 2, suites `Alpha`
3 / `Beta` 4).
