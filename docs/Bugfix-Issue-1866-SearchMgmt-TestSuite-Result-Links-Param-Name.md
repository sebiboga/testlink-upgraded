# Bugfix — Issue #1866: `searchMgmt.html` — Test Suite result links used `tsuite_id=` which `suiteView.html` ignores (every result showed the no-id banner)

**Number:** #1866
**Status:** Fixed (verified — reproduced pre-fix in the browser + DOM, root-caused to a divergent query-parameter name on the modern simple-search link builder, fixed by emitting the documented `id=` contract + accepting `tsuite_id=` as a viewer alias, pinned with a 12-case regression suite)
**Component:** `gui/templates/search/searchMgmt.html` (producer, changed), `gui/templates/testcases/suiteView.html` (consumer, changed)
**Area:** Search / Test Suite Viewer — deep-link parameters
**Branch:** `fix/issue-1866`
**Related:** #1865 (Req. Specification block, fixed first), #1867 (Requirement block, fixed second) — this is the third and last sibling of the same dead-link class

## Symptom

Every **Test suites** result of the simple Full-Text Search screen (`searchMgmt.html`)
opened the Test Suite Viewer with the banner **"No test suite id provided."** instead of
the suite. 100% of Test Suite results on that screen were dead ends.

```
searchMgmt.html?tproject_id=1&target=zephyr
  Test suites
    Suite Alpha zephyr -> suiteView.html?tsuite_id=2&tproject_id=1   (dead end)

suiteView.html?tsuite_id=2&tproject_id=1
  body "No test suite id provided."     (SUITE_ID === 0)

suiteView.html?id=2&tproject_id=1       (control — same suite)
  "Test Suite Viewer - SearchMgmt TS Fixture" loaded   (SUITE_ID === 2)
```

## Root cause

**Producer** — `gui/templates/search/searchMgmt.html:380-381` built every Test Suite
result link with the wrong query-parameter name:

```js
href="/gui/templates/testcases/suiteView.html?tsuite_id=' + x.id + '&tproject_id=' + tprojectId
```

**Consumer** — `gui/templates/testcases/suiteView.html:341` resolves the suite id with a
three-name alias chain:

```js
SUITE_ID = parseInt(p.get('id') || p.get('testsuite_id') || p.get('item_id') || 0, 10);
if (!SUITE_ID) { showError(TLi18n.t('suvw.noId')); return; }   // :354-356
```

`tsuite_id` (plural `suite`) is not a member of the chain, so `SUITE_ID === 0` and the
viewer took the "no id" branch without ever calling the BFF. The data was fine — the BFF
`api/searchmgmt/index.php:487-491` returns the correct `id` (`'id' => intval($rec['id'])`);
only the parameter name on the link was wrong.

**Divergence source** — the legacy opener and every other modern producer emit the
**`id=`** contract (measured: `gui/templates/execute/execSetResults.html:1756`
`openSuiteWindow`, `gui/templates/search/searchAdvancedView.html:661`,
`gui/templates/testcases/suiteEdit.html:350`, `api/directlink/index.php:371`
documented contract `suiteView.html?id=<id>`). The simple-search port wrote `tsuite_id` —
a name not even accepted by its own consumer. Same copy-paste class of bug as #1865
(`reqspec_id` in place of `id`) and #1867 (`req_id` in place of `id`).

**Blast radius (grepped)** — exactly ONE modern producer of the broken parameter for
`suiteView.html`: `searchMgmt.html:380`. The accepted aliases `id` / `testsuite_id` /
`item_id` are used by all other link writers. The `tsuite_id` token elsewhere in the repo
is unrelated (result-table columns, the `reqTcAssign.php` legacy shim). No other consumer
was affected by the producer bug.

## Fix — what landed

Two commits, no i18n impact (no new user-facing strings):

1. **`9721219d8`** — **`gui/templates/search/searchMgmt.html:380`** — emit the documented
   contract: `suiteView.html?id=` (was `?tsuite_id=`). Restores parity with
   `openSuiteWindow` and every other modern producer.
2. **`85ed8aff3`** — **`gui/templates/testcases/suiteView.html:341`** — extend the alias
   chain to `id || testsuite_id || item_id || tsuite_id || 0`, so any historical/bookmarked
   `tsuite_id=` deep link loads instead of silently showing the no-id banner. One token in
   an existing `||` chain; the primary names keep priority (measured: `?id=2&tsuite_id=999`
   resolves to 2, no shadowing by the alias).

Why both halves: (1) alone fixes every *current* producer; (2) alone would leave
`searchMgmt.html` emitting a non-documented name and would keep the class of bug alive for
the next copy-paste. Together they close the producer and make the consumer forgiving.
Alternatives rejected: accepting `tsuite_id` only (leaves the documented URL contract
violated); rewriting the whole `renderResults` link layer (out of scope, drive-by).

## Verification (browser + API, fixture project 1 `SearchMgmt TS Fixture` from `tmp/fixtures_1866.php`)

| Check | Before | After |
|---|---|---|
| searchMgmt Test Suite result href (DOM) | `?tsuite_id=2&…` | `?id=2&…` |
| click-through → viewer | `SUITE_ID=0`, "No test suite id provided." | **`SUITE_ID=2`**, `Test Suite Viewer - SearchMgmt TS Fixture` loaded |
| `?tsuite_id=2` (old broken URL) | `SUITE_ID=0`, banner | **loaded** (new alias) |
| `?testsuite_id=2` (pre-existing accepted name) | works | still works |
| `?item_id=2` (pre-existing accepted name) | works | still works |
| `?id=2` (control) | works | still works |
| `?id=2&tsuite_id=999` (conflict) | — | `id` wins → `SUITE_ID=2` |
| no id param | banner | still banner — no false positive |
| BFF `GET /api/searchmgmt/…&target=zephyr` | testsuite row id=2 | unchanged |
| Event Viewer post-fix | — | 0 new Error/Warning rows (still 3 audit rows, log_level=16) |

**Code review (subagent, rule 16): APPROVE** — XSS/injection clean (`x.id` is a numeric
node id number-concatenated into the href like every sibling link builder; `esc()` on
anchor text), accepted-param priority untouched (`id` > `testsuite_id` > `item_id` >
`tsuite_id`), diff scope exactly 2 files + the suite file.

## Regression suite

`tmp/TLU_Test_Cases.md` → suite **"Regression — Issue #1866: searchMgmt.html Test Suite
result links use tsuite_id= which suiteView.html ignores"** — **12/12 PASS**.
Gate: `TLU_REQUIRE_SUITE="Issue #1866" bash ai/verify_test_suites.sh` → 7 PASS / 0 FAIL,
exit 0. `bash ai/verify_i18n_coverage.sh` → no i18n keys added (not touched).

## Sibling defects (both already fixed)

| Issue | Symptom | Status |
|---|---|---|
| [#1865](https://github.com/sebiboga/testlink-upgraded/issues/1865) | Req.Spec results linked `reqSpecView.html?reqspec_id=` | Fixed `fc58fe36d` |
| [#1867](https://github.com/sebiboga/testlink-upgraded/issues/1867) | Requirement results linked `reqView.html?req_id=` | Fixed `c11dd2076` |
