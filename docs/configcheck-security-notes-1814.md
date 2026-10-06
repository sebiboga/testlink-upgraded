# Issue 1814 — Configuration Check / Security Notes screen + dashboard banner

The legacy app computed a batch of **configuration security notes** (install
directory still present, default `admin` password, LDAP/BTS/email/extension
checks) on startup and required an admin to act on them. TestLink 2.0.1 had
modernized every screen but the one that *rendered* those notes — so the notes
were computed on every legacy page and never displayed. Modernized now.

## Legacy behaviour (1.9.20)

`lib/functions/configCheck.php::getSecurityNotes()` (`:251`) collects the notes
in a fixed check order:

1. `checkForInstallDir` — `install_dir` still present ⇒ "Install directory
   should be removed!"
2. `checkForAdminDefaultPwd` — admin's password unchanged ⇒ "You should change
   the default password for the 'admin' account!"
3. `checkForLDAPExtension` — LDAP used but `ldap` PHP extension missing
4. `checkForBTSConnection` — an issue tracker is configured but unreachable
5. `checkForRepositoryDir` — the repository `dir_path` is not writable
6. `checkSchemaVersion` — database schema differs from the expected version
7. `checkEmailConfig` — email feature breaks down to the missing SMTP settings
   (`tl_admin_email`, `from_email`, `return_path_email`, `smtp_host`, …)
8. `checkForExtensions` — PHP extensions required by the configuration

The note *texts* come from the server-side `strings.txt` of the session locale;
each note carries a stable **code** (`install_dir`, `admin_pwd`, `bts_connection`,
`repository_dir`, `schema`, `email_config`, `extensions`, …) that the UI uses as
a chip. Both the codes and the fixed check order are preserved 1:1 in the BFF.

**The bug:** when `TL_WARNING_MODE` is `FILE` or `SILENT`,
`getSecurityNotes()` *nulls* the notes after logging them, so the (hypothetical)
legacy UI showed nothing while the admin was told in tiny startup output that
warnings were written to a file. The BFF deliberately does **not** repeat that:
the modern screen always lists every note, whatever the mode.

## Modern re-implementation

* **BFF** `api/configcheck/index.php`, `GET|HEAD ?action=init` (route verb guard
  → `405` + `Allow: GET, HEAD`; `is_scalar` action guard → `400
  unknown_action`; session gate **before** the DB connect → `401
  not_authenticated` for anonymous). Response:
  `{status:"ok", notes:[{code,text}], count, mode, file, appVersion, user_id,
  legacy_function}`. `legacy_function` documents the `getSecurityNotes()`
  lineage; `file` only when the mode writes one. `JSON_INVALID_UTF8_SUBSTITUTE`
  keeps the payload serializable for any note text.

* **Screen** `gui/templates/conf/configCheck.html` (Dashio shell, standalone,
  no Smarty) — the same state layout used by `execNotesReadonly.html`:
  * header + toolbar: warning-mode context, locale switcher, Refresh / Back /
    Close;
  * meta strip: mode, destination file (`ccn.fileNone` when the mode writes
    none), `count` warnings, app version;
  * `modeInfo` line + `fileNote` line state both facts with `{mode}`/`{file}`
    interpolation (`TLi18n.t(key, params)`, `i18n.js:174`);
  * notes card: one row per note, red-framed icon + code chip (`.text()`+`esc()`
    escapes, never `innerHTML`); an empty list renders the
    "every check passed" success state;
  * states for `401` (bounce to `login.php` with `destination`), `403`, `404`,
    `400/405`, `500`, plus a loading box.
  * session check first → the screen never flashes an error list at anonymous
    users.

* **Dashboard banner** `gui/templates/mainpage/mainPage.html` — `#cfgCheckBanner`
  fetches the same BFF and shows only when `count > 0`: amber card,
  `ccn.bannerTitle`, `ccn.bannerText` with `{count}` interpolated, and a
  "View details" link opening the screen in a new tab (`target="_blank"`,
  `$actions->configCheck` deep link from `lib/functions/common.php`). The fetch
  failure is silent by design — the dashboard must load regardless.

## i18n

New keys `ccn.title`, `ccn.headerSub`, `ccn.mode`, `ccn.file`, `ccn.fileNone`,
`ccn.count`, `ccn.appVersion`, `ccn.modeInfo`, `ccn.fileNote`,
`ccn.notesHeading`, `ccn.emptyMsg`, `ccn.bannerTitle`, `ccn.bannerText`,
`ccn.bannerLink`, `ccn.errorTitle`, `ccn.errorMsg`, `ccn.accessDenied*`,
`ccn.notFound*`, `ccn.badRequest*` and `footers.configCheck` — added to **all
10** bundles (`de,en,es,fr,it,ja,pt,ro,ru,zh`). Toolbar verbs reuse
`common.refresh`/`common.back`/`common.close`. The note rows themselves keep the
server-side `strings.txt` texts (legacy L10n), so nothing new is rendered as a
raw key; `bash ai/verify_i18n_coverage.sh` passes before commit.

## Behaviour notes

* **Notes are never dropped.** The legacy FILE/SILENT nulling
  (`configCheck.php:220-224` semantics) is intentionally not reproduced, and the
  `modeInfo` line documents that choice on the screen.
* Codes + check order are stable (BFF contract), chip text is the code itself —
  usable as a filter key.
* The banner is advisorial only: it appears on the dashboard and never blocks
  navigation; anonymous/`403` users simply don't get the banner.




## Test suite

*Suite — Issue #1814* in `tmp/TLU_Test_Cases.md` (11 cases, all PASS): BFF
payload/405/400/401, screen render, locale switch, Refresh/Back, dashboard
banner, console + Event Viewer clean, i18n gate.
