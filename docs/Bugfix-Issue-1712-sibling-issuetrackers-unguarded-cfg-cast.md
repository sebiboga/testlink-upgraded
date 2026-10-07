# Bugfix — Issue #1712: 8 sibling issue-tracker interface classes shared #1711's unguarded (string) cast (HTTP 502 on test-connection)

## Symptom

`POST /api/issuetracker/test-connection` returned **HTTP 502** `{"status":"error", ...}`
for eight issue-tracker types whenever one of the config fields that is *structurally a
text value* came back from the parser as something else — an **element-valued** field
(`<uriwsdl><x/></uriwsdl>`), an **empty** field (`<projectkey/>`), a **whitespace-only**
field (`<tracker>  </tracker>`) or a **repeated** one (`<uriwsdl>a</uriwsdl><uriwsdl>b</uriwsdl>`).

Affected implementations and their types:

| class | type | field used in the repro |
|---|---|---|
| `mantissoapInterface` | 3 | `<uriwsdl>` |
| `jirasoapInterface` | 5 | `<uriwsdl>` / `<projectkey>` |
| `jirarestInterface` | 7 | `<projectkey>` |
| `fogbugzrestInterface` | 8 | `<uribase>` / `<username>` |
| `gforgesoapInterface` | 10 | `<uribase>` / `<password>` |
| `redminerestInterface` | 15 | `<apikey>` |
| `tracxmlrpcInterface` | 19 | `<urixmlrpc>` |
| `tuleaprestInterface` | 27 | `<tracker>` |

The Event Viewer showed only the raw language error, e.g.
`Error: Object of class stdClass could not be converted to string`, with **no
indication of which config field** was at fault.

## Root cause

`issueTrackerInterface::setCfg()` (`lib/issuetrackerintegration/issueTrackerInterface.class.php`)
normalises the incoming config with `json_decode(json_encode(simplexml_load_string(...)))`.
XML text nodes survive that round-trip as PHP strings, but element-valued, empty,
whitespace-only and repeated members do **not** — they arrive as nested `stdClass`
objects / arrays.

The eight implementations then read those members unguarded:

```php
$this->cfg->uriwsdl = $base . 'api/soap/mantisconnect.php?wsdl';   // default build, ok
$pk = trim((string)($this->cfg->projectkey));                      // <- throws
```

`(string)` on an object raises `Error`, `trim()` on an object raises `TypeError`. Both are
`\Error`/`\TypeError`, so the `catch (Exception)` / `catch (SoapFault)` blocks in the
classes do not catch them; they escape to `api/issuetracker/index.php`, where the
`catch (\Throwable)` turns them into HTTP 502.

The guard that solves this already existed — issue **#1711** added `cfgStr()`,
`cfgIsNotText()` and `cfgWarn()` — but it was added **privately** to
`bugzillaxmlrpcInterface` only, and the eight siblings never used it.

## Fix

1. The three helpers moved from `bugzillaxmlrpcInterface` to the base class
   `issueTrackerInterface` as **`protected`** (the private copies had to be deleted —
   redeclaring them in the subclass would be a PHP Fatal "must be protected or weaker"),
   plus a new **`protected cfgCoerceText(array $props)`** that reports + empties
   non-text members.
2. Every structurally-string member of the eight classes is now normalised **before**
   it is read: derived URL fields use `cfgStr()` with their carved default, mutable
   fields (`username`, `password`, `project`, `projectkey`, `apikey`, `tracker`, …) go
   through `cfgCoerceText()`.
3. Three reads run **before** `completeCfg()` / on the connection path and are guarded in
   place: `jirarestInterface::checkCfg()` (constructor), `tuleaprestInterface::__construct()`,
   `fogbugzrestInterface::connect()` / `tracxmlrpcInterface::createAPIClient()` /
   `tuleaprestInterface::connect()` credential reads.
4. Code review found a further 5 unguarded `trim($this->cfg->uribase,"/")` sites
   (`fogbugzrest`, `gforgesoap`, `jirarest`, `jirasoap`, `mantissoap`) — the same family,
   the same 502 — now `trim($this->cfgStr('uribase',''),"/")`, byte-identical for a valid
   scalar.

Behaviour for a valid scalar config is **byte-identical** to before; every derived default
string is unchanged, and no `property_exists()`-based feature switch (`canCreateViaAPI()`)
sees a property that did not exist before.

## Why this method

Alternatives rejected:

- **Wrap each class in `try/catch (\Throwable)`** — hides the real problem, keeps emitting
  "stdClass could not be converted to string" in the Event Viewer with no field name.
- **Fix only the 8 repro fields** — leaves the next member one miss away from the same 502.
- **Change `setCfg()` to keep text nodes as strings** (e.g. drop the `json_decode` round-trip)
  — much larger blast radius: every implementation reads `$this->cfg` as stdClass and
  SimpleXML/stdlib differences would leak into all 20+ trackers.

The chosen fix is the one #1711 already proved: one shared guard on the base class,
applied at every read site.

## After the fix

A non-text value is **reported by name** in the Event Viewer as a WARNING and treated as
absent, and the request answers HTTP 200:

```
2  issueTrackerInterface::cfgWarn [IT-1712] :: cfg field <projectkey> is not a text value, using empty string
1  jirarestInterface::checkCfg / jirarestInterface - Empty configuration: <projectKey>
```

## Verification

- `php tmp/repro_1712.php` — 14 cases (8 element-valued + 2 whitespace-only + 1 repeated
  + 2 controls) → **ALL PASS**, exit 0. Pre-fix: `11 FAILURE(S)`.
- `bash tmp/verify_1712.sh` — **25 PASS / 0 FAIL**, exit 0: reported repro on 3 classes,
  all 8 types, whitespace-only, repeated element, field-named check, `bugzillaxmlrpc`
  control, Issue Tracker Management grid + list API, `GET /{id}/check-connection`,
  Event Viewer sweep (0 rows `LIKE '%could not be converted to string%'`, 0 ERROR rows
  mentioning `stdClass`), fixture cleanup.
- Measured HTTP: `type=3/5/10 -> HTTP 502` before → all 8 types `-> HTTP 200
  {"status":"ok",...}` after.
- `php -l` clean on all touched files; regression suite `Regression — Issue #1712`
  appended to `tmp/TLU_Test_Cases.md`; gates `ai/verify_test_suites.sh` (7 PASS) and
  `ai/verify_i18n_coverage.sh` pass. No i18n change (no user-facing strings touched).

## Files changed

- `lib/issuetrackerintegration/issueTrackerInterface.class.php` — protected `cfgStr()`,
  `cfgIsNotText()`, `cfgWarn()`, `cfgCoerceText()`
- `lib/issuetrackerintegration/bugzillaxmlrpcInterface.class.php` — private copies removed
- `lib/issuetrackerintegration/{fogbugzrest,gforgesoap,jirarest,jirasoap,mantissoap,redminerest,tracxmlrpc,tuleaprest}Interface.class.php`
- `tmp/repro_1712.php`, `tmp/verify_1712.sh` — repro / regression harnesses
- `tmp/TLU_Test_Cases.md` — `## Regression — Issue #1712`

## Follow-up (out of this issue's scope)

The same unguarded family exists in implementations that are **not** listed in #1712 and
were therefore left untouched: `githubrestInterface`, `gitlabrestInterface`,
`kaitenrestInterface`, `trellorestInterface`, `jiradbInterface`.

## References

- Issue: https://github.com/sebiboga/testlink-upgraded/issues/1712
- Sibling fix: https://github.com/sebiboga/testlink-upgraded/issues/1711
