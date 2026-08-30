# Install / Upgrade Check — Modernized Screen

The **Install / Upgrade** screen reports the installation & schema status of a
running TestLink instance. It replaces the legacy upgrade landing
(`install/index.php` check flow + `lib/functions/configCheck.php`
`checkSchemaVersion()` / `checkForInstallDir()` / `checkForAdminDefaultPwd()`
messages) with a modern Dashio-styled page backed by a plain-PHP REST BFF.

**Path:** ASIDE menu → System → Install / Upgrade
**URL:** `gui/templates/install/installView.html`
**BFF API:** `api/install/index.php` — `GET /` (status JSON)
**Rights:** any authenticated user (BFF returns 401 otherwise); ASIDE entry
shown to users with the `system_configuration` global right
**Tracking issue:** [#797](https://github.com/sebiboga/testlink-upgraded/issues/797)

> **Scope note:** only the *status-check* part of the legacy install area is
> modernized. The full install wizard (`install/index.php` + step scripts) runs
> **before** the DB/session exist and intentionally stays legacy.

---

## 1. Overview

The screen shows, at a glance:

| Status card | Content |
|-------------|---------|
| **App version** | `TL_VERSION` (e.g. `2.0.1 [TEST]`) |
| **Latest DB schema version** | `TL_LATEST_DB_VERSION` (e.g. `DB 2.0.0`) |
| **DB schema version** | current value from the `db_version` table (max `upgrade_ts`) |
| **Schema state** | badge: OK / Upgrade needed / Manual upgrade / Unknown / Undetermined |
| **Config file present** | `config_db.inc.php` exists on disk |
| **Database reachable** | DB connection succeeded |
| **GD library (charts)** | `gd` extension + PNG support |

Plus two panels: **Upgrade Required** (only when the schema is out of date —
same message cases as the legacy `checkSchemaVersion()`) and **Security Notes**
(install dir still present, default admin password, missing LDAP extension).

## 2. Schema-state logic (parity with legacy `checkSchemaVersion()`)

| DB version | Status | Client message key |
|------------|--------|--------------------|
| `TL_LATEST_DB_VERSION` | ok | — |
| `1.7.0 … , DB 1.1, DB 1.2` | upgrade | `install.msgUpgrade` |
| `DB 1.3 … DB 1.9.19` | manual | `install.msgManual` |
| `DB 1.9.20` + still-32-char password column | upgrade | `install.msgPartialMigration` |
| anything else | unknown | `install.msgUnknown` (shows schema + target version) |
| no DB / no row | undetermined | `install.msgUndetermined` |

## 3. Actions

| Action | Target |
|--------|--------|
| **Open installer wizard** | legacy `install/index.php` (full wizard, `_top`) |
| **Installation manual** | `docs/testlink_installation_manual.pdf` |
| **README** | repo root `README` |
| **CHANGELOG** | repo root `CHANGELOG` |

## 4. Data flow

| Step | Description |
|------|-------------|
| 1 | Screen boots `TLi18n`, renders header/toolbar |
| 2 | `$.getJSON('/api/install/index.php')` fetches status |
| 3 | BFF: session check (401), then version/schema/DB/security checks |
| 4 | Client renders status cards, badge, upgrade + security panels |
| 5 | **Refresh** re-fetches from BFF; **locale switcher** re-labels the page |

## 5. Files

| File | Purpose |
|------|---------|
| `gui/templates/install/installView.html` | Dashio HTML screen |
| `api/install/index.php` | BFF API (install/upgrade status) |
| `gui/templates/i18n/*.json` (10 bundles) | i18n keys (`install.*`) |
| `lib/functions/common.php` | `$actions->installView` ASIDE link |
| `gui/templates/dashio/aside.tpl` | System → Install / Upgrade entry |
| `gui/templates/dashio/labels/labels.aside.tpl` | Smarty `install_header` label |
| `locale/en_GB/strings.txt`, `locale/ro_RO/strings.txt` | `$TLS_install_header` |
| `lib/functions/configCheck.php` | upgrade-linked message now points to the new screen |
| `lib/functions/common.php`, `lib/general/mainPage.php` | corrected legacy `system_configuration` right typo |

![Install / Upgrade status screen](img/install_status.png)

## 6. Regression suite

See `tmp/TLU_Test_Cases.md` — **Suite 797 — Install/Upgrade status screen (#797)**.