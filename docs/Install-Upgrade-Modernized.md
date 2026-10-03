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
| **Forum** | `http://forum.testlink.org` — BFF `links.forum` (#1285) |

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
---

## 7. Community videos (contributor walkthroughs) — #1286

The legacy install landing page (`install/index.php:52-58`) carried a curated block,
"Some user contributed videos (You Tube)", with four walkthroughs contributed by
TestLink users. That block was **dropped** during modernization — the screen had no
videos section and the BFF had no data source for it. It is now ported back.

### 7.1 What the screen shows

A third section, **Community Videos**, after **Actions** (the legacy order had the
video block right above the "New installation" call to action):

| # | Caption | Video |
|---|---------|-------|
| 1 | Installation of "TestLink" & Creating project | `NOvTWZvc2x8` |
| 2 | TestLink Test Management Tool Tutorial | `P2zWScVjuag` |
| 3 | Introduction to TestLink | `7xH1LKQU1TA` |
| 4 | TestLink Walkthrough | `6s48WGuX2WE` |

Each card is an `<a>` with a dark play-button tile, the localized caption and a
"YouTube" source line, styled like the other Dashio cards (white, `0 1px 4px` shadow,
red left border, teal on hover).

### 7.2 How it works

| Step | Description |
|------|-------------|
| 1 | `install_community_videos()` in `api/install/index.php` returns the four `{id, key, url}` entries — the server-side single source of truth |
| 2 | They are exposed as the top-level JSON key `videos`, next to the existing `links` object |
| 3 | `renderVideos(r)` in `installView.html` renders `#videos`; `#videosSection` / `#videosHint` stay hidden until the payload has entries |

The caption is **not** hardcoded in the HTML: the BFF sends an i18n `key` per video
(`install.videoInstallProject`, `install.videoTestManagementTool`,
`install.videoIntroduction`, `install.videoWalkthrough`) and the client resolves it with
`TLi18n.t()`, so the block is localized in all 10 bundles like every other label.

### 7.3 Deliberate deviations from legacy

| Legacy | 2.0.1 | Why |
|--------|-------|-----|
| `<a … target="#">` | `target="_blank" rel="noopener noreferrer"` | `target="#"` was a no-op that also handed `window.opener` to YouTube |
| titles inline in the PHP file | i18n keys + 10 bundles | mandatory i18n (rule 3) |
| plain `<a>` + `<br>` list | Dashio card grid, responsive | look & feel (rule 5) |
| no guard | non-`https` URLs, entries without `url`, and `null` entries are dropped; empty list hides the section | the screen must not render a hostile href or an empty shell |

### 7.4 Files

| File | Purpose |
|------|---------|
| `api/install/index.php` | `install_community_videos()` + the `videos` JSON key |
| `gui/templates/install/installView.html` | `.videos`/`.video-card` CSS, the `#videosSection`/`#videosHint`/`#videos` markup, `renderVideos()` |
| `gui/templates/i18n/*.json` (10 bundles) | `install.videosTitle`, `install.videosHint`, `install.videoSource` + the 4 caption keys |

### 7.5 Regression suite

See `tmp/TLU_Test_Cases.md` — **Task — Issue #1286** (8 cases, all PASS): payload
parity with legacy, 4 rendered cards, `target`/`rel`, `ro` localization, 10-bundle i18n
completeness, degenerate/hostile payloads, console + Event Viewer clean.

## 8. Community forum link — #1285

### 8.1 What legacy did

The legacy installer landing page offered the TestLink community forum **twice**
(`install/index.php:25` defines `$forum_url = 'forum.testlink.org'`):

* inline in the migration notice — `:45`
  *"Please read Section on README file or go to http://forum.testlink.org
  (Forum: TestLink 1.9.4 and greater News,changes, etc)"*
* as a standalone link beside the manual / README / CHANGELOG links — `:49-50`
  *"You are welcome to visit our forum to browse or discuss."*

Modernization ported the doc links (manual, README, CHANGELOG) but not the forum,
so the Actions box offered four buttons and the migration notice said nothing about
the forum — a user following the modern screen could no longer reach the support forum.

### 8.2 What the modern screen does now

* `api/install/index.php` serves `'forum' => 'http://forum.testlink.org'` in the `links`
  payload — the URL lives server-side, the front-end never hardcodes it.
* `renderActions()` appends a 5th action button **Forum** (`fa-comments`, `.action-btn blue`)
  after CHANGELOG, mirroring legacy `:49-50`. It is guarded by
  `if (r.links && r.links.forum)`, so a payload from an older BFF degrades to the previous
  four buttons instead of rendering a dead link.
* `renderForumNotice()` writes the forum sentence into `#upgradeForum` **inside the upgrade
  panel**, i.e. exactly when a schema migration is pending — the modern equivalent of the
  legacy `:45` notice sentence. It is never shown when the schema is up to date.
* Both anchors are built with jQuery `.attr()` (no concatenated HTML), restricted to
  `http(s)` by a regex allow-list (a malformed `links.forum` injects neither an attribute nor a
  `javascript:` URL), carry `target="_blank" rel="noopener noreferrer"` and expose the URL as
  `title`. The notice is cleared again when the schema returns to OK.
* i18n: `install.forum` (label) and `install.forumHint` (the legacy parenthetical) in all
  10 bundles — `+2` lines each, nothing removed.

### 8.3 Files

| File | Purpose |
|------|---------|
| `api/install/index.php` | `links.forum` in the status payload |
| `gui/templates/install/installView.html` | `renderForumNotice()`, `#upgradeForum` markup, the Forum action button |
| `gui/templates/i18n/*.json` (10 bundles) | `install.forum`, `install.forumHint` |

### 8.4 Regression suite

See `tmp/TLU_Test_Cases.md` — **Task — Issue #1285** (9 cases, all PASS): button
rendered, href/target/rel, `links.forum` in the BFF payload, forum sentence inside the
upgrade panel (and hidden again when the schema is OK), localisation in ro/ja/en,
10-bundle key completeness, `node --check` / `php -l` / console / Event Viewer clean.

## 9. Attachments-repository (FS) security check — #1283

### 9.1 The gap

Legacy `getSecurityNotes()` (`lib/functions/configCheck.php:250-300`) ended with the
attachments repository check (`:275-282`):

```php
if ($repository['type'] == TL_REPOSITORY_TYPE_FS) {
  $ret = checkForRepositoryDir($repository['path']);
  if (!$ret['status_ok']) { $securityNotes[] = $ret['msg']; }
}
```

`checkForRepositoryDir()` (`:368-390`) is a `is_dir()` + `is_writable()` test that composes
the note out of localized fragments: `directory for attachments: <path> does not exist` /
`… exists The directory is not writable!`. The note was displayed on `login.php:230`,
`mainPage.php:184` and `common.php:1787`.

Modernization ported the install-dir / LDAP / default-admin-password / e-mail-config notes
into `api/install/index.php`, but never the FS branch — an installation whose attachments
directory was missing or read-only showed **no** warning on the Install / Upgrade screen,
although every attachment upload would fail.

### 9.2 What the modern screen does now

* `install_check_repository_dir()` in `api/install/index.php` mirrors `configCheck.php:368-390`
  line by line (same `lang_get()` fragments, same `clearstatcache()` / `is_dir()` /
  `is_writable()` logic) and additionally returns `exists` / `writable` for the front-end badge.
* It runs **only** when `config_get('repositoryType') == TL_REPOSITORY_TYPE_FS`, exactly like
  legacy. The result is published as a top-level `repository` block:
  `{type, typeCode, path, checked, status_ok, exists, writable, msg}`.
* On failure the legacy string is appended to `securityNotes` (unchanged wording, so any other
  consumer of the payload sees the legacy text) and the code `repository_dir` to `securityCodes`.
* `securityNoteItems` — a new array parallel to `securityNotes` — carries `{code, key, params}`
  for the notes whose text must follow the UI language (here `install.repoDirMissing` /
  `install.repoDirNotWritable` with `{path}`), `null` for every pre-existing note, which keeps
  their current server-rendered strings. `installView.html` re-composes the note with
  `TLi18n.t(key, params)` and falls back to the server string if the key is missing.
* Two **ATTACHMENTS REPOSITORY** cards in the Installation Status grid: the repository type
  (`Filesystem` / `Database`) and the directory state badge — green `repoDirOk`, red
  `repoDirNotWritable` / `repoDirMissing`, and an explicit `repoDirNotChecked` state for the DB
  type (legacy evaluates the directory only for FS). Values are injected with `esc()` and the
  badge class comes from a fixed allow-list, so a path can never inject markup.
* i18n: `install.repository`, `install.repoTypeFs`, `install.repoTypeDb`, `install.repoDirOk`,
  `install.repoDirMissing`, `install.repoDirNotWritable`, `install.repoDirNotChecked` in all
  10 locale bundles (`+7` lines each).

Reproduce a failing state without editing the repository: the built-in server honours
`TESTLINK_UPLOAD_AREA` (`config.inc.php:1615-1619`), e.g.
`TESTLINK_UPLOAD_AREA=/tmp/tlu_missing_dir_test php -S 127.0.0.1:8082 -t .` with that
directory `chmod 555`, or a non-existent path.

### 9.3 Files

| File | Purpose |
|------|---------|
| `api/install/index.php` | `install_check_repository_dir()`, `repository` block, `repository_dir` note, `securityNoteItems` |
| `gui/templates/install/installView.html` | `renderRepositoryCards()`, localized security-note rendering |
| `gui/templates/i18n/*.json` (10 bundles) | `install.repository`, `install.repoType*`, `install.repoDir*` |

### 9.4 Regression suite

See `tmp/TLU_Test_Cases.md` — **Task — Issue #1283** (7/7 PASS): healthy FS dir (green badge,
no note), not-writable dir, missing dir, healthy-state regression, ro localisation, DB type
(no check), Event Viewer / console / `php -l` / `node --check` / `json.tool` gates.
