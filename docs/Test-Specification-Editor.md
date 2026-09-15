# Test Specification — Tree & Editor (editTc) — Modernized Screen

Modernized screen: `gui/templates/testcases/testSpec.html` backed by BFF
`api/testcases/index.php` (session auth, JSON I/O). This page is the docs mirror
of the GitHub wiki page `Test-Specification-Editor` (image lines omitted per
convention). Previous sections cover the tree/drag-and-drop, STEP management
(#906), design-time custom fields (#907), attachment upload/delete (#913) and the
duration field (#911).

## Version selector + version lifecycle actions — Refs #914

The legacy per-version controls (freeze/unfreeze, activate/deactivate,
delete-this-version) and the version dropdown are ported to the modern screen.

**BFF** (`api/testcases/index.php`):
- `get` accepts optional `tcversion_id` (edit/view a SPECIFIC version; default latest).
  A requested version that does not exist returns **404 "Test case version not found"**
  instead of the old silent fallback + `E_WARNING` ("Trying to access array offset on
  false" — root cause: `fetchFirstRow()` returns boolean `false` when no rows,
  `is_null()` never fired; all BFF row checks now use `empty()`).
- `GET version_list` → `versions[]` (`tcversion_id`, `version`, `active`, `is_open`,
  `has_been_executed`, `is_latest`) + grants (`mgt_modify_tc`, `testcase_freeze`,
  `delete_frozen_tcversion`, `testproject_delete_executed_testcases`).
- `POST delete_version` → legacy gates (frozen ⇒ needs `delete_frozen_tcversion`;
  executed ⇒ `testproject_delete_executed_testcases`/`canDeleteExecuted`); when the
  last version is removed the now-empty test-case `nodes_hierarchy` row is deleted too
  (no ghost nodes in the tree).
- `POST freeze` / `unfreeze` (needs `testcase_freeze`) → `tcversions.is_open`.
- `POST activate` / `deactivate` → `tcversions.active` (legacy `setActiveAttr`
  semantics: toggles the viewed tcversion, mirrored by `testcase.class.php`).

**UI** (`gui/templates/testcases/testSpec.html`):
version selector in the viewer + editor (`Ver. N (current|latest)`, frozen marker,
"(inactive)"), per-version Freeze/Unfreeze, Activate/Deactivate (hint "latest only" on
old versions of a multi-version TC), Delete-this-version, `askConfirm` OK-button label
now dynamic. Editor save sends `tcversion_id` so updates hit exactly the edited version.

**i18n:** 20 new `tspec.*` keys across all 9 locale bundles.

**Verified live** (project "Version Lifecycle Test", MultiVer Demo v1=21/v2=23):
get-by-version, 404-on-missing-version, freeze→403-delete-frozen, unfreeze,
deactivate/activate with correct "latest only" hint, per-version edit precision,
delete v1→1 left, delete last→ghost node removed. Event Viewer: no new ERROR/WARNING
rows after the fix. Test suite: `Task — Issue #914` in `tmp/TLU_Test_Cases.md`
(15/15 PASS). Commits `1df35b90f` + `2b14c33ed` on `task/issue-914-version-lifecycle`.