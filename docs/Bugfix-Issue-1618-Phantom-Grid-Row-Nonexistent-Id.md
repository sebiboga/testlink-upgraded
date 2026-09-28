# Bugfix — Issue #1618: a non-existent `?id=` on the Issue Tracker list must not create a phantom grid row

**Status:** resolved — **no code change required** (verified as an already-fixed report).
**Labels:** `bug` · **Reported:** 2026-09-26T13:36:47Z · **Verified:** 2026-09-28
**Branch:** `fix/issue-1618`

---

## 1. Symptom as reported

`lib/issuetrackers/issueTrackerView.php` rendered **one extra, empty grid row** and logged
**8 `E_WARNING` rows** in `events` on every load whenever the `id` request parameter did not
match any existing `issuetrackers` row:

```
E_WARNING Undefined array key "id"            (x2)
E_WARNING Undefined array key "name"
E_WARNING Undefined array key "type_descr"
E_WARNING Undefined array key "env_check_msg"
E_WARNING Undefined array key "link_count"
```

The Issue Tracker Management grid therefore showed a blank, un-clickable row that no record
corresponded to.

## 2. The approach chosen, and why

Two options were available:

1. **Add the `isset()` guard the report suggested.**
2. **Prove the defect is unreachable, and land a regression harness instead.**

Option 1 was rejected after the file was found to be **absent from the tree**. Writing a
"fix" into a deleted controller would be churn on code that no longer exists — and, worse,
it would imply the report was still live when it is not. The correct deliverable for a
stale report is the **evidence that it is stale**, plus a guard that makes the defect class
fail loudly if it ever returns.

## 3. Investigation

### 3.1 The reported file does not exist

```
$ ls lib/issuetrackers/
ls: cannot access 'lib/issuetrackers/': No such file or directory

$ find . -name 'issueTrackerView*'
(nothing — no .php, no .tpl, no .html)
```

### 3.2 The reported URL is a hard 404 — no PHP controller is ever entered

```
$ curl -o /tmp/leg.html -w 'http=%{http_code} size=%{size_download}\n' \
    "http://localhost:8082/lib/issuetrackers/issueTrackerView.php?tproject_id=1&id=999"
http=404 size=596

$ head -c 120 /tmp/leg.html
<!doctype html><html><head><title>404 Not Found</title>...
```

The 596-byte body is the **PHP built-in server's 404 stub**, not a TestLink error page. So
no Smarty template is rendered, no `foreach` runs, and **zero rows can reach `events`**
(verified: the `events` count was unchanged).

### 3.3 The report is stale by exactly one commit

```
$ git log --oneline --all -- lib/issuetrackers/issueTrackerView.php
596444f30 chore(issuetracker): delete legacy issuetrackerView cluster (Refs #966) (#1669)
...

$ git log -1 --format='%h %ad' --date=iso 596444f30
596444f30 2026-09-27 19:41:11 +0300

$ git merge-base --is-ancestor 596444f30 HEAD && echo YES-ancestor
YES-ancestor
```

`596444f30` (merge of PR #1669, Refs #966) deleted all 15 files of the cluster, including
**both** files named in the report:

```
 lib/issuetrackers/issueTrackerView.php                    |  66 ---
 gui/templates/dashio/issuetrackers/issueTrackerView.tpl   | 108 ---
```

**Timeline.** Issue #1618 was filed `2026-09-26T13:36:47Z`, listed as *"found, not fixed"*
in the #1617 CHANGELOG entry (`CHANGELOG:1144`). The deletion landed
`2026-09-27T19:41:11+03:00` — **one day later**. The report was resolved out-of-band by #966
before this run picked it up.

## 4. Root cause — and why it is now unreachable

The defect was an **auto-vivification** of an id-keyed map:

```php
// lib/issuetrackers/issueTrackerView.php:27-28  (DELETED)
if($args->id > 0) {
  $gui->items[$args->id]['connection_status'] = $issueTrackerMgr->checkConnection($args->id) ? 'ok' : 'ko';
}
```

In PHP, assigning to `$arr[<missing key>]['x']` **creates** the entry. `$gui->items` gained an
element holding *only* `connection_status` — no `id`, `name`, `type_descr`, `env_check_msg`
or `link_count` — and the template's `{foreach key=item_id item=item_def from=$gui->items}`
(`issueTrackerView.tpl:51-52`) rendered that stub as a real row, raising the 8 warnings.

It is now unreachable for **three independent reasons**:

1. **The issuetracker copy is deleted** (above) — the route 404s.
2. **The surviving twin is already guarded**, by the fix for the sibling issue **#1625** —
   `lib/reqmgrsystems/reqMgrSystemView.php:28-37`:

   ```php
   if($args->id > 0 && isset($gui->items[$args->id]))
   {
     // Issue #1625: the probe is only stamped on a row that really exists.
     $gui->items[$args->id]['connection_status'] = $mgr->checkConnection($args->id) ? 'ok' : 'ko';
   }
   ```

   `isset($gui->items[$args->id])` is *precisely* the guard this issue suggested; the
   in-code comment names #1625. This is the same defect family, fixed one screen over
   (`CHANGELOG:6628-6670`).
3. **The modern BFF replacement has no hole either** — `api/issuetracker/index.php` builds a
   **list**, not an id-keyed map, and 404s on a missing id:
   - `:128-134` — `$items[] = trackerToJSON($item, $mgr);` in a plain append loop, so no
     key can be auto-vivified.
   - `:196-200` — `$item = $mgr->getByID($id); if (!$item) { http_response_code(404);
     out(['status'=>'error','message'=>'Issue tracker not found']); }`

   Measured, with a real tracker (id=1) **and** a bogus id (999) present:
   ```
   GET /api/issuetracker/999/check-connection  -> 404  {"status":"error","message":"Issue tracker not found"}
   GET /api/issuetracker/?tproject_id=1       -> 200  {"status":"ok","canManage":true,"items":[1],"total":1}
   ```
   Both produced **0 new rows in `events`**.

## 5. Blast radius

`grep -rn "connection_status'\] =" --include=*.php` over the whole tree returns 4 hits; only
2 are legacy controller assignments, and **1 of those 2 is guarded**:

| File:line | Status |
|---|---|
| `lib/issuetrackers/issueTrackerView.php:27` | **DELETED** by `596444f30` (#966) |
| `lib/reqmgrsystems/reqMgrSystemView.php:36` | **guarded** at `:28` by `isset()` (#1625) |
| `lib/functions/tlIssueTracker.class.php:626` | builds a local `$item` — no auto-vivify |
| `lib/functions/tlCodeTracker.class.php:576` | builds a local `$item` — no auto-vivify |
| `api/reqmgrsystems/index.php:108` | writes into a real `$item` from `getAll()` — no auto-vivify |

**No remaining code path can produce a phantom row from an `?id=`.**

## 6. Files changed

| File | Purpose |
|---|---|
| `tmp/verify_1618.sh` | **new** — 8-case regression harness (exit 0 = all PASS); fails loudly if the defect class returns |
| `tmp/TLU_Test_Cases.md` | **new** — `Regression — Issue #1618` suite, 8/8 PASS + the fixture traps |
| `CHANGELOG` | **new** — one `[KEY BUGFIX] - #1618` line, placed next to #1617 (the run that filed it) |
| `docs/Bugfix-Issue-1618-Phantom-Grid-Row-Nonexistent-Id.md` | **new** — this page (no image lines; screenshots live in the wiki) |
| `lib/issuetrackers/issueTrackerView.php`, `gui/templates/dashio/issuetrackers/issueTrackerView.tpl` | **already deleted** by `596444f30` (#966) — deliberately untouched |
| `lib/reqmgrsystems/reqMgrSystemView.php` | **already guarded** by #1625 — deliberately untouched |

No production code was modified, so **no i18n bundle was touched** and no
`python3 -m json.tool` gate was needed.

## 7. Verification — `bash tmp/verify_1618.sh`, **8/8 PASS, exit 0**

Fixtures: `issuetrackers` id=1 (`type=2` bugzilla/`db`, unreachable host `127.0.0.1:1`) and
`reqmgrsystems` id=1 (`type=1` — the **only** key of `tlReqMgrSystem::$systems`). `events`
emptied before each step; only `log_level=2` (`E_WARNING`) rows counted, because the defect's
signature was 8 template-raised warnings.

| # | Check | Expected | Measured | Result |
|---|---|---|---|---|
| M1 | `GET /api/issuetracker/?tproject_id=1` | `total` == 1 == real rows, 0 new events | `total=1`, 0 new rows | **PASS** |
| M2 | `GET /api/issuetracker/1/check-connection` | 200/502 **with** verdict, 0 new `E_WARNING` | `http=502`, `connected` present, 0 new `E_WARNING` | **PASS** |
| M3 | `GET /api/issuetracker/999/check-connection` | 404 + "not found", 0 new events | `http=404`, 0 new rows | **PASS** |
| M4 | `GET /lib/issuetrackers/issueTrackerView.php?id=999` | 404 (deleted) | `http=404`, 596-byte stub, 0 new rows | **PASS** |
| M5 | same route, no `id` | 404 | `http=404`, 0 new rows | **PASS** |
| M6 | `reqMgrSystemView.php?id=999` (surviving twin) | 200, edit-links == 1, 0 new events | `http=200`, 1 link, 0 new rows | **PASS** |
| M7 | `reqMgrSystemView.php`, no `id` | 200, 1 edit link, 0 new events | `http=200`, 1 link, 0 new rows | **PASS** |
| M8 | `GET /api/reqmgrsystems/?tproject_id=1` | `total` == 1, 0 new events | `total=1`, 0 new rows | **PASS** |

**Browser (headless Chrome, `admin`/`admin`).**

- `gui/templates/issuetracker/issuetrackerView.html?tproject_id=1` → DataTables footer
  **"Showing 1 to 1 of 1 entries"**; the one row is the fixture
  (`IT-FIXTURE-1618` · `bugzilla (Interface: db)` · `http://127.0.0.1:1/` · env `OK`).
  **No blank row, no un-clickable stub.**
- The same URL **plus `&id=999`** → still **"Showing 1 to 1 of 1 entries"**, row count
  unchanged. The modern screen ignores a bogus `id` for the list.
- Wrench ("Check connection") clicked on the real row → no crash.
- **Console: 0 errors, 0 warnings.**

## 8. Notes — two fixture traps (both unrelated defects, filtered out)

1. A `reqmgrsystems` fixture of `type=2` raises 6 `E_WARNING`s per load from
   `tlReqMgrSystem.class.php:522/523/113` — that is the separate, already-tracked **#1626**
   (*"row whose type is not in `$systems`"*), not #1618.
   `tlReqMgrSystem::$systems` (`:33`) has exactly one entry, `1 => contour/soap`.
2. A `cfg` that is a bare JSON blob instead of an XML string makes
   `issueTrackerInterface::setCfg()` log `Failure loading XML STRING`
   (`issueTrackerInterface.class.php:121`) — also unrelated.

Both were excluded so the matrix measures only the phantom-row defect. M2 legitimately
produces one `log_level=1` ERROR: that is the **correct** log for a deliberately
unreachable tracker host.

## 9. New defects found while testing (filed, NOT fixed — FIX-ISSUE.md §4)

- **#1700** — `lib/issuetrackerintegration/issueTrackerInterface.class.php:202` reads
  `$this->cfg->dbhost` / `$this->cfg->dbuser` **unguarded**, so a `cfg` XML without those
  nodes logs `E_WARNING Undefined property: stdClass::$dbhost` on every connection check.
  Sibling of #1619, different file. Suggested fix: `!isset(...)`.
- **#1701** — `api/issuetracker/index.php:217` logs
  `" Object of class stdClass could not be converted to string"` with **no provenance**,
  because `__METHOD__` expands to `""` at the **top level of a request script** (measured
  with a standalone PHP 8 script: top level → `[]`, closure → `[{closure}]`). The
  connection check still returns the correct verdict, so the cost is an unattributable ERROR
  row in the Event Viewer that CI inspects after every run. The origin of the swallowed
  `TypeError` is documented as an explicitly **unconfirmed** hypothesis needing one
  bisection probe.

## 10. How to re-test

```bash
bash tmp/verify_1618.sh ; echo "exit=$?"     # expect exit=0, 8/8 PASS
```

Browser: log in `admin`/`admin` → `http://localhost:8082/gui/templates/issuetracker/issuetrackerView.html?tproject_id=1`
→ footer must read **"Showing 1 to 1 of N entries"** → append **`&id=999`** and reload →
the footer must be **unchanged** and no blank row may appear.

Event Viewer: `SELECT id,log_level,description FROM events WHERE log_level=2 ORDER BY id;`
must gain **0** rows from either load.
