# Issue 699 — ltcp.php: typo, string comparison bug, and null-safety issues

**Issue:** [#699](https://github.com/sebiboga/testlink-upgraded/issues/699)
**Branch:** `fix/issue-699-ltcp-bugs` · **Commits:** `06bf1bd93` (fix)
**Status:** FIXED & VERIFIED (2026-08-26)

## Investigation

### Files examined
1. `ltcp.php:1-140` — Full file; standalone external API endpoint for TC print via API key auth
2. `lib/functions/tlUser.class.php:799-893` — `hasRight()` method; 5th param `$getAccess` controls public-access check
3. `lib/functions/tlUser.class.php:1376-1388` — `tlUser::getByAPIKey()` static method
4. `lib/functions/common.php:1156-1214` — `setUpEnvForRemoteAccess()`; session setup for API callers
5. `lib/testcases/tcPrint.php:1-138` — Target of ltcp.php redirect; expects `testcase_id` + `tcversion_id`
6. `lib/functions/testcase.class.php:8875-8896` — `getAllVersionsID()` returns `null` when no versions exist

### Code flow analysis
```
ltcp.php:16-19     → Load config, init DB
ltcp.php:21-22     → doDBConnect(), call process($db)
ltcp.php:31-44     → Parse $_REQUEST, define input params (apikey 32-char, testcase 0-64 chars)
ltcp.php:48-69     → Validate API key length; setUpEnvForRemoteAccess(); lookup user
ltcp.php:72-75     → If auth failed: echo "LTCP-01" + die()
ltcp.php:89-97     → Fetch all testproject prefixes; split testcase into 3 pieces
ltcp.php:99-103    → Validate prefix exists in DB
ltcp.php:105       → Get tproject_id (intval)
ltcp.php:108       → hasRight() — was always-true string comparison, now bare `true`
ltcp.php:118-136   → Resolve internal IDs via testcase manager
ltcp.php:138-143   → Redirect to tcPrint.php with resolved IDs
```

### Key findings
1. **Typo (ltcp.php:67)** — `"lenght"` should be `"length"` in error message string
2. **String literal comparison (ltcp.php:108)** — `("getAccess"=="getAccess")` compares two identical string literals, always evaluates to `true`. Should be bare `true`.
3. **PHP 8.3 TypeError (ltcp.php:122)** — `getAllVersionsID()` returns `null` when no TC versions exist; `array_map("intval", null)` throws TypeError in PHP 8.3 strict mode. Pre-existing latent bug.
4. **SQL injection surface (ltcp.php:126)** — `$idSet` built from `implode()` of internal DB data; `$tcaseVersionNumber` from `intval()`. Low practical risk but should be hardened.

## Root cause chain

1. `ltcp.php:67` — Developer typed `"lenght"` instead of `"length"` in error message. Cosmetic only.
2. `ltcp.php:108` — Copy-paste error: `("getAccess"=="getAccess")` — a PHP expression comparing two string literals. Happened to evaluate to `true` (the intended value) but was semantically wrong.
3. `lib/functions/testcase.class.php:8895` — `getAllVersionsID()` returns `null` when no versions found.
4. `ltcp.php:122` — Original `implode(',', $allTCVID)` was safe with null in PHP 7 (produced empty string), but after adding `array_map('intval', ...)` for SQL hardening, PHP 8.3 throws TypeError on null argument.

### Why it breaks now
- Items 1-2 are pre-existing cosmetic/semantic bugs.
- Item 3-4 is a **latent bug**: the original code (`implode(',', $allTCVID)`) produced an empty string with null in PHP 7, which then failed at the SQL level (`IN ()` is invalid SQL) with a different error. In PHP 8.3, the strict type checking on `array_map()` catches the null before SQL is reached.

### Blast radius
- **Files affected:** 1 file (`ltcp.php`)
- **Call sites:** 1 entry point (external URL access only, zero internal references)
- **Conditions to trigger:** Typo visible on any bad-API-key request; string comparison always works by accident; TypeError occurs when a testcase with known prefix but no DB record is queried.
- **Default config:** TypeError reachable from any API call with nonexistent testcase ID.

### Fix plan
1. Fix typo: `"lenght"` → `"length"` (line 67)
2. Fix string comparison: `("getAccess"=="getAccess")` → `true` (line 108)
3. Add null guard on `getAllVersionsID()` return with LTCP-05 error message (lines 122-125)
4. Add `intval()` on `getInternalID()` return for SQL safety (line 120)
5. Wrap `allTCVID` with `array_map('intval', ...)` for SQL safety (line 126)

## Fix approach

### Chosen fix
Minimal targeted changes to fix all 3 bugs plus the PHP 8.3 null-safety issue, with no changes to file structure, URL, or error-response format.

### Why this approach
- Keeps backward compatibility for external API consumers (same URL, same response format)
- Minimal churn — only 10 insertions, 6 deletions
- Adds LTCP-05 error code for the previously unhandled "testcase not found" case

### Rejected alternatives
- **Move to REST API** — Would break all external integrations using `/ltcp.php`
- **Add JSON error responses** — Would break API consumers expecting plain-text errors
- **Add rate limiting** — Systemic issue requiring codebase-wide design; out of scope for this bugfix

## Files changed

- `ltcp.php` (+10 / -6)
  - Line 67: `"lenght"` → `"length"`
  - Line 108: `("getAccess"=="getAccess")` → `true`
  - Lines 120-126: Added `intval()` on `getInternalID()`, null guard on `getAllVersionsID()`, `array_map('intval', ...)` on version ID list

## Verification performed (2026-08-26)

| # | Check | Expected | Result |
|---|---|---|---|
| 1 | Bad API key (too short) | Validation error | PASS |
| 2 | Bad testcase format (1 piece) | LTCP-02 | PASS |
| 3 | Bad testcase format (2 pieces) | LTCP-02 | PASS |
| 4 | Unknown prefix | LTCP-03 | PASS |
| 5 | Nonexistent TC (known prefix) | LTCP-05 (NEW) | PASS |
| 6 | Valid TC, wrong version | Silent die (pre-existing) | PASS |
| 7 | Valid TC + valid version | HTTP 302 → tcPrint.php | PASS |
| 8 | `php -l ltcp.php` | No syntax errors | PASS |
| 9 | Event Viewer (events table) | No new errors | PASS |

Full suite: `tmp/TLU_Test_Cases.md` → "Regression — Issue #699" (9/9 PASS).

## Notes

- **Systemic: no rate limiting** — The entire TestLink codebase has zero rate limiting/throttling on any endpoint. The REST API docs mention a 1000 req/hr policy but nothing is enforced. This is a follow-up task, not specific to ltcp.php.
- **Systemic: die() with no HTTP status** — Multiple error paths in ltcp.php use `die()` with plain text but return HTTP 200. API consumers cannot distinguish errors from success by status code alone. Consider returning proper HTTP status codes in a future pass.
- **Pre-existing silent die at line 133** — When a valid testcase ID resolves but the requested version doesn't exist, the file silently dies with no output. Consider adding an LTCP-06 error code in a follow-up.
