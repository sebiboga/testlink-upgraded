# Issue 669 — `_insertResultToDB()`: raw interpolation of `$version_number` into the executions INSERT — null kills the entire XML-RPC request

**Issue:** [#669](https://github.com/sebiboga/testlink-upgraded/issues/669)
**Branch:** `fix/issue-669`
**Status:** FIXED & VERIFIED (2026-08-24)

## Symptom

The executions INSERT in `_insertResultToDB()` interpolates
`$version_number` unvalidated (`xmlrpc.class.php:1813` → `:1848`). Since #667,
`$this->versionNumber` can legitimately be `null` (explicit first-class state set
by `_checkTCIDAndTPIDValid()`, :1390-1391). With null, the composed SQL renders
`, , ` → MySQL error **1064**.

Measured aggravator (this is what turns a bad insert into an outage): on any failed
query `database::exec_query()` prints a backtrace and calls **`die()`**
(`lib/functions/database.class.php:218`). A production hit would therefore kill the
whole `tl.reportTCResult` XML-RPC response mid-request — no `IXR_Error` structure,
no partial result — and because the logger buffers per transaction and never commits
on `die()`, the ERROR entry never even lands in the Event Viewer. Invisible failure.

Reproduced pre-fix with a Reflection harness invoking the real protected method
against live MariaDB (`tmp/repro_669.php`; fixtures `tmp/fixtures_669.php`:
project UPD669/24, tcase 26, tcversion_id 27 v1, tplan 29, build 2):

- CASE A `versionNumber=1`: INSERT OK → id=3, rows 0→1.
- CASE B `versionNumber=null`: process terminated inside `_insertResultToDB()`
  (backtrace at `xmlrpc.class.php:1850 database->exec_query()`); "CASE B" output
  line never reached; row count unchanged; nothing persisted to `events`.

## Root cause chain

1. `_checkTCIDAndTPIDValid()` — xmlrpc.class.php:1390-1391 — sets
   `$this->versionNumber = null` when the matched link group carries no `'version'`
   key (post-#667 contract).
2. Property default — xmlrpc.class.php:119 — `protected $versionNumber = null;`.
3. `_insertResultToDB()` — :1813 — `$version_number = $this->versionNumber;`
   (no cast, no validation).
4. SQL composition — :1848 — `" ... {$platform_id}, {$version_number},{$execution_type} ..."`
   → null interpolates empty → `, , ` → 1064.
5. Failure path — database.class.php:162-224 — log + backtrace + `die()` (:218);
   caller at xmlrpc.class.php:2744 never regains control.

Today's live paths only stay safe by coincidence: matched groups always contain ≥1
row and `tcversions.version` is `smallint NOT NULL DEFAULT 1`
(install/sql/mysql/testlink_create_tables.sql:454), so step 1 rarely produces null
on writer flows. The guard hole between resolution and composition remained.

## Blast radius

| Call site | Exposure |
|-----------|----------|
| xmlrpc.class.php:2744 — `_insertResultToDB()` from `reportTCResult` family | ONLY consumer of the interpolated value |
| `_updateResult()` (:5024) | unaffected — never writes `tcversion_number` |
| other files | none compose this INSERT |

## Approach — validate + intval before SQL composition, early-fail via existing convention

At `_insertResultToDB()`:

```php
// unresolved version must never reach SQL composition: exec_query() die()s on bad SQL
$version_number = ( ! is_null( $this->versionNumber ) && is_numeric( $this->versionNumber ) ) ?
    intval( $this->versionNumber ) : 0;
if( $version_number <= 0 ) {
    return 0;
}
```

`return 0` matches how callers already read failure here: `$executionID == 0`
drives the flow at :2743/:2747 and `_updateResult()` also signals failure via 0.
No API surface change, no new error codes needed for a state that today's valid
paths cannot reach anyway.

Rejected alternatives:
- plain `(int)` cast — would silently write bogus `tcversion_number=0` rows;
- throwing / appending IXR_Error here — below the request-validation layer,
  inconsistent with the function's return-value contract.

## Verification (Suite 669 — 4/4 PASS, see tmp/TLU_Test_Cases.md)

Live matrix against http://localhost:8082; harness `tmp/repro_669.php`;
Event Viewer watermark `MAX(events.id)`:

| # | Case | Result |
|---|------|--------|
| R1 | versionNumber=1 (valid) | id=6, rows 0→1 |
| R2 | versionNumber=null (**primary bug case**) | no die(), returns 0, no row written |
| R3 | live `tl.reportTCResult` over HTTP | status=true, id=5; row `tcversion_number=1`, notes stored |
| R4 | Event Viewer delta across matrix | watermark 26 before AND after; 0 new ERROR/WARNING |

## Notes

All ERROR/WARNING events observed during the session (#2–#21, 13:02–13:04) were
created by the agent's first-draft fixture scripts while setting up reproduction,
before the fix — none by product code paths under test.
