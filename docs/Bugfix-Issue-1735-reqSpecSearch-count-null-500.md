# Bug fix — Issue #1735: `reqSpecSearch.php:116` `count()` on an uninitialised `$itemSet` — HTTP 500 / 0 bytes

## Symptom

`lib/requirements/reqSpecSearch.php` (legacy **Search → Requirement Specification
Results** screen) answered **HTTP 500 with a 0-byte body** — a blank page — for the
normal first hit, because of

```
Uncaught TypeError: count(): Argument #1 ($value) must be of type Countable|array, null given
  in …/lib/requirements/reqSpecSearch.php:116
```

`$gui->row_qty=count($itemSet);` sat **outside** the only block that assigns
`$itemSet`, so a state that skips that block makes `count(null)` a PHP-8 `TypeError`.
1.9.20 ran on PHP 5.x, where the same expression was only a warning and still rendered
an empty result table.

## Environment and fixture

- TestLink 2.0.1, PHP **8.3.35** built-in server (docroot = repo root), MariaDB
  `testlink` on `127.0.0.1:3306`, login `admin`/`admin`.
- The CI database is freshly imported on every run and ships with **zero** test
  projects, so the fixture `tmp/fixtures_1735.php` creates tproject `REQ1735`
  (requirements + ReqMgr integration enabled) with two user requirement specs
  `R1735-A` / `R1735-B`. The legacy controller reads the project from the **session**
  (`$_SESSION['testprojectID']`, `reqSpecSearch.php:init_args`), so it must be pinned
  with `GET /index.php?tproject_id=<id>` (a query parameter on the controller itself is
  ignored) — or, for the null-`$itemSet` flavour, no session project at all.

## Measured evidence

Current default branch (`bce8fb392`) — the URL the issue names:

```
$ curl -s -b jar -o /dev/null -w 'HTTP %{http_code} size=%{size_download} redirect=%{redirect_url}\n' \
    "$BASE/lib/requirements/reqSpecSearch.php"
HTTP 302 size=0 redirect=http://localhost:8082/gui/templates/requirements/searchReqSpec.html?tproject_id=1

# XHR flavour
HTTP 405 size=170
{"status":"error","code":"retired_endpoint","message":"This legacy endpoint no longer serves the search results. …"}
```

The modern replacement (`gui/templates/requirements/searchReqSpec.html` +
`api/requirements/index.php`):

```
$ curl ... "$BASE/api/requirements/index.php/reqspec-search?tproject_id=1"
HTTP 200
{"status":"ok","count":2,"rows":[{"req_spec_id":2,"doc_id":"R1735-A",…},
                                 {"req_spec_id":4,"doc_id":"R1735-B",…}],"warning":"" …}
```

Discrimination — restoring the pre-#1825 controller and probing as a user with **no**
session project reproduces the 500:

```
HTTP 500 size=289
  #0 reqSpecCommands.class.php(40): testproject->get_by_id(0)
  #1 reqSpecSearch.php(34): reqSpecCommands->__construct(…)
  [500]: … Uncaught Exception: testproject::get_by_id EXCEPTION: test project ID, is mandatory
```

## Root cause

| hop | file:line (pre-#1825) | code |
|---|---|---|
| 1 | `reqSpecSearch.php:43` | `$itemSet = null;` |
| 2 | `reqSpecSearch.php:104` | `if($args->tprojectID) { … }` — the **only** block that assigns `$itemSet` |
| 3 | `reqSpecSearch.php:113` | `$itemSet = $db->fetchRowsIntoMap($sql,'id',database::CUMULATIVE);` |
| 4 | **`reqSpecSearch.php:116`** | `$gui->row_qty=count($itemSet);` — unconditional |

`count()` on the conditionally-initialised variable is the reported defect. In practice a
request with `$_SESSION['testprojectID'] = 0` fatals one frame earlier, in
`reqSpecCommands::__construct()` (`reqSpecSearch.php:34` → `testproject->get_by_id(0)`),
and a request with a project reaches hop 4. Both end in HTTP 500 / 0 bytes — the same
user symptom.

## The fix — the retirement already landed in #1825

No new production code was needed: the controller that contained hop 4 was **deleted** by
the modernization of this screen.

- `d1b2a884c` — *feat(#1825): retire both legacy req-spec search controllers + switch
  the link* (2026-10-04, four days after this issue was filed) replaced
  `lib/requirements/reqSpecSearch.php` with a redirect shim (`Refs #1825`).
- `7e859c3b9` — *fix(#1825): array params rejected, Refresh keeps the selects, review
  NITs* hardened it.
- The screen is served by the modern Dashio page + BFF, whose counter is **initialised**
  (`api/requirements/index.php:2176` `$count = 0;`), so `count(null)` cannot occur. Its
  empty state is the explicit `else { $warning = 'no_records_found'; }`
  (`:2212-2214`) — the *Expected* behaviour from the issue.

Why close on this rather than re-patch the legacy file: patching `count($itemSet)`
would keep a second, redundant search implementation alive behind a page the ASIDE no
longer links to; the retirement removes the entire class of PHP-8 defects from the path
(including the unguarded `testproject->get_by_id(0)` at hop 1) and is already the
shipped behavior. The alternatives — a `count((array)$itemSet)` cast, or re-adding the
old renderer — were rejected for this reason.

## Blast radius

| reference | status |
|---|---|
| `lib/requirements/reqSpecSearch.php` | redirect shim only — no legacy code path |
| `gui/templates/dashio|tl-classic/requirements/reqSpecSearchForm.tpl:25` | legacy `form action` — **dead**; the screen is no longer rendered (modern action = `…/reqSpecSearchForm.html`, `lib/general/frmWorkArea.php:34`, `lib/functions/common.php:2571`) |
| `lib/functions/tlReqMgrSystem.class.php:673`, `api/requirements/index.php:2095` | comments only |

No surviving PHP caller renders the retired results path.

## Verification

`bash tmp/verify_1735.sh` — self-cleaning, creates its own fixture, exits non-zero on any
failure: **15 PASS / 0 FAIL** (exit 0). It asserts the legacy URL returns 302 (browser) /
405 JSON (XHR) and never 500, the modern screen loads, the BFF returns the two specs,
the filtered count, the `no_records_found` empty state, zero Event-Viewer Error/Warning
rows, and that neither the shim nor the BFF contains `count($itemSet)`.

## Files changed

| file | purpose |
|---|---|
| `docs/Bugfix-Issue-1735-reqSpecSearch-count-null-500.md` | this document |
| `tmp/verify_1735.sh`, `tmp/fixtures_1735.php` | regression harness + fixture (gitignored local test record) |
| `tmp/TLU_Test_Cases.md` | `## Regression — Issue #1735 …`, 11 numbered cases (gitignored) |

## Related

- Issue **#1629** (`tlReqMgrSystem::getInterfaceObject` 500) — the sibling that, once
  fixed, let the request reach this screen's own defect; deliberately did not fix #1735
  (`docs/Bugfix-Issue-1629-ReqMgrSystem-GetInterfaceObject-500.md`).
- Issue **#1825** — the modernization that retired this controller.
