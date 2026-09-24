# Task 963 — Restore link-count delete gating for linked trackers in the Issue Trackers screen (gap vs legacy)

**Issue:** [#963](https://github.com/sebiboga/testlink-upgraded/issues/963)
**Status:** IMPLEMENTED & VERIFIED (2026-09-24)

## The gap

Legacy `issueTrackerView.tpl:78-83` renders the per-row **delete** icon ONLY
when `canManage` AND `$item_def.link_count == 0`; a tracker linked to a test
project shows NO delete icon at all. `link_count` is filled by
`getAll(output=>add_link_count)` (`tlIssueTracker.class.php:591-624`).
The model then double-guards `tlIssueTracker::delete()` (`class.php:291-335`):
a linked tracker refuses deletion with `Failure - id N is linked to:
testproject 'X' with id Y`, listing the linking project.

The modern screen dropped the UI gate:
- `api/issuetracker/index.php` calls `getAll(output=>add_link_count)` but
  **`trackerToJSON()` (index.php:32-51) DROPS `link_count`** from the payload.
- `issuetrackerView.html:238-239` always renders the trash (delete) icon for
  every row when `canManage`.
- Clicking delete on a linked tracker → `window.confirm` → DELETE → HTTP 400
  with the model guard message and an error alert; the record is NOT removed.

## Investigation (measured)

- Fixture: `issuetrackers` id=1 `Bugzilla 963` linked to test project `TC963-prj`
  (`link_count=1`); id=2 `Free 963` unlinked (`link_count=0`).
- BFF `/api/issuetracker` → items have NO `link_count` (measured).
- Browser: BOTH rows render an active trash icon; clicking linked → confirm →
  DELETE returns HTTP 400 (linked-project message, record kept); unlinked →
  confirm → DELETE → record gone (measured via SELECT). Legacy hides the icon
  entirely when linked.

## Fix

**BFF** `api/issuetracker/index.php`:
- `trackerToJSON()` now exposes `link_count` (int) + `links` (linked test project
  names, via `getLinkSet()`), so the UI can distinguish linked vs free trackers.
- The list route attaches `links` to each tracker.

**HTML** `gui/templates/issuetracker/issuetrackerView.html`:
- Delete icon for a linked tracker (`link_count > 0`) renders as a greyed-out,
  `cursor:not-allowed`, **non-clickable** trash (`danger disabled`, no onclick)
  with a localized tooltip "Cannot delete - still linked to test projects:
  <names>". Mirrors legacy which hides the delete action entirely (and the
  `rms.msg.cannotDeleteLinked` pattern already used by reqMgrSystemView / the
  trashed projects screen).
- Unlinked trackers keep the active trash + `onclick="deleteTracker(id,name)"`
  (confirm dialog + model delete, unchanged).

**i18n** — new keys `it.msg.cannotDeleteLinked` (tooltip), added to ALL 10
locale bundles. Locale switch verified (ro) renders the localized tooltip.

## Verification

- **Linked tracker** (`Bugzilla 963`): delete icon is disabled (no onclick, no
  confirm dialog); DELETE not reachable through the UI; record intact.
- **Unlinked tracker** (`Free 963`): delete icon active → confirm → record
  removed (SELECT confirms); confirm-dismiss keeps the record.
- **View-only user** (right `issuetracker_view`, no management): no trash/edit
  icons at all — actions column/toolbar hidden (matches legacy whole-toolset
  gating on canManage); regression pass clean.
- Browsers: admin + a freshly created view-only user; Event Viewer clean.

## Test cases

Appended to `tmp/TLU_Test_Cases.md` (Suite 963, 6/6 PASS); CHANGELOG updated.

**Status:** DONE — closed in #963.
