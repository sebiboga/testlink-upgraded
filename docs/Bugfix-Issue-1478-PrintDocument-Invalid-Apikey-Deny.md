# Bugfix — Issue #1478: printDocument.php — bogus 64-char apikey renders doc + 3x basehref E_WARNING

## Problem

A **bogus/unknown** 64-char object api key on `lib/results/printDocument.php`
was silently accepted: the document rendered (HTTP 200) with broken **relative**
CSS/logo URLs and emitted the same 3 `E_WARNING Undefined array key "basehref"`
events as #1416 (`printDocument.php:175`, `printDocument.php:254`,
`print.inc.php:700`), each polluting the `events` table (`log_level=2`).
`setUpEnvForAnonymousAccess()` returns `false` for an unknown key, but
`printDocument.php::init_args()` ignored the return and rendered anyway.

Reproduced on the CI box (fresh import, PHP 8.x, MySQL) with
`tmp/fixtures_1415.php` (tproject=1, tplan=12, builds 1/2, tc3/6/9):

```
curl "http://localhost:8082/lib/results/printDocument.php?apikey=0000…0⁶⁴&tproject_id=1&tplan_id=12&type=testreport_onbuild&level=testproject&build_id=2&format=0&allOptionsOn=1"
→ HTTP=200 size=7932 + 3 new log_level=2 rows (events ids 5/6/7); baseline 0
```

## Root Cause

Chain (each hop backed by `file:line`, default branch `ce26f778a`):

1. `lib/results/printDocument.php:334` — `init_args()`: 64-char apikey enters
   the anonymous branch and calls `setUpEnvForAnonymousAccess($db,$apikey,$cerbero)`
   as a **bare statement**; the returned boolean is discarded.
2. `lib/functions/common.php:1330-1353` — the paranoic candidate list
   `['testplan','testproject']` (the #1416 shape) runs `getEntityByAPIKey()`
   (`common.php:1395-1435`) against `testplans.api_key` + `testprojects.api_key`;
   a key owned by neither entity yields `$item=NULL`.
3. `common.php:1355-1379` — the anonymous-session block (`userID=-1`, locale,
   `setPaths()` → `$_SESSION['basehref']`) only runs inside
   `if(!is_null($item))`; with `$item=NULL` it is skipped and the function
   returns **`false`**.
4. Back in `init_args()` the `false` is ignored → rendering proceeds with an
   uninitialized session → `basehref` is never set and the three legacy readers
   fire `E_WARNING Undefined array key "basehref"`
   (`printDocument.php:175` renderHTMLHeader, `print.inc.php:700` logo,
   `printDocument.php:254` env basehref). Each E_WARNING is logged as an
   `events` row → Event Viewer pollution. Document returns HTTP 200 with
   relative `href="gui/themes/default/css/tl_documents.css"`.

**Why it breaks now:** not a fresh regression — the unchecked call predates
the modern work; #1415/#1416 merely exercised the plan-report link shapes.
#1416 deliberately did not touch the caller (its own doc: "the deny path at
`common.php:1355-1379` stays unreachable for direct printDocument access").

**Blast radius:** `setUpEnvForAnonymousAccess()` is the shared anonymous
gateway used by 16+ screens, each with its own deny semantics
(`lnl.php:278` flips `light=red`; `displayMgr.php:65` throws later on
post-conditions; `metricsDashboard.php:388` re-verifies via `getByAPIKey`).
Fixing inside the shared function would change all callers; the correct seam is
the per-caller check. `printDocument.php` is the only caller that follows the
gateway call with a *raw document render* — so this fix is a single-caller
change with zero blast radius.

## Fix

Committed on branch `fix/issue-1478` (`a7c80b2d9`):

```php
} else {
  $args->addOpAccess = false;
  $cerbero->method = null;
  // Refs #1478: an object api key that matches NO testproject/testplan
  // entity must not render the document. setUpEnvForAnonymousAccess()
  // returns false when the key is unknown, leaving the anonymous session
  // (basehref included) uninitialized; rendering anyway produced a broken
  // relative-URL document plus 3 basehref E_WARNING events per hit.
  $status_ok = setUpEnvForAnonymousAccess($dbHandler,$args->apikey,$cerbero);
  if (!$status_ok) {
    renderGracefulExit(lang_get('error_print_doc_invalid_apikey'));
    exit;
  }
}
```

Reuses the existing `renderGracefulExit()` page already shipped in this exact
controller for missing id (Refs #683) and missing testplan (Refs #573).

New legacy i18n key `error_print_doc_invalid_apikey` added to
`locale/en_US/strings.txt` (`:638`) and `locale/en_GB/strings.txt` (`:645`) —
the only two bundles carrying the `error_print_doc_*` family; `lang_get()`
falls back to en_GB (`lib/functions/lang_api.php:73-80`) for every other
locale, so all locales resolve the message.

**Why this method:** the gateway return is the authoritative "does this key own
an entity?" signal; checking it at the one caller that renders raw output gives
the minimal, complete deny. Matches the modern BFF precedent
(`api/reportsprint` already answers `HTTP 401 {"status":"error","message":"Unknown api key"}`
for a bogus key).

**Rejected alternatives:** (a) guarding each `$_SESSION['basehref']` reader
with `isset()?:` — hides the symptom but still renders a half-initialized
anonymous session (no userID/locale) and keeps the false accept; (b) turning
the shared `setUpEnvForAnonymousAccess()` failure into an exception — would
change 16 callers' error semantics; (c) doing nothing but the #1416 fallback —
the bogus key still misses every lookup so #1416 cannot help.

## Files Changed

- `lib/results/printDocument.php` — anonymous-branch deny: capture
  `setUpEnvForAnonymousAccess()` return; on `false` graceful exit + `exit`.
- `locale/en_US/strings.txt`, `locale/en_GB/strings.txt` — new key
  `error_print_doc_invalid_apikey`.
- `tmp/TLU_Test_Cases.md` — regression suite "Regression — Issue #1478", 11/11 PASS.
- `docs/Bugfix-Issue-1478-PrintDocument-Invalid-Apikey-Deny.md` — this file.
- `tmp/wiki-repo/Bugfix-Issue-1478-PrintDocument-Invalid-Apikey-Deny.md` — wiki mirror.
- `docs/screenshots/issue-1478-anonymous-invalid-apikey-denied.png`,
  `docs/screenshots/issue-1478-anonymous-valid-apikey-document.png` — deny state
  and valid anonymous render after fix.
- `CHANGELOG` — one-line entry under 2.0.1 key bugfix section.

## Verification

All checks on branch `fix/issue-1478`, PHP 8.x, MySQL fresh-import schema,
fixture `tmp/fixtures_1415.php`:

- Pre-fix control: repro URL → HTTP 200 size=7932 + 3 new `log_level=2`
  basehref E_WARNING events (ids 5/6/7) vs baseline 0.
- Post-fix R1 (bogus key, exact repro URL): HTTP 200 size=422 = graceful error
  page ("Document generation error" + new apikey message), **0** new events.
- Post-fix R2 (valid `testprojects.api_key` + tplan_id): HTTP 200 size=7976
  full document, absolute asset URLs, 0 new events — no #1416 regression.
- Post-fix R3 (valid `testplans.api_key`): HTTP 200 size=7976, 0 new events.
- Post-fix R4 (session render, admin browser session): full Test Plan Design
  Report (title page, TOC, platform/suite tree, 3 TCs incl. Passed exec tc3),
  absolute logo URL, 0 new events.
- Post-fix R5 (`lnl.php` valid key): HTTP 302 → `reportPrint.html?…` (green
  public link unchanged).
- Post-fix R6 (`lnl.php` bogus key): HTTP 200, no redirect, 0 new events.
- Post-fix R7 (modern BFF `api/reportsprint` bogus key): HTTP 401
  `{"status":"error","message":"Unknown api key"}` — already denied; 0 events.
- Browser console on the deny page and both anonymous/session documents:
  `<no console messages found>` — clean.
- Event Viewer: `SELECT COUNT(*) FROM events WHERE log_level=2` final = 3
  (the pre-fix reproduction rows); zero growth across the whole suite.
- `php -l lib/results/printDocument.php` clean.

Regression suite: `tmp/TLU_Test_Cases.md` — "Regression — Issue #1478", 11/11 PASS.

Address: `Fixes #1478`.
