# Bugfix Issue #799 — Quick Search: empty results table when the DataTables CDN is unavailable

## Symptom

On the **Quick Search** screen (`gui/templates/search/searchQuickView.html`), clicking
**Find** intermittently rendered `Search results (N matches)` + a `N matches` footer, but
the **results table body stayed EMPTY** (no row). This was intermittent and happens "from
time to time".

## Root Cause

The page loads jQuery + DataTables from **external CDNs**
(`gui/templates/search/searchQuickView.html:115-117` — `code.jquery.com`,
`cdn.datatables.net`). If the CDN is slow/unavailable at the moment the user clicks **Find**,
then `$.fn.DataTable` is `undefined`.

`renderResults()` (in the same file) showed the count/wrapper first and then unconditionally
called `$('#resTable').DataTable(...)`:

- `:215` shows `#matchCount` ("(1 matches)");
- `:226` shows `#resultsWrap`;
- `:242` called `$('#resTable').DataTable({...})` with **no guard**.

When `$.fn.DataTable` is `undefined`, that call **throws**
(`Uncaught TypeError: $(...).DataTable is not a function`), aborting `renderResults()`
*after* the count/wrapper were already shown — leaving the count visible beside an empty
table body. The `find` button also stayed disabled because the unhandled throw prevented the
`$.getJSON(...).always(...)` callback from re-enabling it.

Confirmed by live reproduction: set `$.fn.DataTable = undefined` inside the page and click
Find → console `TypeError`, `Search results (1 matches)` + empty table, button disabled.

## Fix

`gui/templates/search/searchQuickView.html` — `renderResults()`:

1. Renders a **plain HTML `<tbody>`** first (via jQuery), so the matched rows always
   display regardless of DataTables availability.
2. Only then tries the **DataTables enhancement**, guarded by
   `typeof $.fn.DataTable === 'function'` and wrapped in `try/catch` — if it fails, the
   plain table remains.
3. The up-front teardown now also nulls `resTbl` (`if (resTbl) { resTbl.destroy(); resTbl = null; ... }`).

This keeps the count consistent with the rendered rows and lets the Find button re-enable
(`.always()` runs — no unhandled throw). No new user-facing strings were added, so no i18n
bundle changes were required.

## Verification

- **Fallback scenario** (`$.fn.DataTable = undefined`, then Find): table renders the row
  (`TLU-1 [v1] :: Login works`, summary, version, edit/history buttons), `1 matches` footer,
  Find button re-enabled, no console JS error.
- **Normal scenario** (DataTables present, then Find): enhanced DataTables table still
  renders with search box, pagination and sorting — no regression.
- **Event Viewer** (`events` table): no new Error/Warning (log_level 3/4) entries.

## Affected Files

- `gui/templates/search/searchQuickView.html`
