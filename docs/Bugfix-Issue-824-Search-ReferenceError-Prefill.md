# Bugfix Issue #824 — Search Test Cases: `Uncaught ReferenceError: p is not defined` breaks URL deep-link prefill

## Symptom

On the modernized **Search Test Cases** screen (`gui/templates/search/searchView.html`), the browser
console showed `Uncaught ReferenceError: p is not defined` on **every page load** once the test-project
context loaded. The URL deep-link prefill (`?name=API&importance=2`, etc.) depends on the
`URLSearchParams` instance `p`, so deep links opened with criterion parameters **never prefilled**
the criteria form.

## Root Cause

`var p = new URLSearchParams(window.location.search);` was declared **INSIDE** the jQuery
`$(function () {...})` ready handler (`searchView.html:201` pre-fix). JavaScript `var` is
**function-scoped**, so `p` was local to that anonymous callback only.

The prefill loop lives in `loadContext()` — a **separate top-level function** whose `.done()`
callback executes after the asynchronous context request finishes:

```js
loadContext() { ... $.getJSON(API + '?action=context&tproject_id=' + tprojectId).done(function (r) {
    ...
    [ 'targetTestCase','version','name', ... 'importance','status' ].forEach(function (k) {
        var v = p.get(k);   // ReferenceError: p is not defined
        ...
```

Because `p` does not exist in `loadContext`'s scope, the JS engine throws at the first
`forEach` iteration, which **aborts the entire `.done` callback** — nothing was ever written into
the form, so deep links silently lost their criteria.

## Fix

Minimal hoisting fix (commit `39d9c5b11`):

1. `var p = null;` moved to **top-level module scope** (next to `tprojectId`/`tplanId`) so the
   identifier is shared by every function in the page.
2. The ready handler now **assigns** `p = new URLSearchParams(window.location.search);` instead of
   re-declaring it with `var` — the same instance is then readable from `loadContext()`.

No other code changed for this defect; no new user-facing strings, so no i18n bundle changes.
The same commit also carried the #823 fix (unguarded `DataTable()` init with a plain-`<tbody>`
fallback, the `searchQuickView`/`#799` pattern.)

## Verification

Recreated the fixture (project `SEARCH824` id=1, prefix `S824-`, priority enabled, plan 9,
2 TCs via `tmp/fixtures_824.php`) and opened:

```
/gui/templates/search/searchView.html?tproject_id=1&tplan_id=9&name=API&importance=2
```

- **Console**: `list_console_messages` → 0 errors (post-fix); context request
  `/api/search/index.php?action=context&tproject_id=1` → HTTP 200.
- **Prefill (DOM, post-fix)**: `#name` = `API`, `#importance` = `2`, prefix addon `S824-`,
  page title `SEARCH824 - Search Test Cases`.
- **Find with prefilled criteria**: `(1 matches)`, row `S824-1 [v1] :: API Key Display and Regenerate`,
  footer `1 matches` — proving the prefilled values are actually applied to the query.
- `importance=3` (non-matching) → `(0 matches)` with `no_records_found` — correct data-driven behaviour.
- **Event Viewer** (`events` table): no new Error/Warning (search is read-only).

## Affected Files

- `gui/templates/search/searchView.html`