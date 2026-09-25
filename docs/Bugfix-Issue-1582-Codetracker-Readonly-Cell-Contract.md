# Bug fix — Issue #1582: read-only Code Tracker table emitted a mismatched delete cell

## Symptom

The legacy Dashio Code Tracker list returned HTTP 200 for a user holding only
`codetracker_view`, but DataTables failed during initialization. The read-only
page had three visible column headers and four cells in every tracker row, so
the browser raised `TypeError: Cannot read properties of undefined (reading
'mData')`. The configured page-size control was not rendered.

The management path still rendered, so the failure was limited to the
read-only column contract.

Entry point:
`http://localhost:8082/lib/codetrackers/codeTrackerView.php?tproject_id=2`.

## Environment and fixtures

- TestLink 2.0.1 with PHP 8.3.35 and MariaDB on the local CI instance.
- Project 2: `I1582:Issue 1582 Project`.
- Trackers: `1` (`Stash Tracker`), `2` (`GitHub TestLink`), and `3`
  (`Linked Stash`).
- Project link: `(testproject_id=2, codetracker_id=3)`.
- `ct_viewonly_1582`: global role 3, project role 3, only Code Tracker view
  right 52.
- `admin`: Code Tracker management and view rights.
- Temporary `ct_guest_1582`: guest role 5 with no Code Tracker rights for the
  authorization regression.

## Reproduction before the fix

1. Create the project, tracker, project-link, and read-only-user fixtures above.
2. Log in as `ct_viewonly_1582`.
3. Open the entry point with a cache-bypassing reload.
4. Inspect the table structure, DataTables controls, browser console, network
   response, and Event Viewer.

Measured pre-fix result:

- The document response was HTTP 200.
- The table had 3 headers: `Code Tracker`, `Type`, and `Environment`.
- Every populated row had 4 cells because the delete cell was emitted even when
  the delete header was hidden.
- DataTables did not initialize; the page-size control was absent.
- Chrome reported one uncaught `TypeError` for `mData` plus the associated
  jQuery warning.
- The Event Viewer had no new Error/Warning rows; this was a client-side
  column-contract failure rather than a PHP failure.

The pre-fix state is preserved in
`docs/screenshots/issue-1582-before.png`.

## Root cause chain

1. `lib/codetrackers/codeTrackerView.php:23-25` loads tracker rows, including
   `link_count`, and sets `$gui->canManage` from
   `codetracker_management`.
2. `gui/templates/dashio/codetrackers/codeTrackerView.tpl:40-45` conditionally
   renders the delete header only when `$gui->canManage` is non-empty.
3. Before the fix, parent revision `a548c855d^` rendered the corresponding body block at
   `gui/templates/dashio/codetrackers/codeTrackerView.tpl:75-82` with an
   unconditional `<td>`. Its inner condition only controlled the delete icon,
   not the cell.
4. That same parent block also contained an extra closing `</td>`.
5. The browser/DataTables therefore received four body cells for three headers.
   DataTables attempted to resolve the missing fourth column definition and
   raised the `mData` TypeError.

The defect was latent in the Dashio template introduced by commit
`4fe583e2d094ebe692dac13fd4c384c2322036fa`; it became visible when a
view-only user was exercised against a populated list. The analogous malformed
patterns found in the Issue Tracker and Requirement Management templates were
not changed in this narrowly scoped fix and are tracked separately by #1584 and
#1585.

## Fix approach

The fix changes only
`gui/templates/dashio/codetrackers/codeTrackerView.tpl`:

- Wrap the entire delete `<td>` in the existing `$gui->canManage != ""` check.
- Keep the existing `$item_def.link_count == 0` check inside that cell, so
  managers still cannot delete linked trackers.
- Remove the stray closing `</td>`.
- Leave the controller, rights checks, delete controller, database, labels, and
  JavaScript unchanged.

This makes the header and every row use the same permission-dependent column
count. It also preserves the existing server-side authorization and
link-count protections; the template change is presentational and does not
grant a viewer any management capability.

### Alternatives considered and rejected

- **Always render an empty delete cell for viewers:** this would make the row
  count match only if the header were also unconditional, exposing a misleading
  management column and changing legacy permissions.
- **Hide the extra cell with CSS:** DataTables would still receive the malformed
  DOM contract, and the failure could recur during sorting, searching, or
  pagination.
- **Change DataTables `aoColumns` to ignore the mismatch:** this hides the
  source error and risks silently changing the table's column mapping.
- **Remove only the extra closing tag:** the unconditional body cell would still
  remain, so the read-only table would still have four cells and three headers.
- **Refactor sibling Issue Tracker and Requirement Management templates in this
  commit:** those are separate contracts and would broaden the regression scope.

## Verification

- `php -l lib/codetrackers/codeTrackerView.php` passed.
- `php -l` on the regenerated Smarty PHP passed.
- `git diff --check` passed.
- Read-only populated list: HTTP 200, 3 headers, row cell counts `[3,3,3]`,
  DataTables initialized, length option `20`, no Create or management controls,
  and no console messages.
- Manager populated list: HTTP 200, 4 headers, row cell counts `[4,4,4]`,
  Create visible, six management links, and two delete icons; the linked
  tracker retained no delete icon.
- Empty list: HTTP 200, zero rows, no DataTables length control, Create visible,
  and no console messages; the tracker rows and project link were restored.
- Unauthorized user: the protected URL resolved to the guest dashboard and did
  not render the Code Tracker screen. The expected INFO security audit was
  recorded.
- Final cache-bypassing read-only reload: HTTP 200, 3 headers, `[3,3,3]` cells,
  DataTables initialized, length option `20`, no management controls, and no
  console messages.
- Event Viewer after the complete matrix: max id `8`, `errors=0`, `warnings=0`;
  all new records were expected INFO audit events.
- Regression suite `tmp/TLU_Test_Cases.md`: 7/7 PASS for issue #1582.
- Post-fix screenshot: `docs/screenshots/issue-1582-after.png`.

## Files changed

- `gui/templates/dashio/codetrackers/codeTrackerView.tpl` — align the
  permission-gated delete header and body cell.
- `tmp/TLU_Test_Cases.md` — regression suite and measured results.
- `docs/Bugfix-Issue-1582-Codetracker-Readonly-Cell-Contract.md` — this mirror.
- `docs/screenshots/issue-1582-before.png` — pre-fix evidence.
- `docs/screenshots/issue-1582-after.png` — post-fix evidence.
- `CHANGELOG` — issue #1582 summary.
- `tmp/wiki-repo/Bugfix-Issue-1582-Codetracker-Readonly-Cell-Contract.md` —
  GitHub Wiki page with screenshots.

## Result

Issue #1582 is fixed by aligning the read-only table's body cells with its
permission-gated header. Viewers now receive a working three-column DataTable,
managers retain the four-column management view and linked-tracker delete
gate, unauthorized access remains denied, and the Event Viewer remains free of
new Error/Warning entries. The production and regression commits were pushed
on `fix/issue-1582` as `a548c855d` and `c6859564b`; the documentation phase is
tracked by the same branch and issue.
