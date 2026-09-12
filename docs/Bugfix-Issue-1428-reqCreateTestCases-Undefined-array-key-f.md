# Issue 1428 — reqCreateTestCases.tpl: E_WARNING "Undefined array key f" on requirement type-domain read

**Issue:** [#1428](https://github.com/sebiboga/testlink-upgraded/issues/1428)
**Branch:** `fix/issue-1428`
**Status:** VERIFIED-FIXED (2026-09-12)

## Symptom

The **Create Test Cases from Requirements** screen
(`lib/requirements/reqEdit.php?doAction=createTestCases&req_spec_id=<id>&tproject_id=1`,
rendered by `reqCreateTestCases.tpl`) read `{$gui->reqTypeDomain.$req_type|escape}`
unguarded. Any requirement whose stored `req_versions.type` is outside the seven
standard codes (`cfg/const.inc.php:676-683`, keys `'1'`..`'7'`) logged
`E_WARNING Undefined array key "f"` to the Event Viewer (compiled
`gui/templates_c/…/file.reqCreateTestCases.tpl.php` line 230), and the row's Type cell
rendered blank for that requirement. Same defect class as the already-fixed #1417
(`b45fc02cb`, reqView viewers) — this was the surviving same-class unguarded read.

Screenshots:
- after fix `docs/screenshots/issue-1428-fixed.png` (R-BAD row type cell now shows raw `f`)

## Repro steps

1. Log in as admin.
2. Seed a requirement with a non-standard type (fixture `tmp/fixtures_1428.php`: tproject 1
   `ReqCreateTCBadType`, spec 2 `SRS-1428`; `UPDATE req_versions SET type='f' WHERE id=7`).
3. `TRUNCATE events;` then open
   `lib/requirements/reqEdit.php?doAction=createTestCases&req_spec_id=2&tproject_id=1`.
4. `SELECT * FROM events WHERE log_level <= 8`: `E_WARNING Undefined array key "f"`
   (one row per affected requirement).
5. Optionally compare with an in-domain type row (`type='2'`), which renders "Feature".

## Root cause

`req_versions.type` is `CHAR(1)` and written **verbatim without validation**
(`lib/requirements/requirement_mgr.class.php:2293-2297`). The allowed set exists only as
`req_cfg->type_labels` keys `'1'`..`'7'` (`cfg/const.inc.php:676-683`).
`reqCommands()` builds `$this->reqTypeDomain = init_labels($reqCfg->type_labels)`
(`lib/requirements/reqCommands.class.php:29-30`) and `createTestCases()` exposes that map
on the gui bean via `initGuiBean()` (:63-64); `get_requirements()` returns each row's
`type` unchanged. The template then indexes the map with the stored code
(`gui/templates/dashio/requirements/reqCreateTestCases.tpl:181`, identical in tl-classic),
so an out-of-domain code like `'f'` makes PHP 8 emit the E_WARNING, which the error
watchdog persists to `events`.

## Fix

Guard the read with `isset()` and fall back to the raw stored code (escaped), mirroring
the #1417 fix pattern, in **both** themes:

```smarty
{assign var="req_type" value=$gui->all_reqs[row].type }
<td style="padding:2px;">
{if isset($gui->reqTypeDomain.$req_type)}
  {$gui->reqTypeDomain.$req_type|escape}
{else}
  {$req_type|escape}
{/if}
</td>
```

- Type cell: previously `{$gui->reqTypeDomain.$req_type|escape}` (1 line) — now guarded,
  `|escape` kept on both branches.
- The `expected_coverage` cell on this screen is gated only by
  `$gui->req_cfg->expected_coverage_management` and reads plain row fields
  (`expected_coverage`, `coverage`) — **no** domain-indexed read → left untouched.

Method chosen: minimal, per-read-site guard identical to the established repo pattern,
type-agnostic, no config/DB/migration touched, restores visibility of the stored value
(raw code) exactly as the issue's "Expected" states. Alternative rejected: widening
`type_labels` is a data/model decision, not a template fix, and does not address what an
unknown code should mean.

## Verification

Regression suite `Regression — Issue #1428` in `tmp/TLU_Test_Cases.md` (7 cases):
- 1428.1 pre-fix control render → `Undefined array key "f"` reproduced (+1 event per bad row);
- 1428.2 post-fix render → R-BAD type cell shows raw `f`, R-GOOD shows `Feature`;
- 1428.3 event log: **0** `Undefined array key "f"` events after fix;
- 1428.4 `doCreateTestCases` POST flow → both TCs created, re-render still f/Feature, 0 events;
- 1428.5 tl-classic twin carries the identical guard;
- 1428.6 i18n hygiene → no new user-facing strings (no bundle touched);
- 1428.7 Event Viewer / console clean after suite (only the pre-existing #1480 rows remain).
Result: 7/7 PASS.

## Files changed

- `gui/templates/dashio/requirements/reqCreateTestCases.tpl` (+8/-1)
- `gui/templates/tl-classic/requirements/reqCreateTestCases.tpl` (+8/-1)

## Related bugs discovered while testing (filed separately, not fixed here)

- **#1480** — same screen, `reqCommands::createTestCases()` never sets `tproject_id` on the
  bean → `E_WARNING Undefined property: stdClass::$tproject_id` fires **2× on every view**
  and `openLinkedReqWindow(req_id, undefined)` loses the project id (`reqCreateTestCases.tpl:174`).
- **#1481** — same class, different domain: the unguarded `{$gui->reqStatusDomain.$req_status|escape}`
  at `reqCreateTestCases.tpl:179` (plus the reqView viewers) logs
  `E_WARNING Undefined array key "<code>"` when a stored `status` is outside `status_labels`
  (repro: `UPDATE req_versions SET status='z'`).