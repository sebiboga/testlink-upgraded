# Bugfix — Issue #1869: `searchMgmt.html` — `empty_testproject` early-return hides Req.Spec/Requirement results on requirements-only projects (BFF returns them)

**Number:** #1869
**Status:** Fixed (verified — reproduced pre-fix in browser + BFF contract, root-caused to a TC-only warning flag in the searchmgmt BFF, fixed by gating the warning on the whole result set, pinned with a 10-case regression suite)
**Component:** `api/searchmgmt/index.php` (BFF, changed)
**Area:** Search / Full-Text Search (simple) — warning contract on requirements-only projects
**Branch:** `fix/issue-1869-searchmgmt-empty-reqspecs`
**Related:** #1865 (same screen — RS result link param; sibling filed from that run), #1866/#1867 (sibling dead-link defects on the same screen, still open)

## Symptom

On a test project that has **requirement specifications but zero test cases**, the simple
Full-Text Search screen (`searchMgmt.html`) never showed the Requirement Specifications /
Requirements result blocks — even when the BFF response contained matching rows. The results
panel rendered only the "This test project has no test cases yet" notice.

```
searchMgmt.html?tproject_id=9106&target=zephyr          (project REQONLY1869, 0 test cases)
  BFF  GET /api/searchmgmt/index.php?action=results&...
    -> {"warning":"empty_testproject","count":4,
        "reqspecs":[SPEC Alpha zephyr, SPEC Beta zephyr],
        "requirements":[REQ-ZR-A1, REQ-ZR-B1],
        "testcases":[]}                       <-- rows ARE in the payload
  DOM  #resultsWrap .res-block count = 0     <-- rows are DISCARDED
       innerText = "This test project has no test cases yet\nCreate a test case first..."
```

## Root cause

Two cooperating defects, one producer + one consumer:

1. **Producer** — `api/searchmgmt/index.php:432` initialised `$emptyTestProject = true`;
   it was cleared only at `:454-457` when `getTestCaseIDSet()` returned ≥1 test case.
   The warning emission at `:524-529` then used that **test-case-only** flag:

   ```php
   $total = count($tcRows) + count($tsRows) + count($rsRows) + count($rqRows);
   $warning = '';
   if ($emptyTestProject) {                 // <-- ignores rsRows/rqRows/tsRows
       $warning = 'empty_testproject';
   } elseif ($total == 0) {
       $warning = 'no_records_found';
   }
   ```

   `$total` was computed correctly (so `count=4` and the rows shipped), but the warning
   contradicted the payload: `warning=empty_testproject` **and** non-empty `reqspecs[]`.

2. **Consumer** — `gui/templates/search/searchMgmt.html:352-363` treats
   `warning === 'empty_testproject'` as "project is empty, nothing to show" and
   **early-returns after writing only the notice**, discarding `r.reqspecs` /
   `r.requirements` / `r.testsuites` that are already in the payload.

**Why legacy did not have this bug** — `lib/search/search.php:151-174` builds the RS/RQ
result tables **regardless** of `$emptyTestProject` (the flag only gates the TC search at
`:113-117`), and `:145,156,171` set `$gui->warning_msg = ''` unconditionally before
appending each table section — so in the legacy full-text flow the `empty_testproject`
warning was effectively never displayed whenever any table was built. Legacy showed the
reqspec hits on requirements-only projects.

**Note on the issue body's cited file** — the issue cites `api/search/index.php:539`;
`searchMgmt.html` actually calls `/api/searchmgmt/index.php` (`searchMgmt.html:150`).
`api/search/index.php` shares the TC-only flag pattern (`:539,561-565,631-632`), but its
consumers (`searchView.html`, `searchAdvancedView.html`, `searchQuickView.html`) show the
warning in a warnBox **alongside** the rendered rows — no results are lost there — so it
was left untouched (minimal-fix rule; no symptom on those screens).

**Blast radius** — `api/searchmgmt/index.php` has exactly one consumer:
`gui/templates/search/searchMgmt.html` (grep: `var API = '/api/searchmgmt/index.php'`).

## Fix — what landed

One file, `api/searchmgmt/index.php` — the warning emission is now gated on `$total`
(all four dimensions) instead of the TC-only flag:

```php
$warning = '';
if ($total == 0) {
    $warning = $emptyTestProject ? 'empty_testproject' : 'no_records_found';
}
```

**Why this method (alternatives rejected)**

- *Client-side candidate (b)* — render the blocks even when the warning is set: fixes the
  symptom but leaves the self-contradictory payload (`warning` + `count>0` + rows) in the
  BFF contract for any other/future consumer. The producer fix keeps the contract honest
  and needed **zero** client change — the existing early-return at
  `searchMgmt.html:352-363` then fires only when `total==0`, which is exactly when the
  notice is the right UI.
- *Candidate (a) as literally written* — clear `$emptyTestProject` when ANY dimension
  returns rows: same outcome, but re-purposing a flag named "empty test project" (which
  still means "0 test cases") for "has any hits" invites the next reader to misuse it.
  Gating the **warning emission** on `$total == 0` keeps both flags honest.
- *Change `api/search/index.php` too* — different consumers, no lost results, out of scope.

No i18n impact (no new user-facing strings). No client change.

## Verification (browser + curl, admin/admin)

| # | Case | Expected | Actual |
|---|---|---|---|
| 1 | Req-only project 9106 + term `zephyr` (primary) | Req.Spec/Req blocks render, no early-return notice | BFF `{warning:"", count:4, rs:2, rq:2}`; browser "4 match(es)" + both blocks; `.res-block` = 2 |
| 2 | Req-only + non-matching term | `warning=empty_testproject`, notice (nothing to show) | `{warning:"empty_testproject", count:0}` |
| 3 | Project WITH test cases + non-matching term | `warning=no_records_found` | `{warning:"no_records_found", count:0}` (fixture 92172) |
| 4 | Project WITH test cases + matching term | blocks render, no warning | `{warning:"", count:1, name:"zephyr login case"}` |
| 5 | Requirements-disabled project, 0 TCs | `warning=empty_testproject`, no req arrays | `{warning:"empty_testproject", count:0, rs:0, rq:0}` (fixture 92173) |
| 6 | Req.Spec click-through `id=` (#1865 regression) | spec loads, no deleted-banner | `#deletedBanner display:none`, infoLine `#9206 · Revision r1` |
| 7 | Event Viewer / `events` table | no new Error/Warning from the search flow | search re-runs added 0 rows |
| 8 | `php -l api/searchmgmt/index.php` | clean | `No syntax errors detected` |
| 9 | Browser console on the search screen | clean | no messages |

Screenshots: `docs/screenshots/issue-1869-searchmgmt-empty-testproject-hides-reqspecs-before.png`
(pre-fix — only the empty-project notice) and `...-after.png` (post-fix — 4 matches with
Req.Spec + Requirement blocks).

Regression suite: `tmp/TLU_Test_Cases.md` → "Regression — Issue #1869" — **10/10 PASS**,
gate `TLU_REQUIRE_SUITE="Issue #1869" bash ai/verify_test_suites.sh` exit 0.

## Fixture notes (for whoever re-runs this)

- `tmp/fixtures_1869.sql` — project 9106 REQONLY1869, requirements-enabled via the
  **serialized `testprojects.options` blob** (`requirementsEnabled=1`). The legacy
  `option_reqs` column alone does NOT flip `reqEnabled` in the BFF init path —
  `testproject::getOptions()` reads the blob and falls back to defaults
  (`requirementsEnabled=0`) when the blob is empty/NULL.
- `tmp/fixtures_1869.sql` also inserts the **`nodes_hierarchy` rows for the
  `req_specs_revisions`** (`node_type_id=11`). `requirement_spec_mgr::get_last_child_info()`
  joins `req_specs_revisions` to `nodes_hierarchy` on `NH.id = CHILD.id AND NH.parent_id =
  spec_id`; raw-SQL fixtures that skip those nodes make `reqSpecView.html` emit a 1064 SQL
  error event + the deleted-banner (fixture artifact, not a product defect).
- `tmp/fixtures_1869b.php` — companion projects for the regression matrix: 92172
  TCCASE1869 (has a test case, reqs disabled) and 92173 NOREQ1869 (no TCs, reqs disabled).
