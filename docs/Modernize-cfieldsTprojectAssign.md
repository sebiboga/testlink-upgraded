# Modernize: Custom Fields → Test Project (`cfieldsTprojectAssign`)

**Refs #1816** (enhancement) · suite `Issue #1816` = **49/49 PASS**

## Why this screen

The TODO section of `docs/MODERNIZATION-STATUS.md` is empty — every ASIDE entry already maps to a
modern `.html` screen plus a BFF. The smallest coherent legacy-only slice left was
**Custom Fields → Assign to Test Project**: `lib/cfields/cfieldsTprojectAssign.php` +
`gui/templates/dashio/cfields/cfieldsTprojectAssign.tpl`, the definition ↔ test-project link screen,
reachable only from the Custom Fields manager.

## Authorization holes in the legacy controller

| # | Hole | Legacy | Now |
|---|---|---|---|
| 1 | **Cross-project** authorization | a user with a *project* role of Test Project Manager / Admin / Leader on project A was authorized for project **B** whenever the *global* role was `testproject_manager` or higher (roles 5/9/10 — role 7/3 leaked through) | `cfield_management` checked on the **addressed** project on **every** route, *including the project switcher* |
| 2 | Submitted ids | `cfield_id[]` from the POST body was never proven to exist | every id intersected with the real field set, and the assign path with the *not-yet-linked* set |
| 3 | Addressed project | never proven to exist | `get_node_hierarchy_info()` → **404** `tproject_not_found` |
| 4 | Write verb | assign / unassign / save all ran on **GET** | **POST-only** behind a same-origin CSRF proof |

## Modern screen

`gui/templates/cfields/cfieldsTprojectAssign.html` — Dashio: teal header, TLi18n locale switcher,
dark toolbar, and two cards:

- **Custom fields assigned to this test project** — NAME / LABEL / TYPE / AVAILABLE ON /
  DISPLAY ORDER / LOCATION / ACTIVE / REQUIRED / MONITORABLE, with a confirm-modal **Unassign**.
- **Available custom fields** — *not yet assigned to any test project*, with **Assign**.

The **project switcher** is new: legacy read the project from the session, so moving between
projects required hand-editing the URL. The **LOCATION** dropdown renders only for design-time
test-case fields and `—` for everything else, exactly as `tpl:91-98` did.

## BFF

`api/cfieldstproject/index.php` — `GET ?action=init|projects`, `POST ?action=assign|unassign|save`.
Stable machine codes, each mapped to an i18n key:
`400 nothing_selected|unknown_cfield` · `401` · `403 no_right` · `404 tproject_not_found` ·
`405` · `409 already_assigned`.

Legacy `lib/cfields/cfieldsTprojectAssign.php` is now a session-guarded shim: **302** to the modern
screen for a browser navigation (carrying the session project), **405** for anything that looks like
a write, `login.php?note=expired` for an anonymous caller.

## Bugs found by the browser pass

Each filed with `--label bug`, each fixed in its own commit.

| Issue | Defect |
|---|---|
| **#1817** | `testproject::get_list()` **does not exist** → `?action=projects` answered HTTP 500 with a **zero-length body**. Only reproducible with a session carrying a test project, which is why a bare `curl` login looked like a 200. Replaced with `get_accessible_for_user()`, which is also the semantically right source (it drops private projects the user holds no role on). |
| **#1818** | `guard()` set `busy=true` but only `saveAll()` released it, so after **one** Assign every button became a silent no-op. The dangerous part: a discarded **Save** leaves the edited values in the inputs, so a lost write looks exactly like a successful one (measured against the DB — `display_order` stayed `1`). |
| **#1819** | The assign/unassign toast printed the raw `{count}` placeholder. Save was unaffected because it *did* pass the params, which is what made it easy to miss. |
| **#1820** | `audit_cfield_location_changed` (missing in 15/19 server locales) and `audit_cfield_monitorable_on/_off` (15/19 — and the key is **built from a variable**, so a grep for the literal finds nothing but `zh_CN`) meant two `LOCALIZATION` warnings in the Event Viewer on every save. |
| **#1821** | *Check / uncheck all* was **completely dead**: `toggleAll()` called `invalidateSearch()` on an object that has no such method in DataTables 1.13.7, throwing **before** the rows were ticked. The master checkbox still flipped natively, so it looks like a styling quirk. |
| **#1822** | `link_to_testproject()` does an unconditional `INSERT` against PK `(field_id,testproject_id)`, so a duplicate assign served a **DB Access Error HTML page as HTTP 200**. |

### Reported, not fixed

**#1823** — `ro_RO` and most other server locales lack the node-type and custom-field *location*
labels (`before_summary` is in only 5/19 locales, `hide_because_is_used_as_variable` in 4/19), so the
Location dropdown renders English. The BFF calls `lang_get()` correctly and falls back to `en_GB`
as designed — this is missing translation *data*, not a screen defect. Authoring ~8 keys × 14
locales is a translation task.

## Why the suite asserts state, not "did it crash"

Every one of **#1818**, **#1819** and **#1821** still *looked* fine when clicked — #1821's master
checkbox flipped natively — and would have passed an eyeball check while silently doing nothing. So
every case measures the resulting state: `checked`, `indeterminate`, the `(n)` selection counter,
`disabled`, and a direct SQL cross-check of every write.

## Verification

- Suite `Issue #1816` — 49/49 PASS (`tmp/TLU_Test_Cases.md`), incl. the full rights matrix with a
  real cross-project **write** attempt (403 on all three verbs), CSRF with a missing and with a
  foreign `Origin`, and the duplicate-assign 409.
- `TLU_REQUIRE_SUITE="Issue #1816" bash ai/verify_test_suites.sh` → 7 PASS / 0 FAIL, 74 → 75 suites,
  none lost.
- Browser console: **0 errors**. Event Viewer: **0 new Error/Warning** rows.
- Fixture `tmp/fixtures_1816.php` — two projects, five custom fields covering every shape the screen
  renders, two permission users (one project-scoped, one with no rights at all).

## Commits

`96596c8a9` (BFF) → `d4e40daeb` (screen) → `382ead932` (i18n ×10 + wiring + shim) →
`9a743b67f` (#1817) → `3128139a4` (#1818 + #1819) → `36121574a` (#1820) →
`0df8f57f7` + `d9969d57a` (#1821) → `c7532c787` (#1822) → `7c4702796` + `48dab1612` (suite + fixture).
