# Bugfix — Issue #1865: `searchMgmt.html` — Requirement Specification result links used `reqspec_id=` which `reqSpecView.html` ignores (every result showed the deleted-banner)

**Number:** #1865
**Status:** Fixed (verified — reproduced pre-fix in the browser + DOM, root-caused to a divergent query-parameter name on the modern simple-search link builder, fixed by emitting the documented `id=` contract + accepting `reqspec_id=` as a viewer alias, pinned with a 12-case regression suite)
**Component:** `gui/templates/search/searchMgmt.html` (producer, changed), `gui/templates/requirements/reqSpecView.html` (consumer, changed)
**Area:** Search / Requirement Specification Viewer — deep-link parameters
**Branch:** `fix/issue-1865`
**Related:** #1096 (advanced-search RS click — different defect on the sibling screen), #1866/#1867/#1869 (sibling dead-link / empty-project defects found while reproducing this one — filed, NOT fixed here)

## Symptom

Every **Requirement Specifications** result of the simple Full-Text Search screen
(`searchMgmt.html`) opened the Requirement Specification Viewer with the red banner
**"The requirement specification does not exist or has been deleted."** instead of the
specification. 100% of RS results on that screen were dead ends.

```
searchMgmt.html?tproject_id=9096&target=zephyr
  Requirement specifications
    SPEC Alpha zephyr   ->  reqSpecView.html?reqspec_id=9097&tproject_id=9096   (dead end)
    SPEC Beta zephyr    ->  reqSpecView.html?reqspec_id=9098&tproject_id=9096   (dead end)

reqSpecView.html?reqspec_id=9097&tproject_id=9096
  #deletedBanner display:block  "The requirement specification does not exist or has been deleted."

reqSpecView.html?id=9097&tproject_id=9096          (control — same spec)
  #deletedBanner display:none   "SPEC Alpha zephyr" / "DOC-A" loaded
```

## Root cause

**Producer** — `gui/templates/search/searchMgmt.html:385-389` built every RS result
link with the wrong query-parameter name:

```js
href="/gui/templates/requirements/reqSpecView.html?reqspec_id=' + x.req_spec_id + '&tproject_id=' + tprojectId
```

**Consumer** — `gui/templates/requirements/reqSpecView.html:308-320` resolves the spec
id with a two-name alias chain:

```js
SPEC_ID = parseInt(p.get('id') || p.get('req_spec_id') || 0, 10);
if (!SPEC_ID) { $('#deletedBanner').css('display','block')…; return; }   // :316-319
```

`reqspec_id` is not a member of the chain, so `SPEC_ID === 0` and the viewer took the
"no id" branch without ever calling the BFF. The data was fine — the API
`GET /api/reqspec/index.php?action=spec_view&id=9097` returned `status:ok` with the
spec; only the parameter name on the link was wrong.

**Divergence source** — the legacy opener `openLinkedReqSpecWindow()`
(`gui/javascript/testlink_library.js:1156-1166`) emits `feature_url += "?id=" + reqspec_id`,
i.e. the **`id=`** contract, which is also the documented viewer URL
(`docs/WIKI-REQUIREMENT-SPEC-VIEWER.md`: `reqSpecView.html?id=<req_spec_id>`) and the
name every other modern producer already uses (`reqSpecMgmt.html` after #1028,
`api/directlink/index.php:287`). The simple-search port was written against
`reqspec_id` — the legacy **PHP** argument name of a *different* endpoint
(`lib/requirements/reqSpecPrint.php:31,94`) — almost certainly a copy-paste confusion.

**Blast radius (grepped)** — exactly ONE modern producer of the broken parameter for
`reqSpecView.html`: `searchMgmt.html:387`. The legacy redirect stub
`lib/requirements/reqSpecView.php:16` emits `?req_spec_id=`, already accepted.

## Fix — what landed (`3401c66a1`)

Two lines, no i18n impact (no new user-facing strings):

1. **`gui/templates/search/searchMgmt.html:387`** — emit the documented contract:
   `reqSpecView.html?id=` (was `?reqspec_id=`). Restores parity with
   `openLinkedReqSpecWindow` and every other modern producer.
2. **`gui/templates/requirements/reqSpecView.html:310`** — extend the alias chain to
   `id || req_spec_id || reqspec_id || 0`, so any historical/bookmarked `reqspec_id=`
   deep link loads instead of silently showing the deleted-banner. One token in an
   existing `||` chain; the primary names keep priority (measured:
   `?reqspec_id=9097&req_spec_id=9098` resolves to 9098, no shadowing).

Why both halves: (1) alone fixes every *current* producer; (2) alone would leave
`searchMgmt.html` emitting a non-documented name and would keep the class of bug alive
for the next copy-paste. Together they close the producer and make the consumer
forgiving. Alternatives rejected: accepting `reqspec_id` only (leaves the documented
URL contract violated); rewriting the whole `renderResults` link layer (out of scope,
drive-by).

## Verification (browser + API, fixture project 9096)

| Check | Before | After |
|---|---|---|
| searchMgmt RS result hrefs (DOM) | `?reqspec_id=9097/9098&…` | `?id=9097/9098&…` |
| click-through → `#deletedBanner` | `block`, spec not loaded | **`none`**, `SPEC Alpha zephyr` + `DOC-A` |
| `?reqspec_id=9097` (old broken URL) | `block` | **`none`** (new alias) |
| `?req_spec_id=9098` | works | still works |
| `?id=9097` (control) | works | still works |
| no id param | banner `block` (`rsv.noId`) | still `block` — no false positive |
| API `spec_view&id=9097` | n/a | 200 `status:ok` |
| Event Viewer post-fix | — | 0 new Error/Warning rows |

**Code review (subagent, rule 16): APPROVE** — XSS/injection clean (`req_spec_id` is
`intval()` at `api/search/index.php:604`, same unescaped-number concat as the sibling
link builders; `esc()` on anchor text), accepted-param priority untouched, diff scope
exactly 2 files.

## Regression suite

`tmp/TLU_Test_Cases.md` → suite **"Regression — Issue #1865: searchMgmt.html Req.Spec
result links use reqspec_id= which reqSpecView.html ignores"** — **12/12 PASS**.
Gate: `TLU_REQUIRE_SUITE="Issue #1865" bash ai/verify_test_suites.sh` → 7 PASS / 0 FAIL,
exit 0 (23 → 24 suites, none lost vs merge-base).

## Sibling defects found while reproducing (filed, NOT fixed here)

| Issue | Symptom | Suspected root cause |
|---|---|---|
| [#1866](https://github.com/sebiboga/testlink-upgraded/issues/1866) | Test Suite results link `suiteView.html?tsuite_id=` which `suiteView.html:341` ignores → "No test suite id provided." | same dead-link class; emit `id=` / accept `tsuite_id` |
| [#1867](https://github.com/sebiboga/testlink-upgraded/issues/1867) | Requirement results link `reqView.html?req_id=` which `reqView.html:337` ignores → "This requirement no longer exists" | same dead-link class; emit `id=` / accept `req_id` |
| [#1869](https://github.com/sebiboga/testlink-upgraded/issues/1869) | On requirements-only projects (0 test cases) the Req.Spec/Requirement result blocks never render even though the BFF returns them | `api/search/index.php:539-565` sets `empty_testproject` unless ≥1 testcase; `searchMgmt.html:352-363` early-returns on that warning |
