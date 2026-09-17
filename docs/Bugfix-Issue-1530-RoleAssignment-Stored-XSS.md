# Issue 1530 — Role-assignment screens: stored XSS via unescaped project/plan/user/role names

**Issue:** [#1530](https://github.com/sebiboga/testlink-upgraded/issues/1530)
**Branch:** `fix/issue-1530`
**Status:** VERIFIED-FIXED (2026-09-17)

## Symptom

`usersAssignProject.html` and `usersAssignPlan.html` concatenate DB-controlled
display strings straight into HTML, so a test project, test plan, user or role
whose name contains markup executes JavaScript in the session of every user who
opens the screen. Stored XSS — the payload lives in the database, not in the URL.

Measured before the fix: a project named `<img src=x onerror="alert(3)">`
rendered raw into the project `<option>` and `alert(3)` fired on page load with
zero interaction, on **both** screens.

## Repro steps

1. Log in (admin/admin) and create a test project through the BFF from the app
   origin:
   ```js
   fetch('/api/projects/index.php', {method:'POST',
     headers:{'Content-Type':'application/json'},
     body: JSON.stringify({name:'<img src=x onerror="alert(3)">', prefix:'XSS1530', active:1})})
   ```
2. Browse `http://localhost:8082/gui/templates/usermanagement/usersAssignProject.html`.
   **Before fix:** `alert(3)` fires on load; `<option>` contains a live `<img>`.
   **After fix:** the option shows the literal text `&lt;img src=x onerror="alert(3)"&gt;`,
   no alert, no `<img>` element.
3. Repeat on `usersAssignPlan.html?tproject_id=1` — same for the project option,
   and after selecting a plan the plan option, user login/name and inherited-role
   cells are escaped too.

## Root cause

The modern screens build DOM via HTML string concatenation and inject with
`innerHTML`/`.append()`. DB-sourced strings were interpolated raw:

- `usersAssignProject.html:121` — project `<option>`: raw `p.name`
- `usersAssignPlan.html:107,127` — project and plan `<option>`: raw `p.name`
- `usersAssignPlan.html:149` — role `<option>`: raw `role.name`
- `usersAssignPlan.html:153,154` — user cells: raw `u.login`, `u.name`
- `usersAssignPlan.html:155` — inherited-role cell: raw `u.inheritedRoleName`

`usersAssignProject.html` already had an `esc()` helper (added for #926) and used
it at lines 174/179/185/187/191/192, but line 121 was missed.
`usersAssignPlan.html` had **no** `esc()` helper at all, so every sink was raw.

Source of the data (`api/roles/index.php`): `login` (`:546`/`:657`),
`getDisplayName()` for user/role (`:547`/`:519`/`:622`), plan `name` (`:615`),
project `name` (`:631`), `inheritedRoleName` (`:652-653`). Project and plan names
live in `nodes_hierarchy.name` (the `testprojects` table holds only settings).

**Why it broke:** the Dashio rewrite replaced the legacy Smarty templates, whose
output was server-side escaped, with plain HTML+JS that concatenates raw API
strings. No encoding was reintroduced at the HTML sink.

## Fix

Escape every DB-sourced value at the moment it enters HTML.

- `usersAssignProject.html`: `esc(p.name)` / `esc(p.id)` at the project option;
  `esc(role.id)`; `esc(u.id)` and `esc(u.roleID)` in `data-uid`/`onchange`.
- `usersAssignPlan.html`: added the identical `esc()` helper and applied it to
  `p.name` (project + plan options), `role.name`, `u.login`, `u.name`,
  `u.inheritedRoleName`, and the `data-uid`/`onchange` args.

Why this method: escaping belongs exactly at the HTML sink, so the JSON API keeps
returning raw usable data and every consumer is forced to make its own context
decision. `esc()` encodes `& < > " '`, which is sufficient for both element text
and double-quoted attribute contexts. IDs are already `intval()`-cast server-side
(`api/roles/index.php:519,545,548,615,622,631,656,659`), so escaping them is
defence-in-depth, not the exploitable vector.

Alternatives rejected: server-side encoding in the BFF (would double-encode for
non-HTML consumers and is the wrong layer); switching the whole render to
`textContent`/DOM nodes (larger churn, same result for this targeted fix).

## Blast radius

- 8 raw interpolation sites across the two role-assignment screens, all fixed.
- The same API (`meta/tproject-roles`, `meta/tplan-roles`) is consumed only by
  these two screens.
- A separate, unrelated minor bug found while building role fixtures is filed as
  [#1531](https://github.com/sebiboga/testlink-upgraded/issues/1531)
  (`POST /api/roles` with `rightIDs:[0]` → 500 instead of a 400 JSON error).

## Verification (regression matrix, all PASS)

| Case | Result |
|---|---|
| `usersAssignProject` with `<img ...alert(3)>` project → no alert, no `<img>`, escaped option text | PASS |
| `usersAssignPlan?tproject_id=1` same project → no alert, no `<img>` | PASS |
| Plan `<img ...alert(4)>`, user first name `<img ...alert(5)>`, role `<img ...alert(6)>` → all cells/options escaped, `alerts:[]`, `hasImg:false` | PASS |
| Save-changes flow: role change → badge + enabled Save → PUT → reload shows new role | PASS |
| Normal names render without double-encoding (`admin`, `Testlink Administrator`) | PASS |
| Inline JS syntax gate (`new Function`) on both files | PASS |
| `events` table after full walk | only AUDIT/INFO rows + 1 LOCALIZATION warning caused by the injected test-role fixture via `getDisplayName()`, not by the fix |

Post-fix DOM measurement:

```html
<option value="1">&lt;img src=x onerror="alert(3)"&gt;</option>
```

Regression suite: `tmp/TLU_Test_Cases.md` → **Regression — Issue #1530**
(7/7 PASS).
