# TestLink 2.0.1 — Test Cases

## Task — Issue #1026: hierarchical requirement-spec tree in reqSpecMgmt (gap vs legacy)

**Precondition** (recreate on every run, DB is freshly imported):
```bash
mysql -h 127.0.0.1 -utestlink -ptestlink testlink < /tmp/opencode/fixture_1026.sql
```
Fixture: TP 1 "RefSuite" with `RS-100 Inventory Management Spec` (REQ-101, REQ-102
+ nested `RS-200 Nested Order Spec` with REQ-201) and top-level
`RS-300 Export Compliance Spec` (REQ-301). Login `admin/admin`.
Screen: `http://localhost:8082/gui/templates/requirements/reqSpecMgmt.html?tproject_id=1`

| # | Steps | Expected (legacy) | Actual | Result |
|---|---|---|---|---|
| 1026-1 | open the screen, read the Reqs badge of RS-100 | **3** (2 own + 1 in the nested RS-200) — `treeMenu.inc.php:2149-2150` | `3`, tooltip "3 requirement(s) in this specification and its child specifications (2 direct)" | PASS |
| 1026-2 | read the header total line | project total = **4** (`get_all_requirement_ids`) | "total requirements in RefSuite: 4" | PASS |
| 1026-3 | check the row order | RS-200 indented **under** RS-100, RS-300 at top level | RS-100 (depth 0, "2 sub"), RS-250 (depth 1), RS-200 (depth 1), RS-300 (depth 0) | PASS |
| 1026-4 | click the `-` toggle on RS-100 | only its children disappear | rows → RS-100, RS-300 | PASS |
| 1026-5 | click **Expand all** | all children return | RS-100, RS-250, RS-200, RS-300 | PASS |
| 1026-6 | click **Collapse all** | every spec with children folds | RS-100, RS-300 | PASS |
| 1026-7 | collapse RS-100, reload the page | collapse state restored per project (`localStorage`) | 2 rows after reload, key `TL_req_spec_mgmt_collapsed_1 = ["10"]` | PASS |
| 1026-8 | **Create Spec** → open the Parent select | dotted-path list, "top level" default | options `0`, `10 RS-100 (1)`, `11   RS-200`, `12 RS-300` | PASS |
| 1026-9 | create `RS-250` with parent = RS-100 | created **nested** under RS-100 | HTTP 200 `{"status":"ok","id":26}`; RS-100 badge → "2 sub" | PASS |
| 1026-10 | **Edit** RS-100, look at the Parent select | own parent shown, itself never offered as its own parent | value `0`, select disabled, RS-100 absent from the options | PASS |
| 1026-11 | `POST action=specs` with `filter_*` (regression #1025) | identical to pre-change behaviour | identical (0 matches — the fixture has no `req_versions`↔`nodes_hierarchy` version nodes; verified the same on the **baseline** commit) | PASS |
| 1026-12 | `php -l api/reqspec/index.php`, `node --check` on the screen JS, `python3 -m json.tool` on all 10 bundles | clean | all clean | PASS |

**Bug found while testing (fixed in this run):** 1026-9 first returned **HTTP 500**
(empty body) for every `create_spec` with a `parent_id` — `needOwnedSpec()`'s dead
`requirement_spec_mgr::get_by_id()` call fatals without a `latest_rspec_revision`
view row. Fixed in `api/reqspec/index.php:125-146`.

## Regression — Issue #1711: `bugzillaxmlrpcInterface` element-valued `<version>` / `<urixmlrpc>` raise an uncatchable `Error`

**Precondition.** Fresh DB (`testlink` @ 127.0.0.1:3306), app on `http://localhost:8082`,
PHP 8.3.35, branch `fix/issue-1711` (base `30aeecd0d`). Login `admin`/`admin`.
`DELETE FROM events;` before every live-HTTP batch so the Event Viewer rows are
unambiguous. Tracker type `1` = `bugzilla` / `api: xmlrpc` →
`bugzillaxmlrpcInterface` (`lib/functions/tlIssueTracker.class.php:32-35`).

**Harness.** `php tmp/repro_1711.php` — calls the real constructor
`new bugzillaxmlrpcInterface(0,$cfg,'repro')`, i.e. exactly what
`api/issuetracker/index.php:260` and `tlIssueTracker::checkConnection()` do, wrapped
in `catch(Throwable)` so an `Error` is *observable* instead of fatal. Exit 1 if any
case throws or raises a PHP diagnostic. (`tmp/` is gitignored; the script is pasted
here in full so the suite is reproducible from the repo alone.)

**Repro steps (pre-fix).**

1. Create Issue Tracker → Type `bugzilla (Interface: xmlrpc)` → Configuration:
   `<issuetracker><uribase>http://b/</uribase><version><x/></version></issuetracker>`
2. Press **Check connection**.
3. **Observed pre-fix:** red alert, `POST /api/issuetracker/test-connection` → **502**
   `{"status":"error","connected":false,"message":"Connection check failed"}`, and
   `events` gains `log_level=1` `…POST /test-connection :: Object of class stdClass
   could not be converted to string`.

**Expected post-fix.** The offending field is read as a string (or falls back to the
carved-on-the-stone default); the check answers `200 {"connected":true}`; the Event
Viewer records **one WARNING naming the element**, and **no ERROR row**.

| # | Steps | Expected | Actual | Result |
|---|---|---|---|---|
| 1711-1 | harness, cfg `<version><x/></version>` | default `unspecified`, no throw | `connected=true`, `version=unspecified`; pre-fix `THROWN Error: Object of class stdClass could not be converted to string` | PASS |
| 1711-2 | harness, cfg `<platform><x/></platform>` | default `All` | `connected=true`, `platform=All`; pre-fix same `Error` | PASS |
| 1711-3 | harness, cfg `<urixmlrpc><x/></urixmlrpc>` | derived `http://b/xmlrpc.cgi` (old `:294` cast site) | `connected=true`, `urixmlrpc=http://b/xmlrpc.cgi`; pre-fix same `Error` | PASS |
| 1711-4 | harness, cfg `<uriview><x/></uriview>` | derived `http://b/show_bug.cgi?id=` | `connected=true`, correct URL | PASS |
| 1711-5 | harness, cfg `<uricreate><x/></uricreate>` | derived `http://b/` | `connected=true`, correct URL | PASS |
| 1711-6 | harness, `<uribase>  </uribase>` + nested `<version>` (the #1619 case) | no throw, `$base` degrades to `/` | `connected=true`, `urixmlrpc=/xmlrpc.cgi` | PASS |
| 1711-7 | harness, `<version>1.0</version>` (scalar **control**) | value preserved **byte-identically** | `version=1.0` | PASS |
| 1711-8 | harness, no `<urixmlrpc>` at all | derived `http://b/xmlrpc.cgi` | as expected | PASS |
| 1711-9 | harness, no `product`/`component` | `canCreateViaAPI()` must stay **`false`** — the loop must never CREATE the property | `canCreateViaAPI=false` | PASS |
| 1711-10 | harness, nested `<product>` + scalar `<component>c</component>` | `product` coerced to `''`, `canCreateViaAPI()` still `true` (property existed) | `product=`, `component=c`, `canCreateViaAPI=true` | PASS |
| 1711-11 | harness, **empty element** `<platform/>` (review-discovered; likelier in the wild than an explicit child) | default `All` | `connected=true`, `platform=All` | PASS |
| 1711-12 | harness, **whitespace-only** `<platform>  </platform>` | default `All` | `connected=true`, `platform=All` | PASS |
| 1711-13 | harness, **repeated** `<platform>a</platform><platform>b</platform>` → PHP array | default `All` (pre-fix: literal `"Array"` + `E_WARNING Array to string conversion`) | `connected=true`, `platform=All` | PASS |
| 1711-14 | harness, **attribute-only** `<platform x="1"/>` | default `All` | `connected=true`, `platform=All` | PASS |
| 1711-15 | `curl` live, the 3 reported cfgs (JSON body, `X-Requested-With` + `Origin` proof) | **200** `{"status":"ok","connected":true}` | all 3 × 200; pre-fix all 3 × **502** | PASS |
| 1711-16 | **browser** via chrome-devtools, *Create Issue Tracker* → bugzilla/xmlrpc → nested `<version>` → **Check connection** | green `Connection successful`, network `[200]` | `alert alert-success :: Connection successful`, `POST /api/issuetracker/index.php/test-connection` `[200]` | PASS |
| 1711-17 | Event Viewer after the whole suite | **0** new `log_level=1` rows; one `log_level=2` WARNING **naming the element** | only `bugzillaxmlrpcInterface::completeCfg [UIRepro1711] :: cfg field <version> is not a text value, using default 'unspecified'`; pre-fix 3 ERROR rows | PASS |
| 1711-18 | grid renders a stored row whose cfg is still element-valued | row visible, Environment `OK` | `BZ1711` listed, Server URL `http://b/`, Environment `OK` | PASS |
| 1711-19 | `php -l lib/issuetrackerintegration/bugzillaxmlrpcInterface.class.php` | clean | `No syntax errors detected` | PASS |
| 1711-20 | the **code-review refactor** (single `cfgIsNotText()` predicate) re-run through the whole matrix | no behaviour change | **regression caught and fixed during the run** — see the note below — then 14/14 PASS, harness exit 0 | PASS |

**Note on 1711-20 (honest record).** The code review suggested collapsing the
duplicated `property_exists() && !is_scalar()` predicate into one helper. Doing so
introduced a real bug: `cfgStr()` tested only "present AND not text", so a **missing**
member fell through to `(string)$this->cfg->$prop` and raised 8 ×
`Undefined property: stdClass::$…` — the harness caught it (10/10 cases FAIL),
`cfgStr()` was corrected to `!property_exists(...) || $this->cfgIsNotText(...)`, and
the matrix returned to 14/14 PASS. Recorded because it is the reason the harness
asserts on *diagnostics* and not only on the return value.

**Not fixed / filed, not regressions.** The legacy empty-HTTP-500 half of the report
is unreachable: `lib/issuetrackers/issueTrackerView.php` and its
`?doAction=checkConnection` route were **removed in #966** (measured HTTP 404, dir
empty) and `tlIssueTracker::checkConnection()` has no remaining caller. The 8 sibling
classes carrying the same cast family (`fogbugzrest`, `gforgesoap`, `jirarest`,
`jirasoap`, `mantissoap`, `redminerest`, `tracxmlrpc`, `tuleaprest`) are out of scope
for a one-bug run and were filed as a follow-up.
