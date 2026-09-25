# Task 974 — "Used on Test Project" block in the modern Code Tracker edit modal

**Issue:** [#974](https://github.com/sebiboga/testlink-upgraded/issues/974)
**Status:** IMPLEMENTED & VERIFIED (2026-09-25) — branch `task/issue-974`
**Screen:** `gui/templates/codetracker/codetrackerView.html`
**BFF:** `api/codetracker/index.php`

## The gap

In TestLink 1.9.20 the code-tracker **edit** page carried an `fa-info-circle` anchor
next to the Name label. Clicking it revealed which test projects the tracker is
linked to — or the note *"Code Tracker Not Used (Linked)"* when it is linked to none.
Loading the page also **deleted orphaned link rows** left behind by a deleted test
project.

| Step | Legacy code | What it does |
|---|---|---|
| 1 | `lib/codetrackers/codeTrackerEdit.php:150-157` | `$dummy = $commandMgr->codeTrackerMgr->getLinks($argsObj->id, ['getDeadLinks' => true]);` then `unlink($id, $key)` for each — *"just to fix erroneous test project delete"* |
| 2 | `lib/codetrackers/codeTrackerEdit.php:158-159` | `$gui->testProjectSet = $commandMgr->codeTrackerMgr->getLinks($argsObj->id);` — map `testproject_id => testproject_name` |
| 3 | `lib/functions/tlCodeTracker.class.php:471-500` | `getLinks()` = `testproject_codetracker LEFT OUTER JOIN nodes_hierarchy`; with `getDeadLinks` it keeps only rows whose project node is `NULL` |
| 4 | `gui/templates/dashio/codetrackers/codeTrackerEdit.tpl:163-165` | `<a href="javascript:displayUsedBy('usedByEnvelope')"><i class="fas fa-info-circle" title="{$labels.show_hide_linked_to_project}"></i></a>` |
| 5 | `codeTrackerEdit.tpl:174-180` | hidden `#usedByOuter` row wrapping `#usedByEnvelope` |
| 6 | `codeTrackerEdit.tpl:73-116` | `displayUsedBy()` toggles the row; renders `<b>Used on Test Project</b>` + one `|escape`d name per line, else `<b><i>Code Tracker Not Used (Linked)</i></b>` |
| 7 | `lib/codetrackers/codeTrackerEdit.php:180-184` | the whole edit controller is gated on `codetracker_management` |
| 8 | `locale/en_US/strings.txt:3679,3866,3867` | `Used on Test Project`, `Show/Hide (Linked to Project)`, `Code Tracker Not Used (Linked)` |

The 2.0.1 rewrite dropped steps 1–6: the modern edit modal showed only
Name / Type / config / Active / Save. The BFF called `getLinks($id)` but kept **only**
`count($links)` and dropped the names (`link_count` is what the delete gating needs,
#971) — so the data stopped at the API boundary and the used-by block had nothing to
render.

The sibling screens already had this exact feature: `api/issuetracker/index.php:266-291`
+ `gui/templates/issuetracker/issuetrackerView.html:83-88,346-364` (issue #964) and
`reqMgrSystemView.html` (`rms.usedOnTestProject`). The code-tracker screen was the last
one without it.

## Implementation

### BFF — `api/codetracker/index.php`

- New helper `attachLinks($mgr, $id, &$item, $purgeDead)` (lines 118-156) — a faithful
  port of `initializeGui()`:
  - `$purgeDead` → `getLinks($id, ['getDeadLinks' => true])` + `unlink($id, $tpid)`
    for each orphan, so the used-by list never contains a `NULL` name and
    `link_count` is never inflated;
  - then `links[] = testproject_name` for each remaining row, and
    `link_count = count(links)`.
- `GET /{id}` calls `attachLinks(..., true)` — this is the request the edit modal makes,
  i.e. exactly where legacy ran the purge.
- `POST` (create), `PUT` (update) and `DELETE` call `attachLinks(..., false)` so a write
  response is never misleading (a tracker that has links reports them; the deleted one
  reports its pre-delete state, which is what the legacy delete form showed to explain
  a refusal).
- `trackerToJSON()` always emits a `links` key. The **list** route keeps only
  `link_count` (from `getAll('add_link_count')`): the grid needs no names and resolving
  them per row would be an N+1 query. Documented in the code.
- Values are returned **raw** — escaping stays in the screen (JSON API, issue #1581).

### Screen — `gui/templates/codetracker/codetrackerView.html`

- `#btnUsedBy` (`fa-info-circle`, `data-i18n-title="ct.showHideLinkedToProject"`) next to
  the Name input + hidden `#usedByOuter` / `#usedByEnvelope`, mirroring
  `codeTrackerEdit.tpl:163-180`.
- `toggleUsedBy()` — port of `displayUsedBy()`: collapses/expands, renders the bold
  header plus one escaped name per line, or the italics "not used (linked)" note.
  Guarded by `if (!canManage) return;` because legacy gated the whole edit page on
  `codetracker_management`.
- `usedByProjects[]` is filled from `r.item.links` in `editTracker()` and reset to
  `[]` in `showCreateModal()` (legacy only built `$gui->testProjectSet` when `id > 0`).
  Both open paths collapse the block, so the envelope never shows a stale list.
- Every project name goes through the existing `esc()` helper — a project named
  `<img src=x onerror=…>` renders as inert text.

### i18n

3 new keys in **all 10** locale bundles (`en, de, es, fr, it, ja, pt, ro, ru, zh`),
inserted alphabetically inside the existing `ct.*` group (+3 lines per file, no
reformat):

| Key | en | source |
|---|---|---|
| `ct.usedOnTestproject` | Used on Test Project | `strings.txt:3679` |
| `ct.notUsedLinked` | Code Tracker Not Used (Linked) | `strings.txt:3867` |
| `ct.showHideLinkedToProject` | Show/Hide (Linked to Project) | `strings.txt:3866` |

## Verification

Test suite: `tmp/TLU_Test_Cases.md` → **974.1–974.12, 12/12 PASS** (tooltip, linked
state, toggle on/off/on, unlinked state, create-modal state, Romanian locale, XSS in a
project name, BFF `links`, dead-link purge, grid + write-route regression, syntax
gates, Event Viewer). `events` gained **1** row (a normal `log_level=16` activity from
the audited edit-session) and **0** rows with `log_level IN (1,2)`.

## Side finding (not fixed here)

Seeding a `codetrackers` row whose `type` is not in `tlCodeTracker::$systems` makes
`GET /api/codetracker/index.php` return **HTTP 500 with an empty body**, blanking the
whole grid: `getImplementationForType()`
(`lib/functions/tlCodeTracker.class.php:115-126`) reads `$this->systems[$codeTrackerType]`
with no `isset` guard and returns the literal class name `"Interface"`, so
`$impl::checkEnv()` fatals. One corrupt row kills the screen for every user. Legacy
could not hit it (its type domain only offers valid types) and it is outside this issue
— filed as a separate `bug` issue.
