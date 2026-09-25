# Task 971 — Complete linked Code Tracker delete gating and detail link-count parity

**Issue:** [#971](https://github.com/sebiboga/testlink-upgraded/issues/971)
**Status:** IMPLEMENTED & VERIFIED (2026-09-25) — branch `task/issue-971`

## Scope and legacy behavior

The requested gap was verified against the legacy screen before implementation. `lib/codetrackers/codeTrackerView.php:23-24` requests `getAll(['output' => 'add_link_count', 'checkEnv' => true])`; `tlCodeTracker::getAll()` initializes `link_count` to zero and replaces it with the count from `testproject_codetracker`; the legacy template renders the delete action only when `link_count == 0` (`gui/templates/dashio/codetrackers/codeTrackerView.tpl:43-80`). The legacy delete path also refuses deletion when a tracker is linked.

The exact UI gap described by the issue had already landed in inherited commit `3ebd8ebb90864b489cf966ceff35e3ed22fe3473` for #970: the modern list BFF returns `link_count`, and `gui/templates/codetracker/codetrackerView.html:219-251` hides the trash icon for a linked row. The remaining inconsistency was the detail BFF route: it called `getByID()` and serialized `trackerToJSON()`'s fallback `link_count` of zero even for a linked tracker.

## Implementation

`api/codetracker/index.php:193-201` now:

1. Reads the requested tracker with `getByID($id)`.
2. Uses the existing `getLinks($id)` relation query and counts its returned test-project links.
3. Sets the integer `link_count` on the detail item before serialization.

The list response, client-side management/edit/delete gating, and server-side linked-delete guard were left unchanged. No new user-facing strings were introduced, so no i18n bundle was modified.

The resulting contract is:

- `GET /api/codetracker/index.php` returns one `link_count` per tracker.
- `GET /api/codetracker/index.php/{id}` returns the same count for the selected tracker.
- The modern UI permits a manager to see Edit for every tracker, but shows Delete only for `link_count === 0`.
- A direct `DELETE /api/codetracker/index.php/{id}` remains a server-side HTTP 400 refusal when a test-project link exists.

## Verification

Fixture used:

- `testprojects.id=2` / `nodes_hierarchy.id=2`: `Linked Project`
- `codetrackers`: id 1 `Stash Tracker`, id 2 `GitHub TestLink`, id 3 `Linked Stash`
- `testproject_codetracker`: `(testproject_id=2, codetracker_id=3)`

`Suite 971` in `tmp/TLU_Test_Cases.md` records 6/6 PASS:

1. List link counts: 0, 1, 0.
2. Detail link counts: id 1 = 0 and id 3 = 1.
3. Modern linked/unlinked delete-icon parity.
4. Legacy linked/unlinked delete-cell parity.
5. HTTP 400 response and unchanged row for direct linked deletion.
6. PHP lint, clean modern console, and Event Viewer assessment.

`php -l api/codetracker/index.php` returned `No syntax errors detected`. The modern screen had no current-navigation console messages. Loading the legacy screen during parity testing produced five separate Smarty E_WARNING rows unrelated to the BFF change; they are documented and tracked as bug [#1580](https://github.com/sebiboga/testlink-upgraded/issues/1580).

Screenshot artifacts are stored at `docs/screenshots/issue-971-codetracker-link-gate.png`; the Wiki mirror also includes the modern and legacy captures.
