# Bugfix — Issue #1821: cfieldsTprojectAssign.html "Check / uncheck all" was completely dead

| | |
|---|---|
| **Issue** | [#1821](https://github.com/sebiboga/testlink-upgraded/issues/1821) |
| **Area** | Custom Fields — Test Project (`gui/templates/cfields/cfieldsTprojectAssign.html`) |
| **Symptom** | The **Check / uncheck all** master checkbox in either table header did nothing; the console logged `dtLinked.rows(...).invalidateSearch is not a function` |
| **Impact** | With one row ticked per click there was no way to act on more than a single custom field — Assign / Unassign / Save display attributes could not be applied in bulk |
| **Commits** | `0df8f57f7` (first attempt — wrong), `d9969d57a` (the fix), both on `origin/sebiboga` |
| **Files** | `gui/templates/cfields/cfieldsTprojectAssign.html` (one statement removed) |

## Symptom

On the **Custom Fields — Test Project** screen both data tables have a master checkbox in
the header. Ticking it was a no-op: no row was selected, the selection counter never
appeared, and the Assign / Unassign buttons stayed disabled. The browser console showed one
`TypeError` per click.

## Root cause

`toggleAll()` is wired from the two header checkboxes:

- `gui/templates/cfields/cfieldsTprojectAssign.html:249` — `onclick="toggleAll('linked', this.checked)"`
- `gui/templates/cfields/cfieldsTprojectAssign.html:298` — `onclick="toggleAll('available', this.checked)"`

Its **first** statement, ahead of the loop that does the real work, was:

```js
dtLinked.rows().every(function(r) { r.invalidateSearch(); });
$rows.each(function() { this.checked = on; });   // <- the actual ticking, never reached
```

Which DataTables this is: `cfieldsTprojectAssign.html:106` loads
`https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js` — the CDN 1.13.7 build, *not*
the 1.9.4 file vendored in `gui/templates/dashio/lib/advanced-datatable/`, which this screen does
not use. The live page confirms it: `jQuery.fn.dataTable.version === "1.13.7"`.

`invalidateSearch()` was removed from DataTables in **1.11**, superseded by the generic
`row.invalidate('search')`. The screen bundles **1.13.7**, so the method is absent from every
receiver. Measured live:

```
jQuery.fn.dataTable.version                       = "1.13.7"
typeof dtLinked.row(0).invalidateSearch           = "undefined"   <- Row API: gone
typeof dtLinked.rows().invalidateSearch           = "undefined"   <- rows() collection: gone
dtLinked.rows().every(r => r.invalidateSearch())  -> TypeError
dtLinked.rows().toArray()[0].invalidateSearch()  -> TypeError
```

The exception aborted `toggleAll()` before the ticking loop and before `syncSelState()`, so
neither the rows, nor the `(n)` counter, nor the button enablement changed. No request was
ever sent — the defect is purely client-side, which is why the server log stayed empty and the
button looked merely cosmetic.

The call was also cargo-culted to begin with: `invalidateSearch()` invalidates DataTables'
cached search index for a row whose **data** changed, and ticking a checkbox changes no
column data.

## Fix — remove the statement

**Approach:** delete the bogus call instead of relocating it.

**Why, and what was rejected:**

| Option | Verdict |
|---|---|
| Move it onto the Row node — `dtLinked.rows().toArray()[i].invalidateSearch()` | Rejected. Measured: still `TypeError`; the Row node object has no such method. This was commit `0df8f57f7` and it did **not** work. |
| Move it onto the Row API — `dtLinked.row(i).invalidateSearch()` | Rejected. Measured `undefined` in 1.13.7. |
| Port it — `dtLinked.row(i).invalidate('search')` | Rejected. A correct call, but semantically pointless: the search index cannot be stale when no column data changed. It would also keep a dependency on an API whose signature shifted between DataTables releases, for zero benefit. |
| **Delete it** | Chosen. Minimal, removes the exception that killed the function, and drops a fragile cross-version dependency. `syncSelState()` — which drives the `(n)` counter and the button enablement — is still called by the surviving `$rows.each()` loop. |

A comment at `gui/templates/cfields/cfieldsTprojectAssign.html:358-366` now records the
DataTables-version finding so the call is not re-introduced.

Blast radius: the only remaining hit of `invalidateSearch` anywhere under `gui/templates/` is the
explanatory comment quoted below — there is **no executable call left in the tree**. The call existed
only in this one screen, so exactly the two master checkboxes were affected. No PHP
change was needed or made.

### Why it breaks in 2.0.1 only

The screen is new: `lib/cfields/cfieldsTprojectAssign.php` had no live entry point until
[#1816](https://github.com/sebiboga/testlink-upgraded/issues/1816) added the button in
`cfieldsView.html:318`. The call was therefore never exercised in 1.9.20 — it was written
against a DataTables release that no longer carries the method.

## Verification (live, chrome-devtools MCP)

Same page, both header checkboxes driven through their real `onclick`:

| Step | linked | available | `btnUnassign` | `btnAssign` |
|---|---|---|---|---|
| initial | 2 rows, 0 checked | 2 rows, 0 checked | disabled | disabled |
| **Check all** | **2 checked** | **2 checked** | **enabled** (`Unassign (2)`) | **enabled** (`Assign (2)`) |
| **Uncheck all** | 0 checked | 0 checked | disabled | disabled |

- Console after the fix: **no errors, no warnings**.
- **Redraw edge case** (the likely motive of the removed call): after checking all, a
  DataTables redraw — `dtLinked.order([[1,'asc']]).draw(false)` — keeps the selection and
  `btnUnassign=enabled`, so nothing is lost by dropping `invalidateSearch()`.
- **End-to-end**: check all on Available → `Assign (2)` → click → the fields migrate to the
  linked table (linked badge `2` → `4`, available badge `2` → `0`, empty state *"Every custom
  field is already assigned to this test project."*) and the toast reads
  `3 custom field(s) assigned.`
- **Event Viewer**: no new Error/Warning entry. The Assign button's own path logs level-16
  INFO only.

## Testing it yourself

```bash
php tmp/fixtures_1821.php     # tproject 'CFA 1821': 2 linked + 2 available custom fields
```

Then log in `admin/admin` and open
`http://localhost:8082/gui/templates/cfields/cfieldsTprojectAssign.html?tproject_id=<id>`.
Tick **Check / uncheck all** in either header.

> **Fixture gotcha:** `cfield_mgr::link_to_testproject($tproject_id, $cfield_ids)`
> (`lib/functions/cfield_mgr.class.php:1064`) takes the **project id first**. Passing the field
> id first silently links the wrong field and additionally raises
> `E_WARNING Trying to access array offset on null - in lib/functions/cfield_mgr.class.php - Line 1085`
> (line 1085 does `$cf[$field_id]['name']` on a key that is not there). That warning is a
> badly-called-fixture artefact, not a product defect.

Regression suite: `tmp/TLU_Test_Cases.md` → `## Regression — Issue #1821` (TC-1821-01 … TC-1821-12).
