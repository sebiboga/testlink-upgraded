# Bugfix — Issue #981: testSpec.html Create New Test Case form never loads project keywords

## Problem

In the modernized Test Specification editor (`gui/templates/testcases/testSpec.html`),
the **Create New Test Case** form never loaded the project keywords: the KEYWORDS
section rendered only "No keywords defined for this project" even when the test
project has keywords, so a newly created test case could not be given keywords
during creation. Edit mode was not affected; create mode alone was broken.

## Root Cause

`openCreateTc()` (`testSpec.html:577`) rendered the form directly:

```js
$('#editView').html(formHtml({}, {})).show();
```

`formHtml(data, kwProject)` (`:539-547`) builds the KEYWORDS row from
`Object.keys(kwProject || {})` and prints the `tspec.noProjKeywords` message when
the map is empty — and the create path always passed an empty `{}`.

The working edit path `openEditTc()` (`:590-603`) calls `apiGet('get',
{tcase_id})`; the BFF action `get` returns `keywordsProject` built from
`$tprojectMgr->getKeywords($tprojectId)` (`api/testcases/index.php:762-772`).
Create mode has no test case id, so it never called `get` and there was **no other
fetch for project keywords** — a data-fetch omission, not a render crash.

Legacy 1.9.20 behavior: the legacy create form loads the project keyword list
unconditionally via `$tprojectMgr->get_keywords_map($argsObj->tprojectID)`
(`lib/testcases/containerEdit.php:947`, shown via `keywords_opt_transf_cfg`).

## Fix

1. **`api/testcases/index.php`** — new read-only action
   `GET ?action=keywords&tproject_id=N`, placed right after the `get` action. It
   resolves the owning test project (session fallback), gates on `mgt_view_tc`
   (the same base grant used by `context`/`tree`/`get` on this screen), and
   returns `keywordsProject` in the exact shape the `get` action already exposes:

   ```php
   $projKw = [];
   try {
       $pk = $tprojectMgr->getKeywords($tprojectId);
       if (!is_null($pk)) {
           foreach ($pk as $kwo) {
               $projKw[intval($kwo->dbID)] = strval($kwo->name);
           }
       }
   } catch (Exception $e) { $projKw = []; }
   ```

2. **`gui/templates/testcases/testSpec.html`** — `openCreateTc()` now shows a
   loading spinner, fetches the keywords, then renders:

   ```js
   apiGet('keywords', { tproject_id: ctx.tproject_id }).then(function (r) {
     $('#editView').html(formHtml({}, r.keywordsProject || {})).show();
   }).catch(function (e) {
     $('#editView').html(formHtml({}, {})).show();
     toast(e.message || t('tspec.errLoad'), true);
   });
   ```

   The error path falls back to the previous empty-map render plus a toast, so a
   fetch failure never blocks creating a test case.

Why this method: it mirrors exactly how the already-working edit path obtains the
same data (same `testproject::getKeywords()` call, same map shape, same `mgt_view_tc`
gate), keeping the two form modes consistent. Alternatives rejected: reusing the
keywords-management BFF (`api/keywords/index.php`) — it requires the
`mgt_view_key` right (the create form does not), returns a different payload
shape (usage counts etc.), and would couple the Test Spec screen to an unrelated
right.

No i18n changes: only pre-existing `tspec.*` keys are reused; the fix adds no
user-facing string.

## Files Changed

| File | Change |
|---|---|
| `api/testcases/index.php` | new `GET ?action=keywords&tproject_id=N` BFF action (39+ / 0-) |
| `gui/templates/testcases/testSpec.html` | `openCreateTc()` fetches project keywords before rendering (13+ / 5-) |
| `tmp/fixtures_981.php` | replacable CLI fixture for this repro (project BFX + keywords + suite + case) |
| `docs/screenshots/issue-981-create-no-keywords-before.png` | before screenshot (new) |
| `docs/screenshots/issue-981-create-keywords-fixed.png` | after screenshot, create form shows both keywords (new) |

Commits `c672c920a` (fix), `b22ca7038` (regression suite + fixture) on branch
`fix/issue-981`.

## Testing / Evidence

- **Primary symptom (before):** `testSpec.html?tproject_id=1` → **+ New Test Case**
  → KEYWORDS showed "No keywords defined for this project" although
  `SELECT id,keyword FROM keywords` returned `1 Smoke Test`, `2 Performance`;
  network panel showed only `action=context` and `action=tree` calls (no keyword
  fetch fired); zero JS console errors (proving a data-fetch omission, not a crash).
- **Primary symptom (after):** same click → KEYWORDS renders checkboxes
  "Smoke Test" and "Performance".
- **End-to-end save:** created "Case Smoke" with `Smoke Test` checked → row
  `testcase_keywords` (testcase_id=6, tcversion_id=7, keyword_id=1); viewer chip
  "Smoke Test" displayed; create + edit + reload clean.
- **Negative control:** project without keywords (`NoKeywords Project`, id 8) →
  create form still shows "No keywords defined for this project".
- **Edit mode unchanged:** Edit Test Case on "Case Smoke" → both keywords present,
  `Smoke Test` pre-checked (`get` action untouched).
- **Access control:** unauthenticated `action=keywords&tproject_id=1` → HTTP 401.
- **Syntax gates:** `php -l api/testcases/index.php` → clean; extracted inline JS
  → `node --check` clean.
- **Event Viewer:** `events` table shows only INFO (log_level 16) audits
  (login/project/keyword/assignment) — no new Error/Warning.
- **Code review subagent:** PASS (see review notes).

