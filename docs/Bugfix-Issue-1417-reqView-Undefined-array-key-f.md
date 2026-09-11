# Issue 1417 — reqView.php: 2x E_WARNING "Undefined array key f" on every requirement view

**Issue:** [#1417](https://github.com/sebiboga/testlink-upgraded/issues/1417)
**Branch:** `fix/issue-1417-undefined-array-key-f`
**Status:** VERIFIED-FIXED (2026-09-11)

## Symptom

Opening any requirement whose stored `req_versions.type` is not one of the seven
standard codes logged **two** `E_WARNING Undefined array key "f"` rows in the Event
Viewer (source `gui/templates_c/…/file.reqViewVersionsViewer.tpl.php` lines 299 and
302), and the requirement's "Type" cell rendered blank even though a non-empty type
was stored. Every page load of `lib/requirements/reqView.php` for such a requirement
produced the two events, cluttering the Event Viewer and hiding genuine errors.

Screenshots:
- before fix `docs/screenshots/issue-1417-reqview-type-f-before.png` (blank "Type :")
- after fix `docs/screenshots/issue-1417-reqview-type-f-after.png` ("Type : f")

## Repro steps

1. Log in as admin.
2. Seed a requirement with a non-standard type (fixture `tmp/fixtures_1417.php` writes
   `type='f'` into `req_versions` for REQ-F-1417, requirement_id 4 on tproject 1).
3. Open `lib/requirements/reqView.php?requirement_id=4&tproject_id=1&showReqSpecTitle=1`.
4. Event Viewer / `SELECT * FROM events`:
   two `E_WARNING` rows `Undefined array key "f"` at source lines 179/181 of
   `gui/templates/dashio/requirements/reqViewVersionsViewer.tpl` (compiled lines
   299/302), logged via `watchPHPErrors` (`lib/functions/logger.class.php:1407-1483`).

## Root cause

`req_versions.type` is a `CHAR(1)` column whose value is written verbatim by
`lib/functions/requirement_mgr.class.php:2293-2297` with **no validation** against the
allowed type set. The allowed set lives only in `$tlCfg->req_cfg->type_labels`
(`cfg/const.inc.php:676-683`), which defines keys `'1'`..`'7'`. `lib/requirements/
reqView.php:212` and `lib/requirements/reqCommands.class.php:30-41` build the view's
`reqTypeDomain` and `attrCfg['expected_coverage']` maps exclusively from those keys, so
any other stored code leaves both maps without that key. The Smarty viewer templates
then read them unguarded (source `reqViewVersionsViewer.tpl:179` and `:181`), PHP 8 emits
the `E_WARNING`, and TestLink's error watchdog persists it into the `events` table.

## Fix

Guard both reads with `isset()` and fall back to the raw stored code for display,
exactly following the existing repo pattern at
`gui/templates/dashio/requirements/reqSpecView.tpl:122-128`, in all four viewer templates
(dashio + tl-classic, versions + revision):

```smarty
{* Type row — fall back to the raw stored code for unknown types *}
{assign var="req_type" value=$args_req.type}
…
{if isset($args_gui->reqTypeDomain.$req_type)}
  {$args_gui->reqTypeDomain.$req_type}
{else}
  {$args_req.type}
{/if}
```

- Type row: previously `{$args_gui->reqTypeDomain[$args_req.type]}`.
- Expected-coverage gate: previously `… && $args_gui->attrCfg.expected_coverage[$args_req.type]`,
  now `… && isset($args_gui->attrCfg.expected_coverage.$req_type)
  && $args_gui->attrCfg.expected_coverage[$req_type]` (undefined type ⇒ row hidden, no warning).

Method chosen on purpose: a one-line guard at each read site is minimal, type-agnostic and
works for both "buggy" data (`'f'`) and future legitimate codes, without touching config,
DB or migration — the smallest correct fix that also restores visibility of the stored
value (it was silently hidden before). Alternative rejected: widening `type_labels` was
data-deciding, not a template fix, and bypasses the real question of what the unknown type
means.

## Verification

Regression suite `Regression — Issue #1417` in `tmp/TLU_Test_Cases.md` (6 cases):
- 1417.1 pre-fix control render → bug exactly reproduced (blank Type, +2 events);
- 1417.2 post-fix → "Type : f", **0** new events (`log_level <= 8`);
- 1417.3 standard type `'2'` regression → "Type : Feature" + "Number of test cases
  needed : 5", 0 events;
- 1417.4 tl-classic twins compile with identical guards (dashio is the only runtime
  theme in this build — `lib/functions/tlsmarty.inc.php:102`);
- 1417.5 revision-viewer compiled guards identical (live popup blocked by #1427);
- 1417.6 hygiene → 0 `events` rows matching `Undefined array key` /
  `reqViewVersionsViewer.tpl.php` after all post-fix renders.
Result: 6/6 PASS.

## Files changed

- `gui/templates/dashio/requirements/reqViewVersionsViewer.tpl` (+11/-2)
- `gui/templates/dashio/requirements/reqViewRevisionViewer.tpl` (+11/-2)
- `gui/templates/tl-classic/requirements/reqViewVersionsViewer.tpl` (+11/-2)
- `gui/templates/tl-classic/requirements/reqViewRevisionViewer.tpl` (+11/-2)

Pre-existing bug discovered while testing (out of scope, filed): `reqViewRevision.php`
returns HTTP 500 in dashio because `gui/templates/dashio/requirements/
displayReqCoverageRO.inc.tpl` does not exist (tl-classic has it) → **#1427**.