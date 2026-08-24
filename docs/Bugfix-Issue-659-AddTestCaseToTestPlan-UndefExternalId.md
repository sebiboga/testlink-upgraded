# Issue 659 — `addTestCaseToTestPlan()`: undefined `$tcase_external_id` at xmlrpc.class.php:3522

**Issue:** [#659](https://github.com/sebiboga/testlink-upgraded/issues/659)
**Branch:** `fix/issue-659` · **Commit:** `98fac3e3b` (fix, +2 lines)
**Status:** FIXED & VERIFIED (2026-08-24)

## Symptom

Calling `tl.addTestCaseToTestPlan` with a valid test case but a nonexistent
`version` produced a degraded error message **and** an E_WARNING row in the
Event Viewer on every occurrence:

```
(addTestCaseToTestPlan) - Version (99) does not exist for Test Case (:issue659_tc).
```

```
E_WARNING
Undefined variable $tcase_external_id - .../lib/api/xmlrpc/v1/xmlrpc.class.php - Line 3522
```

Measured during reproduction (fresh DB): fixtures created via the XMLRPC API
(project id=1 prefix `I659`, plan id=2, suite id=3, case id=4 = `I659-1`
version 1), then a single call with `version: 99` → events id=2, log_level=2,
message above. The `%s` slot meant to carry the external id rendered as an
empty string, leaving a dangling colon before the case name.

## Root cause chain

1. `addTestCaseToTestPlan()` opens at xmlrpc.class.php:3462; its local init
   block (:3467-3473) never defines `$tcase_external_id`.
2. The version-existence block (:3513-3524) rejects unknown versions at :3520
   and builds its message at :3522 with
   `sprintf(TCASE_VERSION_NUMBER_KO_STR, $version_number, $tcase_external_id, ...)`
   — reading the variable that was never assigned anywhere in this method.
3. Locale format (`locale/en_GB/strings.txt:3207`)
   `"Version (%s) does not exist for Test Case (%s:%s)."` → PHP coerces the
   undefined variable to NULL → empty slot → `(:name)`; under PHP 8.3 logging
   each occurrence also writes an E_WARNING to `events`.

Whole-file grep confirmed `$tcase_external_id =` exists exactly once outside
this bug site — inside `checkTestCaseAncestry()` (:3824), a different method.
Legacy 1.9.20 code predating strict PHP; same family as #457's audit findings.

## Blast radius

Exactly one faulty call site (:3522). No other screen or API method shares the
line. Sibling patterns available:

- `checkTestCaseAncestry()` :3823-3824 — derives the id via
  `$this->tcaseMgr->getExternalID($tcase_id)` ✔ established pattern;
- :5904 uses `$this->args[self::$testCaseExternalIDParamName]` ✘ not safe here:
  callers may pass `testcaseid` instead of the external id.

## Approach — derive the id from the case id (minimal)

Chosen fix (+2 lines at :3521, immediately before the sprintf):

```php
$dummy = $this->tcaseMgr->getExternalID( $tcase_id, $tproject_id );
$tcase_external_id = isset( $dummy[0] ) ? $dummy[0] : '';
```

Why this way:

- Mirrors the in-file sibling pattern (`checkTestCaseAncestry()`), so the code
  stays consistent with how the same variable is computed elsewhere.
- Passes the already-in-scope `$tproject_id`: `testcase::getExternalID()`
  (lib/functions/testcase.class.php:5697-5721) keeps a *static*
  prefix/root cache (:5699-5707); calling it without a project can reuse
  another project's cached prefix on multi-project API servers. Supplying
  `$tproject_id` sidesteps that entirely.
- The `isset` guard covers `getExternalID()` returning `[]` when the case has
  no last version info (testcase.class.php:5714-5715).

Rejected alternatives:

- Using `$this->args[self::$testCaseExternalIDParamName]` like :5904 — breaks
  for `testcaseid` callers;
- Suppressing warnings globally — hides future defects;
- Refactoring the check block — larger diff with zero behavioral gain.

## Verification

| # | Check | Result |
|---|-------|--------|
| R1 | version=99 post-fix | fault 5051, message `(I659-1:issue659_tc)`, no new E_WARNING | 
| R2 | version=1 valid link | success; `testplan_tcversions` gains the link row |
| R3 | cross-project case O659-1 vs plan of project 1 | ancestry rejection unchanged (7007, correct ids) |
| — | Event Viewer watermark across all runs | zero new log_level=2 rows |
| — | `php -l xmlrpc.class.php` | clean |

Regression suite: `tmp/TLU_Test_Cases.md` → "Suite 659 — 6/6 PASS".
