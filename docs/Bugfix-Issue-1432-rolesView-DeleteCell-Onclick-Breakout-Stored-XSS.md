# Issue 1432 — rolesView.html: delete-cell onclick attribute breakout — stored-XSS via role description

**Issue:** [#1432](https://github.com/sebiboga/testlink-upgraded/issues/1432)
**Branch:** `fix/issue-1432`
**Status:** VERIFIED-FIXED (2026-09-12)

## Symptom

The Role Management grid (`gui/templates/usermanagement/rolesView.html`) rendered the
delete action as an inline attribute handler that interpolated the role name **raw**:

```html
onclick="confirmDelete(4, '<role name with quotes escaped>')"
```

Only single quotes were escaped (`r.name.replace(/'/g, "\\'")` at `rolesView.html:203`).
A role whose name/description contains a double quote (e.g.
`"><img src=x onerror=location.hash=\`RVXSS\`>x`) closes the `onclick` attribute;
the trailing `><img ...>` is then parsed by the HTML parser as a **real element** inside
the actions `<td>`, and its `onerror` fires when the image fails to load — on first
render, no click needed.

Note the Role Management screen is a special case of the #1406/#1430 family: the `roles`
table has **no `name` column** (`SHOW COLUMNS FROM roles` → `id/description/notes`),
so the displayed role "name" *is* the `roles.description` column (`getDisplayName()`);
the description is both the label and the XSS vector.

## Repro steps

1. Plant a payload into a role description:
   `UPDATE roles SET description='"><img src=x onerror=location.hash=`RVXSS`>x' WHERE id=4;`
   (or create the role via the UI — the BFF stores it verbatim).
2. Log in admin/admin at http://localhost:8082.
3. Open `gui/templates/usermanagement/rolesView.html?tproject_id=0&tplan_id=0`.
4. The grid renders: `document.querySelectorAll('img')` returns
   `["<img src=\"x\" onerror=\"location.hash=`RVXSS`\">"]` and `location.hash` becomes
   `#RVXSS` — measured live on this fixture (page URL carried the `#RVXSS` fragment).

## Root cause

- `api/roles/index.php` `roleToJSON()` returns `'name' => $r->getDisplayName()`, i.e. the
  `roles.description` value, served verbatim.
- `gui/templates/usermanagement/rolesView.html:203`:
  `'<i class="fa fa-trash action-btn danger" title="Delete" onclick="confirmDelete(' + r.id + ', \'' + r.name.replace(/'/g, "\\'") + '\')"></i>'`
  — only `'` is neutralized; `"`, `<`, `>` survive, so a `"` in the name breaks the
  attribute and `<img onerror=…>` becomes live markup (= stored-XSS sink, executes at
  grid render).
- Why it breaks NOW (regression source): the #1406 fix
  (`fix/issue-1406-usersview-xss`, ESLented the role name *column* cell here at
  `rolesView.html:208`) but left the delete-cell attribute interpolation raw. The
  same screen's sibling `usersView.html` was fixed by moving the name out of the
  inline attribute entirely (`deleteUser(id)` resolves the login from `allItems`,
  `usersView.html:841-847`).
- Blast radius: only `rolesView.html:203` on this screen; other usermanagement
  screens pass only numeric ids in `onclick=` handlers (verified by grep). Admin-gated
  (mgt_users + ability to plant a role description) → priority minor, same doctrine as
  #1406/#1430.

## Fix (minimal, mirrors usersView #1406)

1. Added a module-level `allItems` array; `loadRoles()` now stores `r.items`.
2. Delete cell passes **only the id**: `onclick="confirmDelete(<id>)"`.
3. `confirmDelete(id, name)` → `confirmDelete(id)`; the role name is resolved from
   `allItems` by id at click time and rendered through the existing `esc()` into the
   modal message — text context only, no attribute context, no breakout possible.

Method chosen on purpose: identical to the #1406/#1430 family so the whole
usermanagement area converges on one safe pattern. Alternative rejected: escaping
`"`/`<`/`>` into the attribute would still leave a JS-string-in-attribute structure
(fragile; the id-only pattern removes the class of bug, not just this instance).

## Verification

Regression suite `Regression — Issue #1432` in `tmp/TLU_Test_Cases.md` (10 cases), all
PASS:
- 1432.1 pre-fix control → injected `<img>` present, `location.hash` = `#RVXSS`;
- 1432.2 post-fix grid → `img_count` 0, hash empty, payload shown as escaped text;
- 1432.3 delete icon = `onclick="confirmDelete(4)"` (id only);
- 1432.4 confirm modal on payload role → entity-escaped name, no element created;
- 1432.5 cancel flow; 1432.6 full create→list→delete of a payload-named role via UI;
- 1432.7 system roles keep no trash icon; 1432.8 edit flow regression;
- 1432.9 Event Viewer clean (audit only, no ERROR/WARNING);
- 1432.10 `node --check` on extracted inline script + grep sweep.
Result: 10/10 PASS. JS syntax check green; no i18n bundles touched (no user-facing
strings added/changed — fix is markup/JS only).

## Files changed

- `gui/templates/usermanagement/rolesView.html` (+8/−2)
- `docs/screenshots/issue-1432-roles-xss-repro.png` (pre-fix) / `issue-1432-roles-postfix-grid.png`
- `tmp/TLU_Test_Cases.md` (suite commit)