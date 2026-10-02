# Build Create/Edit — Modernized (TestLink 2.0.1, Refs #1787)

Legacy controller `lib/plan/buildEdit.php` (769 lines) and Smarty template
`gui/templates/dashio/plan/buildEdit.tpl`.

## Why this screen, given the ledger was already complete

`docs/MODERNIZATION-STATUS.md` had an **empty TODO section** (0 items) and every
legacy `lib/**/*.php` still calling `smarty->display|TLSmarty` already had a
modern twin. A full legacy sweep was therefore run, and the only surviving
**functional** gap in an otherwise-modernized area was picked.

## The gap this closes: build DESIGN custom fields

Legacy `buildEdit.tpl:78-80` rendered the test project's build **design**
custom fields:

```smarty
{foreach $gui->cfields}
<tr><td><b>{$cf.label}</b></td><td>{$cf.input}</td></tr>
{/foreach}
```

fed by `lib/plan/buildEdit.php` `initializeGui()` →
`buildMgr->html_custom_field_inputs($build_id, $tproject_id, 'design', '', $_REQUEST)`,
and persisted by `doCreate()`/`doUpdate()`:

```php
$buildMgr->cfield_mgr->design_values_to_db($_REQUEST, $buildID, $cf_map, null, 'build');
```

The modern inline Create/Edit modal of `buildsView.html` never grew the CF
block, and the BFF ignored them entirely — a **silent drop on both halves**:
the definitions were neither shown nor written.

## Modern stack

| Layer | File |
|---|---|
| Screen | `gui/templates/plans/buildEdit.html` (Dashio standalone HTML/JS/CSS) |
| BFF | `api/builds/index.php` (`GET /`, `GET /{id}`, `GET /cfields`, `POST /`, `PUT /{id}`, `POST /{id}/flags`, `DELETE /{id}`) |
| Legacy | `lib/plan/buildEdit.php` → redirect-only shim (769 → 66 lines) |
| Wiring | `$actions->buildEdit` in `lib/functions/common.php` (inside the `tplan_id` guard) |
| Entry point | "Create Build (full screen)" button in `gui/templates/plans/buildsView.html` |
| i18n | 51 `bedit.*` + `bv.createBuildFullScreen` + `footers.buildEdit` in **all 10** bundles |

## Parity

- **Build name** — trimmed, required, unique within the test project.
- **Build notes**, **Active**, **Open** (with the `closed_on_date` stamp applied server-side).
- **Release date** — validated as a real calendar date, not just a regex.
- **Copy tester assignments** — source build select **with the assignment count in
  brackets**, plus the "Only with execution status" multi-select.
- **Copy to all other active test plans** — create-only, exactly like the legacy
  `enable_copy` flag, with the count of sibling plans in the hint.
- **Show event history** — gated on `mgt_view_events` (legacy `buildEdit.tpl:53`).
- Refresh / Back to Builds & Releases / Close, locale switcher, footer.
- All custom field input shapes: string, numeric, email, list, radio, checkbox,
  multiselection list, text area, date, datetime.

## Custom fields as data, not HTML

`GET /api/builds/index.php/cfields?tplan_id=N[&build_id=M]` returns the
definitions **as JSON**. The legacy template rendered server-built HTML; a
custom field label or `possible_values` entry is free text, so injecting it as
markup would be an XSS sink. Everything goes through `esc()` on the client.

The route resolves and rights-checks the **project first**, then the build, and
rejects a build outside the addressed project with a 404 — identical to a build
that does not exist, so it leaks nothing and a foreign `tplan_id` cannot be used
as an existence oracle.

On write, `saveBuildCfields()` rebuilds the legacy `custom_field_<type>_<id>`
hash **from the field ids the server resolved**, so a submitted key can never
name a field outside the project; unknown ids are dropped.

## Screenshots

| State | Image |
|---|---|
| Create Build, all six custom fields, copy options | `1787-buildedit-create.png` |
| Edit Build with all six custom fields prefilled | `1787-buildedit-edit.png` |
| Insufficient rights (role without `testplan_create_build`) | `1787-buildedit-denied.png` |

## BFF defects found and fixed while testing

**(a) The date locale trap.** A date custom field is stored as a **UNIX
timestamp** (`mktime()` in `_build_cfield()`), but `split_localized_date()`
parses the **session locale's** format — `d/m/Y` for `en_GB`, `m/d/Y` for
`en_US`, `d.m.Y` for `de_DE`, `Y/m/d` for `ja_JP`. Feeding ISO `2026-11-15`
straight in stored `1621296000` = **2021-05-18** under `en_GB`: a silent
five-year corruption of a single user action. The client keeps speaking ISO
(`<input type="date">`) and the server now owns the locale conversion
(`epochToIsoDate()` / `isoToLocaleDate()`), verified round-trip identical for
`en_GB`, `en_US`, `ja_JP` and `de_DE`.

**(b) Partial writes cleared the rest, and miscounted.** `design_values_to_db()`
writes **every** field of the passed `$cfMap` — the hash only supplies values.
A `PUT` carrying one custom field therefore wiped the other five while
`cfields_written` reported `1`. The full-replacement semantics (which is exactly
what 1.9.20 did, since an HTML form always submits every input) are now explicit
and the reported count matches what was written.

**(c) Cross-project IDOR in `tplan_id` addressing.** `GET/PUT/DELETE /{id}` and
`POST /{id}/flags` accepted a `tplan_id` and then **ignored** it:
`resolveBuild()` derives the project from `build.testproject_id`, so the
**right** was checked correctly but the **address** was not. Measured: a
cross-project `DELETE` returned **200 and actually deleted the build**, and the
result was shown inside the other plan's context. Now a build addressed through a
foreign plan is a 404. (`PUT` reads the plan from the JSON body, the legacy form
shape, falling back to the query.)

**(d) `rights.canViewEvents` was missing.** Legacy gated the event-history
button on `mgt_view_events`; the payload never carried the flag, so the button
could never be offered.

**(e) The day silently vanished on every east-of-UTC host.** The stored stamp
is `mktime()` — **local** midnight — but it was read back with `gmdate()`. On the
UTC CI host the two agree, so no test could ever catch it; with
`TZ=Europe/Bucharest` a typed `2026-11-15` is stored as `1794693600` and `gmdate()`
returns **2026-11-14**. Same for `Asia/Tokyo`. `date()` reads back the same
calendar day in every zone probed. A guest who types a ship date on a
non-UTC installation would see it come back one day earlier.

**(f) The screen bypassed its own scope check.** `loadBuild()` called
`GET /{id}` **without** `tplan_id`, so (c) above applied to every write but not to
the prefill. The screen now sends the plan with the build id.

**(g) A datetime custom field silently became midnight.** Type 10 rendered as a
plain text box, and the write path pinned
`custom_field_10_<id>_hour/_minute/_second` to `0`. It now renders
`<input type="datetime-local">` and the submitted time of day reaches the stamp.

## What the code review of the finished screen caught

Nine further defects, **two of them blockers** — none reachable from the happy path the
functional suite walked, which is exactly why the review step exists.

**(h) The shim never navigated — BLOCKER.** `redirect($url, $level)` emits
`"$level.href='$url';"`, so `$level` must be a *location object*, never a method. Passing
`'window.location.replace'` produced `window.location.replace.href='…'` — an expando on the
function object — so a legacy bookmark landed on a **blank page** and the shim quietly did
nothing. The `replace()` is now emitted directly; verified by navigating in Chrome.

**(i) Renaming a build from the table wiped its custom fields — BLOCKER, data loss.**
`saveBuildCfields()` ran on **every** `PUT`, and `design_values_to_db()` writes *every*
field of `$cfMap` — empty when the request carries no `cfields` key. The inline Edit modal
of `buildsView.html` sends only name/notes/release_date/active/open, so a plain rename
cleared all six fields while answering `cfields_written: 6`. The write is now gated on the
key being present: full replacement for the full-screen editor, untouched custom fields for
the table's modal.

**(j) The flags scope check read the wrong place.** `POST /{id}/flags` read `tplan_id` from
the query, but `buildsView.html` puts it in the **JSON body** — so the Active/Open toggles
were not covered by the scope check at all. Body first, query as fallback.

**(k) The audit string warned on every save.** `$ctx['tplan_name']` was read from a
`resolveBuild()` context, which only has `tproject_id` / `tproject_name`:
`Undefined array key "tplan_name"` plus an empty plan name on every create/update/delete.

**Also fixed:** `required` was read from a non-existent `values_required` column instead of
`cfield_testprojects.required`, so the red mandatory `*` was dead code; an unchecked
checkbox/multiselection sent `[]` and `_build_cfield` then read `$value[0]` on an empty
array (`Undefined array key 0`), stored **NULL** and tripped two more deprecations — the key
is now skipped so the `''` initializer applies; #1788's fix had replaced the fatal with
`Undefined array key "input"`, so the empty array is now fully keyed; and on the client Save
stayed disabled after a successful edit save (a second correction needed a reload),
`closed_on_date` was returned but never rendered although legacy printed "Closed on date"
beside the Open checkbox, and three bits of dead code were removed.

## Bugs found and filed separately

- **#1788** — PHP 8 fatal in `cfield_mgr::_build_cfield()` when a date/datetime
  CF is defined but not submitted (`$value['input']` on a string →
  `TypeError: Cannot access offset of type string on string`). The legacy form
  never hit it (an HTML form always submits every input), so **every JSON/API
  caller with a partial hash** did. Fixed by normalizing to `[]` before the
  switch.
- **#1789** — `E_WARNING: Undefined array key 0` in `common.php:512` for admins.
  `tlUser::getAccessibleTestPlans()` only `array_values()`s its result for
  **non**-admins, so an admin got the id-keyed map and `$tplan_data[0]` warned,
  then called `setSessionTestPlan(null)`. Fixed with `reset()`.

`lib/plan/buildEdit.php` also no longer fatals with `Call to undefined method
build::getCustomFieldsValues()` — a method removed in 2.0.1 but still called at
`buildEdit.php:393`, `buildView.php:94` and `planView.php:75`.

## State handling

Every terminal condition is a stable machine-code card rather than a blank page,
and the form is never shown on an error:

| Code | Meaning |
|---|---|
| `no_tplan` | `tplan_id` missing — open the screen from a plan's Builds & Releases list |
| `no_right` | no `testplan_create_build` on the project (verified with a `tester`-role user) |
| `build_not_found` | build deleted, or belonging to another project |
| `http_<code>` | any other failure, with the HTTP status |
| session expiry | redirect to `login.php` with a `destination`, as the other modern screens do |

## Test results

**35 / 35 PASS**, suite in `tmp/TLU_Test_Cases.md` (23 functional cases + 12
found by the mandatory code review, 2 of them blockers). Covered: create and edit
round trips for all six CF types, the locale date trap (4 locales), the
partial-write fatal, full-replacement semantics, cross-project isolation on
read/write/flags/delete/cfields, the legitimate scope still working, error codes
(`tplan_id=0`, unknown plan, unknown build, non-GET on `/cfields`), validation
(empty / duplicate / impossible date), **CSRF** (403 with no same-origin proof),
the 403 permission path with a real limited user, the legacy shim incl. its 405
guard, all-10-bundle i18n presence, the non-UTC day loss across four timezones,
the screen's own `tplan_id` addressing, the datetime control, the shim
navigating for real, inline renames preserving custom fields, the flags scope check, an
unchecked multi-select, and a clean Event Viewer (0 new warnings over a 10-request sweep
of all seven routes).

Browser console clean; Event Viewer shows no new Error/Warning entries.

## Commits

| Commit | Content |
|---|---|
| `25d6d8f97` | BFF: `/cfields` route + create/update persistence |
| `3c588835c` | fix #1788 (PHP 8 fatal) + date locale fix + full-replacement + scope check + `canViewEvents` |
| `883ec2df3` | the screen, i18n in 10 bundles, wiring, legacy shim |
| `840ebe7f8` | fix #1789 (`Undefined array key 0`) |