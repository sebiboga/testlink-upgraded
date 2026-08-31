# Bugfix — Issue #781: TLi18n.t(key, 'default') throws 'Cannot use in operator' and aborts dashboard widget rendering

**Issue:** [#781](https://github.com/sebiboga/testlink-upgraded/issues/781)
**Branch:** `fix/issue-781` · **Commits:** `023a0302e` (fix), `c4c6524fd` (regression suite + fixture)
**Status:** FIXED & VERIFIED (2026-08-31)

## Symptom

A call to the client-side i18n helper passing a **plain string as the second argument** —
e.g. `TLi18n.t("dash.generatedOn", "Generated on")` — made the Dashboard
(`gui/templates/mainpage/mainPage.html`) throw before drawing any widget:

```
Uncaught TypeError: Cannot use 'in' operator to search for 'length' in "Generated on"
```

Result: execution-status table stays empty (`#execTotal` = 0), the Monthly Growth /
open-issues widgets never appear, even though the BFF `api/mainpage/index.php` returns
correct data.

## Root cause chain

1. `gui/templates/i18n/i18n.js:129-137` — `function t(key, params)` treats `params` as an
   interpolation bucket and iterates it with jQuery `$.each`.
2. jQuery's `$.each` / `isArrayLike` evaluates `'length' in obj`. Passing a plain string as
   `params` makes that `in` check throw:
   `TypeError: Cannot use 'in' operator to search for 'length' in "Generated on"` — verified
   in isolation (`node`: `'length' in "Generated on"` reproduces the exact message).
3. The throw happens while `render()` draws the widgets, so the whole widget pass aborts.

**Why it no longer manifests on the default branch:** the modern Dashboard rewrite
(`57378a549` → `ec804b086` "review fixes" → `1274af79c`, Refs #780) uses **single-argument**
`t('dash.generatedOn')` / `t('dash.noRecords')` etc. throughout, with the English literals
supplied by the locale bundles. `grep` across all `gui/templates/**/*.html` shows **zero**
remaining two-argument string `t()` calls, so the primary cause is already absent from the
current source. The issue stayed open because the underlying fragility of `t()` was never
removed.

**Blast radius:** the failure lived in the shared `t(key, params)` contract used by every
modernized screen (cfieldsView.html, etc.), so a future two-arg call could reintroduce the
crash on any screen. Current calling sites = 0.

## Approach — harden the shared `t()` contract (defense-in-depth)

The minimal, safe fix is a type guard inside `TLi18n.t()` (`gui/templates/i18n/i18n.js`):

```js
function t(key, params) {
    var str = _strings[key] || key;
    if (params && typeof params === 'object') {
      $.each(params, function(k, v) {
        str = str.replace(new RegExp('\\{' + k + '\\}', 'g'), v);
      });
    }
    return str;
}
```

Interpolation now only runs for a `typeof 'object'` value (the documented `{k: v}` bucket, or
an `[{0: v}]` array used by `resultsTCFlat.html`/`absoluteLatest.html`). A stray plain-string
second argument is ignored instead of being fed to `$.each`, so the whole class of
«Cannot use 'in' operator» crash cannot recur on any modernized screen.

**Alternatives rejected:**
- Removing the second parameter from the `t()` signature entirely: a breaking API change
  with no benefit — object interpolation (`t('key', {n:5})`) is a legit, documented use
  (`i18n.js:14-18`) and must keep working.
- Only fixing `mainPage.html`: the reported file already had no two-arg calls, so this alone
  would have done nothing and left every other screen vulnerable to the same latent crash.
- An initial `&& !Array.isArray(params)` guard (review finding): it regressed the two
  array-interpolation callers (`rtf.rowCount` in `resultsTCFlat.html`, `alx.rowCount` in
  `absoluteLatest.html`). `typeof 'object'` alone is sufficient — it still blocks the string
  bug case while keeping arrays working.

## Files changed

| File | Change |
|---|---|
| `gui/templates/i18n/i18n.js` | `t()` gains a `typeof params === 'object'` guard before `$.each` (defense-in-depth) |
| `tmp/TLU_Test_Cases.md` | +Suite 781 (dashboard i18n regression, PASS) |
| `tmp/fixtures_781.php` | new fixture — project `DASH781`, plan `Plan781`(38), build `B781`(2), 5 executions (3 passed/1 failed/1 blocked) |

## Verification

- `node --check gui/templates/i18n/i18n.js` → no syntax errors.
- **Primary:** `tmp/fixtures_781.php` → open
  `http://localhost:8082/gui/templates/mainpage/mainPage.html?tproject_id=20&tplan_id=38`:
  execution table populated (Passed 3/60%, Failed 1/20%, Blocked 1/20%, Not Run 0/0%,
  Total 5), Monthly Growth widget visible, browser console **0** errors.
- In-page checks (`evaluate_script`):
  1. `TLi18n.t('dash.generatedOn','Generated on')` → `"Generated on"`, **no throw** (pre-fix class).
  2. `TLi18n.t('dash.testPlan')` (single-arg) → `"Test Plan"`, no throw.
  3. `TLi18n.t('dash.completed',{n:3})` (object interpolation) → `"Completed"`, no throw.
- `events` table: `SELECT COUNT(*) FROM events WHERE log_level IN (4,8)` → **0** (no new
  Error/Warning).
