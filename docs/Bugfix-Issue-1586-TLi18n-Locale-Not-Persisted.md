# Bugfix — Issue #1586: TLi18n locale selection is not persisted when returning to a launcher

**Status:** FIXED — branch `fix/issue-1586`
**Scope:** `gui/templates/i18n/i18n.js` (shared i18n module), `gui/templates/usermanagement/userInfo.html`
**Discovered during:** the #1583 Platforms Export browser regression

---

## 1. Symptom

Choosing Romanian on a modernized screen updated that screen, but returning to
its launcher through **Cancel / Back** rendered the launcher in English again.
Loading the same destination with an explicit `?locale=ro` still worked.

## 2. Reproduction (pre-fix)

1. Log in to TestLink.
2. Open **Platforms Management** for a test project.
3. Click **Export Platforms**.
4. In the locale switcher select **Română** — the export screen renders in
   Romanian and `localStorage['tl_locale']` becomes `ro`.
5. Click **Anulează / Cancel**.
6. Platforms Management renders in **English**.

Measured on the landing launcher:

```json
{ "profile_locale": "en_GB", "ls": "ro", "resolved": "en", "heading": ["Platform Management(1)"] }
```

`en.json` was requested; `ro.json` never was.

---

## 3. Root cause

`gui/templates/i18n/i18n.js` documented a four-step locale resolution order in
its own header, but implemented only two of them:

| Documented | Implemented (before the fix) |
|---|---|
| 1. `?locale=` URL param | yes |
| 2. `localStorage('tl_locale')` | **no — never read** |
| 3. profile from `/api/userinfo/` | yes |
| 4. `'en'` | yes |

`setLocale()` still *wrote* `tl_locale` (i18n.js:67-70), so the contract was
one-way: the write side survived, the read side did not. `detectLocale()`
(i18n.js:57-65) returned `null` after the URL check, which forced `load()`
down the async profile path on every page load. Every modernized screen whose
Cancel/Back handler does not carry `?locale=` therefore lost the language.

**Regression source:** commit `751082c63` ("fix: profile locale from DB takes
priority over localStorage", Aug 20 2026) deleted the localStorage read to fix
a different, real bug — a stale stored value was shadowing the user's actual
DB profile locale. It also removed the line that echoed the profile locale into
storage, but left the writer in `setLocale()` and the header documentation in
place, so the removal was silent.

---

## 4. The fix

### 4.1 Restore step 2 of the chain (`i18n.js`)

```js
// 2. localStorage (user's manual choice). The profile lookup below never
// writes here, so this holds explicit switches only and is therefore
// allowed to outrank the DB profile (Refs #1586).
var fromStorage = storeGet(STORE_KEY);
if (fromStorage) return mapLocale(fromStorage) || 'en';
```

Because the profile lookup no longer writes the store, a value found there can
only be an explicit user switch — which is precisely what the profile should
*not* override. This keeps the benefit `751082c63` was after, while restoring
the documented behaviour the issue asked for.

### 4.2 Stop the English fallback from pinning the session

`loadStrings()` used to write `'en'` into `tl_locale` when a bundle failed to
load. While the store was write-only that was harmless; once it is read back,
a single 404 would downgrade every later screen to English permanently. The
value that failed is now dropped instead, so the next load re-resolves from the
profile.

### 4.3 Hardening applied after code review

| Change | Why |
|---|---|
| All storage access wrapped in `storeGet` / `storeSet` / `storeRemove` with `try/catch` | `detectLocale()` now runs on all 169 modernized screens; a `SecurityError` (site data blocked) escaping `load()` would leave screens showing literal keys. Same guard pattern as `aside.html:328`. |
| Self-heal only on HTTP **404**, comparing the **raw** stored value | A transient 500/timeout no longer destroys a valid choice, and codes that `mapLocale()` rewrites (`ro_RO`→`ro`, `bogus`→`bo`) are no longer left behind re-requesting a missing bundle on every load. |
| `callback()` also fires when the `en.json` fallback fails | The screen was left permanently untranslated before. |
| `userInfo.html` mirrors a saved profile locale into `tl_locale` | With the manual switch outranking the profile, saving the profile alone would have looked like it did nothing. |

### 4.4 Alternatives considered and rejected

- **Propagate `?locale=` in `returnToPlatforms()`** — fixes one screen; the
  other 10 locale-dropping navigation handlers stay broken. The defect is in
  the shared module, not in Platforms.
- **Echo the profile locale into `tl_locale` on load** (the old behaviour) —
  reintroduces the stale-value bug `751082c63` fixed.
- **Persist every `?locale=` load** — would let any inbound link silently
  rewrite the user's stored language.

---

## 5. Verification

Regression matrix (all executed in the browser; full detail in
`tmp/TLU_Test_Cases.md`, suite "Regression — Issue #1586", 13/13 PASS):

| Case | Expected | Result |
|---|---|---|
| Main repro: Export → Română → Cancel | launcher in Romanian | PASS — `ro.json [200]`, title "I1586-LOCALE - Gestionare Platforme" |
| Empty store, profile `en_GB` | English, store stays empty | PASS |
| Empty store, profile `ro_RO` | Romanian, store stays empty | PASS |
| Stored `ro` + `?locale=en` / `?locale=de` | English / German, stored switch untouched | PASS |
| Stored `xx` or `bogus` (no bundle) | English fallback, stored value dropped | PASS — `xx.json [404]` → `en.json [200]`, `tl_locale` self-healed |
| Stored `ro_RO` | mapped to `ro`, raw value preserved | PASS |
| Switcher round-trip `ro → en → ro` | launcher follows every time | PASS |
| Profile form saves `ro_RO` | `tl_locale` becomes `ro` | PASS |
| Blocked `localStorage` (SecurityError) | no exception, screen translated, no raw keys | PASS — callback in 16 ms, console clean |
| Manual switch vs. differing DB profile | explicit switch wins (as designed) | PASS (recorded trade-off) |
| Event Viewer | no new Error/Warning | PASS — only audit rows; the 3 pre-existing `log_level` 1/2 rows predate the fix and came from the fixture author's own first attempts |

Screenshots (committed under `docs/screenshots/`):

* `issue-1586-before-platformsview-english.png` — launcher in English, before the fix
* `issue-1586-after-platformsview-romanian.png` — same launcher in Romanian, after the fix

---

## 6. Files changed

| File | Change |
|---|---|
| `gui/templates/i18n/i18n.js` | restore the `tl_locale` read; guarded storage helpers; 404-only self-heal; fallback callback |
| `gui/templates/usermanagement/userInfo.html` | mirror a saved profile locale into `tl_locale` |
| `CHANGELOG` | 2.0.1 "Key bugfix" entry |
| `tmp/TLU_Test_Cases.md` | regression suite, 13 cases |
| `tmp/fixtures_1586.php` | re-runnable fixture (project / plan / platform) |
| `docs/screenshots/issue-1586-*.png` | before / after evidence |

## 7. Known limitations (deliberate, documented)

* **Manual switch outranks the DB profile.** Changing the profile language no
  longer overrides a language the user explicitly picked in the switcher. This
  is the requested behaviour; the profile form now writes the store, so the
  profile remains an effective way to change the language.
* **`tl_locale` is origin-scoped, not user-scoped.** On a shared browser the
  language carries over to the next user account logged in on the same origin.
  Clearing it on logout would fix that but touches every screen's session
  teardown, so it was left out of this fix.
* **Locales without a JSON bundle** (`cs`, `fi`, `id`, `ko`, `nl`, `pl`) are
  offered by the switcher, which is fed by the same list as the profile form.
  Picking one shows English; with the 404 self-heal the choice is dropped and
  the next load returns to the profile. Pre-existing behaviour, unchanged.
