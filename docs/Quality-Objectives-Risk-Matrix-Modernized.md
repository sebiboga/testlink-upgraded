# Quality Objectives & Risk Traceability Matrix — Modernized Screen

Modernization / new feature: **Quality Objectives & Risk Traceability Matrix**
— GitHub issue [#1280](https://github.com/sebiboga/testlink-upgraded/issues/1280)
(ISTQB quality-objective gap, ISTQB Compliance review #1054).

TestLink 1.9.20/2.0.1 had no quality-objective concept at all: no tables, no
API, no screen. This feature adds a complete objective → requirement → test
case → result traceability matrix as a standalone Dashio page
(`gui/templates/requirements/qualityObjectives.html`) backed by a plain-PHP
REST BFF (`api/requirements/index.php`).

**Path:** ASIDE → Requirements Design → *Quality Objectives*
**URL:** `gui/templates/requirements/qualityObjectives.html?tproject_id=<id>&tplan_id=<id>`
**BFF API:** `api/requirements/index.php`
**Rights:** `mgt_view_req` (read) / `mgt_modify_req` (write), enforced server-side
**Tracking issue:** [#1280](https://github.com/sebiboga/testlink-upgraded/issues/1280)

---

## Table of Contents
1. [What the screen does](#1-what-the-screen-does)
2. [REST API Reference](#2-rest-api-reference)
3. [Data model](#3-data-model)
4. [Risk level calculation](#4-risk-level-calculation)
5. [i18n Keys](#5-i18n-keys)
6. [Security & auditing](#6-security--auditing)
7. [Testing](#7-testing)

## 1. What the screen does

| Feature | Behavior |
|---|---|
| Objective cards | one card per quality objective: name, description, risk badge, Edit/Links/Delete buttons |
| Execution summary | Passed / Failed / Blocked / Not Run totals computed from the latest execution of every linked test case in the active (or project-wide) test plan context |
| Requirement chain | for each linked requirement: its full spec path, version, and every test case covering it (legacy `requirement_mgr::get_coverage()`), each with its latest execution status |
| Direct test cases | directly linked test cases (linked to the objective without an intermediate requirement) rendered under "Directly linked test cases", each with its latest execution status |
| Plan filter | narrow the latest executions to a specific test plan (all plans = project-wide status) |
| Create / Edit modal | Name (mandatory), Description, failure likelihood (1–5), business impact (1–5); a live "Risk: <level> (L×I)" preview recalculates as values change |
| Link editor modal | Requirement picker + test case picker with checkboxes; Save applies add/remove diffs via one BFF call |
| Delete | confirm dialog; deletes the objective and cascades all its links |
| Refresh | reloads objectives + matrix from the BFF |
| Footer | `${n} quality objective(s) · risk traceability matrix` plus generated-on timestamp |

## 2. REST API Reference

All routes live in `api/requirements/index.php` (session auth, `bffSameOriginGuard()`).

- `GET /quality-objectives?tproject_id=&tplan_id=` → `{ objectives: [...], plans: [...], generated: ts }`, right `mgt_view_req`.
  Each objective includes `risk_level` (low/medium/high/critical), `summary` (passed/failed/blocked/not_run/total),
  `requirements[]` (spec path, version, and `tcs[]` with `exec` status + label), `direct_tcs[]`.
- `GET /quality-objectives-meta?tproject_id=` → project requirements and test cases for the link pickers.
- `POST /quality-objectives` — create `{name, description, risk_likelihood, risk_impact}`, right `mgt_modify_req`.
- `PUT /quality-objectives/<id>` — update the same fields.
- `DELETE /quality-objectives/<id>` — delete + cascade links.
- `POST /quality-objectives/<id>/links` — `{req_ids: [], tc_ids: []}` replaces the link set atomically.
- `DELETE /quality-objectives/<id>/links` — `{item_type: 'req'|'tc', item_id}` removes one link.

## 3. Data model

Two new tables (created idempotently by the BFF on demand, then registered in
`lib/functions/object.class.php` `getDBTables()` whitelist):

```
quality_objectives(id, testproject_id, name, description,
                   risk_likelihood, risk_impact, is_active, author_id, creation_ts)
quality_objective_links(id, qo_id, tproject_id, item_type 'req'|'tc', item_id, creation_ts)
```

Latest execution status per test case comes from the `latest_exec_by_testplan`
DB **view** (loaded via `tlObjectWithDB::getDBViews()`).

## 4. Risk level calculation

`risk = likelihood × impact` (both 1–5):

| Product | Level |
|---|---|
| ≥ 16 | Critical |
| ≥ 10 | High |
| ≥ 5 | Medium |
| else | Low |

## 5. i18n Keys

Screen uses the client-side `TLi18n` module. Keys added to all 10 bundles
(`de en es fr it ja pt ro ru zh`):

`qobj.header, qobj.subHeader, qobj.forProject, qobj.planFilter, qobj.planAll,
qobj.addObjective, qobj.noObjectives, qobj.count, qobj.footerCount, qobj.errLoad,
qobj.stPassed, qobj.stFailed, qobj.stBlocked, qobj.stNotRun, qobj.risk,
qobj.riskLow, qobj.riskMedium, qobj.riskHigh, qobj.riskCritical, qobj.edit,
qobj.delete, qobj.links, qobj.linkTitle, qobj.coveredTcs, qobj.noLinkedTc,
qobj.noLinks, qobj.directTc, qobj.name, qobj.description, qobj.riskLikelihood,
qobj.riskImpact, qobj.editObjective, qobj.confirmDelete, qobj.msgNameRequired,
qobj.errSave, qobj.errDelete, qobj.errLinks, qobj.linkRequirements,
qobj.linkTestCases, qobj.saveLinks, qobj.noReqs, qobj.noTcs,
footers.qualityObjectives`. Legacy aside label `href_quality_objectives` was
added to every `locale/*/strings.txt` and to `gui/templates/dashio/labels/labels.aside.tpl`.

## 6. Security & auditing

- Server-side right checks on every route (`mgt_view_req` / `mgt_modify_req`).
- CSRF guard reused from `api/_guard.php` (`X-Requested-With` header).
- Every write logs an audit event in the `events` table
  (`Quality objective created/updated/deleted/linked, ... QOBJ_*`).
- No hardcoded string on the screen; all labels/messages localized.

## 7. Testing

See `tmp/TLU_Test_Cases.md` → **Task — Issue #1280** suite (10/10 PASS).
Browser-verified flows: matrix rendering, plan filter, create (live risk
preview), link editor (add/remove), edit, delete (confirm + cascade), i18n
switcher; no new Error/Warning entries in the `events` table.