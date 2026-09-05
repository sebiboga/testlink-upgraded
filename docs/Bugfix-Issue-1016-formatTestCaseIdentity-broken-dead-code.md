# Issue #1016 — testcase.class.php: formatTestCaseIdentity() is broken dead code (undefined $tc_id, unguarded $path2root[0])

**Issue:** [#1016](https://github.com/sebiboga/testlink-upgraded/issues/1016)
**Commit:** `b36f6bff4` (fix)
**Status:** VERIFIED-FIXED (2026-09-05)

## Symptom

`testcase::formatTestCaseIdentity($id, $external_id = null)` in `lib/functions/testcase.class.php`
was dead code (zero call sites in the whole repo) but broken in three independent ways:

1. The body referenced `$tc_id`, which is never defined — the parameter is `$id` → PHP 8
   `E_WARNING "Undefined variable $tc_id"`.
2. `$path2root[0]['parent_id']` was dereferenced with no null/empty guard → when
   `tree_manager->get_path()` returns `NULL`, PHP 8 raises
   `E_WARNING "Trying to access array offset on null"` twice and `$tproject_id` stays `NULL`.
3. `getTestCasePrefix($tproject_id)` was then called with `NULL` → `SELECT prefix FROM
   testprojects WHERE id = ` → **MySQL 1064** → request dies with the "DB Access Error"
   page. The function also computed `$tcasePrefix` without ever returning it.

The bug is latent today (nothing calls the function), which is why it never surfaced at
runtime — it is a mine, not a live regression.

## Investigation

Repo-wide grep (`*.php`, `*.tpl`, `*.html`, `*.js`) found exactly two matches, both in
`lib/functions/testcase.class.php`: the PHPDoc comment and the definition. No caller exists.

A in-app probe (framework bootstrapped via `config.inc.php` + `common.php` +
`doDBConnect()` + `new testcase($db)`) replicating the body line-by-line measured:

```
path2root = NULL          # get_path($tc_id) — $tc_id UNDEFINED → NULL
tproject_id = NULL        # $path2root[0]['parent_id'] on NULL
[2] Undefined variable $tc_id
[2] Trying to access array offset on null   # [0]
[2] Trying to access array offset on null   # ['parent_id']
```

A second probe calling the real method `formatTestCaseIdentity(1)` died with:

```
#2 testproject.class.php(1002): database->fetchOneValue(...)
#3 testcase.class.php(2153): testproject->getTestCasePrefix(NULL)
```

and logged `log_level=1 ERROR` "SQL 1064 … `SELECT prefix FROM testprojects WHERE id = `"
into the `events` table — the same bug class as #1011.

## Root cause chain

1. `lib/functions/testcase.class.php` (formerly 2149-2155) — `formatTestCaseIdentity($id, $external_id=null)`; zero callers → dead code.
2. Line 2151: `$path2root = $this->tree_manager->get_path($tc_id);` — `$tc_id` undefined (parameter `$id`); evaluates to `NULL`.
3. `tree::get_path(NULL)` → returns `NULL` (no node found, `_get_path()` sets `$node_list = null`).
4. Line 2152: `$tproject_id = $path2root[0]['parent_id'];` — unguarded index on `NULL` → E_WARNING; `$tproject_id = NULL`.
5. Line 2153: `getTestCasePrefix(NULL)` → raw `WHERE id = ` → MySQL 1064, request dies.

The whole function is a legacy copy-paste leftover of `getPrefix()` (same file) that was
never finished (no return value) and was never wired to any call site.

## Fix (minimal, matches the issue's "remove or fix" options)

**Remove** the dead function and its PHPDoc block (single hunk, ~16 deleted lines).
Rationale: the function has no callers, is broken three ways, and is semantically
incomplete even after a typo fix (it computes `$tcasePrefix` but never returns it) —
resurrecting it would leave a still-useless half-function. The `#1011`-style fix
(`$tc_id`→`$id` plus empty-path guard) was the alternative; it was rejected because it
would make dead code "work" for no caller's benefit. `getPrefix()` and its #1011
empty-tree-path guard are untouched.

## Why this method (alternatives rejected)

- **Alternative A (fix the typo + add the guard):** keeps a function nothing calls already
  broken for a third reason (no return value, no defined purpose in any caller). Rejected.
- **Alternative B (leave it):** keeps the same latent SQL-1064 mine documented in #1011's
  follow-up. Rejected — the whole point of the follow-up issue is to eliminate the class.
- **Chosen (remove):** smallest correct diff, eliminates the latent path entirely, zero
  behavioural risk because there are zero call sites.

## Verification

- `php -l lib/functions/testcase.class.php` — no syntax errors.
- Repo-wide grep `formatTestCaseIdentity` → **0 matches** (dead code gone).
- `getPrefix(3)` on fixture (project `PROBE` id=1, suite id=2, tc id=3) →
  `array('PROBE','1')` — valid deep-link path unchanged.
- `getPrefix(99999999)` / `getPrefix(null)` → `array(null, null)` — #1011 guard intact,
  no WARNING/ERROR events.
- `getPrefix(3, 1)` fast path → `array('PROBE', 1)` — unchanged.
- Browser: admin/admin login → landing shells render, no console errors; modern
  `tcView.html?tcase_id=3&tproject_id=1` renders its shell cleanly.
- Event Viewer / `events` table: the only ERROR present pre-purge was the **pre-fix**
  reproduction artifact (id=1, SQL 1064, fired 1788646516 before the fix); post-fix
  activity logged only INFO AUDIT rows. Events were then purged for the clean screenshot
  (0 ERROR / 0 WARNING / 0 DEBUG / 0 L18N). No new Error/Warning introduced.
- Regression suite `TC-1016.1`..`TC-1016.7` appended to `tmp/TLU_Test_Cases.md`
  (**7/7 PASS**).

## Screenshot

After fix — Event Viewer clean: `docs/screenshots/issue-1016-eventviewer-clean.png`

## Files changed

- `lib/functions/testcase.class.php` (−17) — removed dead `formatTestCaseIdentity()` + PHPDoc.
- `tmp/TLU_Test_Cases.md` — regression suite `TC-1016.*` (7/7 PASS, local file).
- `docs/screenshots/issue-1016-eventviewer-clean.png` — evidence.