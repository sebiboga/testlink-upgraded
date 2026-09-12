# Bugfix — Issue #1459: ASIDE "Severity Configuration" label stays English in all non-en_GB locales

## Problem

The ASIDE sub-menu item for **Severity Configuration** (Test Strategy
section, 4th-from-last sub-item) rendered from the legacy Smarty key
`href_severity_config` via `gui/templates/dashio/aside.tpl:161`:

```smarty
<li><a href="gui/templates/projects/severityConfig.html" target="mainframe">
   <i class="fas fa-sliders-h"></i> {$labels.href_severity_config}</a></li>
```

The key was defined **only** in the development bundle
`locale/en_GB/strings.txt:2329` (`$TLS_href_severity_config = "Severity Configuration";`).
The other 18 locale `strings.txt` files (cs_CZ, de_DE, en_US, es_AR, es_ES,
fi_FI, fr_FR, id_ID, it_IT, ja_JP, ko_KR, nl_NL, pl_PL, pt_BR, pt_PT, ro_RO,
ru_RU, zh_CN) did not carry it, so `lang_get()` fell back to en_GB and the
label rendered in **English inside the translated shell** for every
non-en_GB locale.

Reproduced on the CI box (fresh import, mysql CLI to set the user locale +
browser, locale switch via the DB user profile because the legacy shell reads
`$_SESSION['locale']`, populated at login by `setUserSession()`,
`lib/functions/users.inc.php:41`):

```
mysql > UPDATE users SET locale='ro_RO' WHERE login='admin';
browser > login admin/admin, expand ASIDE "Strategia de Testare"
→ 4th-from-last sub-item = "Severity Configuration" (English)
  while every neighbouring item is Romanian
  ("... Ciclul de Viață al Bug-ului | Severity Configuration")
```

Evidence: `grep -rl "href_severity_config" locale/*/strings.txt` → 1 file
(`locale/en_GB/strings.txt`); 19 locale dirs exist. Detected as a follow-up
during the #1458 fix run.

## Root Cause

Contributing already to the LS: the ASIDE Test Strategy chapter-map work
(commit `f934433d7`, Refs #1426/#1431) introduced the sub-item using a new
key that was added **only** to `en_GB/strings.txt`. Because `lang_get()`
(`lib/functions/lang_api.php:51-54,195`) silently falls back to en_GB for any
key undefined in the active locale — and records the fallback only as a
level-32 **L18N** audit row, never as ERROR/WARNING in the Event Viewer — the
gap shipped unnoticed.

Chain:

1. `gui/templates/dashio/aside.tpl:161` — sub-item label = `href_severity_config`.
2. `locale/en_GB/strings.txt:2329` — the ONLY definition.
3. `lib/functions/lang_api.php` — missing key → en_GB fallback for the 18
   locales that lack the key.
4. Result: the label is always English outside en_GB.

**Blast radius:** one ASIDE sub-item label; cosmetic, no functional impact.
The modernized target screen `gui/templates/projects/severityConfig.html`
uses the client-side TLi18n JSON bundles (`gui/templates/i18n/*.json`) and is
NOT affected (ro.json already ships `proj.severityConfig =
"Configurare severitate"`).

## Fix

Committed on branch `fix/issue-1459-severity-config-i18n` (`017891c3d`):

Added one line to each of the 18 missing locale `strings.txt` files,
inserted immediately after the `$TLS_href_plan_define_priority` line
(mirrors the en_GB relative placement), quote style matched per file:

| Locale | Value |
|---|---|
| cs_CZ | `Konfigurace závažnosti` (stored as cp1250 bytes) |
| de_DE | `Schweregrad-Konfiguration` |
| en_US | `Severity Configuration` |
| es_AR / es_ES | `Configuración de Severidad` |
| fi_FI | `Vakavuusasetukset` |
| fr_FR | `Configuration de la sévérité` |
| id_ID | `Konfigurasi Tingkat Keparahan` |
| it_IT | `Configurazione della gravità` |
| ja_JP | `重大度設定` |
| ko_KR | `심각도 설정` |
| nl_NL | `Configuratie van de ernst` |
| pl_PL | `Konfiguracja ważności` |
| pt_BR / pt_PT | `Configuração de Severidade` |
| ro_RO | `Configurare severitate` |
| ru_RU | `Настройка серьёзности` |
| zh_CN | `严重性配置` |

**Encoding gotcha — cs_CZ:** `locale/cs_CZ/strings.txt` declares
`$TLS_STRINGFILE_CHARSET = "UTF-8"` (line 33) but the file body is byte-encoded
**Windows-1250** (verified: `ž` = 0x9E, `á` = 0xE1; a UTF-8 write would have
produced mojibake like several unrelated already-broken strings). The Czech
string was therefore written in cp1250 to render exactly like its neighbours.

**Why this method:** adding the key to each bundle is the minimal, complete
fix for the legacy Smarty path. Writing plain English into every locale file
would "work" but would keep the label untranslated; translating per locale
matches the language contract of the shell. Using the modern JSON bundle is
not possible here — the ASIDE is legacy Smarty with no TLi18n.

**Rejected alternatives:** (a) hard-coding the label in aside.tpl — bypasses
i18n entirely and contradicts the i18n rules; (b) adding the key only to the
`en_US` bundle — en_GB is the fallback bundle, so en_US alone would not help
any other locale; (c) leaving en_GB as the only definition — that's the bug.

## Files Changed

- `locale/cs_CZ/strings.txt`, `locale/de_DE/strings.txt`,
  `locale/en_US/strings.txt`, `locale/es_AR/strings.txt`,
  `locale/es_ES/strings.txt`, `locale/fi_FI/strings.txt`,
  `locale/fr_FR/strings.txt`, `locale/id_ID/strings.txt`,
  `locale/it_IT/strings.txt`, `locale/ja_JP/strings.txt`,
  `locale/ko_KR/strings.txt`, `locale/nl_NL/strings.txt`,
  `locale/pl_PL/strings.txt`, `locale/pt_BR/strings.txt`,
  `locale/pt_PT/strings.txt`, `locale/ro_RO/strings.txt`,
  `locale/ru_RU/strings.txt`, `locale/zh_CN/strings.txt` — +1 line each:
  `$TLS_href_severity_config = "<native translation>";`
- `tmp/TLU_Test_Cases.md` — regression suite "Regression — Issue #1459",
  8/8 PASS.
- `docs/Bugfix-Issue-1459-SeverityConfig-ASIDE-Key-Only-in-en_GB.md` — this file.
- `tmp/wiki-repo/Bugfix-Issue-1459-SeverityConfig-ASIDE-Key-Only-in-en_GB.md` —
  wiki mirror.
- `docs/screenshots/issue-1459-severity-config-fixed-ro.png`,
  `tmp/wiki-repo/issue-1459-severity-config-fixed-ro.png` — fixed state,
  ro_RO ASIDE with the expanded Test Strategy sub-menu.
- `CHANGELOG` — one-line entry under 2.0.1 KEY BUGFIX section.

## Verification

All checks on branch `fix/issue-1459-severity-config-i18n`, PHP 8.x, MySQL
fresh-import schema, admin/admin.

- Pre-fix control: ro_RO session → sub-item "Severity Configuration"
  (English); `grep -rl href_severity_config locale/*/strings.txt` → 1 file.
- Post-fix R1 (ro_RO session, reporter's locale): sub-item now
  **"Configurare severitate"**; grep coverage → **19** files.
- Post-fix R2 (de_DE session): **"Schweregrad-Konfiguration"**.
- Post-fix R3 (en_GB session): still **"Severity Configuration"** (unchanged).
- `php -l` on all 18 touched files: "No syntax errors" (1/1 each).
- cs_CZ bytes re-decoded as cp1250 → `Konfigurace závažnosti` (ž=0x9E,
  á=0xE1), full-file cp1250 decode OK.
- ASIDE link target: last sub-item opens
  `gui/templates/projects/severityConfig.html` (loads fine, project selector
  + disabled Save until a project is chosen).
- Event Viewer / `events`: the only `href_severity_config` L18N-fallback row
  is the pre-fix reproduction (id 47, 14:48:54); **zero** new rows after the
  fix; zero ERROR/WARNING rows overall.

Regression suite: `tmp/TLU_Test_Cases.md` — "Regression — Issue #1459", 8/8 PASS.

Address: `Fixes #1459`.