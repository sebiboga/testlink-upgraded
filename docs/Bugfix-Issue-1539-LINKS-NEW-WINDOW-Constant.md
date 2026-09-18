# Issue 1539 — string CF values containing URLs throw fatal: Undefined constant LINKS_NEW_WINDOW

**Issue:** [#1539](https://github.com/sebiboga/testlink-upgraded/issues/1539)
**Branch:** `fix/issue-1539`
**Status:** VERIFIED-FIXED

## Symptom

Any custom field value containing a URL (`https://...`, `http://...`) threw
`PHP Fatal error: Undefined constant LINKS_NEW_WINDOW` when rendered through
`cfield_mgr::string_custom_field_value()` → `string_display_links()` →
`string_insert_hrefs()` at `lib/functions/string_api.php:322`. Modernized
screens swallowed the fatal in a try/catch and returned `custom_fields: []`;
legacy Smarty screens exposed the HTTP 500 / fatal directly.

## Repro steps

1. Create a string custom field (type 0) linked to the `testsuite` node type,
   enabled on a test project.
2. Assign a URL value (e.g. `https://example.com/docs`) as the design-time value
   on a test suite.
3. Open the suite viewer (`GET /api/suiteview/index.php?action=info&id=<suite>`)
   or any screen calling `string_display_links()` with a URL-valued string.
4. Pre-fix: the URL-replacement closure in `string_insert_hrefs()` evaluates
   `config_get('html_make_links') == LINKS_NEW_WINDOW` and PHP fatals on the
   undefined constant.

**Measured pre-fix evidence** (direct harness on the exact code path, constant
simulated as undefined — the `cfg/const.inc.php` state before commit `5175dd161`):

```
PHP Fatal error:  Uncaught Error: Undefined constant "LINKS_NEW_WINDOW" in lib/functions/string_api.php:322
Stack trace:
#0 [internal function]: {closure}()
#1 lib/functions/string_api.php(318): preg_replace_callback()
#2 (harness): string_insert_hrefs()
```

## Root cause

1. `cfg/const.inc.php` never defined `LINKS_NEW_WINDOW` / `LINKS_SAME_WINDOW`
   (MantisBT constants). Verified with `git show 5175dd161^:cfg/const.inc.php`.
2. Two backports brought the constant reference in. Commit `6969837d9` (2016,
   "PHP7 compatibility ... piece of code from mantisbt") original Mantis-derived
   URL-to-link rewrite used `create_function()` and **no** constant. Commit
   `bbe0cfee2` (2022-05-14, "PHP 8 - create_function() removed") replaced it
   with the MantisBT 2.25.2 closure, whose URL replacement branch references
   `LINKS_NEW_WINDOW` at `lib/functions/string_api.php:322` — the constant was
   never imported, so the fatal has been latent since `bbe0cfee2`. Constants
   are resolved at runtime inside the closure, so the fatal only fires when the
   URL regex matches — i.e. only for URL-valued string CFs (plain text never
   trips it).
3. Call path: `cfield_mgr::string_custom_field_value()` →
   `case 'string': return string_display_links($cfValue)`
   (`lib/functions/cfield_mgr.class.php:1583`) → `string_display_links()` →
   `string_insert_hrefs()` (`string_api.php:124`).
4. `config.inc.php:2033` sets `$tlCfg->html_make_links = ENABLED` (=1). Under
   Mantis semantics `ENABLED == LINKS_NEW_WINDOW` (=1) → the intended behavior
   is new-window (`target="_blank"`) links.

Not a regression of the 2.0.1 work — the latent fatal dates to the 2022 PHP 8
`create_function()` replacement (`bbe0cfee2`); it first became user-visible when
the modernized suite viewer (`api/suiteview action=info`, commit `5175dd161`)
started rendering suite design-time string CF values end-to-end.

**Blast radius** — every call site re-using `string_display_links()` /
`string_custom_field_value()` with URL values:
`api/suiteview/index.php:162` (suiteDesignCfields),
`api/execsetresults/index.php` (testplan/build/tcversion/exec CFs),
`api/reports/index.php:2329,2521,3146`; legacy Smarty
`testsuite.class.php:1467`, `testcase.class.php:5365`,
`requirement_mgr.class.php:1901`, `requirement_spec_mgr.class.php:1456`,
`testplan.class.php:2739`, `build.class.php`. (The REST/XML-RPC serializers and
the raw-value `get_linked_cfields_at_design` consumers — e.g.
`api/requirements/index.php:1081,1161`, `tlRestApi.class.php:300`,
`RestApi.class.php:348` — emit the raw DB value and do NOT hit the href-rendering
fatal.)

## Fix

Define the two missing MantisBT constants in `cfg/const.inc.php` (already landed
on the default branch in commit `5175dd161`, which tagged `Refs #1539`):

```diff
 define('ACTIVE',  1 );
+define('LINKS_SAME_WINDOW', 0);
+define('LINKS_NEW_WINDOW', 1);
 define('INACTIVE',  0 );
```

```php
define('LINKS_SAME_WINDOW', 0);
define('LINKS_NEW_WINDOW', 1);
```

Because `html_make_links` is already `ENABLED` (=1), the comparison
`config_get('html_make_links') == LINKS_NEW_WINDOW` now yields true → links
render with `target="_blank"`, matching the legacy Mantis-derived 1.9.20
behavior the code was written for (`string_api.php:322-326`).

**Alternatives considered and rejected:**
- Changing `string_api.php:322` to compare against the local `ENABLED`
  constant instead — duplicates Mantis semantics, diverges from the backport
  source, and would mask the fact that the constant was never imported;
  defining the constants is the minimal, source-faithful fix (and `LINKS_*`
  are part of the Mantis public API the file already relies on).
- Guarding the closure with `defined('LINKS_NEW_WINDOW')` — silently changes
  behavior instead of restoring intended behavior.
- A config-file runtime `define()` next to `html_make_links` — constants belong
  in `cfg/const.inc.php` with the rest of the `const.inc.php` magic-number block.

## Regression matrix (all executed, live)

Fixtures: project `Issue1539Project` (node 1, prefix `I1539`), suite
`SuiteWithURL` (node 2), string CF `URL_CF` (type 0, linked to node_type 2
testsuite, enabled on project 1), design value `https://example.com/docs`.

| # | Case | Result |
|---|---|---|
| 1 | `php -l cfg/const.inc.php` syntax gate | PASS — clean |
| 2 | Direct harness, constant UNDEFINED (pre-fix simulation) | PASS — fatal reproduced verbatim (exit 255) |
| 3 | Direct harness, constant DEFINED (post-fix) — `string_insert_hrefs('Read the docs at https://example.com/docs and mail a@b.com')` | PASS — `<a href="https://example.com/docs" target="_blank">…</a>` + `mailto:` link, exit 0 |
| 4 | URL-format matrix (7 cases: https, http+query+anchor, trailing dot, two URLs, mailto-only, plain text, empty) | PASS — 7/7, no fatal, only URL/email spans linked; `rtrim('.')` legacy behavior preserved |
| 5 | `GET /api/suiteview/index.php?action=info&id=2` (BFF, URL CF value) | PASS — HTTP 200; `value:"<a href=\"https://example.com/docs\" target=\"_blank\">https://example.com/docs</a>"` (was swallowed-fatal `[]`) |
| 6 | Browser `suiteView.html?id=2&tproject_id=1` | PASS — "Custom fields" card shows `URL CF` → clickable `target="_blank"` link (anchor attrs verified via DOM); no JS console errors |
| 7 | Event Viewer / `events` table after BFF + browser | PASS — no new Error/Warning (log_level ≥ 32); only INFO 16 audit logins |
| 8 | Plain-text string CF value on the same path | PASS — unchanged text, no link, no fatal |

## How the fix was verified in this run

The code fix was already merged to the default branch by the modernize workflow
(commit `5175dd161`, Refs #1363 / Refs #1539). This run confirmed with fresh DB
fixtures: the direct `string_insert_hrefs()` code path (pre-fix fatal / post-fix
link), the BFF endpoint that first surfaced the bug, and the rendered browser
screen — plus the Event Viewer cleanliness gate and a 7-case URL-format matrix.
No further code change was required. Regression suite: `tmp/TLU_Test_Cases.md`
("Regression — Issue #1539"). Screenshot:
`docs/screenshots/issue-1539-suiteview-url-cf-link.png` (wiki:
`issue-1539-suiteview-url-cf-link.png`).