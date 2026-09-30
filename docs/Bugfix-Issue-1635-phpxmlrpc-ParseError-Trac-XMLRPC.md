# Bug fix — Issue #1635: `third_party/phpxmlrpc/lib/xmlrpc.inc` did not compile on PHP 8, taking the whole Issue Trackers grid down

## Symptom

Every request that transitively loaded the vendored XML-RPC client
`third_party/phpxmlrpc/lib/xmlrpc.inc` died with a **compile-time `ParseError`** and
answered **HTTP 500 with a 0-byte body**. The fatal is never routed through `tLog()`,
so the **Event Viewer stayed empty** — the crash was invisible in the product's own
audit trail (measured: `events` held 1 row, the unrelated `audit_login_succeeded`
LOGIN event, after two HTTP 500s).

The library is the Trac XML-RPC issue-tracker transport, loaded at file scope by
`lib/issuetrackerintegration/tracxmlrpcInterface.class.php:30`, so **as soon as one
Trac/XML-RPC issue tracker is configured, the modern Issue Trackers screen is dead**:

| Route (modern BFF) | Pre-fix | Post-fix |
|---|---|---|
| `GET /api/issuetracker/index.php` — **the grid itself** | **500**, 0 bytes → grid renders **EMPTY** | `200` with the Trac row |
| `GET /api/issuetracker/index.php/{id}/check-connection` — the row's wrench (*Check connection*) | **500**, 0 bytes | `200 {"status":"ok","connected":true,…}` |
| `POST /api/issuetracker/index.php/test-connection` — the modal's *Check Connection* | **500**, 0 bytes | `200 {"status":"ok","connected":…}` |
| `GET /api/issuetracker/index.php/cfg-template?type=19` — Configuration eye icon | **500**, 0 bytes | `200` + the `tracxmlrpcInterface` template |

The grid route was the widest of these: `tlIssueTracker::getAll()` resolves every row's
implementation class, so a single Trac row emptied the **whole** management screen
(screenshot `docs/screenshots/issue-1635-trac-xmlrpc-check-connection-broken.png` —
headers and toolbar only, no rows; after the fix
`docs/screenshots/issue-1635-trac-xmlrpc-check-connection-fixed.png`).

## Environment and fixtures

- TestLink 2.0.1, PHP 8.3.35, MariaDB (`testlink`), app at `http://localhost:8082`.
- Branch `fix/issue-1635`, fix commit `a7938609` (library) + `8b5ada4e6` (suite/screenshots).
- The report's fixture uses `type => 22`; on this schema the Trac/XML-RPC system is
  **type 19** — `lib/functions/tlIssueTracker.class.php:71`
  `19 => array('type' => 'trac', 'api' => 'xmlrpc')`, so the implementation class is
  `tracxmlrpcInterface`. With `22` the BFF answers `400`/`invalid_type` and never loads
  the library, so the ParseError is not reachable. Minimal reproduction:

  ```sql
  INSERT INTO testprojects (id,prefix,active,issue_tracker_enabled,code_tracker_enabled)
    VALUES (1,'TLU',1,1,0);
  INSERT INTO issuetrackers (name,type,cfg) VALUES ('TLU Trac',19,
    '<issuetracker><username>anonymous</username><password></password><uribase>http://127.0.0.1:9/trac</uribase></issuetracker>');
  INSERT INTO testproject_issuetracker (testproject_id,issuetracker_id) VALUES (1,1);
  ```

  The `cfg` needs a **single `<issuetracker>` root** (that is what
  `tracxmlrpcInterface::getCfgTemplate()` returns). Two roots make
  `issueTrackerInterface::setCfg()` log
  `Failure loading XML STRING / Extra content at the end of the document` and leave the
  interface half-built, which then reports a misleading `connected:false`.

## Root cause — two stacked PHP-8 removals, one cause (a PHP-4 era vendored library)

**Hop 1 — the reported `ParseError`.** `xmlrpc.inc:554`

```php
$temp =& new xmlrpcval($GLOBALS['_xh']['value'], $GLOBALS['_xh']['vt']);
```

Assigning the result of `new` by reference was deprecated in PHP 5.3 and became a
**syntax error in PHP 7**. This is a *compile-time* error, so it does not matter which
line is executed — merely `require`-ing the file is fatal. `php -l` reports only the
**first** error, and line 554 is the **first of 47**:

```
$ grep -c '=& *new' third_party/phpxmlrpc/lib/xmlrpc.inc
47
```

A one-line fix (as suggested in the report) would therefore have re-surfaced as a fresh
`ParseError` at the next occurrence (`:1117`).

**Hop 2 — the constructors of the same file are no longer constructors.** `xmlrpc.inc:857
xmlrpc_client()`, `:1891 xmlrpcresp()`, `:2039 xmlrpcmsg()`, `:2649 xmlrpcval()` are
PHP-4-style constructors. PHP 8 only calls `__construct()`, so `new` silently returns an
**uninitialised** object — no error, just garbage:

```
$ php ctor_probe.php        # new xmlrpc_client('http://127.0.0.1:9/trac/xmlrpc')
before:  path=NULL   server=NULL   port=0   method='http'      <-- parse_url() never ran
after :  path='/trac/xmlrpc' server='127.0.0.1' port=9 method='http'
```

Without hop 2 the fix would only have converted a 500 into a green "Connection OK" for a
client that can never address a server.

**Why it stayed hidden.** Nothing else in the tree loads the library
(`grep -rn phpxmlrpc --include=*.php .` outside `third_party/` returns exactly one hit,
`tracxmlrpcInterface.class.php:30`), it is a 3 600-line vendored file that no modern
screen imports, and its failures are compile-time fatals that produce no `events` row.

## The fix

1. `third_party/phpxmlrpc/lib/xmlrpc.inc` — dropped the illegal reference on **all 47**
   `=& new` assignments. In PHP 5+ objects are handles, so `= new` is the same
   assignment `=& new` emulated; behaviour-preserving.
2. `third_party/phpxmlrpc/lib/xmlrpc.inc` — renamed the 4 PHP-4-style constructors to
   `__construct` (one explanatory comment each).
3. `api/issuetracker/index.php` — **defence in depth**: the autoloading
   `class_exists($impl)` probe sat **outside** the `try` of both check-connection routes
   (`:202`, `:263`), so a broken implementation file escaped as an unattributable
   0-byte 500. It now sits **inside** the `try`, so any future unloadable interface
   degrades to the route's already-implemented
   `502 {"status":"error","connected":false}` **plus a `tLog()` Event Viewer ERROR row**.
   Measured with the library reverted and the BFF patched: `502`, size 72, and
   `events` id=5 `api/issuetracker/index.php::GET /{id}/check-connection :: syntax error…`.

Rejected alternatives: fixing line 554 only (the file still cannot compile);
replacing `xmlrpc.inc` with a modern `phpxmlrpc` 4.x release (a third-party version bump
rewriting ~3 600 lines and changing the Trac wire behaviour — far beyond a minimal fix);
disabling the Trac type (hides the defect, drops a feature).

## Verification

Suite 1635 in `tmp/TLU_Test_Cases.md` — **14 PASS / 0 FAIL / 1 known residual**:

- `php -l` and `php -r 'require …'` clean; `grep -c '=& *new'` = 0.
- the client object is now really initialised (`path`/`server`/`port` correct).
- all four Trac routes answer `200`; the controls (`cfg-template?type=20`, the list route
  for other types) are byte-identical to before.
- browser (chrome-devtools): ASIDE → Issue Trackers → the wrench of the Trac row sends
  `GET .../1/check-connection` → **200** `{"status":"ok","connected":true,"message":"Connection OK"}`
  and the cell turns into the green `fa-heartbeat` icon.
- Event Viewer: **0** new Error/Warning rows (`events` = 1 row, the own login).
- rights unchanged: the `$canManage` gate is still evaluated *before* the `try`.

## Known residual — filed as a follow-up issue, deliberately NOT fixed here

The **wire layer below** the client object still uses functions PHP removed:

```
$ php -r 'require "…/xmlrpc.inc"; $m = new xmlrpcmsg("ticket.get");
          $m->addParam(new xmlrpcval(5)); echo $m->serialize();'
PHP Fatal error: Uncaught Error: Call to undefined function each() in .../xmlrpc.inc:2946
#0 xmlrpcval->serialize()  #1 xmlrpcmsg->createPayload()
```

14 `each()` sites (`xmlrpcval::serialize/serializeval/structeach/getval/scalarval/scalartyp`,
`php_xmlrpc_decode`, `php_xmlrpc_encode`, `xmlrpc_client::send`…) plus
`xmlrpc.inc:2277 split("\r?\n", …)` in `parseResponseHeaders()`. Both were removed from
PHP (`each()` in 8.0, `split()` in 7.0), so a real `ticket.get` round-trip still fatals
even though the *Check connection* path now works. That is a separate, larger port (every
`each()` site relies on the array internal pointer) whose end-to-end verification needs a
live XML-RPC endpoint, so it is filed as its own issue instead of being rushed into this
fix. `xmlrpcs.inc` and `xmlrpc_wrappers.inc` (the server side of the same vendored
library) carry the same PHP-4 constructs but are not loaded by TestLink.

Second, related finding: `tracxmlrpcInterface::connect()`
(`tracxmlrpcInterface.class.php:130-156`) never probes the server — it only builds the
client object — so `isConnected()` answers `true` for any syntactically valid cfg even
when the Trac host refuses connections (the fixture points at `http://127.0.0.1:9`, where
nothing listens, and the check still says "Connection OK"). That false positive is
**pre-existing 1.9.20 behaviour** and was deliberately not changed by this fix.

## Files changed

| file | change |
|---|---|
| `third_party/phpxmlrpc/lib/xmlrpc.inc` | 47 × `=& new` → `= new`; 4 constructors → `__construct` |
| `api/issuetracker/index.php` | autoloading `class_exists($impl)` moved inside the `try` (2 routes) |
| `tmp/TLU_Test_Cases.md` | Suite 1635 (15 checks) |
| `CHANGELOG` | one line under *KEY BUGFIX / COMPATIBILITY EFFORTS* |
