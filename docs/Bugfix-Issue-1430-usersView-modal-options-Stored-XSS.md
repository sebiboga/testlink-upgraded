# Issue 1430 — usersView.html: edit-modal `<option>` labels appended unescaped — stored-XSS via role names

**Issue:** [#1430](https://github.com/sebiboga/testlink-upgraded/issues/1430)
**Branch:** `fix/issue-1430`
**Status:** VERIFIED-FIXED (2026-09-11)

## Symptom

The Create/Edit User modal option lists in `gui/templates/usermanagement/usersView.html`
are built by string concatenation into `<option>` HTML without escaping:

```js
sel.append('<option value="' + role.id + '">' + role.name + '</option>');   // line 247
```

A role stored with an HTML payload in its `description` (rolesView accepts it
server-side unescaped) was parsed as a real element inside the `<option>` — the
`<img onerror>` handler executed. Because `loadMeta()` runs at page init
(`loadGrants` → `loadMeta(cb)`, usersView.html:200), the XSS fired on **plain page
load** of the usersView screen, and again when the Create/Edit modal was opened — the
ticketer originally assumed only the modal path was exposed; the drive-by page-load path
is strictly worse.

![modal before fix — payload executes, document.title flips to MODALXSS1430](issue-1430-modal-xss-repro.png)

## Repro steps

1. Plant a role description containing markup, e.g. (fresh fixture):
   `INSERT INTO roles (description, notes)
      VALUES ('"><img src=x onerror=document.title=\'MODALXSS1430\'>x','xss-fixture');`
   Leading `>` keeps the name outside the `<...>` system-role translation in
   `tlRole::getDisplayName()` (lib/functions/tlRole.class.php:261), so the payload is
   served verbatim by `GET /api/users/index.php/meta/roles`.
2. Log in admin → open `gui/templates/usermanagement/usersView.html?tproject_id=0&tplan_id=0`.
3. Measured before fix: the tab title becomes `MODALXSS1430` immediately on load; the
   Create modal's Global Role select shows the payload shell with a real `<img>` element
   (option label rendered as `">x`). No operator click required.

## Root cause

- Four sinks in `loadMeta()` (usersView.html:241-273) interpolate labels raw into
  `<option>` markup: `role.name` (247), `loc.name` (255), auth `cfgDesc`/`it.label`
  (270/272).
- Of these, only `role.name` is **user-controlled** — `roles.description` is written
  verbatim via rolesView. The other three are server-configuration literals:
  `config_get('locales')` (cfg/const.inc.php:282) and
  `config_get('authentication')['domain']` (config.inc.php:465-466).
- The locale config values are **pre-HTML-entity-encoded** by design
  (`'fr_FR' => 'Fran&ccedil;ais'`, `'ro_RO' => 'Rom&acirc;n&#259;'`); the legacy Smarty
  path renders them through `htmlspecialchars(..., double_encode=false)`. Naively
  applying the client-side `esc()` to `loc.name` **double-encodes** them — measured
  corruption: the France option displayed the literal text `Fran&ccedil;ais`.
- The grid path was already fixed by #1406 (`esc()` on text cells, committed a7e97345a);
  `loadMeta()` predates that convention and was left raw. The `esc()` helper itself
  already exists at usersView.html:173 (introduced by #1406).

## Fix (minimal)

Wrap the single user-controlled sink with the existing `esc()` helper (usersView.html:173):

```js
sel.append('<option value="' + role.id + '">' + esc(role.name) + '</option>');  // line 247
```

`value` stays the plain numeric role id. `loc.name`, auth `cfgDesc` and auth `it.label`
are config-owned, pre-entity-encoded literals — escaping them would corrupt the UI
(verified and reverted), and they carry no user input, so they are not XSS vectors.
No backend change: output encoding belongs at the render boundary.

Alternatives rejected: (a) `esc()` on all four sinks — breaks `Français`/`Română`
display via double-encoding; (b) decoding config entities client-side before
re-escaping — fragile, and unnecessary for config-owned data.

## Verification

Regression suite `Regression — Issue #1430` in `tmp/TLU_Test_Cases.md` (8 cases):
- 1430.1 pre-fix control → page title flips to `MODALXSS1430` on load, base modal
  option shows `<img>` consumed;
- 1430.2 post-fix → title stays `User Management`, 0 `<img>` elements in `#editRole`,
  payload option text renders the literal `"><img src=x onerror=...'>x`;
- 1430.3 no double-encode regression — `fr_FR` label `Français`, `ro_RO` `Română`,
  auth `Default (DB)`/`DB`/`LDAP`;
- 1430.4 create user `xss_test_user` (role tester) via the modal → row appears;
- 1430.5 edit that user → title `Edit User: xss_test_user`, role pre-selected `tester`,
  modal contains 0 `<img>`, title unchanged;
- 1430.6 "Manage user" lookup → `Edit User: admin`;
- 1430.7 rolesView cross-check — role grid column still renders the payload escaped
  (post-#1406); a **new** same-family sink observed in the rolesView delete-cell
  `onclick` → filed as **#1432**;
- 1430.8 hygiene — `events` table shows only info-level audit rows (login + user
  creation), 0 new Error/Warning.
Result: 8/8 PASS.

![modal after fix — payload shown as literal text, français display intact](issue-1430-modal-xss-fixed.png)

## Files changed

- `gui/templates/usermanagement/usersView.html` (+1/-1)
- `docs/screenshots/issue-1430-modal-xss-repro.png` / `...-fixed.png`
- `tmp/TLU_Test_Cases.md` (suite commit)

Same-family bug discovered while testing (out of scope, filed):
- `rolesView.html` delete action interpolates `role.name` into an inline
  `onclick="confirmDelete(id, '<name>')"` attribute with only single-quote escaping —
  a role description containing `"` breaks out of the attribute and executes on grid
  render (verified: `location.hash` flips to `#RVXSS`) → **#1432**.