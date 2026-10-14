# Bugfix Issue #1862 — `tcEdit.php`: `edit_tc` on a NONEXISTENT `tcase_id` fataled with `count(): ... null given` (HTTP 500)

Class of defect: an unguarded `count()` over the `null` that `tree::get_path()` returns for a
node id absent from `nodes_hierarchy`. Latent in the legacy code; PHP 8 promoted `count(null)`
from a warning (count 0) to a thrown `TypeError`, turning a previously harmless edge case into a
hard HTTP 500 with an empty body.

## Symptom

`GET /lib/testcases/tcEdit.php?edit_tc=1&tcase_id=999999` (an id with no node) answered
**HTTP 500, 0 bytes**. The same URL **without** the edit action answered 200 (no render), so the
crash requires the `edit_tc` action. Server log:

```
PHP Fatal error:  Uncaught TypeError: count(): Argument #1 ($value) must be of type Countable|array, null given
  in .../lib/functions/testcase.class.php:5105
Stack trace:
#0 .../lib/functions/testcase.class.php(5079): testcase->getTestProjectFromTestCase()
#1 .../lib/functions/testcase.class.php(5203): testcase->get_linked_cfields_at_design()
#2 .../lib/testcases/testcaseCommands.class.php(412): testcase->html_table_of_custom_field_inputs()
#3 .../lib/testcases/tcEdit.php(88): testcaseCommands->edit()
#4 {main}
```

## Root cause

1. `lib/testcases/tcEdit.php:88` dispatches `testcaseCommands->edit()` for `edit_tc=1`.
2. `testcaseCommands::edit()` (`lib/testcases/testcaseCommands.class.php:412`) renders the
   custom-field locations via `html_table_of_custom_field_inputs($tcase_id, …)`.
3. `testcase::html_table_of_custom_field_inputs()` → `get_linked_cfields_at_design()`
   (`lib/functions/testcase.class.php:5079`) → `getTestProjectFromTestCase()` (`:5102`):
   `$path_len = count($the_path);` at `:5105`.
4. **`tree::get_path()` returns `null`, not `array()`, for a missing node.** `get_path()`
   (`lib/functions/tree.class.php:408`) seeds `$the_path = array()` and calls
   `_get_path($node_id, $the_path, …)`, which takes the path **by reference**. For an id with no
   row, `_get_path()` executes `$node_list = null; return;` (`tree.class.php:472`) — the by-ref
   assignment wipes the caller's `$the_path` to `null`. `get_path()` then returns `null`.
5. PHP 8 throws on `count(null)` → uncaught `TypeError` → 500.

**Why it breaks now:** pre-existing. Issue #1859 fixed an earlier `key(get_last_active_version())`
fatal at `tcEdit.php:327` that used to abort this same request *before* CF rendering, unmasking
the second crash. Not a regression from #1859's change.

## Fix (minimal, two sites + one Info code)

**1. `lib/functions/testcase.class.php:5104` — null-safe path count** (also protects the
xmlrpc/rest callers of the same method):

```php
// tree::get_path() returns NULL (not array()) for a node id that does not
// exist in nodes_hierarchy (_get_path() nulls the by-ref path). Cast to
// array so count() does not throw a PHP 8 TypeError (#1862).
$the_path = (array)$this->tree_manager->get_path( (!is_null($id) && $id > 0) ? $id : $parent_id);
$path_len = count($the_path);
```

**2. `lib/testcases/testcaseCommands.class.php:402` — stop before rendering a nonexistent case:**

```php
$tc_data = $this->tcaseMgr->get_by_id($argsObj->tcase_id,$argsObj->tcversion_id,null,$gopt);

// A request to edit a test case that does not exist must not fall through
// to the editor render: every field is null there, so the template emits
// an E_WARNING for each array offset accessed on null (Event Viewer) and
// the user gets a meaningless empty form. Send a clean "not found" notice
// instead of the legacy fatal (#1862).
if (empty($tc_data)) {
  redirectToInfoPage('testcase_not_found');
}
```

**3. `api/info/index.php`** — new known Info code `testcase_not_found` →
`info.testcaseNotFoundTitle` / `info.testcaseNotFoundMsg`; keys added to all 10 locale bundles.

**Why the cast alone is insufficient:** with only fix #1, the request survives to the editor
render, which accesses offsets on the `null` `$gui->tc` and writes **9 `E_WARNING` "Trying to
access array offset on null"** rows to the Event Viewer *per request* (`tcEdit.tpl.php:206`,
`attributesLinear.inc.tpl.php:33/70`) — measured. The issue's Expected explicitly wants a
meaningful "test case not found", so the guard is the correct primary fix; the cast remains as
defense-in-depth for the sibling callers.

**Alternatives rejected:**
- Fixing only `get_path()` to return `array()` instead of `null` — that return value is a
  contract many callers rely on for "not found" detection (some already defend with
  `(array)$this->get_path(...)`, e.g. `requirement_spec_mgr.class.php:446`,
  `tree.class.php:1434`); changing it has a far larger blast radius than this defect warrants.
- Making the template null-safe — still yields a meaningless empty editor for a missing case.

## Verification (fresh DB, fixture `php tmp/fixtures_1859.php`)

| # | Step | Expected | Observed | Verdict |
|---|------|----------|----------|---------|
| 1 | pre-fix `GET tcEdit.php?edit_tc=1&tcase_id=999999` | HTTP 500 | HTTP 500, 0 bytes, `count()` fatal @ 5105 | PASS (reproduced) |
| 2 | cast only (guard reverted): same request + `events` scan | — | HTTP 200 but **+9 E_WARNING** rows | captured evidence |
| 3 | post-fix: same request | 302 → Info page, 0 new events | `302 Found`, `Location: /gui/templates/feedback/info.html?code=testcase_not_found`, +0 events | PASS |
| 4 | `GET tcEdit.php?edit_tc=1&tcase_id=6&tcversion_id=7` (active) | HTTP 200 editor | HTTP 200, 18686 bytes, +0 events | PASS |
| 5 | `GET tcEdit.php?tcase_id=999999` (no action) | HTTP 200 | HTTP 200, 0 bytes | PASS |
| 6 | `GET /api/info/index.php?code=testcase_not_found` | known code + keys | `{"status":"ok","code":"testcase_not_found","known":true,"title_key":"info.testcaseNotFoundTitle","message_key":"info.testcaseNotFoundMsg",…}` | PASS |
| 7 | `GET gui/templates/feedback/info.html?code=testcase_not_found` | HTTP 200 | HTTP 200 | PASS |
| 8 | `php -l` both PHP files | no errors | No syntax errors detected | PASS |
| 9 | `bash ai/verify_i18n_coverage.sh` | 0 missing | 9 bundles passed, 0 failed (7581 keys) | PASS |
| 10 | `python3 -m json.tool` × 10 bundles | valid | all OK | PASS |
| 11 | `events` table after post-fix run | no new Error/Warning | +0 (only AUDIT rows) | PASS |

Gate: `TLU_REQUIRE_SUITE="Issue #1862" bash ai/verify_test_suites.sh` → 7 PASS / 0 FAIL.
Suite: `Regression — Issue #1862` in `tmp/TLU_Test_Cases.md` (14 cases).

## Files changed

- `lib/functions/testcase.class.php` — `(array)` cast in `getTestProjectFromTestCase()`.
- `lib/testcases/testcaseCommands.class.php` — not-found guard in `edit()`.
- `api/info/index.php` — `testcase_not_found` info code.
- `gui/templates/i18n/*.json` (10 bundles) — `info.testcaseNotFoundTitle` / `info.testcaseNotFoundMsg`.
- `docs/` mirror + GitHub Wiki page + `CHANGELOG` key-bugfix line.

## Sibling defect found (filed separately, not fixed here)

`lib/functions/testplan.class.php:2659` has the identical `$the_path = …get_path(…); $path_len = count($the_path);`
shape (`testplan::get_linked_cfields_at_execution()`), i.e. the same latent `count(null)` `TypeError`
for a missing plan id. Out of this issue's scope; tracked as a separate `bug` issue.
