# Issue 1389 — planImport (modern) import never parses XML — always "Failed to load XML"

**Issue:** [#1389](https://github.com/sebiboga/testlink-upgraded/issues/1389)
**Branch:** `fix/issue-1389`
**Status:** VERIFIED-FIXED (2026-09-11)

## Symptom

Every XML upload through the modernized import screen (`gui/templates/plans/planImport.html`
→ BFF `api/planimport/index.php` action=import) returned `status:ok` with
`result_map [["Please give this text to your TestLink Administrator<br> - Failed to load XML<br>", "Not imported"]]`.
Nothing was ever imported; `testplan_tcversions` stayed empty. The legacy controller parses
the very same file fine (its wrapper reads with `simplexml_load_string`).

## Repro steps

1. Log in as admin.
2. Fixture `tmp/fixtures_pimp.php`: tproject **13 PIMP**, plan **24 PIMP-Plan**, TCs
   PIMP-1..3, platform PIMP-Android; zero linked TCs.
3. `POST /api/planimport/?action=import&tproject_id=13&tplan_id=24` with
   `uploadedFile` = a valid XML file (`tmp/pimp_xml/mixed.xml`).
4. Observe report `Failed to load XML / Not imported`; DB unchanged.

**Expected:** platform + test-case links created (or updated), with OK messages.

## Root cause

`api/planimport/index.php:113-116` (the import pipeline's parse step):

```php
@libxml_disable_entity_loader(true);
$xml = @simplexml_load_file($targetFile);
```

Under this stack (**PHP 8.3.33 NTS + libxml 2.9.14**, both CLI and web SAPI), once
`libxml_disable_entity_loader(true)` has been called, `simplexml_load_file()` returns
`false` for **any** local file — libxml reports `failed to load external entity "<path>"`.
`simplexml_load_string()` is unaffected (the loader flag does not break in-memory parsing),
and the XXE guard still holds with `load_string`.

The legacy parse helper `simplexml_load_file_wrapper()` (`lib/functions/xml.inc.php:28-44`)
already uses `file_get_contents()` + `simplexml_load_string()` for exactly this reason — so
the legacy import survives PHP 8.x while the BFF port's naive `simplexml_load_file()` did
not. The "byte-for-byte parity" comment at `api/planimport/index.php:96-97` was not accurate
for the parse step.

Isolated probe (PHP 8.3.33, `php -v` + libxml 2.9.14):

| Probe | Result |
|---|---|
| `libxml_disable_entity_loader(true)` + `simplexml_load_file()` | FALSE — "failed to load external entity" |
| `libxml_disable_entity_loader(true)` + `simplexml_load_string(file_get_contents())` | OK |
| `simplexml_load_file()` with loader enabled | OK |
| XXE payload via `load_string` + loader disabled | blocked (no `file://` leak) |

## Fix (minimal)

`api/planimport/index.php:113-118` — mirror the legacy wrapper:

```php
@libxml_disable_entity_loader(true);
$zebra = @file_get_contents($targetFile);
$xml = ($zebra !== false) ? @simplexml_load_string($zebra) : false;
@libxml_clear_errors();
```

XXE defense-in-depth preserved (loader still disabled during parse), `file_get_contents`
failure guarded, libxml error state cleared so the degraded "Failed to load XML" path stays
accurate. No i18n/UI change. Files: `api/planimport/index.php` only
(commit `2d9b25a44`).

## Verification (regression matrix, all PASS on localhost:8082, fresh DB)

- **create:** `mixed.xml` → `Platform PIMP-Android has been linked` + 2×
  `linked to Test Plan for Platform PIMP-Android` OK + link #3 (no platform element)
  `Not imported`; DB rows tcversion 16/19, platform 2, order 10/20.
- **update / no-dup:** re-import `mixed.xml` → 2× `already linked ... only execution
  order has been updated`; links stay at 2.
- **execution order:** `update.xml` (order 5) → `node_order` 10→5 for tcversion 16.
- **unknown platform:** `badplat.xml` → `platform NoSuchPlatform ... does not exist on
  target Test Project` Not imported; DB unchanged.
- **malformed XML:** `invalid.xml` → HTTP 200 graceful `Failed to load XML / Not imported`.
- **XXE:** entity payload `file:///etc/hostname` in a platform name → text never leaks
  (name rendered empty, entity not expanded).
- **no-platform plan:** `single.xml` → create OK `platform_id=0`; platform-in-link on a
  platform-less plan → `link_with_platform_not_needed`.
- **browser:** upload + submit through `planImport.html` renders the report with OK rows,
  `Import completed successfully.`; no console errors. Screenshots:
  `docs/screenshots/issue-1389-modern-import-report.png` (Not imported path) and
  `docs/screenshots/issue-1389-modern-import-ok.png` (OK/update path).
- **hygiene:** `events` table has only AUDIT(16) rows from the imports, no new ERROR/WARNING.

## Discoveries while testing

- **New bug #1407 (filed, label `bug`):** a WELL-FORMED XML whose root is not `<testplan>`
  (e.g. legacy `<xml>`-wrapped export) now gets past parsing and crashes the BFF with an
  unhandled `TypeError: count(): Argument #1 ($value) must be of type Countable|array, null
  given` at `api/planimport/index.php:151` (`$xml->executables` → null because
  `executables` is not a direct child of the root) — HTTP 500, empty body. Legacy
  `lib/plan/planImport.php:285-286` has the identical unguarded `count()` and dies the same
  way; both generations share the flaw. Left for the fix-bug factory (or alongside #1390).
- The previous `tmp/fixtures_pimp.php` from the #815 analysis was lost in the fresh-DB reset;
  a recreated fixture is now tracked (force-added, `enable_on_design=1` — the BFF platform
  universe filter rejects platforms whose `enable_on_design` flag is 0, which silently turned
  into `no_platforms_on_tproject` during setup).

## Evidence

Commit range: `2d9b25a44` (fix) + `8fad04dfe`/`0a44be2b3` (regression suite + fixture + payloads + screenshots) on branch `fix/issue-1389`. Wiki page: `tmp/wiki-repo/Bugfix-Issue-1389-PlanImport-XML-Parse-SimplexmlLoadFile.md`.