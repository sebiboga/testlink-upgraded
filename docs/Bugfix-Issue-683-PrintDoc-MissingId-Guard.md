# Issue 683 — printDocument.php: stale deep link without `id` param crashes with SQL 1064

**Issue:** [#683](https://github.com/sebiboga/testlink-upgraded/issues/683)
**Branch:** `fix/issue-683-printdoc-missing-id`
**Status:** VERIFIED-FIXED (2026-08-25)

## Symptom

Opening `lib/results/printDocument.php` for any document type without the `id` request parameter
(stale bookmark / hand-built link) crashes with a raw **DB Access Error** debug page and writes
an ERROR event (SQL syntax 1064) to the Event Viewer. The debug page leaks internal file paths
(CWE-200 path disclosure).

## Root cause chain & fix approach (the HOW)

1. **`init_args()` sets `itemID` from missing `id` param.** At `printDocument.php:343`,
   `$args->itemID = $args->id;` — when `id` is absent from the request, `$args->id` is `null`
   (R_PARAMS sets missing INT_N to null), so `$args->itemID` becomes `null`.

2. **`get_subtree()` called with null node ID.** At `printDocument.php:52`,
   `$tree_manager->get_subtree($args->itemID, ...)` passes `null` as the root node ID.

3. **SQL interpolation produces empty operand.** `tree.class.php:913-932` builds
   `WHERE parent_id = {$node_id}` — with `node_id = null`, PHP interpolates an empty string,
   producing `WHERE parent_id =  AND ...` → MariaDB 1064 syntax error.

**Fix:** Added a controller-level guard in `init_args()` after computing `$args->itemID`
(inside the non-API-key branch). If `itemID` is null or ≤ 0, calls
`renderGracefulExit(lang_get('error_print_doc_missing_id'))` and exits. This follows the
same pattern as the #573 guard (lines 30-45) which validates the test plan.

Added i18n key `error_print_doc_missing_id` to `locale/en_US/strings.txt` and
`locale/en_GB/strings.txt`. Other locales fall back to English.

**Why this approach:** Controller-level guard matches the existing #573 pattern, keeps
input validation close to the source, and protects ALL doc types (test spec, req spec,
plan-based) because `get_subtree()` at line 52 is shared. Alternative rejected: guarding
inside `tree.class.php::get_subtree()` would mask legitimate programming errors elsewhere.

## Files changed

- `lib/results/printDocument.php` — added null/zero guard for `itemID` after line 343
- `locale/en_US/strings.txt` — added `$TLS_error_print_doc_missing_id`
- `locale/en_GB/strings.txt` — added `$TLS_error_print_doc_missing_id`

## Verification performed (2026-08-25)

| Check | Result |
|---|---|
| Plan-based doc without `id` | Graceful error page (heading "Document generation error") |
| Plan-based doc with valid `id` | Report renders normally |
| Plan-based doc with missing plan | Graceful error (existing #573 guard) |
| Test spec doc without `id` | Graceful error page |
| Test spec doc with valid `id` | Report renders normally |
| Req spec doc without `id` | Graceful error page |
| Req spec doc with valid `id` | Report renders normally |
| Event Viewer after all tests | No new ERROR events (log_level=1) |

Regression suite: `tmp/TLU_Test_Cases.md` → "Regression — Issue #683" (**8/8 PASS**).
