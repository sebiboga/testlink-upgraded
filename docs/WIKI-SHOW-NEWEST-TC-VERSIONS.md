# Show Newest Test Case Versions (showNewestTcVersions) — Wiki

The modernized **Show Newest Test Case Versions** screen replaces the legacy
`lib/plan/newest_tcversions.php`. It is a **read-only report**: for every test
case linked to a test plan whose LINKED version is older than the newest ACTIVE
version it shows linked vs newest version plus compare / design / execution
history shortcuts — exactly what TestLink 1.9.20 showed
(`testplan::get_linked_and_newest_tcversions()`).

**Path:** Test Plan Management > Show Test Cases Newest Versions (requires an active test plan)
**URL:** `gui/templates/plans/showNewestTcVersions.html?tproject_id=<id>&tplan_id=<id>`
**BFF API:** `api/plans/index.php` (REST: GET `/newest-versions`)
**Prerequisite:** every request requires `testplan_planning` server-side
(same as legacy `checkRights()` `rightsAnd` contract); menu entry is hidden
when no plan is selected.


---
## Table of Contents
1. [Screen Layout](#1-screen-layout)
2. [States & Legacy Parity](#2-states--legacy-parity)
3. [Rights Model](#3-rights-model)
4. [REST API Reference](#4-rest-api-reference)
5. [i18n Keys](#5-i18n-keys)
6. [Testing](#6-testing)

---
## 1. Screen Layout
* Teal Dashio header: title, subtitle, current test plan badge.
* Toolbar: test project name, **Test Plan picker** (accessible plans only),
  linked-count chip, reload button, locale switcher.
* Report table columns:
  * *Test Case* — execution-history icon (`execHistory.html`, modernized
    screen), design icon (`archiveData.php` read-only view), suite path,
    external id (`PREFIX-n`) and name.
  * *Linked version* / *Newest version* — version tags; the newest one is green.
  * *Compare* — opens legacy `tcCompareVersions.php`
    (`version_left=linked & version_right=newest`, new window), same as 1.9.20.
* Footer hint reminds that the report is read-only and points to
  *Update Linked Test Case Versions* for actually switching versions.

## 2. States & Legacy Parity
| State | Trigger | Screen behaviour |
|---|---|---|
| `pick` | no `tplan_id` in URL/session | "Please select a test plan." + plan dropdown |
| `no-linked` | plan has zero linked tcversions | "There are no Test Cases linked to this Test Plan!" (legacy `no_linked_tcversions`) |
| `all-current` | links exist but none outdated | "All linked Test Case Versions are already the newest available!" (legacy `no_newest_version_of_linked_tcversions`; this is the #528 fatal path — PHP 8 `count(null)` — kept safe by array casts in both legacy controller and BFF) |
| `ok` | ≥1 outdated link | report table |

The BFF reuses `get_linked_items_id()` + `get_linked_and_newest_tcversions()`
unchanged (max-id active sibling candidate AND strictly greater *version*
number, see #624 discussion) and builds suite paths via
`tree::get_full_path_verbose()` like the legacy controller.


## 3. Rights Model
* Server side: `hasRight($db,'testplan_planning',$tproject_id)` on EVERY call
  (403 JSON otherwise) — mirrors legacy `pageAccessCheck()` `rightsAnd`.
* Client side: users without the right get the red "Insufficient rights" state;
  the ASIDE entry is not rendered at all when planning rights are missing or no
  plan is active.


## 4. REST API Reference
### GET `/api/plans/index.php/newest-versions?tproject_id=<id>&tplan_id=<id>`
Response:
```json
{
  "status": "ok",
  "state": "ok | pick | no-linked | all-current",
  "tproject": {"id": 1, "name": "..."},
  "tplans": [{"id": 2, "name": "Plan Alpha", "active": 1, "is_public": 0}],
  "tplan": {"id": 2, "name": "Plan Alpha", "active": 1},
  "tcprefix": "NTCV-",
  "linked_count": 3,
  "items": [{
      "tc_id": 4, "tcversion_id": 5, "newest_tcversion_id": 18,
      "tc_external_id": 1, "name": "Valid login",
      "version": 1, "newest_version": 2, "path": "Login Suite / "
  }]
}
```
Errors: `400 NO_TPROJECT/BAD_TPLAN` (invalid context), `403 NO_RIGHTS`,
`401` unauthenticated. Context-only mode (`tplan_id=0`) returns the plan picker
payload instead of a dead end (same contract as `update-linked`, #624).

## 5. i18n Keys
All strings go through the client-side `TLi18n` module under the `ntcv.*`
prefix (header, toolbar labels, column headers, empty-state messages, footer
hint, icon titles). Present in ALL locale bundles:
`en ro de fr ru pt es ja zh`.

## 6. Testing
Suite **643** in `tmp/TLU_Test_Cases.md`: 15/15 PASS — happy path, all three
empty states, invalid contexts, permission paths (BFF + UI + menu gating),
aside integration inside the shell, popup-link liveness, locale switch and
Event Viewer check (independent pre-existing L18N warnings filed as #644).

## Commits
| Commit | Content |
|---|---|
| `9d1da3f7c` | feat(plans): BFF GET /newest-versions |
| `2d494e1e5` | feat(plans): showNewestTcVersions.html Dashio screen |
| `104fecdd3` | feat(i18n): ntcv.* ×9 bundles + aside link switch |
| `1249290c5→` | Suite 643 + execHistory.html icon switch + NaN guard |
