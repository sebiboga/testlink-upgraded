# Bugfix — Issue #1839: `locale/fr_FR/strings.txt` PHP parse error — the whole `fr_FR` locale returned HTTP 500

- **Issue**: [#1839](https://github.com/sebiboga/testlink-upgraded/issues/1839)
- **Branch**: `fix/issue-1839`
- **Commit**: `2222419a8` — *fix(locale): escape apostrophes in fr_FR `$TLS_href_tc_auto_exec`, unblocking the whole fr_FR locale*
- **Files changed**: `locale/fr_FR/strings.txt` (1 line), `CHANGELOG` (1 entry)
- **Severity**: major — total loss of the legacy (server-side) i18n layer for every French user
- **Regression suite**: `tmp/TLU_Test_Cases.md` → `## Regression — Issue #1839: …` (R1–R10, all PASS)

## Symptom

`locale/fr_FR/strings.txt` failed to compile. TestLink loads language strings by
`require`-ing that file, so the failure was a **compile-time fatal**, not a
recoverable one: any `fr_FR` request that resolved a server-side string died with
**HTTP 500 and a zero-byte body**. The client-side bundle
(`gui/templates/i18n/fr.json`, 6690 keys) was unaffected, so the French UI half
looked fine while the whole server-side label layer was gone.

Measured pre-fix:

```
$ php -l locale/fr_FR/strings.txt
PHP Parse error:  syntax error, unexpected identifier "automatisation" in locale/fr_FR/strings.txt on line 4089

$ for d in locale/*/; do php -l "$d/strings.txt" >/dev/null 2>&1 || echo "PARSE ERROR: $d"; done
PARSE ERROR: locale/fr_FR/            # 1 of 19 bundles

$ curl -s -b c.jar -w "%{http_code}\n" 'http://localhost:8082/api/aside/index.php?action=init'
500                                # aside.json = 0 bytes
```

`tmp/php_server.log` (server stderr, `display_errors=Off`):

```
[Mon Oct  5 03:17:12 2026] PHP Parse error:  syntax error, unexpected identifier "automatisation" in
    .../locale/fr_FR/strings.txt on line 4089
[Mon Oct  5 03:17:12 2026] 127.0.0.1:42168 [500]: GET /api/aside/index.php?action=init - syntax error,
    unexpected identifier "automatisation" in .../locale/fr_FR/strings.txt on line 4089
```

## Root cause

One line, `locale/fr_FR/strings.txt:4089`:

```php
$TLS_href_tc_auto_exec = 'Execution de l'automatisation des tests';
```

Two bare apostrophes inside a **single-quoted** PHP literal. PHP closes the string
at the first `l'`, so `automatisation` is lexed as a bare identifier → compile
error.

### Why the whole locale went down (the chain)

| Hop | Location | Role |
|---|---|---|
| 1 | `locale/fr_FR/strings.txt:4089` | the unparseable literal |
| 2 | `lib/functions/lang_api.php:240` | `require($lang_resource_path);` — turns the file's syntax error into a request-wide fatal |
| 3 | `lib/functions/lang_api.php:283` | `lang_get()` → `lang_ensure_loaded()` → `lang_load()` is **lazy**, so the first localized string of a request is enough to detonate it |
| 4 | `lib/functions/lang_api.php:49-58` | with no explicit locale, `lang_get()` takes the locale from `$_SESSION['locale']` — i.e. the user record |
| 5 | `api/aside/index.php:521` | `lang_get('href_tc_auto_exec')` with no explicit locale — reached first on login, so the **ASIDE menu** was dead (legacy twin: `gui/templates/dashio/aside.tpl:256`) |

### Regression source

```
$ git log -1 --format='%H %s' -L 4089,4089:locale/fr_FR/strings.txt
5cd5962190907541418d21073103458db8f05507 Wire Test Automation Execution into ASIDE + legacy shim (Refs #1587)
+ $TLS_href_tc_auto_exec = 'Execution de l'automatisation des tests';
```

Commit `5cd596219` (#1587) added the key to **all 19** bundles. 18 of the
translations happened to contain no apostrophe — they elide it (`'Ejecucion de
la automatizacion de pruebas'`, `'Execucao da automatizacao de testes'`) — so
`fr_FR` was the only bundle that broke. The trap is systemic for this file, not a
one-off: any future French string with an apostrophe must be escaped.

Unrelated to the Documentation Hub work in #1275/#1281: those appended their keys
at lines 4102-4103, after the broken line, and the file was already unparseable
before them.

## The fix

Escape the apostrophes in place, with the same `\'` escaping the file already
uses elsewhere (`locale/fr_FR/strings.txt:4129`):

```diff
-$TLS_href_tc_auto_exec = 'Execution de l'automatisation des tests';
+$TLS_href_tc_auto_exec = 'Execution de l\'automatisation des tests';
```

**Why this method** — an in-place escape is the smallest diff that cannot alter
the translated text. The alternatives were rejected:

* *Rewrite as a double-quoted literal* — works, but inconsistent with the rest of
  the file and invites interpolation of a `$` that does not exist here.
* *Reword the translation* (e.g. `Execution de l'automatisation` → `Execution
  automatisation des tests`) — silently changes user-visible copy to dodge a
  syntax problem. Wrong trade.
* *Harden `lang_load()` to swallow parse errors* — would turn a hard, obvious
  failure into a silent, partial-translation failure. The loud failure is
  correct behaviour; the bug was the one bad byte.

**One line, one file.** No other locale was touched.

## Verification

| ID | Check | Expected | Observed | Verdict |
|---|---|---|---|---|
| R1 | `php -l locale/fr_FR/strings.txt` | no syntax errors | `No syntax errors detected` | PASS |
| R2 | `php -l` over all 19 `locale/*/strings.txt` | zero failures | zero failures | PASS |
| R3 | `lang_load('fr_FR')` + `lang_get(...)` | French, no fatal | `doc_user_manual` = `Manuel utilisateur`; `href_tc_auto_exec` = `Execution de l'automatisation des tests` | PASS |
| R3b | locale resolved via `$_SESSION['locale']` (the real request path) | French | identical French output | PASS |
| R4 | `GET /api/aside/index.php?action=init`, `fr_FR` admin | 200 + French tree | **200, 6686 bytes**, `status: ok`, 52 server-side labels in French | PASS (was 500 / 0 bytes) |
| R5 | same request, `en_GB` admin — no regression | 200 + English tree | 200, 6481 bytes, same 52 labels in English | PASS |
| R6 | translated text preserved | `Execution de l'automatisation des tests` | identical | PASS |
| R7 | browser, `fr_FR` admin | ASIDE menu renders French | `navBar.html?locale=fr_FR` + `aside.html?locale=fr_FR` load; ASIDE shows `Tableau de bord`, `Système`, `Projets`, `En révision`; nav bar shows `Se déconnecter` | PASS |
| R8 | browser console | no errors | `<no console messages found>` | PASS |
| R9 | Event Viewer / `events` table | no new Error/Warning | `select count(*) from events where log_level in (1,2)` (`logger.class.php:50-51` `ERROR=1`, `WARNING=2`) = **0** | PASS |
| R10 | `tmp/php_server.log` | no new parse errors | exactly **1** `PHP Parse error`, the pre-fix one at `03:17:12`; **0** after the fix | PASS |

Screenshot: the wiki page for this bug carries
`images/1839-fr_FR-aside-menu-after-fix.png` (780×493) — the French ASIDE menu
and nav bar after the fix.

## Regression risk that was checked and cleared

A scan of every bundle for single-quoted literals containing an unescaped `'`
returns **86** hits, but **85** of them are apostrophes inside trailing `//`
comments and are harmless — those bundles lint clean:

```php
$TLS_active_click_to_change = '有効 (クリックして無効化)'; // 'Active (click to set inactive)';
```

Only `fr_FR:4089` was a real break, and R2 proves it: no bundle fails `php -l`.

## Related, not fixed here

[#1840](https://github.com/sebiboga/testlink-upgraded/issues/1840) —
`gui/templates/i18n/fr.json` is missing 26 of the 6716 `en.json` keys, so a few
menu entries (`Test Strategy`, `Documentation`) and the Req. Monitor Overview grid
toolbar still render in English for `fr_FR` users. That is a **client-side
missing-translation gap**, a different class from this server-side parse error,
and it was filed separately rather than folded into this one-line fix.