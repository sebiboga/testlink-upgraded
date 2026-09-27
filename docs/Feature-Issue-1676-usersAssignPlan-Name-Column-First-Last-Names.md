# Issue #1676 — `usersAssignPlan.html`: first/last names in the Name column (gap vs legacy)

**Status:** implemented and verified (closes #1676)
**Screens:** `gui/templates/usermanagement/usersAssignPlan.html` (Assign Test Plan Roles) and its
test-project twin `gui/templates/usermanagement/usersAssignProject.html` (Assign Test Project Roles)
— the legacy template was **shared**, so one legacy cell, two modern grids
**BFF:** `api/roles/index.php` — `GET /api/roles/index.php/meta/tplan-roles`

## The gap

Legacy 1.9.20 `gui/templates/dashio/usermanagement/usersAssign.tpl:242` rendered the single
"User" cell as:

```smarty
{$user->login|escape} ({$user->firstName|escape} {$user->lastName|escape})
```

i.e. the first/last names were **always** on screen, whatever the configured
`$tlCfg->username_format` is (`config.inc.php:756-764` — `'%first% %last%'`,
`'%last%, %first%'`, `'%first% %last% %login%'`, but also `'%login%'` / `'%email%'`;
overridable through `custom_config.inc.php` / `config_db.inc.php`).

The modern grids split that legacy cell into a **Login** and a **Name** column, and the Name
column carried only `$u->getDisplayName()` (`api/roles/index.php`, `'name' => $u->getDisplayName()`;
the same pattern in `meta/tproject-roles` feeds the Assign Test Project Roles screen, which the
shared legacy template governed with the very same line).
`tlUser::getDisplayName()` (`lib/functions/tlUser.class.php:525-534`) expands the format, so for
every format that omits `%first%`/`%last%` the Name column became a byte-for-byte duplicate of the
Login column and the names were rendered **nowhere** — a silent loss of information that legacy
never had.

Measured, same fixture, only the format differs:

| `username_format` | Login | Name before | Name after |
|---|---|---|---|
| `%login%` | `an1676designer` | `an1676designer` (== Login) | `an1676designer (Anna Designer)` |
| `%login%` | `admin` | `admin` | `admin (Testlink Administrator)` |
| `%email%` | `an1676guest` | `an1676guest@localhost` | `an1676guest@localhost (Gus Guest)` |
| `%email%` | `admin` (no e-mail) | *(empty)* | `Testlink Administrator` |
| `%first% %last%` (default) | `an1676designer` | `Anna Designer` | `Anna Designer` (unchanged) |
| `%first% %last% %login%` | `an1676tester` | `Tina Tester an1676tester` | unchanged |
| default, user with no names | `an1676noname` | *(empty)* | *(empty)* (unchanged) |

## The fix

**BFF** — both assign payloads ship the raw names next to the display name:

* `api/roles/index.php` `meta/tproject-roles` item: `firstName` / `lastName` (additive, so the
  twin screen `usersAssignProject.html` can be closed in its own run without another BFF change);
* `api/roles/index.php` `meta/tplan-roles` item: `firstName` / `lastName`.

`name` stays exactly `getDisplayName()` — the BFF is not made responsible for composing the cell,
so both columns remain reusable by any other screen.

**Screens** — a new pure helper `userNameCell(u)` in `usersAssignPlan.html` **and** its identical
twin in `usersAssignProject.html` (the file-local `esc()` duplication is this repo's established
per-screen convention, so the helper is too):

* returns the display value unchanged when it already contains both raw names (default format,
  `'%last%, %first%'`, `'%first% %last% %login%'`, …) — **no** `Anna Designer (Anna Designer)`;
* returns `<display> (<first> <last>)` when it does not (`'%login%'`, `'%email%'`, `'%last%'`, …);
* returns the bare `<first> <last>` when the display value is empty (no leading `()`);
* returns the display value alone when the user has no first and no last name;
* accepts (and documents) a repeated name for a **single-token** format whose token is one of the
  two names (`'%first%'` → `Anna (Anna Designer)`) — nothing is lost, the other name would
  otherwise be invisible, and the format was chosen by an administrator;
* trims only the outer parts, so a name containing a non-breaking space renders as stored.

The decision is a **substring test against the resolved display value**, not a guess about the
configured format string — any custom format (`'%last% - %first%'`) is handled by construction.
The Name cell is rendered through `esc()`, so the appended text is HTML-escaped like the rest of
the cell.

Bonus parity: the appended names are part of the cell text, so the DataTables search on the Name
column matches them again — in 1.9.20 the fused `login (first last)` cell was the only searchable
user column, so searching by first name was always possible.

No new i18n key: no new user-facing string is introduced (the "Name" header keeps `user.fullName`,
the appended text is data).

![Name column with username_format = %login%](issue-1676-usersAssignPlan-name-column-login-format.png)

## Verification

* Automated harness `tmp/suite_1676.py` — **32/32 PASS**: Part A evaluates the *shipped*
  `userNameCell()` **of both screens** (extracted from the HTML, not a copy) over 9 format/edge
  combinations in node; Part B logs in over real HTTP and asserts the BFF contract for 3 formats
  (raw names shipped, empty names survive the cast, existing keys untouched, `name` still follows
  the format); Part C asserts the twin screen ships the same helper and renders it through `esc()`.
* 14 browser cases (`tmp/TLU_Test_Cases.md`, suite 1676) — 14/14 PASS, including sort / paging /
  bulk "Do" / Save regressions on BOTH grids and the console.
* Twin screen measured: with `'%login%'` → `an1676designer (Anna Designer)`, `admin (Testlink
  Administrator)`; with the default format → `Anna Designer` (unchanged), bulk "Do" + Save state
  still work (5 rows changed, Save enabled), console clean.
* `php -l api/roles/index.php`, `node --check` on the extracted inline script: clean.
* Browser console (error + warn) after all reloads: none. Event Viewer: 2 rows, both audit INFO
  (`log_level=16`), no new Error/Warning.

Fixture used by the suite: `php tmp/fixtures_1676.php` → tproject `AN1676` (id 1), plan
`AN1676PLAN` (id 2), users `an1676designer/Anna Designer`, `an1676leader/Lars Leader`,
`an1676tester/Tina Tester`, `an1676guest/Gus Guest` (plus a hand-added `an1676noname` with both
names empty for the edge case).

## Files

| File | Purpose |
|---|---|
| `api/roles/index.php` | ship `firstName` / `lastName` in the tplan-roles (and, additively, tproject-roles) items |
| `gui/templates/usermanagement/usersAssignPlan.html` | `userNameCell()` helper, Name cell render, data-model fields |
| `gui/templates/usermanagement/usersAssignProject.html` | same helper + Name cell render on the test-project twin grid |
| `tmp/suite_1676.py` | executable harness (client + live BFF) |
| `tmp/fixtures_1676.php` | throwaway fixture (project/plan/users) |
| `docs/screenshots/issue-1676-usersAssignPlan-name-column-login-format.png` | fixed state with `username_format = '%login%'` |

## Reproduce

```bash
php tmp/fixtures_1676.php
printf '%s\n' '<?php' '$tlCfg->username_format = "%login%";' > custom_config.inc.php
# browser: admin/admin ->
#   http://localhost:8082/gui/templates/usermanagement/usersAssignPlan.html?tproject_id=1&tplan_id=2
rm -f custom_config.inc.php     # back to the default format
python3 tmp/suite_1676.py
```
