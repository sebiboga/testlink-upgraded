# Bugfix: `requirement_spec_mgr::get_by_id()` builds broken SQL for specs without a revision row (Issue #1714)

**Issue**: `GET api/reqspec/index.php?action=spec_view&id=<n>` for a `req_specs`
row that has no matching `req_specs_revisions` row (an *orphaned* spec) makes
`requirement_spec_mgr::get_by_id()` build a SQL statement with an empty
`RSPEC_REV.id =` comparison → MariaDB error 1064, plus an E_WARNING row, and the
BFF answers HTTP 200 with the legacy HTML debug backtrace instead of JSON.

## Root cause

Chain (each hop `file:line`, verified on this branch):

1. `api/reqspec/index.php:1212-1218` — the `spec_view` existence probe only
   reads `req_specs` (no join to `req_specs_revisions`), so an orphan passes it.
2. `api/reqspec/index.php:1225` — `$reqSpecMgr->get_by_id($specId)` is called.
3. `lib/functions/requirement_spec_mgr.class.php:185` — `get_last_child_info()`
   returns `null`: for an orphan the revision scan computes
   `COALESCE(MAX(revision),-1)` = `-1` (`:2234-2241`), the `>= 0` branch is
   skipped and `$info` stays `null` (`:2221`, `:2263`). The same method also
   returns `null` for a non-numeric/≤0 `$id` (`:2225-2230`).
4. `:186` — `$childID = $info['id'];` reads an array offset on **null** →
   **E_WARNING**, `$childID` becomes `null`.
5. `:213` — `" AND RSPEC_REV.id = {$childID} "` interpolates the null as an
   empty string → `… RSPEC_REV.id =   AND RSPEC.id = 1` → **SQL 1064**.
6. `lib/functions/database.class.php:196-226` — `exec_query()` logs the ERROR
   event and, for a non-XHR request, prints the legacy HTML backtrace and
   `die()`s → the BFF breaks its JSON contract (HTTP 200, HTML body); for XHR it
   throws instead (#1423). Either way the route fails.

Latent since 1.9.20; not a recent regression. The orphan state only occurs when
a `req_specs` row exists without its first revision (specs are normally created
together with their first revision, `:154`). The modernized `spec_view` route
(#755) exposed it via direct URL; two earlier workarounds documented the hazard
`api/reqspec/index.php:143-161` (Refs #569/#1026) but `needOwnedSpec()` is not
used by `spec_view`.

**Blast radius**: 38 other call sites of `requirement_spec_mgr::get_by_id()`
(30 in `lib/` — reqEdit, reqExport, reqImport, reqSpecCommands, reqCommands,
print.inc.php:394, treeMenu.inc.php:2144/2234, requirement_mgr:2092/3465,
resultsReqs:760, xmlrpc v1:4159 …; 8 more in `api/`) plus 5 internal calls
(`:1108, :1412, :1904, :1964, :2080`). Every one emitted the E_WARNING + ERROR
pair when handed a spec id with no revision row.

## Fix (approach)

A single guard inside `get_by_id()`, immediately after `get_last_child_info()`:

```php
$info = $this->get_last_child_info($id, array('output' => 'credentials'));
if (!is_array($info) || !isset($info['id']) || !is_numeric($info['id'])) {
    return null;   // documented "null if query fails" contract
}
$childID = $info['id'];
```

Why this method: the defect is in the data layer, so fixing the class protects
all 39 external + 5 internal callers at once — patching only `api/reqspec`
(or `intval()`-ing the child id, or joining revisions into every caller's
probe) would leave the other paths exposed. Returning `null` matches the
method's documented contract ("null if query fails" / no data) and every caller
already treats `null` as "not found" (e.g. `api/reqspec/index.php:1226`).
The guard also closes the second latent interpolation on the same path
(`AND RSPEC.id = {$id}`, `:227`) because `get_last_child_info()` only returns an
array once `$id` has passed its `is_numeric($id) && intval($id) > 0` gate.

**Rejected alternatives**: `@get_by_id()`/try-catch in the BFF only (38 call
sites stay exposed); probing plus an API-only guard (duplicates the guard per
caller); `intval($childID)` (queries revision 0, still leaves the raw-`$id` hole).

## Files changed

- `lib/functions/requirement_spec_mgr.class.php` — `get_by_id()`: null/validity
  guard on the resolved revision info (+14/-2 lines, guard + comment only).
- `docs/screenshots/issue-1714-spec-view-healthy.png` — healthy spec after fix.
- `docs/screenshots/issue-1714-spec-view-orphan-404.png` — orphan deep link after
  fix (screen shows the 404 message instead of a broken HTML response).

## Verification

- `php -l` passes.
- Regression matrix (6/6 PASS, see `tmp/TLU_Test_Cases.md` "Regression — Issue
  #1714"): orphan → 404 JSON + 0 event rows (was 200 HTML + E_WARNING + ERROR
  1064); nonexistent/invalid ids unchanged (404 / 400); healthy spec renders in
  API and UI; Event Viewer clean.
- No user-facing strings added → no i18n bundle touched.