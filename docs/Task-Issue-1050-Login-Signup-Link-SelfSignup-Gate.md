# Task — Issue #1050: honor `user_self_signup` for the sign-up link on the modern login screen

The modern Dashio login screen (`gui/templates/auth/login.html`) received the
`user_self_signup` setting from its own BFF (`api/auth/index.php:142,175` →
`config.selfSignup`) and then **threw it away**: `#tl_sign_up` was rendered
unconditionally and the registration row was force-shown with

```js
// The registration footer (with the Sign Up link) is always shown so the
// path to registration stays visible regardless of the self-signup config.
$('.registration, #registrationRow').show();
```

With `user_self_signup = FALSE` the login card therefore advertised a
registration entry point that leads to a dead end: following it opens
`/gui/templates/auth/firstLogin.html`, which renders the refusal
*"New user — self-registration is disabled on this site. Please contact your site
administrator."* (i18n key `auth.signUpDisabled`) Legacy never offered that entry point.

## Legacy reference (the behavior that was dropped)

- `login.php:234` — `$gui->user_self_signup = config_get('user_self_signup');`
- `config.inc.php:2125` — `$g_tpl['login'] = 'login/login-model-marcobiedermann.tpl';`
  (this is the login template that is actually selected)
- `gui/templates/dashio/login/login-model-marcobiedermann.tpl:76-79`

```smarty
{if $gui->user_self_signup}
  <a href="firstLogin.php?viewer=new" id="tl_sign_up">{$labels.new_user_q}</a> &nbsp; &nbsp;
{/if}

{* the configured authentication method don't allow users to reset his/her password *}
{if $gui->external_password_mgmt eq 0 && $tlCfg->demoMode eq 0}
  <a href="lostPassword.php?viewer=new" id="tl_lost_password">{$labels.lost_password_q}</a>
{/if}
```

Two independent gates in the template that is in use:

| Element | Legacy gate | Modern before this task |
|---|---|---|
| `#tl_sign_up` | `user_self_signup` (line 76) | **always rendered** (gap) |
| `#tl_lost_password` | `external_password_mgmt eq 0 && demoMode eq 0` (line 82) | already correct |
| separator between them | implicit (`&nbsp;` inside each link, never a dangling `\|`) | shown whenever the row was shown |
| registration row | rendered, but empty when both links are off | always force-shown |

> Note on the second legacy template: `login-dashio.tpl:85-98` nests the
> lost-password link **inside** the `user_self_signup` block. It is *not* the
> configured template, and there the lost-password link is deliberately
> independent. The port therefore implements the configured template's
> semantics — gating lost password on self-signup too would have created a new
> gap.

## The implementation

Single file, front-end only — the flag is already delivered by the BFF and no
new user-facing string was introduced, so **no i18n bundle changed**.

`gui/templates/auth/login.html`, inside the `GET /api/auth/config` callback:

```js
// Sign-up link: rendered ONLY when self-registration is enabled, exactly as
// legacy gated it (login-model-marcobiedermann.tpl:76 …). Refs #1050.
var showSignUp = !!c.selfSignup;
if (showSignUp) {
  $('#tl_sign_up').attr('href', '/gui/templates/auth/firstLogin.html'); // #1338
} else {
  $('#tl_sign_up').hide();
}

// Lost-password link: independent of self-signup (marcobiedermann.tpl:82)
var showLost = (!c.externalPasswordMgmt && !c.demoMode);
if (showLost) { $('#tl_lost_password').attr('href', '/gui/templates/auth/lostPassword.html'); }
else { $('#tl_lost_password').hide(); }

// Separator only when BOTH links are on screen.
if (showSignUp && showLost) { $('#lostSep').show(); } else { $('#lostSep').hide(); }

// The row ships hidden; reveal it only when there is something to show.
if (showSignUp || showLost) { $('.registration, #registrationRow').show(); }
```

## Visibility matrix (measured in Chrome against the running app)

| # | `selfSignup` | `demoMode` | `externalPasswordMgmt` | `#tl_sign_up` | `#tl_lost_password` | `#lostSep` | `#registrationRow` |
|---|---|---|---|---|---|---|---|
| A | false | false | false | hidden | visible | hidden | visible |
| B | true | false | false | visible | visible | visible | visible |
| C | true | true | false | visible | hidden | hidden | visible |
| D | false | true | false | hidden | hidden | hidden | hidden |
| E | false | false | true | hidden | hidden | hidden | hidden |

Row B (the shipped default) is unchanged from before the fix, including the
absolute href required by #1338. Rows A/E reproduce the dead-end screenshot:
with self-registration disabled the footer shows only `Lost password?`.

## Authorization is unaffected

The change is presentation-only. The registration POST was — and still is —
refused server-side when self-registration is off:

- modern: `api/auth/index.php:368-369` → `{"success":false,"reason":"error_self_signup_disabled"}`
- legacy: `firstLogin.php:23-26` → `lang_get('error_self_signup_disabled')`

## Verification

- `node --check` on the extracted inline `<script>` block → OK.
- All 5 matrix rows measured in Chrome (`offsetParent !== null` per element).
- Click-through with `user_self_signup = TRUE` still reaches the full sign-up
  form (User ID / First Name / Last Name / Email / Password / Repeat password).
- Regression: `login.php?note=expired` still renders its info note and the
  registration row; `admin/admin` logs in and lands on
  `/index.php?caller=login&viewer=web`.
- Event Viewer / `events` table: only the audit row of the successful login
  (`log_level = 16`, `activity = LOGIN`) — **0 Error/Warning rows**; browser
  console clean.
- Test suite: `tmp/TLU_Test_Cases.md` → `## Task — Issue #1050: …` (PASS).
