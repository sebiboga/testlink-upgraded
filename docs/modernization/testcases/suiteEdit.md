# Test Suite Create / Edit / Delete (`suiteEdit`) — Refs #1852

Modern replacement for the three legacy modes of `lib/testcases/containerEdit.php`
(`new_testsuite`, `add_testsuite`, `edit_testsuite`, `update_testsuite`,
`delete_testsuite`), which had been reduced to a bare **name + details** inline
modal inside `api/suiteview`.

## What the legacy screen did that the modern one had lost

| Legacy | `api/suiteview` inline modal | `suiteEdit` |
|---|---|---|
| `html_table_of_custom_field_inputs()` rendered the test-suite **design custom fields** | never rendered, never saved | rendered from API data, saved with `design_values_to_db()` |
| keyword assignment (`addKeywords` / `deleteKeywords`) | not part of the modal | keyword dual picker |
| `build_del_testsuite_warning_msg()` + `system_blocks_tsuite_delete_due_to_exec_tc` — no delete button when a deep case was linked **and executed** without `delete_executed_testcases` | `delete_deep()` on a plain `mgt_modify_tc` | gate re-checked at write time (409 `suite_has_executed`) |
| `validateForm()`: pipe rejection, empty name | client-side only | client **and** server |

## Files

- Screen `gui/templates/testcases/suiteEdit.html` (Dashio, `TLi18n`)
- BFF `api/suiteedit/index.php`
  - `GET ?action=init&mode=create|edit|delete`
  - `POST ?action=create|update|delete`
- Entry points: `gui/templates/testcases/suiteView.html`,
  `gui/templates/testcases/testSpec.html`, `lib/functions/common.php`
  (`$actions->suiteEdit` / `suiteCreate` / `suiteDelete`)
- Legacy `lib/testcases/containerEdit.php` — the five actions above now
  **302 to the new screen** (no write is executed there any more)
- i18n: 58 `sued.*` keys + `footers.suiteEdit` in all ten bundles

## Security / correctness notes

- The test project is **re-derived from the node**; a mismatch is
  `404 project_mismatch`, so `tproject_id` is never trusted as an address.
- `mgt_modify_tc` is checked on the **owning** project on every route.
- `delete_executed_testcases` is enforced **at write time**, not trusted from
  the GET that rendered the form.
- All writes are POST-only behind `bffSameOriginGuard()`.
- Custom-field labels, type names and possible values arrive as **data** and are
  inserted with `textContent`/`esc()` — a free-text CF label can never inject markup.
- `TLi18n.t()` interpolates with a **replacer function** (`i18n.js:174`), so a
  value containing `$&`, `` $` `` or `$'` must not be post-processed with
  `String.replace()`; `fill()` documents that.

## Bugs found while testing

- **#1853** (filed, not fixed) `cfield_mgr::_build_cfield()` classifies a hash
  key as a DATE part purely by its `_input` suffix, so a non-date field keeps an
  ARRAY `cf_value` and `design_values_to_db()` fatals
  (`tlStringLen(): Argument #1 must be of type string, array given`).
- **#1854** (filed, not fixed) `check_string()` answers "forbidden characters"
  for a string whose `preg_match` returned 0 — it made every suite name
  unsaveable. `api/suiteedit` calls `preg_match()` directly until it is understood.

## Class-API traps recorded for the next screen

- `testsuite::addKeywords()` / `addKeyword()` write `fk_table='nodes_hierarchy'`
  (the node id **is** the suite id), not `'testsuite'`.
- Keywords are created with `tlKeyword::initialize(null, $tprojectId, $name, $notes)`
  followed by `writeToDB($db)` — `tlKeyword` has no `create()`.
- `testcase::get_exec_status()` INNER JOINs `testplan_tcversions`, so an
  "executed" case only exists through
  `test plan -> testplan_tcversions -> tcversion -> executions`; an
  `executions` row alone reads back `no_links`.
- A stored DATE custom field is a UNIX timestamp (`mktime` in `_build_cfield`),
  not the localized string; `suiteEditIsoToLocale()` owns the conversion.
- `nodes_hierarchy` has **no `details` column** (2.0.1 schema drift).

## Defects the browser pass found (all fixed in the same run)

The API matrix cannot see these — each one needs a real DOM, a real click and a
real reload. They are recorded here because every one of them is a silent data
loss or a dead button, i.e. exactly the class of defect this screen exists to fix.

1. **The whole CF block died on a multiselection field.** `cfInput()` declared
   `var cv` inside the *checkbox* branch and the *multiselection* branch read it
   (`var` is function-scoped), so `cv.length` threw — and because `renderCfs()`
   aborts, the keyword picker below it never rendered either. One field type
   took down the form.
2. **Every save wiped the suite's keywords.** Neither `renderKws()` nor
   `moveKw()` set `selected` on the Assigned options, and `kwIdsSelected()` only
   counts `:selected` options, so the payload always carried `keywords: []` —
   verified in the DB, 1 row -> 0. The picker now marks the Assigned entries
   (and only those) as selected, so a save that does not touch the picker is a
   no-op instead of a wipe.
3. **The form could be saved exactly once per page load.** `BUSY` was released on
   every error path but not on success (`load()` does not touch it), so after one
   successful save `if (BUSY) return;` swallowed every later click — silently.
4. **33 `E_WARNING` per page load** in the Event Viewer: `suiteEditIsoToLocale()`
   read `$m[4]`…`$m[6]` of the optional time group of a DATE-only value. The
   locale/date conversion is not optional — the format string always has the
   placeholders.
5. **The legacy redirect dropped the identity** when it was reached with
   `suite_id` / `container_id` (the names the modern entry points use) instead of
   `testsuiteID` / `objectID` (the names the legacy Smarty form posts), so a
   direct link dead-ended on `invalid_parameter`.
6. **Keyword ownership was unchecked.** `testsuite::addKeywords()` takes raw ids
   and inserts them without validating them, so a payload could attach another
   project's keywords (or ids that no longer exist) to a suite; the write path now
   refuses anything outside the owning project's map with `400 keyword_mismatch`.

### A note on the two bugs that were *not* filed

The CF silent-drop and the missing executed-delete gate in `api/suiteview` are
**not** filed as separate bug issues: they are precisely the two defects
`Refs #1852` fixes (the bare name+details modal and the ungated `delete_deep()`),
so a second issue for each would only duplicate this one. The two defects that
were **outside** this screen's scope are filed: #1853 and #1854.

## Test suite

`tmp/TLU_Test_Cases.md` → `## Issue #1852` — 51 PASS / 1 SKIPPED / 0 FAIL,
including a 15-case browser pass (section G) and the keyword-ownership group (H).
Fixture: `tmp/fixtures_1852.php` (re-runnable; prints the ids it created).
