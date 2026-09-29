# Bugfix — Issue #1711: element-valued `<version>` / `<urixmlrpc>` in a bugzilla/xmlrpc Issue Tracker raised an **uncatchable** `Error` (HTTP 502 / empty 500)

**Status:** resolved — fix landed and verified (`fix/issue-1711`)
**Labels:** `bug` · **Priority:** major
**Reported:** 2026-09-29T02:24:25Z (from the mandatory code review of #1619) · **Fixed:** 2026-09-29
**Branch:** `fix/issue-1711` · **Files changed:** 1 (`lib/issuetrackerintegration/bugzillaxmlrpcInterface.class.php`)
**Related:** #1619 (same defect, the `$uribase` site, in this same class) · #1629 (the `catch(Exception)` vs PHP 8 `Error` gap) · #1710 (the sibling classes' `$uribase`/catch sites)


---

## 1. Symptom

A manager who pastes a **Configuration** into *Create Issue Tracker* / *Edit Issue
Tracker* that parses but carries a **non-text field** — an element-valued field such as
`<version><x/></version>`, but equally the far likelier `<platform/>` (empty) or
`<platform>  </platform>` (whitespace only) — got a hard failure on the connection check:

| path | pre-fix result |
|---|---|
| modern BFF — *Check connection* | red alert, `POST /api/issuetracker/test-connection` → **HTTP 502** `{"status":"error","connected":false,"message":"Connection check failed"}` |
| Event Viewer | one `ERROR` row per request: `api/issuetracker/index.php::POST /test-connection :: Object of class stdClass could not be converted to string` |
| legacy `tlIssueTracker::checkConnection()` | empty **HTTP 500** (no `try`/`catch` at all around the instantiation) |

The **stored** configuration was the only clue: nothing named the offending element,
and the *same* XML misbehaved differently per field name, so the manager could not
tell which part of their XML was at fault.

## 2. The one thing that makes this a hard failure

The raised thing is `Error`, **not** `Exception`:

```
Error: Object of class stdClass could not be converted to string
```

`Error` does not extend `Exception`, so `catch(Exception $e)` cannot intercept it. Both
guards in the class — `connect()` (`:151`) and `createAPIClient()` (`:304`) — are
`catch(Exception)`, so the throw propagated straight out of the **constructor**.

The 502 rather than an empty 500 is only because `api/issuetracker/index.php:264`
catches `\Throwable` (a later #1701/#1629 improvement). Any caller without that guard
dies hard.

## 3. Root cause

### The hinge

`lib/issuetrackerintegration/issueTrackerInterface.class.php:165` rebinds the config to
a `stdClass` so it can be serialized into `$_SESSION`:

```php
$this->cfg = json_decode(json_encode($this->cfg));
```

Through that round-trip, a cfg field decodes to:

| XML | decoded as |
|---|---|
| `<version>1.0</version>` | `string` ✅ |
| `<version><x/></version>` | **nested `stdClass`** ❌ |
| `<version/>`, `<version>  </version>`, CDATA/comment-only | **nested `stdClass`** ❌ |
| `<platform>a</platform><platform>b</platform>` | **PHP `array`** ❌ |
| `<platform x="1"/>` | object (`@attributes`) ❌ |

### The two sites that were left

1. **The `issueDefaults` loop** — `bugzillaxmlrpcInterface.class.php:109` (pre-fix):

   ```php
   $this->cfg->$prop = (string)(property_exists($this->cfg,$prop) ? $this->cfg->$prop : $default);
   ```

   `property_exists()` is **true** for a nested `stdClass`, so the guard passes and the
   `(string)` cast throws. Affects `version`, `severity`, `op_sys`, `priority`, `platform`.

2. **`urixmlrpc`** — `completeCfg()` only *skipped* the default when the property existed
   (`if(!property_exists($this->cfg,'urixmlrpc'))`), so a nested `<urixmlrpc>` survived
   into `:294`:

   ```php
   $this->APIClient = new Zend_XmlRpc_Client((string)$this->cfg->urixmlrpc);
   ```

**Why it breaks now:** not a regression from #1619 — #1619 fixed the `$uribase` site in
this same class and explicitly warned that "a plain `(string)` cast only moved that
fatal to the issueDefaults loop below". This issue **is** that second site, which the
first fix was always going to expose. It was found by the mandatory code review of #1619.

## 4. The fix

Confined to `lib/issuetrackerintegration/bugzillaxmlrpcInterface.class.php`, built on
the same `is_scalar()` guard #1619 established — the idiomatic PHP 8 test for "this
member is a value I can stringify".

```php
private function cfgStr($prop,$default)
{
  if( !property_exists($this->cfg,$prop) || $this->cfgIsNotText($prop) )
  {
    return $default;
  }
  return (string)$this->cfg->$prop;
}
```

`completeCfg()` now routes every field the class is *structurally known to be a string*
through it:

| field(s) | behaviour on a non-text value | why |
|---|---|---|
| `urixmlrpc`, `uriview`, `uricreate` | treated as **absent** → the derived `$base`-based default is built | the derived URL is strictly better than a nested object, and `Zend_XmlRpc_Client`'s own exception then *names* the URL it tried |
| `version`, `severity`, `op_sys`, `priority`, `platform` | falls back to the carved-on-the-stone default (`unspecified`, `Trivial`, `All`, `Normal`, `All`) | exactly what the surrounding `property_exists()` logic already intended for a missing value |
| `username`, `password`, `product`, `component` | **coerced to `''`**, and the property is never **created** | `canCreateViaAPI()` (`:473`) is `property_exists`-based — *creating* `product`/`component` would silently switch `addIssue()` from disabled to enabled |

**A scalar is returned byte-identically, so no legitimate configuration changes.**
Measured: 7 valid cfgs (full, minimal, custom `uriview`/`uricreate`/`urixmlrpc`,
no-`uribase`, empty-`uribase`, empty root) produce **byte-identical** output pre- and
post-fix. Only the 6 malformed shapes changed, all from fatal/garbage → sane default.

### Diagnostics: the report now names the field

Each replacement emits one `WARNING` naming the offending element **and the tracker**:

```
bugzillaxmlrpcInterface::completeCfg [UIRepro1711] :: cfg field <version> is not a text value, using default 'unspecified'
```

That is what the issue asked for: the manager learns *which* element of their XML is
wrong instead of a generic `Connection check failed`.

## 5. Alternatives considered and rejected

| Option | Why rejected |
|---|---|
| widen `catch(Exception)` → `catch(\Throwable)` in `connect()` / `createAPIClient()` | belongs to **#1629**, and it would **mask** the defect — the issue says so explicitly |
| validate the cfg XML in the form / in `setCfg()` | cross-cutting, and it cannot protect rows **already stored** with a broken cfg |
| a custom serializer for the `json_decode(json_encode(...))` round-trip | identical result, far more machinery for no behavioural gain |
| fix the 8 sibling classes too | correct in principle but out of scope for a one-bug run; filed as a follow-up instead of silently expanding the diff |

## 6. Blast radius — narrower than filed

The report's blast radius says the `issueDefaults` loop "exists in every interface
class". Measured: `jirasoapInterface.class.php:116`, `githubrestInterface.class.php:100`,
`gitlabrestInterface.class.php:99` and `redminerestInterface.class.php:108` all write
into **`$this->issueAttr`**, never `$this->cfg`, and never cast a `stdClass`.
**This specific cast family is confined to `bugzillaxmlrpcInterface`** — which is why
the fix is a single file. (The other four are tracked by #1710 / the follow-up below.)

## 7. Verification

Fresh DB, `http://localhost:8082`, PHP 8.3.35, `admin`/`admin`, tracker type `1`
(bugzilla/xmlrpc).

| # | Case | pre-fix | post-fix |
|---|---|---|---|
| 1 | `<version><x/></version>` | `Error` / 502 | **200** `{"status":"ok","connected":true}` |
| 2 | `<platform><x/></platform>` | `Error` / 502 | **200** |
| 3 | `<urixmlrpc><x/></urixmlrpc>` | `Error` / 502 | **200** |
| 4 | `<uriview><x/></uriview>`, 5 `<uricreate><x/></uricreate>` | — | **200** |
| 6 | `<uribase>  </uribase>` + nested `<version>` (the #1619 case) | — | **200** |
| 7 | `<version>1.0</version>` (scalar control) | 200 | **200, value preserved** |
| 8 | no `<urixmlrpc>` | 200 | **200, derived** |
| 9 | no `product`/`component` | `canCreateViaAPI()=false` | **`false`** (unchanged) |
| 10 | nested `<product>` + scalar `<component>` | `Error` | `product=''`, `canCreateViaAPI()=true` |
| 11 | **empty** `<platform/>` | `Error` | default `All` |
| 12 | **whitespace** `<platform>  </platform>` | `Error` | default `All` |
| 13 | **repeated** `<platform>a</platform><platform>b</platform>` (PHP array) | literal `"Array"` + `E_WARNING` | default `All` |
| 14 | **attribute-only** `<platform x="1"/>` | `Error` | default `All` |

* **Harness:** `php tmp/repro_1711.php` — 14/14 PASS (exit 0). The same harness against
  the pre-fix class reports **3 FAIL**, so it genuinely detects the defect.
* **Browser** (chrome-devtools): *Create Issue Tracker* → bugzilla/xmlrpc → nested
  `<version>` → *Check connection* → `alert alert-success :: Connection successful`,
  network panel `POST /api/issuetracker/index.php/test-connection [200]` (screenshot
  above). Pre-fix: 502 + red alert.
* **Event Viewer:** **0** new `ERROR` rows. Pre-fix, the same 3 failing cases produced 3
  `log_level=1` rows; post-fix there is 1 `log_level=2` WARNING naming the field.
* **Stored broken rows still render:** the `issuetrackers` grid lists a row whose cfg is
  element-valued with Environment `OK`.
* Regression suite **1711: 14/14 PASS** (`tmp/TLU_Test_Cases.md`).
* Code review run before the push; findings applied (see §8).

## 8. What the code review changed

* The **#1619 comment** on `:83` still said the cast "only moved that fatal to the
  issueDefaults loop below" — true when written, misleading once fixed. Now reads
  "…and that second site is what issue #1711 fixes".
* The new log lines carried `__METHOD__` but **not the tracker name**, so they could
  not be attributed when several bugzilla trackers are configured. Now
  `__METHOD__ . " [$this->name] :: …"`, following the precedent at
  `issueTrackerInterface.class.php:108`.
* The duplicated `property_exists() && !is_scalar()` predicate was collapsed into
  `cfgIsNotText()`. **This refactor introduced a real bug** — `cfgStr()` tested only
  "present AND not text", so a **missing** member fell through to the cast and raised
  8 × `Undefined property: stdClass::$…`. The harness caught it (10/10 FAIL), the
  helper was corrected to `!property_exists(...) || $this->cfgIsNotText(...)`, and the
  matrix returned to 14/14. Recorded because it is exactly why the harness asserts on
  *diagnostics*, not only on the return value.

## 9. Not fixed here, deliberately

* **The 8 sibling classes** carrying the identical cast family — `fogbugzrest`,
  `gforgesoap`, `jirarest`, `jirasoap`, `mantissoap`, `redminerest`, `tracxmlrpc`,
  `tuleaprest`. Out of scope for a one-bug run. A `protected cfgStr()` on
  `issueTrackerInterface` would fix all of them and stop this being copy-pasted a 9th
  time — filed as a follow-up.
* **The report's "legacy empty HTTP 500" half is unreachable.** `lib/issuetrackers/`
  is empty: `issueTrackerView.php` and its `?doAction=checkConnection` route were
  **removed in #966** (measured HTTP 404), and `tlIssueTracker::checkConnection()` now
  has no remaining caller. The *class-level* hazard is still real and is still
  reachable by any future caller that instantiates the class without a `\Throwable`
  guard, so the fix stands — but the empty-500 path could not be measured because its
  entry point no longer exists.

## 10. Reproduce it yourself

```bash
# 1. the class-level harness (14 cases, exit 0 = pass)
php tmp/repro_1711.php

# 2. the live BFF route
curl -s -b "$COOKIE" -X POST http://localhost:8082/api/issuetracker/test-connection \
  -H 'Content-Type: application/json' \
  -H 'X-Requested-With: XMLHttpRequest' -H 'Origin: http://localhost:8082' \
  -d '{"name":"T1711","type":1,
       "cfg":"<issuetracker><uribase>http://b/</uribase><version><x/></version></issuetracker>"}'
# => 200 {"status":"ok","connected":true,"message":"Connection OK"}

# 3. the Event Viewer must hold no ERROR row
mysql -h 127.0.0.1 -utestlink -ptestlink testlink \
  -e "SELECT id,log_level,description FROM events ORDER BY id;"
```

`type: 1` is `bugzilla` / `api: xmlrpc`
(`lib/functions/tlIssueTracker.class.php:32-35`); the two headers are required by
`bffSameOriginGuard()`, and the body must be **JSON** — `getBody()` at
`api/issuetracker/index.php:71` is `json_decode(file_get_contents('php://input'), true)`.
