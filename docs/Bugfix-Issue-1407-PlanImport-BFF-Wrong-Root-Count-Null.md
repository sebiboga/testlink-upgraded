# Issue 1407 — planImport (modern): well-formed XML with wrong root element ⇒ HTTP 500 `count(null)` at api/planimport/index.php:151

**Issue:** [#1407](https://github.com/sebiboga/testlink-upgraded/issues/1407)
**Branch:** `fix/issue-1407`
**Status:** VERIFIED-FIXED (2026-09-11)

## Symptom

`POST /api/planimport/?action=import&tproject_id=<id>&tplan_id=<id>` with a
**well-formed** XML file whose root is not `<testplan>` — e.g. the old planner export
format rooted at `<xml>` wrapping `<testplan><executables>...` — returns an empty
**HTTP 500** body: no JSON error, nothing imported. PHP 8 throws:

```
TypeError: count(): Argument #1 ($value) must be of type Countable|array, null given
  at api/planimport/index.php:151
```

The unprefixed minimal repro file (`/tmp/pimp_wrongroot.xml` in the verification run):

```xml
<?xml version="1.0"?>
<xml><testplan><executables><link>
  <platform><name>PIMP-Android</name></platform>
  <testcase><externalid>1</externalid><name>Login</name><version>1</version><execution_order>1</execution_order></testcase>
</link></executables></testplan></xml>
```

## Repro steps

1. Log in as admin (`POST /api/auth/login`, JSON `{"login":"admin","password":"admin"}`).
2. Fixture `tmp/fixtures_pimp.php`: tproject **1 PIMP**, plan **12 PIMP-Plan**, TCs
   Login/Logout/Settings (external ids 1/2/3, version 1, tcversions 4/7/10), platform 1
   PIMP-Android (not yet linked).
3. `POST /api/planimport/?action=import&tproject_id=1&tplan_id=12` with `uploadedFile` =
   the `<xml>`-wrapped file above.
4. **Before fix:** HTTP 500, empty body; fatal `TypeError: count() ... null` at
   `api/planimport/index.php:151`.
   **After fix:** HTTP 200 with `{"status":"ok","result_map":[],"tplan_name":"PIMP-Plan"}`.

**Expected:** graceful `status:ok` + result_map (or at least a JSON error), mirroring how
invalid XML already reports `simplexml_load_file_wrapper_error` / "Not imported".

## Root cause

`api/planimport/index.php:144-151`:

```php
if ($status_ok && $xml->xpath('//executables')) {
    ...
    $xmlLinks = $xml->executables->children();
    $loops2do = count($xmlLinks);        // line 151 → count(null) on PHP 8
```

Chain:

1. `:145` — `$xml->xpath('//executables')` matches the `<executables>` node at **any
   depth**, so the block is entered for the `<xml>`-wrapped file.
2. `:150` — `$xml->executables` is a **direct-child** property access on the document
   root. The root is `<xml>` whose direct child is `<testplan>`, so `->executables`
   resolves to an **empty node whose `->children()` is `null`**.
3. `:151` — `count(null)` is a **TypeError on PHP 8.x** (PHP < 8 silently coerced null→0).
   Uncaught ⇒ the request dies before the route's `out()` JSON envelope, hence the empty
   500 body.

The graceful invalid-XML path (`:288-290`, `simplexml_load_file_wrapper_error`) is never
reached because parsing itself succeeds — only the *structure* is unexpected.

Blast radius:

- Only `api/planimport/index.php:150-151` crashes in the modern BFF. Files whose root
  contains **no** `<executables>` at any depth already behave gracefully (`:282-287` else
  branch → silent `status:ok` + empty result_map, legacy parity) — so a wrong root that
  merely lacks `<executables>` was already safe; the crash was specific to files where
  `<executables>` exists at depth (the `<xml>` wrapper / old planner exports).
- The legacy twin `lib/plan/planImport.php:284-286` has the **identical** unguarded
  `count($xml->executables->children())` pattern and crashes on the same file — filed
  separately as **#1433** (label `bug`), out of this run's scope.

## Fix (minimal)

`api/planimport/index.php:150-153` — align the direct-child access with the possibility
that the executables block is not directly under the root:

```php
$xmlLinks = $xml->executables instanceof SimpleXMLElement
            ? $xml->executables->children() : null;
$loops2do = is_null($xmlLinks) ? 0 : count($xmlLinks);
```

- `$loops2do = 0` ⇒ the legacy `for(...)` loop body is skipped; the function returns
  `$msg` normally ⇒ route emits `status:ok` + empty `result_map`. This output is
  **byte-identical** to the already-existing behavior for any other wrong-structure input
  (e.g. root `<foo>` with no `<executables>`), i.e. the fix normalises the crash case to
  the previously-safe cases — no UX/i18n change needed.
- The supported `<testplan>`-root import path is completely unchanged (normal imports
  still parse, count and link exactly as before).
- No new user-facing string ⇒ **no i18n bundle changes**.

Rejected alternatives:

- **Navigate down into `$xml->testplan`** to actually import old planner exports — that is
  a new feature (the legacy import never supported it either, it just crashed), changes
  platform handling too (`property_exists($xml,'platforms')` at `:130` has the same
  direct-child assumption), and is out of scope for a minimal bug fix.
- **A new "wrong root" message** — would require new keys in every legacy locale
  `strings.txt` for marginal value and is inconsistent with the existing silent handling
  of every other wrong-root shape.

## Verification (regression matrix, all PASS on localhost:8082)

Fixture reset before the run (`php tmp/fixtures_pimp.php`): tproject **1**, plan **12**,
tcversions Login **4** / Logout **7** / Settings **10**, platform **1**.

- **primary repro (`<xml>` wrapper, old planner format):** → HTTP **200**
  `{"status":"ok","result_map":[],"tplan_name":"PIMP-Plan"}`; no fatal in
  `tmp/php_server.log`. (pre-fix: HTTP 500 empty + `TypeError ... :151`).
- **arbitrary wrong root (`<foo><bar/>`):** HTTP 200 ok, empty result_map (unchanged).
- **valid `<testplan>`-root import, one create-link:** HTTP 200, `Platform PIMP-Android
  has been linked to test plan.` + `Test Case with external id 1 version 1 has been linked
  to Test Plan for Platform PIMP-Android`; DB `testplan_tcversions` row (tcversion 4,
  platform 1). **Proves the supported path is untouched.**
- **valid `<testplan>`-root with no `<executables>`:** HTTP 200, empty result_map
  (unchanged).
- **invalid XML (parse fail):** HTTP 200, `simplexml_load_file_wrapper_error` /
  "Not imported" (unchanged).
- **browser (chrome-devtools):** login admin/admin → `planImport.html?tproject_id=1
  &tplan_id=12` → upload the `<xml>`-wrapped file → Import report shows "No import
  results to display."; POST `...action=import` → **200**; zero console errors.
  Screenshot: `tmp/wiki-repo/planImport-graceful-issue1407.png`.
- **hygiene:** `php -l api/planimport/index.php` clean; `events` table — only AUDIT(16) /
  LOGIN / CREATE rows, no new Error/Warning; no fatal logged after the fix.

## Discoveries while testing

- The crash is reachable **only** when `<executables>` exists at an unexpected depth; the
  same wrong-root file with **no** `<executables>` was already graceful, which guided the
  fix toward making the crashing case behave identically rather than adding special
  handling.
- Filing **#1433** (label `bug`): the legacy twin `lib/plan/planImport.php:286` crashes on
  the same `<xml>`-wrapped file with the identical `count($xml->executables->children())`
  pattern; left untouched here per one-issue scope (legacy screen also slated for removal
  via #1409).
- Code-review subagent (see AGENTS.md rule 16) **APPROVED** the diff; its single LOW note
  (phantom empty node vs null on the `->executables` property access under PHP 8.3) was
  incorporated into the NOTE comment so future fixers of #1433 see the real trap. The fix
  itself is correct for both mechanisms because the `is_null($xmlLinks)` clause catches
  the null `->children()` result.

## Evidence

Commit range `(fix)`, `(regression suite)`, `(docs + wiki)` on branch `fix/issue-1407`.
Docs screenshot: `docs/screenshots/issue-1407-planimport-graceful.png`.
Wiki page: `tmp/wiki-repo/Bugfix-Issue-1407-PlanImport-BFF-Wrong-Root-Count-Null.md`
(screenshot `planImport-graceful-issue1407.png`).