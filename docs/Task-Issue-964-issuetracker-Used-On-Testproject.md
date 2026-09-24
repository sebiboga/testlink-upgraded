# Task 964 — Implement 'used on test project' display in issuetrackerView edit (gap vs legacy)

**Issue:** [#964](https://github.com/sebiboga/testlink-upgraded/issues/964)
**Status:** IMPLEMENTED & VERIFIED (2026-09-24)

## The gap

Legacy edit form `gui/templates/dashio/issuetrackers/issueTrackerEdit.tpl:73-119`
(`displayUsedBy`) draws an **info icon** (`fa-info-circle`, title
`show_hide_linked_to_project`) next to the Name field that toggles a hidden
`usedByOuter`/`usedByEnvelope` block showing:

- **Used on Test Project** (`used_on_testproject`) followed by one line per
  test project linked to the tracker, or
- **Issue Tracker Not Used (Linked)** (`issuetracker_not_used_linked`) in
  italics when the tracker is linked to nothing.

The project set comes from `lib/issuetrackers/issueTrackerEdit.php:140-153`
(`initializeGui` → `issueTrackerMgr->getLinks($argsObj->id)`, which purges dead
links first then maps `testproject_id → testproject_name` via
`tlIssueTracker::getLinks()` (`class.php:516-543`). Browser test (legacy):
editing tracker id=1 listed `prj_its` under "used on testproject".

The modern screen dropped it:
- `api/issuetracker/index.php` GET `/{id}` returned the tracker row through
  `trackerToJSON()`, which defaults `link_count => 0` and `links => []` — the
  single-item route was never enriched with the linked projects (only the LIST
  route attaches `links` via `getLinkSet()` for the #963 delete tooltip).
- `gui/templates/issuetracker/issuetrackerView.html` trackerModal had only
  Name/Type/Configuration fields, no info icon, no way to see which projects
  use a tracker before editing/deleting it.

## Investigation (measured)

- Fixture: `issuetrackers` id=1 `Bugzilla Tracker` linked to `Project Alpha`
  (testproject id 1) + `Project Beta` (id 2); id=2 `GitLab CI` unlinked.
- BFF `GET /api/issuetracker/index.php/1` → `link_count:0, links:[]` (measured
  BEFORE fix) despite two `testproject_issuetracker` rows.
- Browser: edit modal on `Bugzilla Tracker` → DOM probe `hasUsedBy:false`, no
  linked-project info anywhere (measured).

## Fix

**BFF** `api/issuetracker/index.php` (single-item GET route):
- Calls `$mgr->getLinks($id)` (the same map the legacy `initializeGui` uses),
  attaches `links` (linked test-project names) + `link_count` to the item
  payload before `trackerToJSON()`.

**HTML** `gui/templates/issuetracker/issuetrackerView.html`:
- Modal: `fa-info-circle` anchor (localized tooltip
  `it.showHideLinkedToProjects`) beside the Name label + hidden
  `#usedByOuter`/`#usedByEnvelope` block (mirrors `issueTrackerEdit.tpl:167-184`).
- JS `toggleUsedBy()` — port of legacy `displayUsedBy()`: toggles collapse;
  renders `it.usedOnTestproject` + one line per linked project name (HTML-
  escaped) or italic `it.notUsedLinked` when empty (create + unlinked states).
- `editTracker()` feeds `usedByProjects` from `r.item.links`; `showCreateModal()`
  resets to `[]` (legacy create: `$gui->testProjectSet` null → not-used state);
  `hidden.bs.modal` collapses the block on close.

**i18n** — new keys `it.usedOnTestproject`, `it.notUsedLinked`,
`it.showHideLinkedToProjects` added to ALL 10 locale bundles
(en/ro/de/es/fr/it/ja/pt/ru/zh), each validated with `python3 -m json.tool`.
Locale switch verified (ro).

## Verification

- **Linked tracker** (Bugzilla Tracker): toggle → "Used on Test Project" +
  "Project Alpha" + "Project Beta"; second click collapses; reopen resets.
- **Unlinked tracker** (GitLab CI): toggle → italic "Issue Tracker Not Used
  (Linked)".
- **Create modal**: toggle → "Issue Tracker Not Used (Linked)" (create parity).
- **BFF parity**: `GET /{id}/1` → `link_count:2, links:[Project Alpha, Project
  Beta]`; `GET /{id}/2` → `link_count:0, links:[]`.
- **Locale RO**: tooltip "Arată/Ascunde (Legat de proiecte)"; linked envelope
  "Folosit în proiectul de test / Project Alpha / Project Beta"; unlinked
  "Urmăritor de probleme neutilizat (nelegat)".
- Event Viewer / `events` table: no new Error/Warning; browser console clean.

## Test cases

Appended to `tmp/TLU_Test_Cases.md` (Suite 964, 6/6 PASS); CHANGELOG updated.

**Status:** DONE — closed in #964.