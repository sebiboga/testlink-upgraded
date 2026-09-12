# Bugfix — Issue #1416: printDocument.php — 3x `Undefined array key basehref` on anonymous/apikey plan reports

## Problem

Anonymous direct access to `lib/results/printDocument.php` (64-char object api
key, no session) for a plan-scoped document emits **three
`E_WARNING "Undefined array key basehref"`** per render
(`printDocument.php:175`, `printDocument.php:254`, `print.inc.php:700`), each
logged as an `events` row (`log_level=2`) and polluting the Event Viewer used
by CI checks. Session-based access is unaffected.

Reproduced at HEAD (fresh-import CI box, PHP 8.x, MySQL):

```
E_WARNING\nUndefined array key "basehref" - in .../lib/results/printDocument.php - Line 175
E_WARNING\nUndefined array key "basehref" - in .../lib/functions/print.inc.php - Line 700
E_WARNING\nUndefined array key "basehref" - in .../lib/results/printDocument.php - Line 254
```

Verified 2026-09-12 on the `tmp/fixtures_1415.php` dataset: curl of
`printDocument.php?apikey=<testprojects.api_key>&tproject_id=1&tplan_id=12&type=testreport_onbuild&level=testproject&build_id=2&format=0&allOptionsOn=1`
created exactly 3 new `log_level=2` rows (ids 5/6/7; BEFORE=0). Doc still
returns HTTP 200 — the symptoms are session-state noise plus broken absolute
asset URLs (CSS/logo paths), not a crash.

## Root Cause

Chain (each hop backed by `file:line`, default branch 4dea71b1c):

1. `lib/results/printDocument.php:320-335` — `init_args()`: 64-char api key
   enters the anonymous branch → `setUpEnvForAnonymousAccess($db,$apikey,$cerbero)`
   with `$cerbero->args->tplan_id = $args->tplan_id` (from the URL) and default
   `envCheckMode` = `'paranoic'`.
2. `lib/functions/common.php:1335-1344` (setUpEnvForAnonymousAccess, paranoic) —
   `$tk[] = (intval($rightsCheck->args->tplan_id) != 0) ? 'testplan' : 'testproject';`
   → with `tplan_id` present the candidate list is ONLY `['testplan']`.
3. `common.php:1346-1353` — the loop checks `getEntityByAPIKey($db,$apikey,'testplan')`
   which queries `testplans.api_key` (`common.php:1408-1411`). A
   **testprojects.api_key** never appears there → NULL; the loop has no second
   candidate → `$item` stays NULL.
4. `common.php:1355-1379` — `if (!is_null($item))` is FALSE → the anonymous
   session block never runs: no `userID`, no locale, NO `$_SESSION['basehref']`;
   the function returns `false`.
5. printDocument.php renders anyway (anonymous object-key access requests no
   rights check, `$cerbero->method = null`) and hits the three readers:
   - `printDocument.php:175` → `renderHTMLHeader(...,$_SESSION['basehref'],...)`
   - `printDocument.php:254` → `$env->base_href = $_SESSION['basehref']`
   - `print.inc.php:700` → `$safePName = $_SESSION['basehref'] . TL_THEME_IMG_DIR ...`

The #1021 `setPaths()` basehref fallback (`common.php:1370-1373`) was in place
but **unreachable**: it only runs *inside* the skipped session block.

**Why it breaks now:** not a fresh regression from #1415 (reproduced identically
on the pre-#1415 file — A/B). The paranoic single-candidate lookup has existed
since legacy code; #1415 and #1416 merely exercised the same plan-report link
shape with a testproject api key.

**Blast radius:** `setUpEnvForAnonymousAccess()` is the shared anonymous gateway
for 16+ screens (`lib/results/{printDocument,resultsGeneral,resultsTCFlat,
overallPieChart,metricsDashboard,charts,topLevelSuitesBarChart,keywordBarChart,
resultsByTesterPerBuild,resultsByStatus,displayMgr,platformPieChart,
resultsTCAbsoluteLatest,neverRunByPP}.php`), `lib/functions/displayMgr.php` and
the `lnl.php` public-link redirector. Every plan-scoped call carries a
`tplan_id`, so a testproject-key plan report either bounced as a dead "red" link
via `lnl.php` or rendered with basehref events via direct printDocument access.
The printDocOptions-generated links (testplan key, `printDocOptions.php:50-54`)
never showed it, which is why normal UI navigations looked fine.

## Fix

Committed on branch `fix/issue-1416`:

```php
default:
  // Refs #1416: a 64-char object api key is bound to ONE entity
  // (testproject or testplan). The paranoic primary is chosen from the
  // request shape (plan report -> testplan), but the key may belong to
  // the other entity; always keep the counterpart as a fallback candidate
  // so a testprojects.api_key plan report still initializes the anonymous
  // session (basehref included) instead of failing to render.
  $primary = (intval($rightsCheck->args->tplan_id) != 0) ? 'testplan' : 'testproject';
  $tk = ('testplan' == $primary)
      ? array('testplan','testproject')
      : array('testproject','testplan');
break;
```

The paranoic priority is preserved (testplan first when the request is a plan
report, testproject otherwise) and the counterpart entity is always appended as
a fallback candidate — exactly what the `'hippie'` mode already does. Once the
session block runs, the #1021 `setPaths()` fallback sets
`$_SESSION['basehref'] = get_home_url(...)`, so all three readers get a real
value and the doc renders with correct absolute asset URLs.

**Why this method:** the anonymous gateway keyed its entity lookup on the
*request shape* instead of the *api key's owner*. Trying the counterpart keeps
the paranoic intent (testplan ownership preferred for plan reports) while making
a testproject-key report work — the documented public-link feature.

**Rejected alternatives:** (a) guarding each of the 5+ `$_SESSION['basehref']`
readers with `isset()?:` — hides the symptom but leaves the anonymous session
half-initialized (no userID/locale), and new readers would keep tripping;
(b) switching the whole function to `'hippie'` mode unconditionally — changes
lookup priority for every caller; the fallback-only change is strictly more
conservative.

## Files Changed

- `lib/functions/common.php` — `setUpEnvForAnonymousAccess()` paranoic branch
  now builds a 2-candidate entity list (primary + counterpart fallback).
- `tmp/TLU_Test_Cases.md` — regression suite "Regression — Issue #1416", 11/11 PASS.
- `docs/Bugfix-Issue-1416-PrintDocument-Anonymous-Basehref-EWarning.md` — this file.
- `tmp/wiki-repo/Bugfix-Issue-1416-PrintDocument-Anonymous-Basehref-EWarning.md` — wiki mirror.
- `docs/screenshots/issue-1416-anonymous-report-renders.png` — full-report screenshot
  (anonymous, testproject api key) after fix.

## Verification

All checks on branch `fix/issue-1416`, PHP 8.x, MySQL fresh-import schema:

- Pre-fix control: repro URL → HTTP 200 + 3 new `E_WARNING ... basehref` events
  (printDocument.php:175 / print.inc.php:700 / printDocument.php:254) vs
  BEFORE=0.
- Post-fix R1 (repro URL, testproject key + tplan_id): HTTP 200, output grows
  7932→7976 bytes because CSS/logo absolute URLs now resolve
  (`basehref='http://localhost:8082/'`), **0** new `log_level=2` events.
- Post-fix R2 (testplan key + tplan_id, printDocOptions-shaped): HTTP 200, 0 new events.
- Post-fix R3 (testproject key, no tplan_id): HTTP 200, 0 new events.
- Post-fix R4 (session render, admin): HTTP 200, 0 new events.
- Post-fix R5 (`lnl.php?apikey=<testproject key>&type=testreport_onbuild&entities=7&...`):
  HTTP 302 → Location `reportPrint.html?...` (public link GREEN; pre-fix dead red).
- Post-fix R6 (modern BFF `api/reportsprint/index.php?action=print&...&apikey=<testproject key>`):
  HTTP 200 `{"status":"ok",body_html:...}` full report, 0 new events.
- Post-fix R7 (browser, no session): `reportPrint.html?...` renders the complete
  doc (TOC, Project/Plan/Build headers, 3 TCs incl. Passed execution for TC3,
  absolute TestLink logo URL); console clean except the pre-existing 401 header
  userinfo call (unrelated to this bug).
- Event Viewer: `COUNT(*) WHERE log_level=2` unchanged across the whole suite
  (only the 3 pre-fix rows remain).
- `php -l lib/functions/common.php` clean.

Regression suite: `tmp/TLU_Test_Cases.md` — "Regression — Issue #1416", 13/13 PASS.

## Related Find

While stress-testing the fix, a **bogus** 64-char object api key on a plan URL
renders the bare document (HTTP 200) with the same 3 basehref events, because
`printDocument.php:334` calls `setUpEnvForAnonymousAccess()` but never checks
its return value — invalid keys miss every lookup both before and after this
fix, so the deny path at `common.php:1355-1379` stays unreachable for direct
`printDocument.php` access. Behavior verified identical pre-fix (A/B) — filed
separately as bug #1478.

Address: `Fixes #1416` (verified + pushed on `fix/issue-1416`). Not #1478.