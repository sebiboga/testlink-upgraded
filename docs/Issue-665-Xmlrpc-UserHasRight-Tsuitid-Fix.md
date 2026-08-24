# Issue 665 — xmlrpc userHasRight() `$tsuitid` assigned but `$tsuiteid` read (lines 477–481), 2x E_WARNING per contextless call

**Issue:** [#665](https://github.com/sebiboga/testlink-upgraded/issues/665)
**Branch:** `fix/issue-665` · **Commits:** `0173468bc` (fix), `5295744bc` (regression suite)
**Status:** FIXED & VERIFIED (2026-08-24)

## Symptom

Every XMLRPC call whose rights-check context lacks a test project id AND test plan id
AND suite/case id fired 2 E_WARNING rows into the Event Viewer:

```
E_WARNING Undefined variable $tsuiteid - lib/api/xmlrpc/v1/xmlrpc.class.php - Line 478
E_WARNING Undefined variable $tsuiteid - lib/api/xmlrpc/v1/xmlrpc.class.php - Line 481
```

Reproduced live against http://localhost:8082 on a fresh DB (`events` table empty):
one `tl.createPlatform` call with a devKey-only struct added exactly rows 1–2,
both `log_level=2`, matching the report.

Note: admin users cannot reproduce this — `userHasRight()` short-circuits
`return true` for `TL_ROLES_ADMIN` at line :445 before the buggy block is reached.
Repro requires a non-admin devKey user (fixture: role "no rights").

## Root cause chain

1. `userHasRight($rightToCheck, $checkPublicPrivateAttr = false, $context = null)`
   (xmlrpc.class.php:441) normalizes context ids; a contextless call yields
   `$tprojectid = 0`, `$tplanid = -1`.
2. The "Contribution by frankfal" fallback block (:473) is entered when
   `$tprojectid <= 0 && $tplanid == -1` (:475).
3. **Line :477 assigns the misspelled variable:**

   ```php
   $tsuitid = intval( isset( $context[self::$testSuiteIDParamName] ) ? $context[self::$testSuiteIDParamName] : 0 );
   ```

4. Line :478 reads `$tsuiteid == 0` → undefined ⇒ **E_WARNING #1**
   (evaluates true ⇒ backfills from args when testsuiteid IS present).
5. Line :481 reads `$tsuiteid > 0` → still undefined when args had no testsuiteid
   ⇒ **E_WARNING #2**.
6. Net effect beyond noise: the suite-based TestProject lookup only worked by
   accident of the warning-true comparison; with neither id present `$tprojectid`
   stayed 0 (which is correct behavior, just noisy).

**Why it breaks now:** the typo shipped with the upstream frankfal contribution and
was carried into this repo; PHP 8 logs undefined-variable reads as E_WARNINGs into
the `events` table instead of silently ignoring them.

## Blast radius

- `userHasRight()` is THE rights gate of the XMLRPC API (~40 call sites via grep).
- Every call reaching :475 without tprojectid/tplanid warned ≥1x per call
  (2x when args also lacked testsuiteid). No data corruption — log pollution plus
  a lookup path that worked only incidentally.

## Approach — minimal rename

Chosen fix (1 line, zero contract change): rename the assignment so both reads see it:

```php
$tsuiteid = intval( isset( $context[self::$testSuiteIDParamName] ) ? $context[self::$testSuiteIDParamName] : 0 );
```

Post-rename semantics match the obvious intent of the block: context value wins,
else backfill from args, else fall through to the testcaseid branch.
Rejected alternative: rewriting/reordering the block — pointless churn; the code was
correct except for the identifier typo.

## Files changed

| File | Change |
|---|---|
| `lib/api/xmlrpc/v1/xmlrpc.class.php` | :477 `$tsuitid` → `$tsuiteid` |
| `tmp/TLU_Test_Cases.md` | Suite 665 regression entry (5 cases) |

## Verification

- `php -l lib/api/xmlrpc/v1/xmlrpc.class.php` PASS.
- Fixtures: project Bug665Proj id=1 (prefix B665), SuiteA id=2, CaseB id=3;
  user bugrepro665 role 3 ("no rights") → 4 ("test designer") mid-suite.
- Regression matrix re-run post-fix, events count watched around every call:

| # | Case | Expected | Actual | Verdict |
|---|------|----------|--------|---------|
| R1 | contextless `tl.createPlatform`, role 3 | INSUFFICIENT_RIGHTS 2010 (tprojectid 0); 0 new events | identical response; events 2→2 | PASS |
| R2 | `getTestSuiteAttachments` testsuiteid-only, role 3 | denial embeds RESOLVED tprojectid=1; 0 events | "test project id: 1"; events 4→4 | PASS |
| R3 | `getTestCaseAttachments` testcaseid-only, role 3 | resolved tprojectid=1; 0 events | "test project id: 1"; events 4→4 | PASS |
| R4 | R2/R3 as designer | success, 0 warnings | empty attachment structs; events 4→4 | PASS |
| R5 | testsuiteid + tprojectid in args | block skipped entirely | success; events 4→4 | PASS |

Regression suite: `Suite 665` in `tmp/TLU_Test_Cases.md` — 5/5 PASS.
Final `events` table contains ONLY the two pre-fix repro rows (#1,#2), one level-16
audit row (#3) and one unrelated warning (#4).

## Related defect found during this run

[#666](https://github.com/sebiboga/testlink-upgraded/issues/666) — unguarded
`$steps[$jdx]['expected_results']` read at `testcase.class.php:803` (E_WARNING when a
step struct omits/misnames the key). Discovered while creating fixtures; NOT touched
by this fix.
