# Bugfix — Issue #1867: `searchMgmt.html` — Requirement result links used `req_id=` which `reqView.html` ignores (every result showed the does-not-exist banner)

**Number:** #1867
**Status:** Fixed (verified — reproduced pre-fix in the browser + DOM, root-caused to a divergent query-parameter name on the modern simple-search link builder, fixed by emitting the documented `id=` contract + accepting `req_id=` as a viewer alias, pinned with an 11-case regression suite)
**Component:** `gui/templates/search/searchMgmt.html` (producer, changed), `gui/templates/requirements/reqView.html` (consumer, changed)
**Area:** Search / Requirement Viewer — deep-link parameters
**Branch:** `fix/issue-1867`
**Related:** #1865 (identical defect on the Requirement-Specification block, fixed first — this issue is the sibling), #1866 (third sibling, Test Suite block, filed by the #1865 run, still open)

## Symptom

Every **Requirements** result of the simple Full-Text Search screen (`searchMgmt.html`)
opened the Requirement Viewer with the red banner **"This requirement no longer
exists"** instead of the requirement. 100% of Requirement results on that screen were
dead ends.

```
searchMgmt.html?tproject_id=1&target=zephyr
  Requirements
    REQ Alpha zephyr  ->  reqView.html?req_id=4&tproject_id=1   (dead end)

reqView.html?req_id=4&tproject_id=1
  #deletedBanner display:block  "This requirement no longer exists"   (REQ_ID === 0)

reqView.html?id=4&tproject_id=1          (control — same requirement)
  #deletedBanner display:none   "REQ Alpha zephyr" / "REQ-SM1867-1" loaded   (REQ_ID === 4)
```

## Root cause

**Producer** — `gui/templates/search/searchMgmt.html:395-396` built every Requirement
result link with the wrong query-parameter name:

```js
href="/gui/templates/requirements/reqView.html?req_id=' + x.req_id + '&tproject_id=' + tprojectId
```

**Consumer** — `gui/templates/requirements/reqView.html:336-340` resolves the
requirement id with a two-name alias chain:

```js
REQ_ID = parseInt(p.get('id') || p.get('requirement_id') || 0, 10);
if (!REQ_ID) { $('#deletedBanner').show(); return; }   // :345-348
```

`req_id` was not a member of the chain, so `REQ_ID === 0` and the viewer took the
"no id" branch without ever calling the BFF. The data was fine — the API
`GET /api/requirements/index.php/view?id=4` returned `status:ok` with `req_id:4`;
only the parameter name on the link was wrong.

**Divergence source** — the legacy opener for the requirement viewer
(`gui/javascript/testlink_library.js` `openLinkedReqWindow`) and every other modern
producer emit the **`id=`** contract (measured: `reqSpecView.html:857`,
`reqSpecListTree.html:565`, `reqCreateTestCases.html:166`, `reqCopy.html:246`,
`reqMonitors.html:179`, `reqOverview.html:396`, `searchReq.html:626`,
`execSetResults.html:2046`, `reqRevisionView.html:226`, `reqCoverageTree.html:258`,
`reqCompare.html:452`, `printReq.html:110`). The simple-search port wrote `req_id` —
the **JSON**-payload key name of its own BFF result rows (`api/searchmgmt/index.php:513`
returns `rqRows[]['req_id']`) — i.e. it confused the AJAX data-model key with the
deep-link parameter contract. Same copy-paste class of bug as #1865
(`reqspec_id` in place of `id`).

**Blast radius (grepped)** — exactly ONE modern producer of the broken parameter for
`reqView.html`: `searchMgmt.html:395`. The accepted aliases `id` / `requirement_id`
are used by all other link writers. The legacy redirect stub
`lib/requirements/reqView.php` emits `?id=` (the documented contract). No other
consumer was affected by the producer bug.

## Fix — what landed (`c11dd2076`)

Two lines, no i18n impact (no new user-facing strings):

1. **`gui/templates/search/searchMgmt.html:395`** — emit the documented contract:
   `reqView.html?id=` (was `?req_id=`). Restores parity with `openLinkedReqWindow`
   and every other modern producer.
2. **`gui/templates/requirements/reqView.html:340`** — extend the alias chain to
   `id || requirement_id || req_id || 0`, so any historical/bookmarked `req_id=`
   deep link loads instead of silently showing the deleted-banner. One token in an
   existing `||` chain; the primary names keep priority (measured:
   `?id=4&req_id=999` resolves to 4, no shadowing by the alias).

Why both halves: (1) alone fixes every *current* producer; (2) alone would leave
`searchMgmt.html` emitting a non-documented name and would keep the class of bug alive
for the next copy-paste. Together they close the producer and make the consumer
forgiving. Alternatives rejected: accepting `req_id` only (leaves the documented URL
contract violated); rewriting the whole `renderResults` link layer (out of scope,
drive-by).

## Verification (browser + API, fixture project `SM1867` from `tmp/fixtures_1867.php`)

| Check | Before | After |
|---|---|---|
| searchMgmt Requirement result href (DOM) | `?req_id=4&…` | `?id=4&…` |
| click-through → `#deletedBanner` | `block`, `REQ_ID=0`, title absent | **`none`**, `REQ_ID=4`, `REQ Alpha zephyr` loaded |
| `?req_id=4` (old broken URL) | `block` | **`none`** (new alias) |
| `?requirement_id=4` (pre-existing accepted name) | works | still works |
| `?id=4` (control) | works | still works |
| `?id=4&req_id=999` (conflict) | — | `id` wins → `REQ_ID=4` |
| no id param | banner `block` | still `block` — no false positive |
| API `GET /api/requirements/index.php/view?id=4` | n/a | 200 `status:ok`, `req_id:4` |
| Event Viewer post-fix | — | 0 new Error/Warning rows |

**Code review (subagent, rule 16): APPROVE** — XSS/injection clean (`req_id` is
`intval()` on the API side and number-concatenated into the href like every sibling
link builder; `esc()` on anchor text), accepted-param priority untouched
(`id` > `requirement_id` > `req_id`), diff scope exactly 2 files.

## Regression suite

`tmp/TLU_Test_Cases.md` → suite **"Regression — Issue #1867: searchMgmt.html
Requirement result links use req_id= which reqView.html ignores"** — **11/11 PASS**.
Gate: `TLU_REQUIRE_SUITE="Issue #1867" bash ai/verify_test_suites.sh` → 7 PASS / 0 FAIL,
exit 0. `bash ai/verify_i18n_coverage.sh` → 9/9 bundles, exit 0 (no i18n keys added).

## Sibling defect still open

| Issue | Symptom | Suspected root cause |
|---|---|---|
| [#1866](https://github.com/sebiboga/testlink-upgraded/issues/1866) | Test Suite results link `suiteView.html?tsuite_id=` which `suiteView.html:341` ignores → "No test suite id provided." | same dead-link class; emit `id=` / accept `tsuite_id` |