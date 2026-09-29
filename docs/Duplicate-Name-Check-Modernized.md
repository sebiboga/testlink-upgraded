# Duplicate Name Check — Modernized (TestLink 2.0.1)

**Tracking issue:** [#1724](https://github.com/sebiboga/testlink-upgraded/issues/1724) (enhancement)
**Bug fixed by this run:** [#1725](https://github.com/sebiboga/testlink-upgraded/issues/1725) — *test SUITE name check answered `parent_not_found`*

| | |
|---|---|
| Legacy backends | `lib/ajax/checkNodeDuplicateName.php` (generic), `lib/ajax/checkDuplicateName.php` (test-case only) |
| Modern screen | `gui/templates/testcases/nameCheck.html` |
| BFF | `api/namecheck/index.php` |
| Wiring | `$actions->nameCheck` + a new ASIDE item in the Test Specification section (`api/aside/index.php`) |
| i18n | `nchk.*` (44 keys) + `footers.nameCheck` in all 10 modern bundles; `$TLS_href_duplicate_name_check` in all 19 server locales |
| Suite | `tmp/TLU_Test_Cases.md` → **Suite 1724, 55/55 PASS** |
| Fixture | `tmp/fixtures_1724.php` (2 projects, 3 suites, 4 cases, 2 users) |

---

## 1. Why this screen

The `TODO` section of `docs/MODERNIZATION-STATUS.md` is **empty** — every ASIDE entry already maps
to a modern `.html` screen + BFF. The last remaining *legacy feature* without a modern twin was the
generic node name-uniqueness backend, and reading it showed two authorization holes that no
migration of the surrounding screens would ever have fixed, because nothing else touched them:

**`lib/ajax/checkNodeDuplicateName.php`** — its own header comment admitted *"no check on user
rights is done"*. There was no project scope and no same-origin proof, so **any authenticated
session** could POST a `parent_id` belonging to **any** test project and learn whether a node name
was taken there: a project-wide name oracle.

**`lib/ajax/checkDuplicateName.php`** — better, but gated on the **global** `mgt_view_tc`
(`has_rights($db, 'mgt_view_tc')` with no project context). A user holding that right on one test
project could enumerate test case names inside **every other** test project. Its error branch also
called `lang_get('Invalid right')`, a key that exists in no locale bundle, so a denial rendered the
literal English text `Invalid right`.

Neither endpoint has a caller inside the application after the modernization (the Test
Specification form posts to `api/testcases/index.php?action=check_name`). They are kept for
bookmark/external callers, now hardened.

## 2. Semantics preserved

The check delegates to `tree::nodeNameExists()` (`lib/functions/tree.class.php`), so the answer is
exactly what 1.9.20 computed: **a collision is scoped to the parent**, not to the project. A test
suite name is unique among its siblings under the project root; a test case name is unique among
its siblings inside one suite. The same name free under one suite and taken under another is the
normal case, not an edge case — which is why the screen returns the colliding rows instead of a
bare sentence, and why `parent_id` is a first-class selector.

**The check is a WARNING, never a save gate** — neither legacy endpoint blocked the form submit,
and 2.0.1 does not either.

## 3. The screen

Dashio shell: teal header, dark toolbar (`Refresh` / `Check name` / `Back to Test Specification` /
`Close`), TLi18n locale switcher.

* **Context card** — Test project, Test case prefix, Containers in this project, *Warn on duplicate
  names* (`check_names_for_duplicates`) and *Action on duplicate name*
  (`action_on_duplicate_name`) with a `DUPLICATES WARNED` chip.
* **Name to check card** — Node type (Test Suite | Test Case), Parent container (the project root
  plus every suite), Name, *Node being edited (id)*. Live debounced check, mirroring the legacy
  `onkeyup="checkDuplicateName()"` wiring.
* **Result card** — `AVAILABLE` / `DUPLICATE` / `ERROR` chip, a verdict sentence and a collision
  table (ID / Existing node / Parent ID).
* **State cards** — loading, access denied, not found, project mismatch; a 401 bounces to
  `login.php?note=expired` as on every other modernized screen.

The BFF returns the legacy `lang_get('name_already_exists')` sentence verbatim in `message`; the
screen renders it as **text**, never as markup, and interpolates only the free-name sentence
client-side with `{name}`.

### Screenshots

| State | File |
|---|---|
| Duplicate test suite (the headline case) | `docs/screenshots/issue-1724-namecheck-duplicate-suite.png` |
| Romanian locale | `docs/screenshots/issue-1724-namecheck-ro.png` |
| Access Denied (no-rights user) | `docs/screenshots/issue-1724-namecheck-denied.png` |

## 4. The BFF

```
GET /api/namecheck/index.php?action=init&tproject_id=N
GET /api/namecheck/index.php?action=check&node_type=testsuite|testcase&name=..&parent_id=M[&node_id=K][&tproject_id=N]
```

Session auth, `bffSameOriginGuard()`, `bffEnforceSession()`, and `mgt_view_tc` **or**
`mgt_modify_tc` on the **owning** project of the addressed parent — resolved by walking
`parent_id` up to the test project node, never on a project supplied by the caller. The parent is
then proven to belong to the addressed project.

| Code | HTTP | When |
|---|---|---|
| `missing_name` / `name_too_long` / `missing_context` / `unknown_node_type` / `project_mismatch` / `testcase_needs_suite` | 400 | bad or contradictory request |
| `not_authenticated` / `session_expired` | 401 | no session |
| `no_permission` | 403 | no right on the owning project |
| `parent_not_found` / `node_not_found` / `project_not_found` | 404 | node genuinely absent |
| `method_not_allowed` | 405 | non-GET |
| `server_error` | 500 | guarded |

Auth is checked **before** the verb, and rights **before** existence, so an anonymous caller cannot
learn the route's verb policy and a session with no rights anywhere cannot enumerate which project
or node ids exist by telling 403 apart from 404.

## 5. Bugs

### #1725 — a test SUITE name check always failed *(fixed here)*

`?action=check&node_type=testsuite&name=Alpha%20Suite&parent_id=3` answered
`404 parent_not_found` although `Alpha Suite` was listed in the *same endpoint's* `init` response.

The owning-project walk stepped **parent-first**:

```php
$parent = SELECT parent_id WHERE id = $walk;   // project root -> 0
if ($parent == 0) { return null; }               // -> "not found"
$nt = SELECT node_type_id WHERE id = $parent;    // never reached
if ($nt === 1) { return $parent; }
```

A test **suite** is a direct child of the test project root, so the walk started on the very node
whose `parent_id` is `0` and bailed out before classifying the root. Only deeper nodes
(test **case** → suite → project) ever worked — a **partially working feature that looked fine**,
and the defect only surfaced because the fixture created a *suite*-level duplicate. In the legacy
shim the same null was treated as "no permission", so the user got a bogus *Access denied*.

The node's own `node_type_id` is now tested **first**, in the BFF **and** both shims. The guard is
not weakened: a bogus parent still 404s and a cross-project parent still 400s `project_mismatch`.

### Found by the mandatory code review

| # | Defect | Fix |
|---|---|---|
| 1 | **The screen was unreachable** — `$actions->nameCheck` had no caller anywhere in the repo | new ASIDE item, gated on `view_tc` (verified absent for a no-rights user) |
| 2 | **A deep link could answer for the wrong container.** `$('#parentSel').val(x)` is a *silent no-op* when the select has no such option, so `?tproject_id=3&parent_id=7` (suite 7 belongs to project 4) kept the first option — the project root — and rendered a green **"Available"** for a name that is a duplicate in the requested suite. The BFF's `project_mismatch` guard could never fire, because the request no longer mentioned the foreign parent. | the prefill is compared against the resolved value and refuses with `nchk.projectMismatch` |
| 3 | Changing the node type left the **previous** verdict on screen, relabelled with the new type | `nodeTypeSel` re-runs the check, like `parentSel` always did |
| 4 | A test **case** addressed at project level answered "Available" — a green verdict about a question that cannot be true | `400 testcase_needs_suite` + a localized explanation in 10 locales |
| 5 | Existence before rights let a no-rights session enumerate project and node ids | rights first; 404 reserved for a genuinely absent node |
| 6 | `?action[]=x` emitted a PHP 8 `Array to string conversion` **E_WARNING** into the Event Viewer; `intval(array)` silently yielded `1` | scalar GET readers that degrade to a defined value |
| 7 | The 405 check ran before the 401, so an anonymous caller learned the verb policy | auth first (peer order) |
| 8 | The walk hardcoded `node_type_id === 1` for "testproject" | id taken from the `node_types` table |
| 9 | `node_type` was validated against the whole `node_types` table, so `testproject`/`testplan`/`build` were answerable | allowlisted to `testsuite|testcase` |
| 10 | A caller-supplied `node_id` was never proven to be a child of `parent_id`, so an id borrowed from another project **silently suppressed a real collision** (a false negative — the dangerous direction) | self-exclusion only for a genuine sibling; re-verified that `node_id=17` under `parent_id=5` still reports the collision |

Two implementation traps found the hard way, both now fixed: `fetchFirstRowSingleColumn()` takes
**one** column (it cannot return `parent_id, node_type_id`), and `getDBTables()` returns an
**array**, not a string — `"FROM $nh"` interpolates to `From Array`.

## 6. Notable learning

The 41-case suite was green **before** the review, and the review still found four defects — because
each needs a value the happy path never produces: a `parent_id` from **another** project, an
**array-typed** query parameter, a **nonexistent** project id, and a test case addressed at project
level. Green tests were evidence that the fixtures only walked the happy path, not evidence of
correctness.

The two that would have shipped as real user-facing defects are #1 (dead menu entry) and #2 (the
silent `<select>` no-op). Both are *client* bugs behind a *correct* API — a reminder that a deep
link is a client/server contract with no shared type: nothing forces "the id in the URL" and "the
id that will be sent" to be the same value. Worth checking on every remaining deep-linkable screen.

## 7. Verification

`tmp/TLU_Test_Cases.md` → **Suite 1724: 55/55 PASS** (41 + 14 code-review regressions).

Fixture `tmp/fixtures_1724.php` — test projects `NCHK1724` (3) and `NCHK1724B` (4); suites
`Alpha Suite` (5), `Beta Suite` (6), `Gamma Suite` (7); cases 8/11/14/17; users `nchkadmin` (full
rights) and `nchknorights` (no rights) for the 403 path.

Covered: the BFF contract (anonymous, cross-origin, wrong verb, unknown action, unknown project),
the duplicate / free / self-exclude outcomes for **both** node types, parent scoping, the
cross-project claim 400, the bogus-parent 404, the 100-character cap, the 403 path, both legacy
endpoints (the two 1.9.20 holes now closed, anonymous redirected to login), and 13 browser cases
(load, five live check outcomes, deep link with auto-run, EN→RO, the 403 card, back link, console,
Event Viewer).

Event Viewer: `SELECT count(*) FROM events WHERE log_level >= 32` → **0**. Console clean.
