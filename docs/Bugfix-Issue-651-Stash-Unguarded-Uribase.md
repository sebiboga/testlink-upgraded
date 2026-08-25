# Issue 651 — stashrestInterface.class.php: unguarded cfg property reads raise E_WARNING for incomplete codetracker configs

**Issue:** [#651](https://github.com/sebiboga/testlink-upgraded/issues/651)
**Branch:** `fix/issue-651-stash-cfg-guard`
**Status:** VERIFIED-FIXED (2026-08-25)

## Symptom

For a savable code-tracker config that lacks optional elements (`uribase`, or credentials),
instantiating `stashrestInterface` triggers PHP E_WARNINGs:
- `stashrestInterface.class.php:47` (`completeCfg()`): `Undefined property: stdClass::$uribase`
  + `trim(): Passing null to parameter #1 ($string) ... deprecated`
- For empty `<uribase></uribase>` tag: `Fatal TypeError: trim(): Argument #1 must be of type string, stdClass given`

The credential reads at lines 99-100 (`connect()`) were ALREADY fixed by commits
`77f37dbf7` / `e3f958049` (issue #496). Only the `uribase` read at line 47 remained unguarded.

## Root cause chain & fix approach (the HOW)

1. **`codeTrackerInterface.class.php:147`** — `$this->cfg = json_decode(json_encode($this->cfg))`
   converts SimpleXMLElement to stdClass. Missing XML elements become undefined properties;
   empty XML elements (`<uribase></uribase>`) become nested `stdClass` objects.

2. **`stashrestInterface.class.php:47`** — `trim($this->cfg->uribase,"/")` reads `uribase`
   without any `property_exists()` / `isset()` guard. PHP 8.3 fires `E_WARNING: Undefined
   property: stdClass::$uribase` and `E_DEPRECATED: Passing null to parameter`.

3. **Empty `<uribase>` tag** — SimpleXML + json_decode produces a nested `stdClass` (not
   string/null), causing `Fatal TypeError: trim(): Argument #1 must be of type string, stdClass given`.

**Fix:** Added `property_exists() + is_string()` guard around the `uribase` read in
`completeCfg()`. When `uribase` is missing/empty/non-string, `$base` is set to `""` and the
default URL construction for `uriapi`/`uriview`/`uricreate` is skipped (these are meaningless
without a valid base URL). Also added downstream guards in `getEnterCodeURL()` (for `uricreate`)
and `connect()` (for `uriapi`) to prevent warnings when `completeCfg()` legitimately skips
setting those properties.

**Why this approach:** Follows the exact same guard pattern already used in the same function
for `uriapi`/`uriview`/`uricreate` (lines 49/54/59 use `property_exists()`) and in `connect()`
for credentials (lines 108-114). The `$base !== ''` guard on downstream assignments prevents
setting meaningless defaults when there is no base URL.

## Files changed

- `lib/codetrackerintegration/stashrestInterface.class.php` — guarded `uribase` read in `completeCfg()`, added `$base !== ''` condition on downstream URL assignments, guarded `uricreate` in `getEnterCodeURL()`, guarded `uriapi` in `connect()`

## Verification performed (2026-08-25)

| Check | Result |
|---|---|
| Empty root config `<codetracker></codetracker>` | No warnings, `connected=false` |
| Partial config (username/password, no uribase) | No warnings, `connected=false` |
| Empty `<uribase></uribase>` tag | No fatal TypeError, graceful fallback |
| Full valid config with uribase | `uriapi`/`uriview`/`uricreate` built correctly from base |
| Config with explicit uriapi (not overridden) | Custom `uriapi` preserved |
| uribase without trailing slash | Trailing slash added correctly |
| Empty username/password (skip connection) | `connected=false`, no warnings |
| `getEnterCodeURL()` with full config | Returns correct URL |
| `getEnterCodeURL()` with no uribase | Returns `projects` (no crash) |
| `connect()` with credentials but no uriapi | No warning, `connected=false` |
| PHP syntax check | No syntax errors |
| Events table after all tests | Zero new E_WARNING rows |

Regression suite: `tmp/TLU_Test_Cases.md` → "Regression — Issue #651" (**12/12 PASS**).
