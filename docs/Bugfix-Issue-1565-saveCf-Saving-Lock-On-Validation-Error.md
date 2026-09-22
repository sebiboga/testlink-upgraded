# Bug fix — Issue #1565: cfieldsView saveCf() leaves saving=true locked when client-side validation fails — Save button dead until reload

**Issue:** [#1565](https://github.com/sebiboga/testlink-upgraded/issues/1565)
**Branch:** `fix/issue-1565`
**Status:** FIXED & VERIFIED (2026-09-22)

## Symptom

In the modern Custom Fields screen (`gui/templates/cfields/cfieldsView.html`), if
the create/edit modal fails client-side validation (empty Label or Name), the Save
button stops working for the rest of the session — every further click is silently
swallowed — until the page is reloaded. No request is sent, no data is lost.

## Repro steps (this run, fresh DB)

1. `http://localhost:8082/gui/templates/cfields/cfieldsView.html?tproject_id=1&tplan_id=0` (admin/admin).
2. Click `+ Create Custom Field`, leave Label/Name empty, click `Save`.
3. Modal shows "Label and Name are required." (`#modalError`).
4. Fill Label = `Repro CF`, Name = `repro_cf`, click `Save` again.

**Actual (pre-fix):** no request fires (network panel unchanged), `saving` stays `true`
in page scope. **Expected:** the second Save POSTs the create.

## Root cause chain

1. `saveCf()` acquires a re-entrancy lock before validation —
   `gui/templates/cfields/cfieldsView.html:344` `if (saving) return;` and `:345`
   `saving = true;`.
2. The client-side validation branch — `gui/templates/cfields/cfieldsView.html:359-362`:
   ```js
   if (!data.label || !data.name) {
     $('#modalError').text(TLi18n.t('cf.validation.required')).show();
     return;
   }
   ```
   `return`s WITHOUT releasing the lock.
3. Every other exit path releases it: ajax `success` (`:375` `saving = false;`) and
   ajax `error` (`:385` `saving = false;`). The validation path is the only gap.
4. Consequence: the guard at `:344` swallows every later Save click until a reload
   re-runs `var saving = false;` (`:155`).

**Regression source:** commit `06aaadab7` (Refs #953, "create-and-assign to current
test project") introduced the `saving` guard/lock and the resets only on the ajax
handlers; the already-existing validation `return` was not retrofitted, so the
lock-out shipped with that change.

## Fix approach

Minimal one-line change — release the lock on the validation-error path:

```js
if (!data.label || !data.name) {
  $('#modalError').text(TLi18n.t('cf.validation.required')).show();
  saving = false;
  return;
}
```

Why this method: the lock's purpose is only to prevent double-submit while an ajax
call is in flight; a client-side validation failure means nothing was sent, so the
flag must be cleared so the user can correct the fields and retry (exactly the
behaviour of the server-error handler). The button stays live, the modal keeps the
error visible for feedback. Alternatives considered and rejected: (a) reordering the
validation before `saving = true` — stylistically cleaner but a larger diff that
changes control flow; (b) a `try/finally` — overkill for one return path.

**Blast radius / scope check (entire tree):** the `saving` lock is used only in
`cfieldsView.html` and `platformsAssign.html:341,347` (brace variant) — the latter
acquires it only after its no-op checks and always resets via `.always()`, so it is
unaffected. `cfieldsAssignView.html:300` has the same validation branch but NO
`saving` guard → unaffected.

## Verification

Full browser regression matrix on `http://localhost:8082` (admin) — 6/6 PASS (see
`tmp/TLU_Test_Cases.md` suite 1565):

1. Valid-validate → error shown AND `saving === false` (pre-fix `true`).
2. Retry after validation failure → `POST /api/cfields/index.php [200]`, modal closes,
   row appears.
3. Validate → cancel → reopen → still unlocked, Save works.
4. Edit path → `GET /{id}` + `PUT /{id} [200]` + refresh; modal closes.
5. Server-error path (duplicate name) → `POST [400]` «Custom field name already
   exists» shown, `saving === false`, retry possible.
6. Event Viewer: only `log_level=16` audit rows (login, create, update); zero
   Error/Warning. No new JS console errors.

Screenshots: `docs/screenshots/issue-1565-save.locked-before.png` (pre-fix locked
state) and `docs/screenshots/issue-1565-save.locked-after.png` (post-fix server-error
handling, lock released).

## Files changed

- `gui/templates/cfields/cfieldsView.html` — 1 line added (`saving = false;` on the
  validation-error path).
- `tmp/TLU_Test_Cases.md` — Regression — Issue #1565 suite (6/6 PASS).
- `CHANGELOG` — KEY BUGFIX entry.
- This doc + wiki mirror + two screenshots.

## Result

Issue #1565 closed as fixed: one-line release of the `saving` lock on the validation
failure path; full regression matrix passes error-free.