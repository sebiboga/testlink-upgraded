# Task 915 — Per-testcase-version platform assignment in Test Specification (gap vs legacy)

**Issue:** [#915](https://github.com/sebiboga/testlink-upgraded/issues/915)
**Status:** IMPLEMENTED (2026-09-15)

## The gap

The legacy editor (`gui/templates/dashio/testcases/tcEdit.tpl` + `platforms.inc.tpl`,
backed by `lib/testcases/tcEdit.php` `addPlatform`/`removePlatform` and
`lib/functions/testcase.class.php` `addPlatforms` / `deletePlatforms` /
`deletePlatformsByLink` / `getPlatforms`) lets a user assign **platforms to a
specific test case version** — a `testcase_platforms` row per
`(testcase_id, tcversion_id, platform_id)`. Design-visible platforms
(`platforms.enable_on_design = 1`) are shown as a clickable picker: assigned ones
become red removable chips, unassigned ones are offered as "add" links.

The modern `testSpec.html` editor, the `tcEdit.html` standalone editor and the
`tcView.html` / `testSpec.html` viewers dropped this completely: there was no
platform UI and the modern BFF (`api/testcases/index.php`,
`api/testcasesedit/index.php`) neither exposed platform data nor persisted any
platform assignment.

## Legacy source of truth

- `lib/testcases/tcEdit.php:116-117` — `doAddPlatform` / `doRemovePlatform`
  dispatch; per-task `platRW = $tcase->canDo()` + `remove_enabled` logic.
- `lib/functions/testcaseCommands.class.php:1551-1601` — the platform ops
  (`doAddPlatform` / `doRemovePlatform` gates: version must be open, no
  executions, `mgt_modify_tc` right, design-visible platforms only).
- `lib/functions/testcase.class.php` — `addPlatforms` / `deletePlatforms` /
  `deletePlatformsByLink` / `getPlatforms` (`testcase_platforms` CRUD).
- `gui/templates/dashio/testcases/platforms.inc.tpl` — per-version picker with
  assigned = red removable chips and free = "add" links.
- `install/sql/mysql/testlink_create_tables.sql` — `testcase_platforms`
  (`testcase_id, tcversion_id, platform_id`, PK + two FKs) and `platforms`
  (`enable_on_design` flag controlling design visibility).

## Modern implementation (port)

**Backend `api/testcases/index.php`** (Test Specification + inline editor)
- New helpers: `projectPlatforms()` (`{id: {name, enable_on_design}}` for the
  owning project), `versionPlatformAssignments()` (assigned platforms incl. the
  `testcase_platforms.id` link id), `platformFreeList()` (design-visible minus
  assigned), `canAssignPlatforms()` (== legacy `platRW`: `mgt_modify_tc` + open
  version + version not executed) and `resolveTcversionForPlatform()` /
  `versionHasExecutions()` used by the mutators.
- `get` action now returns `platformsAssigned`, `platformsFree`, `platformsProject`
  and `platformsEditable` (defaults false when the project has no platforms).
- `view` action returns `platformsProject` top-level plus per-version `platforms`
  and `canAssignPlatforms` for the test case list rows.
- New POST routes:
  - `add_platform` `{tcase_id, tcversion_id, platform_id}` → `tcaseMgr->addPlatforms`,
    restricted to `enable_on_design = 1`, open version and no executions.
  - `remove_platform` `{tcase_id, tcversion_id, platform_id}` (or `tcplat_link_id`)
    → `deletePlatforms` / `deletePlatformsByLink`.
- `create` and `update` accept a `platforms` array and diff-sync the full
  assignment set against `testcase_platforms` via the shared `$syncPlatforms`
  closure (add missing, remove stale), mirroring the legacy full-set save.

**Backend `api/testcasesedit/index.php`** (standalone editor `tcEdit.html`)
- New helpers `tceProjectPlatforms()`, `tceVersionPlatformAssignments()`,
  `tcePlatformFreeList()`; `buildEditPayload()` returns `platformsAssigned`,
  `platformsFree`, `platformsProject`, `platformsEditable` under `tcase`.
- `update` route persists the `platforms` array the same full-set way.

**Frontend `gui/templates/testcases/testSpec.html`**
- New `platformsEditorHtml(data, mode)`: hidden entirely in `create` mode (no
  version exists yet — legacy `tcNew` has no platform picker); for existing
  versions it renders a `tspec.platforms` label, assigned platforms as chips, the
  `No platforms assigned to this version` note, and a checkbox multi-select
  (`#platList`) of every project design-visible platform.
- `formHtml()` places the block right before the custom-fields section;
  `openEditTc()` and `switchEditVersion()` thread `platforms*` from the `get`
  payload into the form.
- `collectForm()` gathers the checked ids into `platforms: [...]` on every save.
- Read-only `renderTcView()` shows the platform chips row under KEYWORDS when the
  project has platforms
  (`No platforms assigned to this version` when none).

**Frontend `gui/templates/testcases/tcEdit.html`** (standalone editor)
- New Platforms section (`tcedit.platforms`), pills toggling
  `ctx.selectedPlatforms`; `renderPlatforms()` renders assigned platforms as
  active pills and free ones as inactive (gated by `platformsEditable` +
  `mgt_modify_tc`, with `tcedit.noPlatforms` note); `collectPlatforms()` is
  included in the save body and the BFF persists it.

**Frontend `gui/templates/testcases/tcView.html`** (full viewer)
- Read-only `tcview.platforms` chips row (assigned platforms for the viewed
  version).

**New-version platform copy**
- `POST ?action=create_version` already routes through the legacy core
  `testcase::create_new_version()`, which **copies** the platforms of the source
  version to the new one (`copyPlatformsTo(..., array('delete' => false))`,
  `lib/functions/testcase.class.php:2456/10146`) — verified live (new version got
  the 3 assigned platforms).

**i18n**
- New keys in all 10 locale bundles (`en/de/es/fr/it/ja/pt/ro/ru/zh`):
  `tcedit.platforms`, `tcedit.noPlatforms`, `tcview.platforms`,
  `tspec.platforms`, `tspec.noProjPlatforms`, `tspec.noPlatformsAssigned`.

## Verification

Browser checks on a local instance (`http://localhost:8082`, project
"Platform Demo" with platforms Android / iOS / Windows all `enable_on_design=1`,
test case "TC Login" id 3 version 1 id 4):

- `testSpec.html` edit → **PLATFORMS** section shows Android/iOS/Windows chips and
  checked checkboxes; unchecking iOS and Save removes the `testcase_platforms`
  row (verified in DB); viewer shows PLATFORMS chips (Android, Windows).
- `tcEdit.html` → Platforms pills; toggling iOS and Save re-added the row (new
  link id 5), Android + iOS + Windows present afterwards.
- `tcView.html` → PLATFORMS chips row (Android, Windows, iOS).
- `create_version` cloned the 3 platform rows to the new version (legacy parity);
  the throwaway version was deleted and its orphan `testcase_platforms` rows
  manually purged (orphan-on-delete itself is reported separately — #1512).
- No new `events` Error/Warning rows during all steps (only INFO audit entries);
  no browser console errors on any of the three screens.

## Related issue

- [#1512](https://github.com/sebiboga/testlink-upgraded/issues/1512) — bug
  discovered while testing: `delete_version` leaves orphaned `testcase_platforms`
  rows (pre-existing legacy `_blind_delete` behavior).

## Files changed

- `api/testcases/index.php`
- `api/testcasesedit/index.php`
- `gui/templates/testcases/testSpec.html`
- `gui/templates/testcases/tcEdit.html`
- `gui/templates/testcases/tcView.html`
- `gui/templates/i18n/{en,de,es,fr,it,ja,pt,ro,ru,zh}.json`
- `docs/Task-Issue-915-TestSpec-PerVersion-Platform-Assignment.md`
- `docs/screenshots/issue-915-*.png`
- `CHANGELOG`