# Issue 481 — PHP 8.4+ `E_DEPRECATED` "Implicitly marking parameter $domain as nullable" in symfony/translation on every page load

**Issue:** [#481](https://github.com/sebiboga/testlink-upgraded/issues/481)
**Branch:** `fix/issue-481`
**Status:** FIXED & VERIFIED (2026-08-24)

## Symptom

On PHP ≥ 8.4 every page load prints:

```
Deprecated: Symfony\Component\Translation\t(): Implicitly marking parameter $domain as nullable is deprecated, the explicit nullable type must be used instead in vendor/symfony/translation/Resources/functions.php on line 18
```

The reporter's quickfix (`string $domain = null` → `?string $domain = null`) worked
but was expected to be reverted by any later `composer install`.

## Root cause chain

1. `vendor/symfony/translation/Resources/functions.php:18` — helper `t()` declared
   `function t(string $message, array $parameters = [], string $domain = null)`.
   A non-nullable type with an implicit-null default is *implicitly nullable*;
   PHP ≥ 8.4 emits the E_DEPRECATED above when compiling the file.
2. `vendor/composer/autoload_files.php:15` (+ `autoload_static.php:16`) — Composer's
   *files* autoloader includes that functions file unconditionally whenever
   `vendor/autoload.php` is required. Every entry point (`index.php`, all
   `lib/**.php`, XML-RPC/REST APIs, CLI) requires autoload first → the warning
   prints on **every** request, not only when translation is used.
3. Installed `symfony/translation` is **v6.4.0**, predating Symfony's Nov-2024
   explicit-nullable backports (~v6.4.8+). It is a transitive dependency — no
   direct constraint in `composer.json`.

### Why this fix approach

| Option | Verdict |
|--------|---------|
| Full `composer update` of symfony/* | rejected — needs network, risks unrelated dependency drift, not minimal |
| `cweagans/composer-patches` plugin | rejected — new dependency + network for a one-line hunk |
| Suppress deprecations via `error_reporting` | rejected — hides real problems |
| **Direct committed-vendor patch + composer guard hook** | **chosen** — vendor/ IS committed to this repo (fresh clones get it), and the guard keeps it alive across installs |

## Fix

1. **Minimal patch** — `vendor/symfony/translation/Resources/functions.php:18`:
   `string $domain = null` → `?string $domain = null`.
2. **Composer-overwrite guard** — new idempotent script
   `tools/patch_vendor_php84.php`, wired into `composer.json`
   `scripts.post-install-cmd` + `post-update-cmd`. Re-applies the patch after any
   `composer install/update`; fail-safe: missing package → skip exit 0; upstream
   signature changed → skip exit 0; already patched → no-op exit 0.

## Verification (reproduction & regression matrix)

Because the deprecation fires only on PHP ≥ 8.4 and the dev machine runs 8.3.33,
reproduction used a static scanner (`tmp/repro-issue-481.php`) that walks tokens
and applies exactly the engine's condition, emitting the byte-identical message:

```
$ php tmp/repro-issue-481.php vendor/symfony/translation/Resources/functions.php
Deprecated: Implicitly marking parameter $domain as nullable is deprecated, the explicit nullable type must be used instead in vendor/symfony/translation/Resources/functions.php on line 18
```

Post-fix matrix (suite 481 in `tmp/TLU_Test_Cases.md`, 6/6 PASS):

| # | Case | Result |
|---|------|--------|
| R1 | scanner on patched file | 0 findings, exit 0 |
| R2 | php -l | clean |
| R3 | behavioral: autoload + call `t()` | TranslatableMessage returned, domain kept |
| R4 | overwrite simulation: revert signature → guard re-patch → idempotent second run | PASS |
| R5 | app boot: HTTP 200 /login.php + browser login admin/admin through the index shell (navBar / asideMenu / mainframe) | all render, zero Deprecated text |
| R6 | Event Viewer delta | only audit row log_level=16 (login_succeeded); no new Error/Warning |

## Blast radius note (out of scope, documented)

Scanner measured **51 further implicit-nullables across symfony translation +
translation-contracts** (103 files), e.g. `TranslatorBagInterface.php:28`. Those
surface lazily (only when their classes load) and would be fixed wholesale by a
future dependency upgrade to ~v6.4.8+. Other vendors (ldaprecord, …) carry more.
This issue's scope was the every-page-load warning; that is fixed and verified.

## Commits

- `d0b5d13ae` — fix(vendor): symfony/translation t() implicit nullable -> explicit ?string (PHP 8.4 deprecation)
- `1a4552569` — fix(tools): composer post-install/update guard re-applies PHP 8.4 symfony/translation patch
