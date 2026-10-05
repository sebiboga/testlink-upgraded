# Bugfix — Issue #1696: `lib/ajax/getrequirementnodes.php` leaked requirement doc_ids/titles of every project

**Area:** Requirement Specification Tree (legacy lazy loader)
**Impact:** Broken access control (IDOR) — every logged-in user could read the requirement
specification `doc_id`s, requirement `req_doc_id`s, titles and the shape of the requirement
branch of **any** test project, and walk the whole `nodes_hierarchy`.
**Status:** fixed in `18c9680c2` (branch `fix/issue-1696`), suite in `cb00fc15e`.

---

## 1. Symptom

The legacy requirement specification tree lazy loader returned the requirement tree of **any**
node to **any** authenticated user. It performed no rights check at all — only a session check
— and resolved whatever node id the caller passed.

## 2. Environment

* App: `http://localhost:8082` (PHP built-in server, docroot = repo root), repo at `ba3f3ed75`.
* DB: MariaDB `127.0.0.1:3306`, database `testlink`.
* **The freshly imported database is EMPTY** — `testprojects`, `req_specs`, `requirements` and
  `user_testproject_roles` all hold 0 rows. There is no "project B" to leak until you build
  one, which is why this class of bug is invisible right after an import. Fixtures are given
  in the regression suite in `tmp/TLU_Test_Cases.md`.

## 3. Root cause — the `file:line` chain

| hop | location | what happens |
|---|---|---|
| 1 | `lib/ajax/getrequirementnodes.php:19-21` | `require_once('../../config.inc.php'); require_once('common.php'); testlinkInitPage($db);` — the **one-argument** form. It boots the page and checks the SESSION only. `grep -c "hasRight\|checkRights"` on the pre-fix file → **0**. |
| 2 | `lib/ajax/getrequirementnodes.php:23-28` | The *scope* of the answer is read straight out of the request: `$root_node`, `$node`, `$filter_node`. Nothing validates that `$node` is a requirement node, and nothing ties `$node` back to `$root_node`. |
| 3 | `lib/ajax/getrequirementnodes.php:30` | `display_children($db, $root_node, $node, …)` runs before any decision about the caller's rights. |
| 4 | `lib/ajax/getrequirementnodes.php:32` | `echo json_encode($nodes);` — the payload leaves the process. |
| 5 | pre-fix `display_children()` SQL | `… WHERE NHA.parent_id = intval($parent)` **only** (`filter_node` narrows the ROOT children, it does not scope). Any `nodes_hierarchy.id` is a valid parent. |
| 6 | pre-fix `case 'requirement_spec'` / `case 'requirement'` | `$path['text'] = "{$doc_id}:{$name}"` is built from `req_specs.doc_id` / `requirements.req_doc_id` and the whole row goes to ExtJS. **The doc_id — the identifier that keys the requirement tracker — is disclosed before the name.** |

### Why it became reachable

1.9.20 only ever reached this file through the `reqSpecListTree` frame, and that frame had
already done its role check — the loader's own missing check was masked by the frame.
`lib/requirements/reqSpecListTree.php` became a 302 shim to the modern screen in **Refs #1695**,
so **the mask was removed while the loader was left in place**: the endpoint became directly
reachable. The defect itself is legacy 1.9.20 code, not a regression introduced by this cycle.

## 4. Measured reproduction (pre-fix)

Attacker: role `3` = `<no rights>`, with an **empty `user_testproject_roles`** — a pure
authenticated IDOR, not a misconfigured grant.

```
$ curl -s -b c1696.txt '…/lib/ajax/getrequirementnodes.php?mode=reqspec&root_node=1'
[{"text":"SPEC-B-001:Secret Spec B (1)","id":"2",…,"href":"javascript:REQ_SPEC_MGMT(1,2)"}]   HTTP 200

$ curl -s -b c1696.txt '…/lib/ajax/getrequirementnodes.php?mode=reqspec&root_node=1&node=2'
[{"text":"REQ-B-001:Secret Requirement B1","id":"3",…,"href":"javascript:REQ_MGMT(1,3)"}]       HTTP 200

$ for n in 1 2 3 5 10 11; do curl -s "…/getrequirementnodes.php?node=$n"; done
node=1   -> SPEC-B-001:Secret Spec B (1)     href="javascript:REQ_SPEC_MGMT(,2)"
node=2   -> REQ-B-001:Secret Requirement B1  href="javascript:REQ_MGMT(,3)"
node=10  -> SPEC-A-001:Spec A1 (0)           href="javascript:REQ_SPEC_MGMT(,11)"
node=3 / node=5 / node=11 -> null
```

Two extras beyond the original report:

* `root_node` is optional and becomes an **empty string** in the emitted href — with only
  `node=<id>` the JSON contains `REQ_SPEC_MGMT(,2)`: a node addressed with no project at all.
* There is **no node-type gate on the requested node**, only on the returned children
  (`NT.description NOT IN ('testcase','testsuite',…)`), so any `nodes_hierarchy.id` is accepted
  as a parent and the endpoint doubles as a generic hierarchy probe.

Layer-by-layer:

| layer | measurement | verdict |
|---|---|---|
| session auth | anonymous GET → `top.location.href='../../login.php?note=expired&…'` | **works** — this is IDOR, not unauthenticated |
| **rights** | no `hasRight`, no role check, no project-scope check | **the bug** |
| modern replacement | `api/reqspectreelist/index.php` → `403 no_right` / `404`, rights checked BEFORE resolution (`:154-167`) | **properly guarded** |
| Event Viewer | `SELECT COUNT(*) FROM events` → **0** | the disclosure left **no trace at all** |

## 5. Blast radius

```
$ grep -rn "getrequirementnodes" --include='*.php' --include='*.tpl' --include='*.html' \
      --include='*.js' --include='*.md' . | grep -v '^./tmp/' | wc -l
25      # 1 live reference (tlRequirementFilterControl.class.php:272) + 24 comment/doc mentions
$ grep -rn "new tlRequirementFilterControl" . | wc -l
0       # the one live reference is UNREACHABLE dead code
```

* **Reachable by:** every authenticated user, any role, any project.
* **Data at risk:** `req_specs.doc_id` and titles, `requirements.req_doc_id` and titles, the
  requirement-branch shape of every project, plus a generic `nodes_hierarchy.id` probe.
* **Not at risk:** anything without a session (measured), and nothing else — the endpoint is
  strictly read-only.
* **Live callers that must keep working:** none. The modern tree is
  `api/reqspectreelist/index.php`, which never calls this file.
* **Siblings already fixed, same bug class, untouched here:**
  `lib/ajax/gettprojectnodes.php:31` (#1770) and `lib/ajax/getreqcoveragenodes.php:31` (#1765).
  `lib/ajax/gettestcasesummary.php:27` and `lib/ajax/requirements/getreqmonitors.php:14` only
  *mention* #1696 in a comment.

## 6. The fix

`lib/ajax/getrequirementnodes.php` is **retired in place as a non-mutating 302 shim**, the exact
shape its two already-fixed siblings use (`gettprojectnodes.php:57-121`):

1. **Preserve** the legacy `testlinkInitPage()` session contract via
   `doDBConnect()` + `checkSessionValid($db)` — an anonymous visitor is still bounced to
   `login.php?note=expired&destination=…`. `checkSessionValid()` is used rather than a
   hand-rolled `header()` because it walks up from `dirname(SCRIPT_FILENAME)` until it finds
   `login.php`; a relative `'login.php'` would resolve against `/lib/ajax/` and 404.
2. **Refuse every non-`GET`/`HEAD` verb** with `405` + JSON
   `{"code":"method_not_allowed", …}` and a `tLog(…, 'WARNING')` row, so the retirement shows up
   in the Event Viewer instead of being a silent behaviour change.
3. **Delete `display_children()` and all of its SQL** — that function *was* the leak.
4. `302` a legacy `GET` to `gui/templates/requirements/reqSpecListTree.html`, carrying
   `tproject_id` from **`tproject_id` or the legacy `root_node`** (first one > 0 wins) plus
   `filter_node` when present, so an old bookmark or the dead
   `tlRequirementFilterControl.class.php:272` URL still lands on a working screen. The
   `$_SESSION['basehref']` base is honoured, as on every sibling shim, so a sub-directory
   installation is not redirected off the document root.
5. The read is deliberately **NOT replayed** — it was never authorized. The modern screen and
   its BFF decide what to reveal, per request, with a real rights check.

### Alternatives considered and rejected

| alternative | why rejected |
|---|---|
| **Delete the file outright** (the issue's first suggestion) | `lib/functions/tlRequirementFilterControl.class.php:272` still builds this URL into its loader. That call path is dead (`new tlRequirementFilterControl` → 0 hits), but deleting would turn it into a **404** — a strictly larger and less friendly change than the convention the repo already established for #1770/#1765, which both kept the file and redirected it. |
| **Add the two rights checks to the existing loader** | Sound security-wise, but it leaves a legacy ExtJS surface alive and answering, reachable from a dead call path, with SQL driven by request ids. Retiring it removes the class of bug instead of guarding it, and the modern BFF (`api/reqspectreelist/index.php:154-196`) already implements both checks *plus* the node-type and project-scope proofs the loader lacked. |
| **Touch `tlRequirementFilterControl::build_tree_menu()` to drop the dead loader string** | Unrelated cleanup. With the file still present and now redirecting, the dead URL resolves; there is no functional need to touch the class. |

## 7. Verification (post-fix, `18c9680c2`) — 10/10 PASS

| # | step | result |
|---|---|---|
| R1 | anonymous `GET ?mode=reqspec&root_node=1` | HTTP 200, body has `login.php?note=expired` — **unchanged** |
| R2 | `<no rights>` `GET ?mode=reqspec&root_node=1` | HTTP 302 → `…/reqSpecListTree.html?tproject_id=1`; `grep -c 'SPEC-B-001\|REQ-B-001\|Secret'` → **0** |
| R3 | `<no rights>` `GET ?node=2` | HTTP 302 → `…/reqSpecListTree.html`, no project param; leak check → **0** |
| R4 | `POST` / `PUT` / `DELETE` | HTTP 405 + `method_not_allowed` for all three, no data |
| R5 | `admin` `GET ?mode=reqspec&root_node=1&filter_node=2` | HTTP 302 → `…?tproject_id=1&filter_node=2` — `filter_node` preserved |
| R6 | `admin` `GET /api/reqspectreelist/index.php?action=init&tproject_id=1` | HTTP 200 `{"status":"ok",…,"grant":{"view":true,"modify":true}}` — modern tree unaffected |
| R7 | `<no rights>` same endpoint | HTTP 403 `no_right` — **unchanged** |
| R8 | `GET /lib/requirements/reqSpecListTree.php?tproject_id=1` | HTTP 302 → `…/reqSpecListTree.html?tproject_id=1` — **unchanged** |
| R9 | `php -l lib/ajax/getrequirementnodes.php` | `No syntax errors detected` |
| R10 | `events` table | 3 rows at `log_level` 2 (WARNING) = exactly the R4 refusals; **no Error row** |

Regression suite: `tmp/TLU_Test_Cases.md`, heading
`## Regression — Issue #1696: legacy requirement tree loader lib/ajax/getrequirementnodes.php retired (IDOR)`
— 10 cases, appended with `>>`, gate `TLU_REQUIRE_SUITE="Issue #1696" bash ai/verify_test_suites.sh`
→ `7 PASS / 0 FAIL / 0 SKIP`.

## 8. Files changed

| file | purpose |
|---|---|
| `lib/ajax/getrequirementnodes.php` | rewritten as the session-guarded 302 shim; `display_children()` and its SQL deleted |
| `CHANGELOG` | one-line summary of the security fix under the 2.0.1 key-bugfix section |
| `docs/Bugfix-Issue-1696-Requirement-Tree-Loader-Unauthorized-IDOR.md` | this document |
| `tmp/TLU_Test_Cases.md` | the 10-case regression suite (append-only, shared file) |

## 9. Resuming / re-testing in one pass

```bash
php -l lib/ajax/getrequirementnodes.php
curl -s -c /tmp/c -X POST http://localhost:8082/api/auth/login \
     -H 'Origin: http://localhost:8082' -d login=admin -d password=admin -o /dev/null
curl -s -o /dev/null -w '%{http_code} %{redirect_url}\n' -b /tmp/c \
     'http://localhost:8082/lib/ajax/getrequirementnodes.php?mode=reqspec&root_node=1'
# expect: 302 http://localhost:8082/gui/templates/requirements/reqSpecListTree.html?tproject_id=1
curl -s -o /dev/null -w '%{http_code}\n' -b /tmp/c -X POST \
     'http://localhost:8082/lib/ajax/getrequirementnodes.php?root_node=1'
# expect: 405
grep -rn "display_children" lib/ajax/getrequirementnodes.php   # expect: no output
```
