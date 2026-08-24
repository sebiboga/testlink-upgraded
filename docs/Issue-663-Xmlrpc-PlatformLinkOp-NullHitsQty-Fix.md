# Issue 663 — `platformLinkOp()`: 2× E_WARNING "Trying to access array offset on null" on EVERY successful `removePlatformFromTestPlan`

**Issue:** [#663](https://github.com/sebiboga/testlink-upgraded/issues/663)
**Branch:** `fix/issue-663` · **Commit:** `e3d1c1eea` (fix, +5/−2) + `09248397d` (regression suite)
**Status:** FIXED & VERIFIED (2026-08-24)

## Symptom

Every SUCCESSFUL `tl.removePlatformFromTestPlan` call where the platform has
ZERO linked test case versions (the normal case) returns the correct payload
(`{operation: unlink, msg: 'unlink done', linkStatus: true}`) but ALSO writes
2 × E_WARNING rows into the Event Viewer (`events` table):

```
mysql> SELECT id, log_level, description FROM events\G
     log_level: 2
  description: E_WARNING
Trying to access array offset on null - in .../lib/api/xmlrpc/v1/xmlrpc.class.php - Line 7008
     log_level: 2
  description: E_WARNING
Trying to access array offset on null - in .../lib/api/xmlrpc/v1/xmlrpc.class.php - Line 7008
```

Two rows because line 7008 performs two chained offset reads
(`$hits[$platform['id']]` then `['qty']`) on a NULL value.

Repro measured live (fresh DB, events table truncated to 0 first): admin
devKey, fixtures built through the API itself (`createTestProject`,
`createTestPlan`, `createPlatform`, `addPlatformToTestPlan`), then
`removePlatformFromTestPlan`. Response correct, events table gained exactly
2 E_WARNING rows at Line 7008.

## Root cause chain

1. `lib/api/xmlrpc/v1/xmlrpc.class.php:6841` —
   `removePlatformFromTestPlan()` delegates to private `platformLinkOp()`
   (`:6958`) with `$op='unlink'`.
2. `:7007` — unlink branch calls
   `$hits = $this->tplanMgr->countLinkedTCVersionsByPlatform($testPlanID,(array)$platform['id']);`
3. `lib/functions/testplan.class.php:3521-3533` — that method runs a
   `GROUP BY platform_id` query and ends with
   `return $this->db->fetchRowsIntoMap($sql,'platform_id');`. When NO row
   matches (platform linked to the plan but zero `testplan_tcversions` rows
   use it), `fetchRowsIntoMap()` returns **null**, never an empty array.
4. `:7008` — `if($hits[$platform['id']]['qty'] == 0)` reads offsets off null
   ⇒ E_WARNING ×2. The expression still evaluates true (`null == 0`), so the
   unlink proceeded correctly — damage was pure event-log pollution.

## Blast radius

Exactly ONE unguarded consumer repo-wide:

| Consumer | Guard |
|----------|-------|
| `lib/api/xmlrpc/v1/xmlrpc.class.php:7007-7014` | **none (bug)** |
| `lib/platforms/platformsAssign.php:39-41` | `isset($qtyByPlatform[0]['qty']) ? … : 0` |
| `api/platforms/index.php:550-551` | `isset(...) && intval(...) > 0` |
| `api/platforms/index.php:635-636` | same isset pattern |

The GUI/BFF layers were never affected; only the XMLRPC API call site lacked
the convention. The error branch at `:7014` re-reads the qty for its message,
but only executes when qty > 0 ($hits non-null there) — normalizing once
covers both reads.

## Approach — normalize to $hitsQty via isset() (minimal, matches existing convention)

At `xmlrpc.class.php:7007-7015`:

```php
$hits = $this->tplanMgr->countLinkedTCVersionsByPlatform( $testPlanID,( array ) $platform['id'] );
// fetchRowsIntoMap() returns null when the GROUP BY matches no row
// (platform linked but zero TC versions use it)
$hitsQty = isset($hits[$platform['id']]['qty']) ? intval($hits[$platform['id']]['qty']) : 0;
if($hitsQty == 0) { ... 'unlink done' ... }
else { ... sprintf(PLATFORM_REMOVETC_NEEDED_BEFORE_UNLINK_STR, $platName, $hitsQty); }
```

Rejected alternatives:
- changing `countLinkedTCVersionsByPlatform()` to return `[]` instead of
  null — alters a public method consumed by three working callers
  (return-type change risk for zero benefit);
- guarding inside `fetchRowsIntoMap()` — touches the shared DB layer far
  beyond this bug's blast radius;
- deleting the count check — would remove the PLATFORM_REMOVETC_NEEDED_
  BEFORE_UNLINK contract shared with the GUI behaviour.

## Verification (Suite 663 — 7/7 PASS, see tmp/TLU_Test_Cases.md)

Regression matrix executed live against http://localhost:8082 with events
table truncated before every case:

| # | Scenario | Result |
|---|----------|--------|
| R1 | unlink linked platform, ZERO TC versions (**primary bug case**) | `{unlink done}` correct, ZERO new events (was: +2 E_WARNING @7008) |
| R2 | unlink when not linked | `{nothing to do}`, no new events |
| R3 | unlink blocked by 1 linked TC version | code 12001, message correctly says "used by 1 test case(s)", no new events |
| R4 | unknown platform name | code 235 unchanged, no new events |
| R5 | link op after fix | `{link done}`, no new events |
| R6 | sibling consumers untouched | platformsAssign.php / api/platforms/index.php already guarded, untouched |
| — | syntax gate | `php -l` clean |

Event Viewer watermark stayed at 0 across the entire post-fix matrix.

## Side discovery (already tracked, not touched here)

Building case 3's fixture through `tl.createTestCase` +
`tl.addTestCaseToTestPlan` reproduced open issue
[#661](https://github.com/sebiboga/testlink-upgraded/issues/661): documented
step key `expectedresults` triggers E_WARNING "Undefined array key
\"expected_results\"" at testcase.class.php:803 and links the version with
platform_id=0. No duplicate filed.
