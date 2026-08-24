# Issue 667 — `_checkTCIDAndTPIDValid()`: 2× E_WARNING on EVERY successful no-platform `getLastExecutionResult` when TC linked under a specific platform

**Issue:** [#667](https://github.com/sebiboga/testlink-upgraded/issues/667)
**Branch:** `fix/issue-667` · **Commit:** `d44bdfa07` (fix, +7/−1) + `e85f342fe` (regression suite)
**Status:** FIXED & VERIFIED (2026-08-24)

## Symptom

Every successful XML-RPC call that reaches the shared helper
`_checkTCIDAndTPIDValid()` **without** a platform filter
(`getLastExecutionResult`, `getAllExecutionsResults`, `getTestCasesForTestPlan`,
`getTestCaseBugs`, `getExecutionSet`) for a test case linked to the plan *under
a concrete platform* returns correct data but writes 2 × E_WARNING rows per
request into the Event Viewer (`events` table), and leaves the protected member
`$this->versionNumber` set to NULL:

```
E_WARNING Undefined array key 0 - lib/api/xmlrpc/v1/xmlrpc.class.php - Line 1379
E_WARNING Trying to access array offset on null - lib/api/xmlrpc/v1/xmlrpc.class.php - Line 1379
```

Repro measured live: fixtures built via API (`createTestProject` → project 13,
`createTestPlan` → plan 14, `createPlatform` → platform 5,
`addPlatformToTestPlan`, `createTestCase` → case 16/tcversion 17,
`addTestCaseToTestPlan {platformid:5}`); DB row confirmed
`testplan_tcversions: tcversion_id=17, testplan_id=14, platform_id=5`.
Then `tl.getLastExecutionResult {testplanid:14, testcaseid:16}` (no platform)
→ response `[{"id":-1}]` (success) AND events #7/#8 with exactly the pair above.

## Root cause chain

1. Read-only methods call `_checkTCIDAndTPIDValid(null, …)`
   (`xmlrpc.class.php:1586, :1721, :3105, :7510, :8878`) ⇒ at `:1364`
   `$platform_id = null`.
2. `:1372` calls `get_linked_versions($tcase_id, filters)`; the
   `exec_status=ALL` branch (`lib/functions/testcase.class.php:1772-1783`)
   re-keys the result's third level by each link row's REAL `platform_id`
   (key `5` in the repro).
3. Back in the helper `:1378` assumes legacy platform-less links:
   `$plat = is_null($platform_id) ? 0 : $platform_id;` → `0`.
4. `:1379` `$this->versionNumber = $dummy[$tplan_id][0]['version'];`
   → *Undefined array key 0* → null → *array offset on null* →
   `versionNumber = NULL` despite success.

Platform-less links (`testplan_tcversions.platform_id NOT NULL DEFAULT 0`,
i.e. plans without platforms) DO use key `0` and always worked — which is why
the latent bug survived. Downstream, `versionNumber` feeds
`_insertResultToDB()` (`xmlrpc.class.php:1806`, writes
`executions.tcversion_number`); writer paths currently resolve a platform
first so they were unaffected by coincidence.

## Blast radius

| Call site | Method | Exposure |
|-----------|--------|----------|
| `xmlrpc.class.php:1586` | getLastExecutionResult | vulnerable (null platformInfo) |
| `:1721` | getAllExecutionsResults | vulnerable |
| `:3105` | getTestCasesForTestPlan (helper) | vulnerable |
| `:7510` | getTestCaseBugs | vulnerable |
| `:8878` | getExecutionSet | vulnerable |
| `:2685` | reportTCResult | passes resolved platform; null only for platform-less plans (safe by construction) |
| `:7426`, `:7693` | getTestCaseAssignedTester / exec task mgmt | pass resolved platform info |

## Approach — first-entry fallback only when key `[0]` is absent (minimal)

At `xmlrpc.class.php:1375-1379`:

```php
$this->tcVersionID = key( $info );
$dummy = current( $info );
$plat = is_null( $platform_id ) ? 0 : $platform_id;
if( !isset($dummy[$tplan_id][$plat]) ) {
    // no platform filter requested but link stored under concrete platform id(s):
    // every entry belongs to the same tcversion, first entry has the right version
    $plat = (int)key( $dummy[$tplan_id] );
}
$this->versionNumber = isset($dummy[$tplan_id][$plat]['version']) ?
    $dummy[$tplan_id][$plat]['version'] : null;
```

The fallback is semantically exact: all entries under `info[tcversion_id]`
belong to the SAME tcversion, so `version` is identical across platform keys.
Legacy `[0]`-keyed links keep the untouched original path. The final isset
guard also removes any residual PHP8 offset warning if the map were empty.

Rejected alternatives:
- changing `get_linked_versions()` keying — public method consumed across UI/
  reports, far too invasive for this blast radius;
- looping over entries / merging platforms — unnecessary since versions are
  identical per top-level tcversion key.

## Verification (Suite 667 — 5/5 PASS, see tmp/TLU_Test_Cases.md)

Live matrix against http://localhost:8082; counter =
events matching `%xmlrpc.class.php - Line 1379%`:

| # | Scenario | Result |
|---|----------|--------|
| R1 | no-platform call + link under platform 5 (**primary bug case**) | success `[{"id":-1}]`, warning delta **0** (was +2/call) |
| R2 | no-platform call + legacy platform-less link (row `platform_id=0`) | success, delta 0 — legacy path intact |
| R3 | call WITH `platformid=5` | success, delta 0 |
| R4 | unlinked TC | clean IXR_Error 3030 branch, delta 0 |
| R5 | `reportTCResult` end-to-end consuming `versionNumber` | executions row `tcversion_number=1, status=p`, delta 0 |
| EV | Event Viewer delta across matrix | no new Error/Warning types |

## Side discovery (filed separately)

Same repro surfaced a PRE-EXISTING unrelated notice (first seen pre-fix as
event #9): `E_USER_NOTICE database/fetchRowsIntoMap - missing column: exec_id`
on every never-executed query — filed as
[#668](https://github.com/sebiboga/testlink-upgraded/issues/668). Fixture
gotcha documented along the way: platforms created via `tl.createPlatform`
default to disabled-on-design/execution unless flags passed explicitly.
