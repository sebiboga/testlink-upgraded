# Fix Test Plans repair utility (fixTPlans) — Modernized Screen

Modernization of the **Fix Test Plans** admin repair utility
(`lib/project/fix_tplans.php`) — GitHub issue
[#1540](https://github.com/sebiboga/testlink-upgraded/issues/1540).

The legacy Smarty screen is replaced by a standalone Dashio page
(`gui/templates/projects/fixTPlans.html`) backed by a plain-PHP REST BFF
(`api/fixplans/index.php`). It repairs **orphaned test plans** (plans whose
`testproject_id` is 0 or points at a missing test project) by reassigning them
to a chosen project, and — as a 2.0.1 superset — repairs **orphaned builds** the
same way.

**Path:** Projects area — link `$actions->fixTPlans` in `lib/functions/common.php`
(no ASIDE entry, same as the legacy standalone tool)
**URL:** `gui/templates/projects/fixTPlans.html`
**BFF API:** `api/fixplans/index.php`
**Right:** `mgt_modify_product` (legacy `pageAccessCheck` parity), enforced on
every route.

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
| Orphan plan list | `getTestPlansWithoutProject()` (never defined → PHP Fatal on every load) | BFF `init` lists test plans with `testproject_id` 0 or pointing at a missing `testprojects` row; display name joined from `nodes_hierarchy` |
| Orphan build list | not covered (1.9.x plan-scoped concept) | same orphan rule applied to `builds` (project-scoped in 2.0.1) — a true superset |
| Target project selector | inherited session context only | per-row `<select>` of existing projects (`name (prefix)`), `(no change)` sentinel |
| Apply action | POST keys trusted blindly | `POST reassign` / `POST fix_build` — every id validated server-side (404 unknown, 400 empty/malformed) before `UPDATE testplans/builds SET testproject_id` |
| Confirmation | none | localized Bootstrap modal listing every row (`Test plan #N → <project>` / `Build #N → <project>`) |
| Result feedback | legacy message list | success banner + toasts |
| Refresh / empty states | — | toolbar **Refresh** re-runs `init`; friendly empty-state panels when nothing to repair |
| Back | legacy return area | **Back** → `projectsView.html` |
| Locale switcher | none (legacy server locale) | client-side `TLi18n` switcher (all 10 bundles) |

## 2. REST API Reference

All routes require an authenticated session and `mgt_modify_product` on the
system level (401 anonymous, 403 without the right, JSON body).

| Method & path | Purpose | Failure modes |
|---|---|---|
| `GET /init` | orphan test plans + orphan builds (name/status/testproject_id) and the candidate projects (`id, name, prefix`) | 401 anonymous; 403 no rights |
| `POST /reassign` `{assignments:[{plan_id, project_id}]}` | validate every pair then `UPDATE testplans SET testproject_id` | 400 empty/malformed assignments / unknown project; 404 unknown plan; 403 no rights |
| `POST /fix_build` `{build_id, project_id}` | validate then `UPDATE builds SET testproject_id` | 400 missing ids; 404 unknown build; 403 no rights |

The BFF also applies the session same-origin guard (`bffSameOriginGuard()`), so
a POST without `X-Requested-With: XMLHttpRequest` is rejected with **403**. The
`action` parameter for POST routes is read from the **JSON request body**
(first bug found in-browser: reading `$_REQUEST` only answered `Unknown or
missing action`).

## 3. Legacy defects fixed

The legacy `fix_tplans.php` was **dead on arrival** on the upgraded schema: its
core query called `getTestPlansWithoutProject()`, a function that has never
existed in the codebase, so every load ended in a PHP Fatal error. The upgraded
schema also moved test plan/build **display names into `nodes_hierarchy`**
(`testplans`/`testprojects` no longer carry a `name` column) — the modern BFF
joins the name from there (this misread was the second in-browser bug, a BFF
500 over `tp.name`).

The legacy POST also wrote `testproject_id` updates straight from uncleansed
POST keys; the BFF re-checks the right and validates every plan/build/project id
before any write. Repairing orphan *builds* extends the legacy intent
(1.9.x concept was test-plan-scoped `builds.testplan_id`).

## 4. i18n Keys

`fixp.*` (31 keys) + `footers.fixTPlans` in all 10 client bundles
(`de, en, es, fr, it, ja, pt, ro, ru, zh`). Dynamic (JS-generated) table cells
are localized with `TLi18n.t()` — `data-i18n` never re-applies to rows created
after page load (third in-browser bug).

## 5. Security

- Session authentication + `bffSameOriginGuard()` on every route.
- `mgt_modify_product` gates `init`, `reassign` and `fix_build` (403 verified
  with a role-5 guest user).
- Write routes re-validate every plan/build/project id server-side; forged
  unknown ids are rejected with 400/404 before any write.
- All responses are JSON with the correct HTTP status code (4xx never downgraded
  to 200; 405 on non-POST/non-GET).

## 6. Testing

Regression suite `Task — Issue #1540` appended to `tmp/TLU_Test_Cases.md` —
**12/12 PASS**. Coverage: admin reassign of 2 orphan plans + 1 orphan build
through the confirm modal, cancel path, empty states, EN↔RO locale
(`Repară planurile de test`), guest-403 banner, anonymous-401 banner, BFF
400/404 validation, and a clean Event Viewer (intermediate SQL-error and
CLI-warning rows were removed after the root causes were fixed).