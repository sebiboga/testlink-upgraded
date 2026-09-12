# Issue 1481 — reqCreateTestCases/reqView viewers: E_WARNING "Undefined array key" on requirement STATUS-domain read

**Issue:** [#1481](https://github.com/sebiboga/testlink-upgraded/issues/1481)
**Branch:** `fix/issue-1481`
**Status:** VERIFIED-FIXED (2026-09-12)

## Symptom

Three requirement screens read `reqStatusDomain` unguarded. `reqStatusDomain` is built
from `req_cfg->status_labels` (`lib/requirements/reqCommands.class.php:29`), whose only
keys are the eight standard codes `D/R/W/F/I/V/N/O`
(`cfg/const.inc.php:625-643`). `req_versions.status` is a `CHAR(1)` column written
**verbatim** without validation (`lib/functions/requirement_mgr.class.php:2293-2297`), so
any stored code outside that set — e.g. `'z'` — made every affected read log
`E_WARNING Undefined array key "z"` to the Event Viewer (`events` table, log_level=2),
and the status cell rendered blank on those screens. Same defect class as the already
fixed type-domain bugs #1417 (`b45fc02cb`) and #1428 (landed `caa45c5fd`):
this issue covers the sibling **status** reads that were left unguarded.

Affected reads (6 sites, 2 themes × 3 templates):
- `gui/templates/dashio/requirements/reqCreateTestCases.tpl:179` == tl-classic:179 — `{$gui->reqStatusDomain.$req_status|escape}`
- `gui/templates/dashio/requirements/reqViewVersionsViewer.tpl:176` == tl-classic:172 — `{$args_gui->reqStatusDomain[$args_req.status]}`
- `gui/templates/dashio/requirements/reqViewRevisionViewer.tpl:57` == tl-classic:57 — `{$args_gui->reqStatusDomain[$args_req.status]}`

## Repro steps

1. Log in as admin.
2. Seed a requirement with an out-of-domain status (fixture `tmp/fixtures_1481.php`:
   tproject 1 `RCB1481:ReqCreateTCBadStatus`, spec 2 `SRS-1481`; `R-BAD`
   `req_versions` id=7 `status='z'`, `R-GOOD` id=5 `status='V'`).
3. `TRUNCATE events;` then open
   `lib/requirements/reqEdit.php?doAction=createTestCases&req_spec_id=2&tproject_id=1`.
4. `SELECT * FROM events WHERE log_level <= 8`: `E_WARNING Undefined array key "z"`
   (absent before fix; also reproducible on `lib/requirements/reqView.php?requirement_id=6&req_version_id=7`
   and `lib/requirements/reqViewRevision.php?item_id=7`).
5. Compare with the in-domain row (`R-GOOD`), which renders "Valid".

## Root cause

Chain (each hop measured / source-verified):
1. `req_versions.status` is written verbatim by `create_version()` —
   `lib/functions/requirement_mgr.class.php:2293-2297` (single INSERT, no domain check).
2. The valid code set exists only as `$tlCfg->req_cfg->status_labels` keys
   D/R/W/F/I/V/N/O — `cfg/const.inc.php:625-643`.
3. `reqCommands` builds `$this->reqStatusDomain = init_labels($reqCfg->status_labels)`
   (`reqCommands.class.php:29`) and exposes it on the gui bean (`initGuiBean()`, :63; also
   :149/:201/:231); `reqViewRevision.php:108` builds `$gui->reqStatus` likewise for the
   revision viewer. `get_requirements()` / `get_version()` return each row's `status`
   unchanged.
4. Templates then index the map with the stored code; an out-of-domain code makes
   PHP 8 emit `E_WARNING Undefined array key "<code>"`, which the error watchdog
   persists to `events`.

Why now: identical to the pre-#1417/#1428 state — those two PRs guarded the **type**
reads only; the sibling **status** reads were never touched.

## Fix

Mirror the established #1417/#1428 guard pattern in **all 6** sites (both themes):

`reqCreateTestCases.tpl` (status cell):
```smarty
{assign var="req_status" value=$gui->all_reqs[row].status }
<td style="padding:2px;">
{if isset($gui->reqStatusDomain.$req_status)}
  {$gui->reqStatusDomain.$req_status|escape}
{else}
  {$req_status|escape}
{/if}
</td>
```

Both viewers (`reqViewVersionsViewer.tpl`, `reqViewRevisionViewer.tpl`):
```smarty
<td>{$labels.status}{$smarty.const.TITLE_SEP}
{$req_status=$args_req.status}
{if isset($args_gui->reqStatusDomain.$req_status)}
  {$args_gui->reqStatusDomain.$req_status}
{else}
  {$args_req.status}
{/if}
</td>
```

Method chosen: minimal per-read-site guard identical to the repo-established pattern,
domain-agnostic, no config/DB/migration touched, restores visibility of the stored value
(raw code) exactly as the issue's "Expected" states. Alternative rejected: widening
`status_labels` is a data/model decision, not a template fix, and does not define what an
unknown code should mean.

## Verification

Regression suite `Regression — Issue #1481` in `tmp/TLU_Test_Cases.md` (9 cases):
- 1481.1/1481.2 pre-fix control renders → `Undefined array key "z"` reproduced on
  createTestCases (1×/load) and reqView versions viewer (1×/load);
- 1481.3/1481.4 post-fix renders → R-BAD status cell shows raw `z`, R-GOOD shows `Valid`,
  events **0** `Undefined array key` rows (only the pre-existing #1480 `tproject_id` rows);
- 1481.5 `doCreateTestCases` POST flow → both TCs created, coverage 0%→100%, 0 events;
- 1481.6 standalone Smarty render of both viewers in both themes → `Status : z` / `Status : Valid`;
- 1481.7 tl-classic parity; 1481.8 i18n hygiene (no bundles touched); 1481.9 Event Viewer clean.
Result: 9/9 PASS.

## Files changed

- `gui/templates/dashio/requirements/reqCreateTestCases.tpl` (+7/-1)
- `gui/templates/tl-classic/requirements/reqCreateTestCases.tpl` (+7/-1)
- `gui/templates/dashio/requirements/reqViewVersionsViewer.tpl` (+8/-1)
- `gui/templates/tl-classic/requirements/reqViewVersionsViewer.tpl` (+8/-1)
- `gui/templates/dashio/requirements/reqViewRevisionViewer.tpl` (+8/-1)
- `gui/templates/tl-classic/requirements/reqViewRevisionViewer.tpl` (+8/-1)
- fixture: `tmp/fixtures_1481.php`; render smoke test: `tmp/smoke_1481.php`

## Notes / related findings (not fixed here)

- **#1480** (open) — `reqCommands::createTestCases()` never sets `tproject_id` on the bean
  → `E_WARNING Undefined property: stdClass::$tproject_id` fires 2× per view of
  `createTestCases`. Pre-existing; separate issue.
- `lib/requirements/reqViewRevision.php` (revision viewer page) returns HTTP 500 in this
  environment **pre- and post-fix** — a pre-existing class-loading/autoload problem
  (`include_once(reqCommands.class.php)` failing from CLI-served context,
  `lib/functions/common.php:122`). Independently verifiable only via the standalone
  Smarty render (1481.6); its status guard is identical to the browser-verified versions
  viewer guard.