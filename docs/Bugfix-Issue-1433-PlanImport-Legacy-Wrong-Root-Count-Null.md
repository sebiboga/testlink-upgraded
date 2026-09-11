# Issue 1433 — planImport (legacy): well-formed XML with wrong root element ⇒ HTTP 500 `count(null)` at lib/plan/planImport.php:286

**Issue:** [#1433](https://github.com/sebiboga/testlink-upgraded/issues/1433)
**Branch:** `fix/issue-1433`
**Status:** VERIFIED-FIXED (2026-09-11)

## Symptom

`POST /lib/plan/planImport.php?tplan_id=<id>` (legacy Smarty screen) with a
**well-formed** XML file whose root is not `<testplan>` — e.g. the old planner export
format rooted at `<xml>` wrapping `<testplan><executables>...` — returns an empty
**HTTP 500** body. PHP 8.3 throws:

```
TypeError: count(): Argument #1 ($value) must be of type Countable|array, null given
  at lib/plan/planImport.php:286
```

## Repro steps

1. Log in as admin (`POST /login.php`, `tl_login=admin&tl_password=admin`, keep the
   session cookie).
2. Fixture `tmp/fixtures_pimp.php`: tproject **1 PIMP**, plan **12 PIMP-Plan** (zero
   linked platforms), platform 1 PIMP-Android.
3. `curl -b cookies.txt -F "importType=XML" -F "uploadFile=1" -F "uploadedFile=@wrongroot.xml"
   "http://localhost:8082/lib/plan/planImport.php?tplan_id=12"` with `wrongroot.xml`:
   `<?xml version="1.0"?><xml><testplan><executables><link>...` (root `<xml>`, old
   planner export format).
4. **Before fix:** HTTP 500, empty body; fatal `TypeError: count() ... null` at
   `lib/plan/planImport.php:286`.
   **After fix:** HTTP 200, the import screen renders with an empty import report
   (`$loops2do = 0` → nothing to import).

**Expected:** graceful non-500 report (empty result), matching the modern BFF fix #1407.

## Root cause

`lib/plan/planImport.php:279-286`:

```php
if( $status_ok && $xml->xpath('//executables') )
{
    ...
    $xmlLinks = $xml->executables->children();
    $loops2do = count($xmlLinks);        // line 286 → count(null) on PHP 8
```

Chain:

1. `:279` — `$xml->xpath('//executables')` matches the `<executables>` node at **any
   depth** (XPath `//` axis), so the block is entered for the `<xml>`-wrapped file.
2. `:285` — `$xml->executables` is a **direct-child** property access on the document
   root. The root is `<xml>` whose direct child is `<testplan>`, so `->executables`
   resolves to an empty node whose `->children()` is `null`.
3. `:286` — `count(null)` is a **TypeError on PHP 8.x** (PHP < 8 silently coerced null→0).
   Uncaught ⇒ request dies before the Smarty page renders, hence the empty 500 body.

The graceful invalid-XML path is never reached because parsing itself succeeds — only the
*structure* is unexpected.

Blast radius:

- Only `lib/plan/planImport.php:285-286` crashes on this input. Files whose root
  contains **no** `<executables>` at any depth already behave gracefully (block not
  entered → empty `$msg`, empty import report) — so a wrong root that merely lacks
  `<executables>` was already safe; the crash was specific to files where `<executables>`
  exists at depth (the `<xml>` wrapper / old planner exports).
- The modern BFF twin `api/planimport/index.php:145-151` had the **identical** pattern and
  was already fixed by **#1407** (commit `6b3aca759`) — this legacy file was the
  remaining unprotected copy. Legacy screen itself is slated for removal via task #1409
  (minimal patch only, no refactor).

## Fix (minimal)

`lib/plan/planImport.php:285-289` — mirror the verified #1407 guard from the BFF:

```php
// NOTE: //executables xpath matches at any depth but ->executables only
// reaches a direct child of the root; on non-<testplan> roots
// (e.g. <xml>-wrapped exports) it resolves to null, so count() fatals on PHP 8.
$xmlLinks = $xml->executables instanceof SimpleXMLElement
            ? $xml->executables->children() : null;
$loops2do = is_null($xmlLinks) ? 0 : count($xmlLinks);
```

- `$loops2do = 0` ⇒ the legacy `for(...)` loop body is skipped; the function returns
  `$msg` normally ⇒ page renders with an empty import report. This restores the exact
  **PHP 5/7 behavior** (`count(null)` = warning + 0) that the legacy screen showed before
  PHP 8 made it fatal.
- The supported `<testplan>`-root import path is completely unchanged (normal imports
  still parse, count and process links exactly as before).
- No new user-facing string ⇒ **no i18n bundle changes**.

Rejected alternative:

- **Actually import old `<xml>`-wrapped planner exports** (walking into `$xml->testplan`)
  — a new feature the legacy import never supported (it just crashed), touches platform
  handling too (`property_exists($xml,'platforms')` at `:256` has the same direct-child
  assumption), and is out of scope for a minimal bug fix.

## Verification (regression matrix, all PASS on localhost:8082)

Fixture reset (`php tmp/fixtures_pimp.php`): tproject **1**, plan **12**, platform **1**.

- **primary repro (`<xml>` wrapper, old planner format):** pre-fix HTTP 500 + fatal at
  `:286`; post-fix **HTTP 200**, 9149-byte page (import screen with empty report), server
  log `[200]: POST /lib/plan/planImport.php?tplan_id=12`, no fatal.
- **valid `<testplan>`-root import:** HTTP 200; page reports `Test case link #1 has
  platform but Test Plan has no linked platforms: Not imported` — normal legacy
  processing intact (this fixture plan has zero platforms so the platformed link is
  intentionally skipped). **Proves the supported path is untouched.**
- **hygiene:** `php -l lib/plan/planImport.php` clean; `events` table — only pre-existing
  AUDIT(16) fixture/login rows, **no new Error/Warning** (log_level ERROR=1, WARNING=2).
- **i18n:** zero bundle files touched (no user-facing string changed).

## Evidence

Commit range `(fix)`, `(regression suite)`, `(docs + wiki)` on branch `fix/issue-1433`.
Wiki page: `tmp/wiki-repo/Bugfix-Issue-1433-PlanImport-Legacy-Wrong-Root-Count-Null.md`.
Regression suite: `tmp/TLU_Test_Cases.md` → "Regression — Issue #1433".