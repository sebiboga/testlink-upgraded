# Issue 654 — dashio theme missing `login/firstLogin.tpl` → HTTP 500 / Smarty fatal on plain GET of `firstLogin.php` (self-signup enabled)

**Issue:** [#654](https://github.com/sebiboga/testlink-upgraded/issues/654)
**Branch:** `fix/issue-654-firstlogin-tpl`
**Status:** FIXED & VERIFIED (2026-08-24)

## Symptom

With self-registration left at its default (`$tlCfg->user_self_signup = TRUE;`,
`config.inc.php:590`), any **plain** GET of the signup page dies with a Smarty fatal:

```
curl -sS -o /dev/null -w "%{http_code}\n" http://localhost:8082/firstLogin.php
500
```

The response body is empty (display_errors off); the Smarty error is
`Unable to load template 'file:login/firstLogin.tpl'`. The self-registration flow is
completely broken for direct URL entry, bookmarks and external links.

Measured during reproduction: the sibling path
`firstLogin.php?viewer=new` returned **200**, which is why click-through testing of the
login page never caught this — every in-theme "Sign Up" link (`login-dashio.tpl:87`)
carries `?viewer=new`.

## Root cause chain

1. `firstLogin.php:18` → `templateConfiguration()` builds the default template name
   from the script name → `login/firstLogin.tpl`.
2. `firstLogin.php:75-80` → `$tpl = 'login/' . $tpl; $smarty->display($tpl);` — the only
   bypass is `$args->viewer == 'new'`, which switches to
   `firstLogin-model-marcobiedermann.tpl`.
3. `lib/functions/tlsmarty.inc.php:101-110` → the TLSmarty constructor hardcodes
   `template_dir` to `gui/templates/dashio/` (single-theme layout, no fallback).
4. `gui/templates/dashio/login/` contained only `login-dashio.tpl` +
   `login-model-marcobiedermann.tpl` + `firstLogin-model-marcobiedermann.tpl` — the default
   signup template was never ported when dashio became the active theme.

**Why it breaks:** tl-classic still ships `login/firstLogin.tpl`, but it is unreachable
because Smarty resolves exclusively under the dashio dir. POST submissions that fail
validation (e.g. password mismatch) re-render through the same missing template, so those
paths 500'd too.

## Blast radius

- Any plain GET `/firstLogin.php` → 500.
- Any failing signup POST → 500 after submit (user sees a dead page instead of the form +
  validation message).
- All in-repo links use `viewer=new`, unaffected — masking effect described above.

## Approach — add the missing Dashio template (no PHP change)

Chosen fix: create `gui/templates/dashio/login/firstLogin.tpl`, visually modeled on the
existing `login-dashio.tpl` (same head assets: favicons, bootstrap, FontAwesome,
style.css/style-responsive.css; same `#login-page > form.form-login` card, `.btn-theme`
submit button, backstretch background using the static theme asset `img/login/login-bg.jpg`)
with full functional parity to `tl-classic/login/firstLogin.tpl`:

- fields with legacy names `login`, `password`, `password2`, `firstName`, `lastName`,
  `email`; maxlengths from `input_dimensions.conf` (`LOGIN_MAXLEN`, `PASSWD_SIZE`,
  `NAMES_SIZE`, `EMAIL_MAXLEN` — `LOGIN_*` live outside sections, always loaded);
- `$gui->message` rendered in an alert box so server-side validation errors show;
- `external_password_mgmt` branch: hides both password fields and shows the
  "Password management is external" notice;
- localized labels via `lang_get` (same keys as legacy), "Back to login" link;
- posts to `firstLogin.php`, so failed signups re-render this same template.

Alternatives rejected:

- *TLSmarty fallback to tl-classic when a template is missing* — broad blast radius across
  every screen, hides future porting gaps, not minimal.
- *Pointing firstLogin.php at the marcobiedermann model unconditionally* — leaves the
  default URL rendering a non-Dashio page; inconsistent theme.

Implementation note: deliberately used `{#PASSWD_SIZE#}` instead of the model template's
never-assigned `{$gui->pwdInputMaxLength}` (that unguarded reference is pre-existing E_WARNING
noise on the `viewer=new` path — documented separately in issue #656).

## Verification

| Check | Result |
|---|---|
| `GET /firstLogin.php` | 200, all 6 inputs present (was 500) |
| `GET /firstLogin.php?viewer=new` | still 200 |
| POST mismatched passwords | 200 + error box, values preserved (was 500) |
| POST valid signup (browser) | redirect `login.php?note=first`, user row created |
| Back to login link | navigates to `/login.php` |
| Event Viewer | zero new events caused by the new template |

Regression suite: `tmp/TLU_Test_Cases.md` → *"Suite 654 — 8/8 PASS"*.

