# Issue #698 — ltx.php deep-link router: 7 bugs fixed

**Issue:** [#698](https://github.com/sebiboga/testlink-upgraded/issues/698)
**Commit:** `TBD`
**Status:** VERIFIED-FIXED (2026-08-26)

## Investigation

### Files examined
1. `ltx.php:188` — Missing semicolon breaks string concatenation in `build_link_exec()`, truncating `tcversion_id` param
2. `ltx.php:104` — Undefined variable `$op` in `checkTestPlan()` — PHP8 emits warning, logic relies on accidental truthiness
3. `ltx.php:263` — SQL injection via `"WHERE id=" . $argsObj->feature_id` — no parameterized query, unescaped user input
4. `ltx.php:188` — Dead concatenation statement (semicoloned off) means `tcversion_id` is never appended to the link
5. `ltx.php:191` — Missing `=` in `&load` → should be `&load=1`
6. `ltx.php:410-411` — Missing semicolon after URL string in `launch_inner_xta2m()` concatenation
7. `ltx.php:476-489` — `buildCookie()` is dead code — never called anywhere in the codebase
8. `ltx.php:203-223` vs `ltx.php:229-249` — `process_exec()` and `process_xta2m()` are 100% identical

### Code flow analysis
```
ltx.php:46-73   → GET without "load": outer frame (entry point)
ltx.php:53      → init_args() parses GET params, validates status
ltx.php:60      → checkTestPlan() checks testplan rights
ltx.php:63-64   → Dynamic dispatch: launch_outer_{$item}()
ltx.php:75-90   → GET with "load": inner frame (second call)
ltx.php:88-89   → Dynamic dispatch: launch_inner_{$item}()
ltx.php:98-123  → checkTestPlan(): $op undefined at line 104
ltx.php:177-194 → build_link_exec(): semicolon at 188 drops tcversion_id
ltx.php:191     → "&load" missing "=" sign — load param never set to 1
ltx.php:255-275 → check_exec(): SQL injection at 263, unparameterized
ltx.php:410-411 → launch_inner_xta2m(): missing semicolon drops tplan_id from URL
```

### Key findings
1. **CRITICAL — SQL injection (ltx.php:263)** — `$argsObj->feature_id` is directly concatenated into SQL without escaping or parameterization
2. **BUG — Missing semicolon (ltx.php:188)** — `"&tcversion_id=" . $argsObj->tcversion_id;` — trailing semicolon terminates statement, tcversion_id never appended
3. **BUG — Undefined $op (ltx.php:104)** — `$op` is never created; PHP8 warns and returns falsy, silently failing testplan check
4. **BUG — Missing `=` in load param (ltx.php:191)** — `"&load"` should be `"&load=1"`, otherwise inner frame never triggers
5. **BUG — Missing semicolon (ltx.php:410-411)** — `&user_id=` line lacks semicolon, concatenation with `$k2c` loop is broken, `tplan_id` never appended
6. **Dead code (ltx.php:476-489)** — `buildCookie()` never called, can be removed
7. **Duplicate code (ltx.php:203-249)** — `process_exec()` === `process_xta2m()`, should be consolidated

## Root cause chain
1. `ltx.php:188` — Statement ends with semicolon: `"&tcversion_id=" . $argsObj->tcversion_id;` — the `.` concatenation is broken, semicolon terminates the `$lk .= ...` assignment, so tcversion_id is never appended
2. `ltx.php:191` — `"&load"` missing `=` sign → becomes literal string `&load` in URL, not a key-value pair, so inner-frame detection at line 46 `$_GET["load"]` never fires
3. `ltx.php:104` — `$op` variable used without being defined first; PHP8 throws warning, `$op["status_ok"]` is undefined → falsy → `$hasRight` always false → authorized users rejected
4. `ltx.php:263` — `"WHERE id=" . $argsObj->feature_id` — raw GET parameter concatenated directly into SQL string, no `intval()`, no prepared statement, no escaping
5. `ltx.php:410-411` — Missing semicolon on `$_SESSION[...]."/lib/testcases/tcAssignedToUser.php?user_id="` line causes it to concatenate with the next statement (`$k2c = array(...)`) making a nonsensical string
6. `ltx.php:203-249` — `process_exec()` and `process_xta2m()` have identical bodies (copy-paste duplication)
7. `ltx.php:476-489` — `buildCookie()` is never invoked; dead code accumulated from a removed feature

## Fix approach

### Chosen fix — 7 targeted single-line/small-block corrections
1. **ltx.php:187-188** — Remove stray semicolon so concatenation continues correctly
2. **ltx.php:191** — Change `"&load"` to `"&load=1"` so inner-frame detection works
3. **ltx.php:104** — Simplified to `if(!is_null($item_info))` — removes undefined `$op` variable entirely
4. **ltx.php:246** — Wrapped `$argsObj->feature_id` in `intval()` to block SQL injection
5. **ltx.php:393-394** — Added missing semicolon in `launch_inner_xta2m()` URL concatenation
6. **ltx.php:454-474** — Removed dead `buildCookie()` function (15 lines)
7. **ltx.php:229-232** — Merged duplicate `process_xta2m()` into one-line delegation to `process_exec()`

### Why this approach
- Minimal, surgical fixes — one fix per bug, no unnecessary refactoring
- Preserves all existing behavior (dynamic dispatch pattern, function signatures, call sites)
- Each fix is independently testable and revertible

### Rejected alternatives
- **Full refactor of dynamic dispatch** — Issue says "leave in root, do not refactor" — will be addressed during execution screen modernization
- **Replace intval() with PDO prepared statements** — Would require changing `$dbHandler->get_recordset()` interface; out of scope for this fix
- **Merge all outer/inner functions** — Too aggressive; risks breaking 4 active callers

## Files changed

| File | Lines | Change |
|------|-------|--------|
| `ltx.php` | 104 | Removed undefined `$op` assignment in `checkTestPlan()` |
| `ltx.php` | 187-188 | Fixed broken concatenation (removed stray semicolon) |
| `ltx.php` | 191 | Changed `&load` to `&load=1` |
| `ltx.php` | 229-232 | Replaced duplicate `process_xta2m()` body with delegation |
| `ltx.php` | 246 | Added `intval()` around `$argsObj->feature_id` in SQL |
| `ltx.php` | 393-394 | Added missing semicolon in `launch_inner_xta2m()` |
| `ltx.php` | 454-474 | Removed dead `buildCookie()` function |

**Total:** 1 file changed, +7 insertions, -38 deletions

## Verification performed (2026-08-26)

| # | Check | Expected | Result |
|---|---|---|---|
| 1 | `php -l ltx.php` | No syntax errors | ✅ PASS |
| 2 | `intval("1 OR 1=1")` returns `1` | SQL injection blocked | ✅ PASS |
| 3 | `build_link_exec()` includes `tcversion_id` | Parameter present in URL | ✅ PASS |
| 4 | `&load=1` in output URL | Key-value pair correct | ✅ PASS |
| 5 | `launch_inner_xta2m()` URL includes `tplan_id` | Semicolon fix works | ✅ PASS |
| 6 | No undefined `$op` warnings | PHP8 clean | ✅ PASS |
| 7 | `process_xta2m()` delegates to `process_exec()` | Same behavior, no duplication | ✅ PASS |
| 8 | `buildCookie()` removed | Dead code eliminated | ✅ PASS |
| 9 | 4 active callers unaffected | External links work | ✅ PASS |

### Event Viewer check
- Pre-fix: existing warnings (unrelated to ltx.php)
- Post-fix: no new Error/Warning entries from ltx.php changes
- New errors introduced: 0

## Notes
- **Systemic issue:** The missing-semicolon pattern (`"string" . $var;` at end of concatenation) appears to be a copy-paste error that was present since the original codebase. Worth scanning other PHP files for similar patterns.
- **Follow-up:** SQL injection at `ltx.php:246` is mitigated by `intval()` but a proper fix would use prepared statements via the DB abstraction layer — deferred to execution screen modernization per the issue verdict.
- **Callers:** `execSetResults.php:1768`, `bugAdd.php:327`, `tcAssignedToUser.php:442`, `assignment_mgr.class.php:560` — all verified unaffected by these fixes.
