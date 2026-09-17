# Copy & Execute Task Assignment (execTaskCopy) — Modernized Screen

Modernization of the **Copy & Execute Task Assignment** screen
(`lib/plan/buildCopyExecTaskAssignment.php`) — GitHub issue
[#1522](https://github.com/sebiboga/testlink-upgraded/issues/1522).

The legacy Smarty screen is replaced by a standalone Dashio page
(`gui/templates/plans/execTaskCopy.html`) backed by a plain-PHP REST BFF
(`api/execassignmentcopy/index.php`). It copies the **tester assignments** of a
source build onto a target build of the same test project, replacing the
target's current tester assignments.

**Path:** Execute → Execution Dashboard → **Copy Task Assignment** toolbar button
**URL:** `gui/templates/plans/execTaskCopy.html?build_id=<target build id>`
**BFF API:** `api/execassignmentcopy/index.php`
**Right:** `testplan_planning` on the test project owning the target build
(legacy `pageAccessCheck` parity), enforced on every route.

---
## Table of Contents
1. [What the screen does](#1-what-the-screen-does)
2. [REST API Reference](#2-rest-api-reference)
3. [Legacy defects fixed](#3-legacy-defects-fixed)
4. [i18n Keys](#4-i18n-keys)
5. [Security](#5-security)
6. [Testing](#6-testing)

## 1. What the screen does

| Feature | Legacy behavior | Modern implementation |
|---|---|---|
| Target build card | build name/release/open state | Dashio card with build name, release date, open/closed chip and the live tester-assignment count |
| Source build selector | `get_builds_for_html_options()` (broken, see §3) | active + open builds of the **target build's test project**, target excluded, newest first, each labelled `name (assignments: N)` |
| Default source | — | the newest source that actually **has** assignments (falls back to the newest source when none do) |
| Copy action | `doAction=copy`: `delete_by_build_id(target)` + `copy_assignments(source, target)` | same `assignment_mgr` calls, wrapped in a localized confirm modal; the target's current tester assignments are removed first, then the source's are duplicated |
| Result feedback | Smarty result list | success banner + **Result** card (`Copy finished: N tester assignments are now assigned to build "…"`), target count refreshed in place, success/error toasts |
| Refresh | GUI reload | toolbar **Refresh** re-runs `init` |
| Back | legacy return area | **Back** → `execDashboard.html?build_id=<target>` |
| Locale switcher | none (legacy server locale) | client-side `TLi18n` switcher (all 10 bundles) |

## 2. REST API Reference

All routes require an authenticated session and `testplan_planning` on the owning
test project (401 anonymous, 403 without the right, JSON body).

| Method & path | Purpose | Failure modes |
|---|---|---|
| `GET /init?build_id=<target>` | target build (name, release_date, is_open, tester-assignment count), owning project, source candidates with counts, `selected_source_id`, `can_copy` | 400 missing/invalid `build_id`; 404 unknown build / build without project; 403 no rights |
| `POST /copy` `{build_id, source_build_id}` | removes the target's tester assignments and copies the source's onto it; returns `{target:{id,assignments}, source:{id,assignments}, copied}` | 400 missing ids / same build / foreign-project source / inactive-closed source / empty source; 404 unknown target build; 403 no rights |

The BFF also applies the session same-origin guard (`bffSameOriginGuard()`), so a
POST without `X-Requested-With: XMLHttpRequest` is rejected with **403**.

## 3. Legacy defects fixed

The legacy `buildCopyExecTaskAssignment.php` was **broken on the upgraded
schema**: since builds are scoped to the test project (`builds.testproject_id`;
there is no `builds.testplan_id`), the screen's
`get_builds_for_html_options($tplan_id = 0)` resolved `testproject_id = 0` and
therefore *always* returned an empty source list — every run ended in the
`no_builds_available_for_tester_copy` error. The modern BFF resolves the source
candidates from the **target build's `testproject_id`** instead.

The legacy POST also trusted `build_id`/`source_build_id` blindly; the BFF
re-checks the right on every route and validates that the source build belongs to
the same test project, is active + open and has assignments before the
destructive delete + copy.

## 4. i18n Keys

`etc.*` (28 keys) + `footers.execTaskCopy` in all 10 client bundles
(`de, en, es, fr, it, ja, pt, ro, ru, zh`). The Execution Dashboard wiring adds
`edb.copyAssign` and `edb.copyAssignNoBuild`.

## 5. Security

- Session authentication + `bffSameOriginGuard()` on every route.
- `testplan_planning` on the owning test project gates both `init` and `copy`
  (verified 403 for a tester-role user).
- `copy` re-validates target and source server-side; forged/foreign build ids are
  rejected with 400/404 before any write.
- All responses are JSON with the correct HTTP status code (the `out()` helper
  never downgrades a previously set 4xx to 200).

## 6. Testing

Regression suite `Screen — Copy & Execute Task Assignment` appended to
`tmp/TLU_Test_Cases.md` — **22/22 PASS**. Coverage: BFF init/copy/re-copy
idempotency, all 400/401/403/404 paths, CSRF guard, rights matrix (admin vs
tester role), screen render, zero-assignment toast, confirm modal, success Result
panel that persists, Refresh, EN↔RO locale and a clean Event Viewer.
