# Issue 653 — `LOCALIZE: error_self_signup_disabled` placeholder rendered on the signup-disabled page (key missing from ALL locale bundles)

**Issue:** [#653](https://github.com/sebiboga/testlink-upgraded/issues/653)
**Branch:** `fix/issue-653` · **Commits:** `2c4d89119` (fix), `4ebd4d469` (regression suite)
**Status:** FIXED & VERIFIED (2026-08-24)

## Symptom

With self-registration disabled (`$tlCfg->user_self_signup = FALSE;`), visiting
`firstLogin.php` renders the literal placeholder instead of a human-readable message:

```
<h1 class="title">TestLink ::: Fatal Error</h1>
LOCALIZE: error_self_signup_disabled
<p><a href=".../login.php">Back to login</a>
```

Measured during reproduction: `curl http://localhost:8082/firstLogin.php` returned the
verbatim `LOCALIZE: error_self_signup_disabled` line; the key existed in **0 of 19**
PHP locale bundles and **0 of 10** client-side i18n JSON bundles.

## Root cause chain

1. `firstLogin.php:24-30` — when `config_get('user_self_signup')` is FALSE, the page is
   rendered through `workAreaSimple.tpl` with `$content = lang_get('error_self_signup_disabled')`.
2. `lib/functions/lang_api.php:118` — lookup misses in the active locale AND in the en_GB
   fallback → `lang_get()` returns `TL_LOCALIZE_TAG . $p_string`, i.e. `"LOCALIZE: <key>"`.
3. `gui/templates/dashio/workAreaSimple.tpl` prints `$content` verbatim.

**Why it breaks:** the key was never defined anywhere — not even upstream TL 1.9.20
(`grep -rln error_self_signup_disabled locale/` → empty on a pristine tree). The state only
manifests when admins explicitly disable self-signup, so it went unnoticed until the #434
verification captured the rendered page. The sibling key used by the same template,
`$TLS_link_back_to_login`, IS present (`locale/en_GB/strings.txt:1361`) — exactly one key was missing.

## Blast radius

- Single call site: `grep -rn "error_self_signup_disabled" --include="*.php" .` → only `firstLogin.php:26`.
- Every locale (19) × one screen state (`firstLogin.php` with `user_self_signup=FALSE`).
- No events written for this path: the L18N-miss warning in `lang_api.php` is gated by
  `isset($_SESSION)` and firstLogin.php runs session-less here.

## Approach — add the missing key to every bundle

Chosen fix: append `$TLS_error_self_signup_disabled = '<per-language sentence>';` to **all 19**
`locale/*/strings.txt`. Alternatives rejected:

- *Changing the code to fall back to another existing string* — hides the gap, still no proper copy.
- *Adding only to en_GB* — violates the repo's i18n policy (all bundles, no hardcoded English leaks).

Implementation notes:

- The `.txt` string files are loaded via PHP `require` (`lang_api.php:235`), so on files ending with
  a closing `?>` (10 of 19) the new line was inserted **before** the tag, never after it.
- cs_CZ contains a pre-existing invalid UTF-8 byte at offset ~1652; its bytes were preserved
  byte-for-byte via a `surrogateescape` round-trip — untouched by this fix.
- Wording authored fresh per language ("New user self-registration is disabled on this site.
  Please contact your site administrator." + translations for de/es/fr/it/pt/nl/pl/ru/zh/ja/ko/fi/id/cs/ro).
- Client-side JSON bundles were left alone: this legacy Smarty path does not consume them.

## Verification

| Check | Result |
|---|---|
| Repro before fix | literal `LOCALIZE: error_self_signup_disabled` rendered |
| After fix, same request | "New user self-registration is disabled on this site. Please contact your site administrator." — zero `LOCALIZE:` occurrences |
| Key coverage | `grep -rln … locale/*/strings.txt \| wc -l` → 19/19 |
| Syntax gate | `php -l` on all 19 files → PASS |
| Event Viewer | 0 new Error/Warning rows after full suite |

Regression suite: Suite 653 in `tmp/TLU_Test_Cases.md` — 7/7 PASS.

## Related defect found while testing (not fixed here)

With signup ENABLED (default config) `firstLogin.php` dies with
`Smarty: Unable to load template 'file:login/firstLogin.tpl'` — the dashio theme never got that
template. Verified identical on a pristine worktree of HEAD `020283511`, therefore unrelated to
this fix. Tracked as [#654](https://github.com/sebiboga/testlink-upgraded/issues/654).

## How to re-test in one command

```bash
printf '<?php\n$tlCfg->user_self_signup = FALSE;\n' > custom_config.inc.php \
  && curl -s http://localhost:8082/firstLogin.php | grep -c LOCALIZE; \
  rm custom_config.inc.php   # expect: 0
```
