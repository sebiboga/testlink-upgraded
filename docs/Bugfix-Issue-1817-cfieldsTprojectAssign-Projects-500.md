# Bugfix — Issue #1817: `GET ?action=projects` on the cfieldsTprojectAssign BFF answered HTTP 500 with a 0-byte body

| | |
|---|---|
| **Issue** | [#1817](https://github.com/sebiboga/testlink-upgraded/issues/1817) |
| **Area** | Custom Fields — Test Project BFF (`api/cfieldstproject/index.php`, route `GET ?action=projects`) |
| **Symptom** | The endpoint returned HTTP 500 with a **zero-length** body (content-type `application/json` and `nosniff` still set), so the screen's project switcher showed *Could not load the data / Server error. / http_500* |
| **Impact** | The project switcher of `cfieldsTprojectAssign.html` was unusable — a user could not move between test projects without hand-editing the URL |
| **Commit** | `9a743b67f` (fix, already on `origin/sebiboga`); verification trail added in this run |
| **Files** | `api/cfieldstproject/index.php` (`?action=projects` handler) |

## Symptom

`GET /api/cfieldstproject/index.php?action=projects` returned **HTTP 500 with a 0-byte body**. The
headers were correct (`Content-Type: application/json`, `X-Content-Type-Options: nosniff`) because
they are emitted at `api/cfieldstproject/index.php:84-85`, before the crash — only the body was
missing. The screen rendered its *Could not load the data / Server error. / http_500* state card.

## Root cause

**Chain**

1. The route handler (added with the #1816 BFF, commit `96596c8a9`) called
   `$tproject_mgr->get_list()` **unconditionally** right after the auth check:

   ```php
   if ($action === 'projects') {
       if ($method !== 'GET') { cfpaFail(405, …); }
       $tproject_mgr = new testproject($db);
       foreach ((array) $tproject_mgr->get_list() as $p) { … }
   }
   ```

2. `testproject` (`lib/functions/testproject.class.php`) defines `get_all()`, `get_by_id()`,
   `get_by_name()`, `get_by_prefix()` and `get_accessible_for_user()` (line 490) — **but no
   `get_list()`**. Measured:

   ```
   bool(false)   <- method_exists(new testproject($db), 'get_list')
   bool(true)    <- method_exists(new testproject($db), 'get_accessible_for_user')
   Error: Call to undefined method testproject::get_list()
   ```

3. `display_errors` is off in the TestLink config, so the uncaught `Error` produced a **0-byte
   HTTP 500**.

**Correction to the original report** — the report stated the 500 "only reproduced with a session
that carries a test project". That does **not** apply to this route: `$action === 'projects'` reaches
`get_list()` for any authenticated caller; there is no test-project branch before it. The session
observation describes *when the screen renders the switcher*, not a server-side branch.

**Why it breaks in 2.0.1 only** — legacy had no project switcher (`lib/cfields/cfieldsTprojectAssign.php`
read the project from the session via `getTproj()`), so the phantom call was written against a method
that never existed on `testproject` and was never exercised in 1.9.20.

**Blast radius** — `grep -rn "\->get_list(" api/ lib/ gui/ --include=*.php` finds **no** other call
site (only the real `get_list_reports()` in `api/resultsnav/index.php:137`), so exactly this one
route was affected.

## Fix — use `get_accessible_for_user()`

```php
foreach ((array) $tproject_mgr->get_accessible_for_user(
             intval($userId), array('output' => 'map', 'order_by' => ' ORDER BY name ')
         ) as $pid => $p) {
    $pid = intval($pid);
    if ($pid <= 0) { continue; }
    if (!cfpaRequireManage($db, $user, $pid)) { continue; }
    $rows[] = array('id' => $pid,
                    'name'   => is_array($p) ? ($p['name'] ?? '')   : '',
                    'prefix' => is_array($p) ? ($p['prefix'] ?? '') : '');
}
```

**Approach and rejected alternatives**

| Option | Verdict |
|---|---|
| `testproject::get_all()` | Rejected. It lists **every** test project regardless of the caller's rights — the switcher would leak projects the user holds no role on. |
| Add a `get_list()` alias | Rejected. The method never existed; inventing it to shadow the real API hides the wrong-model call and duplicates `get_accessible_for_user()`. |
| **`get_accessible_for_user($userId, ['output'=>'map', …])`** | Chosen. It is the semantically correct source: it returns `$pid => $row` (the `output => 'map'` branch builds the map at `testproject.class.php:641-651`) and drops private projects the user holds no role on (the "Private test project feature" `WHERE` clause at `testproject.class.php:574-585`). The unchanged per-project `cfpaRequireManage()` filter then enforces `cfield_management` on each returned project. |

No source change was needed in this run — the fix was already on the default branch; this run adds
the missing regression suite, `docs/` page and wiki trail.

## Verification (live at http://localhost:8082)

Fixture: `php tmp/fixtures_1816.php` → `CFP Main`=1, `CFP Other`=2; users `cfp1816mgr` (Admin role
on project 1 only) and `cfp1816none` (no rights). The freshly imported DB has no test projects at
all, so the fixture is mandatory.

| Actor / call | HTTP | Body |
|---|---|---|
| `admin` `?action=projects` | **200** | `{"status":"ok","projects":[{"id":1,"name":"CFP Main","prefix":"CFPM"},{"id":2,"name":"CFP Other","prefix":"CFPO"}],"count":2}` — was **500 / 0 bytes** |
| `cfp1816mgr` `?action=projects` | **200** | project 1 only (`count:1`) |
| `cfp1816none` `?action=projects` | **200** | `{"status":"ok","projects":[],"count":0}` |
| unauthenticated `?action=projects` | **401** | `not_authenticated` |
| `admin` `?action=init&tproject_id=1` | **200** | context + linked(2) + available |
| `cfp1816mgr` `?action=init&tproject_id=2` | **403** | `no_right` |

- **Browser** (chrome-devtools MCP): opening `cfieldsTprojectAssign.html?tproject_id=1` renders the
  switcher with **CFP Main (CFPM)** and **CFP Other (CFPO)** and no *http_500* card; changing the
  switcher to *CFP Other* navigates to `?tproject_id=2` and loads that project's context
  (1 linked, 4 available). Console: no errors.
- **Event Viewer:** `events` table has only `log_level=16` audit rows — **zero Error/Warning**.
- Regression suite: `tmp/TLU_Test_Cases.md` → `## Regression — Issue #1817`, TC-1817-01 … TC-1817-12,
  **12/12 PASS**. `TLU_REQUIRE_SUITE="Issue #1817" bash ai/verify_test_suites.sh` → 7 PASS / 0 FAIL,
  180 → 181 suites, none lost.

## Testing it yourself

```bash
php tmp/fixtures_1816.php    # CFP Main=1 (2 linked) + CFP Other=2 (1 linked)
# log in admin/admin, then:
curl -s -b <cookiejar> 'http://localhost:8082/api/cfieldstproject/index.php?action=projects'
# -> HTTP 200 {"status":"ok","projects":[…],"count":2}
```

Then open `http://localhost:8082/gui/templates/cfields/cfieldsTprojectAssign.html?tproject_id=1`.
