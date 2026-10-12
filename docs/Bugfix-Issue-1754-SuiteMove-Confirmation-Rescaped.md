# Issue 1754 — Move/Reorder Test Suites: suite names containing $, &, ' or backtick are mangled in the confirmation text

**Issue:** [#1754](https://github.com/sebiboga/testlink-upgraded/issues/1754)
**Branch:** `fix/issue-1754` · **Fix commit:** `13f2ca183` (landed in the `#1740` code-review commit)
**Screen:** `gui/templates/testcases/suiteMove.html` · **BFF:** `api/suitemove/index.php`
**Status:** VERIFIED FIXED (2026-10-12) — the issue was a stale report of a fix that had already landed

## Symptom

The **Move to...** confirmation dialog re-escaped the suite name, so a suite named
`R&D "Q1" 'beta'` was confirmed as `Move "R$$&D "Q1" $$'beta$$'" ...`. Every `$`, `&`,
single quote or backtick in the name was doubled/expanded.

## Root cause chain

`gui/templates/testcases/suiteMove.html` had a **file-local** `fill()` helper that did its own
interpolation *after* `TLi18n.t()`:

```js
// pre-fix (13f2ca183^)
function fill(tpl, params) {
  params = params || {};
  return TLi18n.t(tpl, params).replace(/[$&`']/g, function (c) {
    return ({ '$': '$$$$', '&': '$$&', '`': '$$`', "'": "$$'" })[c];
  });
}
```

`TLi18n.t()` (`gui/templates/i18n/i18n.js:174-181`) already substitutes placeholders with a
**replacer function** (`str.replace(new RegExp('\\{' + k + '\\}', 'g'), function () { return v; })`),
precisely so a value containing `$&`, `` $` `` or `$'` survives verbatim. The extra
`String.replace()` pass therefore re-escaped the **already interpolated** value:
`&`→`$$&`, `$`→`$$$$`, `` ` ``→`$$``, `'`→`$$'`.

The confirmation body is built at `gui/templates/testcases/suiteMove.html:435`:
`$('#mText').text(fill('smv.confirmText', { suite: row.name }))`.

### Why the report was stale

The defect was fixed by the `#1740` code-review commit
`13f2ca18306dc7342f768d56b317882549f7b264` ("#1740: apply code-review fixes to Move/Reorder Test
Suites"): authored `2026-09-30 13:30:30Z`, landed `2026-09-30 13:35:13Z`. Issue #1754 was filed at
`2026-09-30T13:30:53Z` — **after** the fix was authored but **before** it was committed — describing
the then-current code. `git merge-base --is-ancestor 13f2ca183 origin/sebiboga` is true, so no build
carries the reported behaviour today.

## Fix

No new change was required. The fix is the removal of the second pass; the current helper is:

```js
// gui/templates/testcases/suiteMove.html:241-243
function fill(tpl, map) {
  return TLi18n.t(tpl, map);
}
```

This run verified that contract, appended the `Regression — Issue #1754` suite and closed the
tracking issue.

### Alternatives considered

| Option | Verdict |
|---|---|
| keep escaping but apply it to the *template* before substitution | rejected — `TLi18n.t()` already interpolates safely; a pre-escape would corrupt the translated template |
| stop using `TLi18n.t()` and hand-roll interpolation with `String.replace(tpl, value)` | rejected — reintroduces exactly the `$&` expansion bug |
| double-escape the value then interpolate | rejected — brittle, and other screens (`suiteEdit.html:276`, `reqTcAssign.html:213`, `reqTcBulkAssign.html:185`) already use the replacer-function pattern |

## Blast radius

`fill()` was local to `suiteMove.html` with two call sites: `countChip` (numeric, `:360`) and the
confirmation (`:435`). The buggy `replace(/[$&`']/g, …)` pattern appears nowhere else in `gui/` or
`api/` (grep clean).

## Verification

Live, on current `origin/sebiboga`, fixture `php tmp/fixtures_1754.php` — project `MOVE54` id=1,
top-level suite id=2 named `R&D "Q1" 'beta' $x$ `tick``, target id=3:

```
pre-fix  fill(): Move "R$$&D "Q1" $$'beta$$' $$$$x$$$$ $$`tick$$`" to the selected test suite. ...
current  fill(): Move "R&D "Q1" 'beta' $x$ `tick`" to the selected test suite. ...
live #mText    : Move "R&D "Q1" 'beta' $x$ `tick`" to the selected test suite. ...
```

| Case | Expected | Observed |
|---|---|---|
| hazard name in confirmation | verbatim, no `$$` | verbatim |
| pre-fix `fill()` on same value | reproduces the report | `R$$&D … $$'beta$$' … $$`tick$$`` |
| browser console | no JS error | only 1 a11y notice |
| `events` after run | 0 new Error/Warning | only 2 audit rows (level 16) |

Suite `Regression — Issue #1754` in `tmp/TLU_Test_Cases.md`: **7/7 PASS**.

Screenshot: `docs/screenshots/issue-1754-confirmation-verbatim.png`.

## Related

* #1740 — the modernized screen and BFF this issue was found while reviewing.
* #1753 — sibling stale report fixed by the same `13f2ca183` commit.
* `13f2ca183` — the code-review commit that fixed it.
