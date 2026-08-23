# Assign Platforms to Test Plan (platformsAssign) — Wiki
The modernized **Assign Platforms to Test Plan** screen replaces the legacy
`lib/platforms/platformsAssign.php` OptionTransfer dual-select page. It links
and unlinks the platforms of a test plan, shows per-platform linked test case
counts, blocks removal of platforms that are still in use and auto-repairs
test plan links that still sit on the "unknown platform" (platform_id 0) —
with the same server-side semantics as TestLink 1.9.20
(`tlPlatform::linkToTestplan()`, `unlinkFromTestplan()`,
`testplan::countLinkedTCVersionsByPlatform()`, `changeLinkedTCVersionsPlatform()`).

**Path:** Test Plan > Add / Remove Platforms (visible when a test plan is active in context)
**URL:** `gui/templates/platforms/platformsAssign.html?tproject_id=<id>&tplan_id=<id>`
**BFF API:** `api/platforms/index.php` (REST: GET `/assign`, POST `/assign`)
**Prerequisite:** `testplan_add_remove_platforms` right on the test project
(legacy `checkPageAccess()`).

---
## Table of Contents
1. [Screen Layout](#1-screen-layout)
2. [Assignment Model](#2-assignment-model)
3. [Unlink Guard](#3-unlink-guard)
4. [Unknown-Platform Repair](#4-unknown-platform-repair)
5. [Rights Model](#5-rights-model)
6. [REST API Reference](#6-rest-api-reference)
7. [i18n Keys](#7-i18n-keys)
8. [Legacy Divergences](#8-legacy-divergences)
9. [Testing](#9-testing)

---
## 1. Screen Layout
* Teal Dashio header: title, subtitle, locale switcher.
* Toolbar: test project name, test plan picker (reloads the whole assignment),
  reload button.
* Warning banner (conditional): shown when linked TC versions still reference
  platform_id 0.
* Dual-pane working copy:
  * **Available platforms** — all platforms of the project that are not yet
    linked to the plan.
  * **Assigned platforms** — platforms linked to the plan; each shows a badge
    `(Used in N testcases)` when it has linked TC versions and a red
    **closed for exec** tag for `is_open=0` platforms.
  * Middle buttons: `>` add selected, `<` remove selected, `»` add all,
    `«` remove all.
* Actions: **Save** (plain link/unlink) and **Save and assign linked test
  cases to the platforms** (`mode=save_tcv`, see §4). Nothing is persisted
  until you save.

## 2. Assignment Model
The two panes form an unsaved *working copy*. On Save the client diffs it
against the loaded state and posts only `add[]` / `remove[]` id sets; the BFF
applies them with the same manager calls the legacy controller used.

## 3. Unlink Guard
Legacy blocked moving TC-bearing platforms to the left pane client-side
(`platform_count_map` + Ext.Msg.alert). The modern screen keeps both layers:
* client: warning modal "Platform is in use", move aborted;
* server: POST returns HTTP 422 `PLATFORM_HAS_LINKED_TCVS` if a remove id has
  `count_testcases(tplan, platform) > 0`.

## 4. Unknown-Platform Repair
When a plan contains TC version links with platform_id 0 (created before any
platform was assigned), legacy showed the `unknown_platform` warning and —
when exactly ONE platform was added — moved those links onto the new platform
(`changeLinkedTCVersionsPlatform(tplan, 0, added)`). Behavior mirrored:
banner while `fixNeeded` is true, automatic repair on single-platform saves,
toast note about the reassignment.

## 5. Rights Model
* Screen + both endpoints require `testplan_add_remove_platforms`
  (403 otherwise).
* Read-only users get the panes but no transfer buttons (same as legacy's
  `can_do` gating of the form).

## 6. REST API Reference
| Method | Path | Purpose |
|--------|------|---------|
| GET | `/api/platforms/index.php/assign?tproject_id=&tplan_id=` | full payload: tplan list, available[], assigned[] (+linkedCount,is_open), fixNeeded, unknownQty, rights |
| POST | `/api/platforms/index.php/assign` | body `{tproject_id,tplan_id,add[],remove[],mode}`; applies link/unlink, unknown-platform repair, guard; returns refreshed payload |

Deep-link tolerance: GET resolves the project from the tplan when
`tproject_id` is omitted.

## 7. i18n Keys
Namespace `passign.*` (header, panes, buttons, warnings, badges, messages) plus
shared `common.save/ok/error/generatedOn`. Present in **all ten** locale
bundles: en, de, es, fr, it, ja, pt, ro, ru, zh.

## 8. Legacy Divergences
* **Enable/Disable selected button** — omitted: no handler exists anywhere in
  the legacy PHP; clicking it reloaded the page (dead UI).
* **save_tcv** — upstream intended copying TC links between platforms via
  `copyLinkFromPlatformToPlatform()`, but that method never existed and its
  inputs (`onRightSide`, `to_select_box`) were never populated/sent, so both
  legacy buttons only ever link/unlink. The modern API keeps `mode=save_tcv`
  as an alias so the historical button label survives.

## 9. Testing
Suite 57 in `tmp/TLU_Test_Cases.md`: 13/13 PASS — panes, link/unlink
(DB-verified), fixNeeded banner + auto-repair, unlink guards (client +
422 server), locale switch, aside switch, Event Viewer clean after fixes.
