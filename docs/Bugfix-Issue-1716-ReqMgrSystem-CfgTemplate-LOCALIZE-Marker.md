# Bug fix — Issue #1716: `getreqmgrsystemcfgtemplate.php` answered `LOCALIZE: <key>` and logged a localization Event Viewer row

## Symptom

`lib/ajax/getreqmgrsystemcfgtemplate.php` ships a **developer-facing missing-key
marker** in its JSON payload and writes a `log_level 32` (`LOCALIZATION`) row into
the `events` table (Event Viewer source) at the same time. Both `lang_get()` keys
the endpoint uses exist in **no** locale bundle:

| Request | Pre-fix payload | Post-fix payload |
|---|---|---|
| `...getreqmgrsystemcfgtemplate.php?type=1` (line 41) | `{"sucess":true,"cfg":"LOCALIZE: reqmgrsystem_interface_not_implemented"}` | `{"sucess":true,"cfg":"Configuration example not available: the 'contoursoapInterface' interface is not implemented"}` |
| `...getreqmgrsystemcfgtemplate.php?type=99` (line 46) | `{"sucess":true,"cfg":"LOCALIZE: reqmgrsystem_invalid_type"}` | `{"sucess":true,"cfg":"Invalid requirement management system type: 99"}` |
| bare URL, no `type` (line 46) | `LOCALIZE: reqmgrsystem_invalid_type` | `Invalid requirement management system type: 0` |


Entry point: `http://localhost:8082/lib/ajax/getreqmgrsystemcfgtemplate.php?type=1`
(session-authenticated; the legacy Ext.Ajax caller was
`reqMgrSystemEdit.tpl`'s `displayCfgExample()`, retired by #1727 — the endpoint
itself is still reachable and still answered the marker).

Pre-fix `events` (measured this run, fresh database, rows 2 and 3):

```
id  log_level  description
 2  32         string 'reqmgrsystem_interface_not_implemented' is not localized for locale 'en_GB'
 3  32         string 'reqmgrsystem_invalid_type' is not localized for locale 'en_GB'
```

## Environment and fixtures

- TestLink 2.0.1, **PHP 8.3.35**, PHP built-in server on `http://localhost:8082`,
  MariaDB `testlink` on `127.0.0.1:3306` (`testlink`/`testlink`), database freshly
  imported, login `admin`/`admin` (locale `en_GB`).
- Branch `fix/issue-1716`; fix commit `d9adca617`.
- Minimal reproduction (no fixtures needed — the endpoint needs only a session):

  ```bash
  curl -s -c c.txt -d "tl_login=admin&tl_password=admin" -o /dev/null http://localhost:8082/login.php
  curl -s -b c.txt "http://localhost:8082/lib/ajax/getreqmgrsystemcfgtemplate.php?type=1"
  curl -s -b c.txt "http://localhost:8082/lib/ajax/getreqmgrsystemcfgtemplate.php?type=99"
  mysql -h 127.0.0.1 -utestlink -ptestlink testlink \
        -e "SELECT id,log_level,description FROM events ORDER BY id DESC LIMIT 6;"
  ```

## Root cause

1. `lib/ajax/getreqmgrsystemcfgtemplate.php:41` / `:46` call
   `lang_get('reqmgrsystem_interface_not_implemented')` and
   `lang_get('reqmgrsystem_invalid_type')`.
2. `lang_get()` (`lib/functions/lang_api.php:41-89`) looks the key up in the
   session locale, then force-loads `en_GB` as the fallback (`:78-87`). Neither
   bundle — nor any of the other 17 — defines either key
   (`grep -rn 'reqmgrsystem_invalid_type' locale/ → 0 hits`), so `$loc_str` stays
   `NULL`.
3. `lang_api.php:118` then returns `TL_LOCALIZE_TAG . $p_string`, i.e. the literal
   `LOCALIZE: <key>`, and `:48` `json_encode()`s it straight into the response.
4. The same `$missingL18N` branch calls `logL18nWarningEvent()` (`lang_api.php:137`)
   → the `log_level 32` row, de-duped **once per key per session** by
   `$_SESSION['missingL18N']` (`:133`).

**Why it never blew up:** `sprintf()` on the marker (which contains no `%s`)
silently returns the marker unchanged, so there is no fatal, no HTTP error and no
notice — only the Event Viewer row and the visible marker. The keys were
introduced with the req-mgmt-system config-example feature (1.9.6) and never added
to any bundle; `git log -S'reqmgrsystem_invalid_type' -- locale/` shows they never
existed anywhere (a *never-added* gap, not a rename).

**Blast radius:** exactly **2** call sites repo-wide (both in this endpoint).
`?type=1` is not an edge case: `tlReqMgrSystem::$systems` registers only
`1 => contour/soap` (`lib/functions/tlReqMgrSystem.class.php:33`) and
`contoursoapInterface.class.php` ships nowhere, so `stream_resolve_include_path()`
is `FALSE` and **every** `type=1` request took the line-41 branch. Any other/absent
`type` (`intval()` of a missing param is `0`) took line 46.

## Fix

**Add the two keys to all 19 `locale/*/strings.txt` bundles — no PHP changed.**

| File | Change |
|---|---|
| `locale/{cs_CZ,de_DE,en_GB,en_US,es_AR,es_ES,fi_FI,fr_FR,id_ID,it_IT,ja_JP,ko_KR,nl_NL,pl_PL,pt_BR,pt_PT,ro_RO,ru_RU,zh_CN}/strings.txt` | `+$TLS_reqmgrsystem_interface_not_implemented` and `+$TLS_reqmgrsystem_invalid_type`, each with exactly one `%s` matching the `sprintf(..., $iname)` / `sprintf(..., $type)` call sites (+2 lines per file, 19 files) |
| `CHANGELOG` | one `[KEY BUGFIX]` entry |

Insertion is byte-preserving: next to the existing `$TLS_check_reqmgrsystem_*`
block in the 12 bundles that have it, before the closing `?>` (`de_DE`, `es_ES`,
`it_IT`, `ru_RU`) or at EOF in the 7 bundles without the block (`es_AR`, `fi_FI`,
`id_ID`, `ko_KR`, `ro_RO`) — all LF, all UTF-8, nothing else in any file touched.
Native translation in every non-English bundle; `php -l` clean on all 19 before and
after (the bundles are `require`d as PHP by `lang_load()`, `lang_api.php:244`).

### Alternatives rejected

- **Hardcode English at `:41/:46`** — user-facing text with no locale entry, and it
  would drop the `$iname`/`$type` context the message exists for.
- **Reuse an existing generic key** (`$TLS_reqmgrsystem_cfg_example`) — would hide
  *which* interface/type failed.
- **Change the endpoint to swallow the miss** (`lang_get(..., null, true)`) — stops
  the event but still ships the raw marker; treats the symptom, not the cause.
- **Fix only `en_GB`** — `lang_get()` falls back to `en_GB` for every locale, so the
  marker would disappear, but each non-English locale would keep firing the
  `- using en_GB` LOCALIZATION row forever (the exact noise #1820/#549 removed).

## Verification

```text
?type=1  -> {"sucess":true,"cfg":"Configuration example not available: the 'contoursoapInterface' interface is not implemented"}
?type=99 -> {"sucess":true,"cfg":"Invalid requirement management system type: 99"}
?type=   -> {"sucess":true,"cfg":"Invalid requirement management system type: 0"}
users.locale=it_IT ?type=1 -> "Esempio di configurazione non disponibile: l'interfaccia 'contoursoapInterface' non è implementata"
users.locale=ru_RU ?type=1 -> "Пример конфигурации недоступен: интерфейс 'contoursoapInterface' не реализован"
events after 4 fresh sessions x 3 requests x 3 locales: only log_level 16 (login audits)
  -> 0 new log_level 32 rows   (pre-fix rows 2/3 remain as historical evidence)
php -l locale/<each>/strings.txt   -> 19/19 "No syntax errors detected" (same as HEAD copies)
bash ai/verify_i18n_coverage.sh    -> PASS 9/9 bundles
git diff --stat                    -> 19 files, +2 lines each, nothing else
TLU_REQUIRE_SUITE="Issue #1716" bash ai/verify_test_suites.sh -> 7 PASS / 0 FAIL
```

Browser pass: `gui/templates/reqmgrsystems/reqMgrSystemView.html` → `+ Create` →
`show configuration example` renders `Interface contoursoapInterface not implemented`
(the modern BFF path, i18n key `rms.connNotImplemented` — unaffected by this change
and confirmed still correct), console clean apart from the pre-existing favicon 404.

Code review (subagent) found two garbled translations — `ko_KR` 시스**텀** → 시스템
and `cs_CZ` `požadavkö` → `požadavků` — both corrected before commit; all other
categories (string validity, `%s` count, key names, insertion position, diff scope)
came back clean.

Regression suite: `tmp/TLU_Test_Cases.md` → `## Regression — Issue #1716`
(8 cases, TC-1716-01..08, all PASS).

## Sibling defects found while testing (filed, NOT fixed)

* **#1879** — a **bare** request (no `type` param) makes
  `intval($_REQUEST['type'])` at `getreqmgrsystemcfgtemplate.php:23` raise
  `E_WARNING "Undefined array key \"type\""` → an `events` row with `log_level 2`.
  Independent of this fix (the #1716 diff touches only `locale/*/strings.txt`).
* The other **6** req-mgmt keys (`$TLS_reqmgrsystem_check_ok`, `_management`,
  `_deleted`, `$TLS_reqmgrsystem_show_cfg_example`, …) are still missing from the
  7 bundles that carry no req-mgmt block at all (`es_AR`, `fi_FI`, `id_ID`, `it_IT`,
  `ko_KR`, `ro_RO`, `ru_RU`), so those locales keep firing `- using en_GB`
  LOCALIZATION rows whenever the Req Mgmt System screens are used — pre-existing,
  out of scope for this issue.

## Related

- #1626 — the sibling of this issue (filed it while fixing `tlReqMgrSystem::getTypes()`)
- #549, #1820 — the same "missing locale key" family, fixed the same way
- #1727 — retired the legacy `reqMgrSystemEdit.tpl` caller (the endpoint itself
  remains reachable and is what this fix repairs)
