# Attachment Download — modernized screen (2.0.1)

**Refs #1794** (enhancement) · bug **#1795** (legacy authorization hole, read-side twin of #1768)

Modern twin of the legacy `lib/attachments/attachmentdownload.php` (TestLink 1.9.20), the controller
that streamed attachment bytes to the browser. Together with #1525 (upload) and #1638 (delete) this
completes the Attachments area of the UI.

| Legacy | Modern |
|---|---|
| `lib/attachments/attachmentdownload.php` | `api/attachmentsdownload/index.php` (stream + metadata) |
| `gui/templates/tl-classic/attachmentdownload.tpl` | `gui/templates/attachments/attachmentDownload.html` |
| `attachments.inc.tpl` / `inc_attachments.tpl` (dashio + tl-classic) | same lists, now opening the modern popup |

## What the screen shows

A Dashio popup (teal header, dark toolbar, card layout) with:

- **Attachment card** — title, file name, type + human size + a type badge (`IMAGE` / `SVG` /
  `FILE`), upload date, description, and the owner with project/plan context chips.
- **Preview card** — the image in place for raster images (transparent checkerboard background),
  otherwise an explicit state:
  - `Preview blocked for safety` for any SVG (TestLink always serves an SVG as a download),
  - `No preview available` for everything else.
- **Actions** — `Download` (always a download), `Open in new tab` (only when the stream will really
  render the file — hidden with an explanatory hint otherwise), plus `Refresh` and `Close` in the
  toolbar so they survive every error state.
- **Locale switcher** (16 locales offered, `adl.*` keys present in all 10 bundles).

## API

### `GET ?action=init&id=<attachments.id>`

```json
{"status":"ok","attachment":{
  "id":6,"file_name":"screenshot.png","title":"…","description":"…",
  "file_type":"image/png","file_size":89,"file_size_human":"89 B","date_added":"…",
  "token":"<sha256(file_name)>","is_svg":false,"is_image":true,"can_inline":true,
  "preview_url":"…&disposition=inline","inline_url":"…&disposition=inline",
  "download_url":"…&disposition=attachment",
  "owner":{"table":"tcversions","id":10,"label":"10 - ADL test case"},
  "context":{"tproject_id":7,"tplan_id":0,"testproject":"ADL1794","testplan":""}}}
```

### `GET ?action=download&id=<id>[&token=<t>][&disposition=inline|attachment]`

Returns the bytes with `Content-Type`, `Content-Length` (real `strlen`), `X-Content-Type-Options:
nosniff`, `Content-Disposition` (ASCII `filename` + RFC 5987 `filename*`) and, for the inline branch,
`Content-Security-Policy: sandbox` + `X-Frame-Options: DENY`.

| Condition | Answer |
|---|---|
| no session | `401 NOT_AUTHENTICATED` |
| attachments globally disabled | `403 ATTACHMENTS_DISABLED` |
| no right on the owning object | `403 FORBIDDEN` |
| `token` present but wrong | `403 INVALID_TOKEN` |
| bad / missing id, unknown action, bad `disposition` | `400` |
| unknown attachment, empty or undecodable content | `404` |
| wrong verb | `405` |

## Security — bug #1795

The legacy file called `testlinkInitPage($db)` **without** a rights-check callback: `checkRights()`
was defined at the bottom of the file and never invoked. The only effective gate was
`config_get('attachments')->enabled`, a global installation switch, and `$args->id` is a sequential
`attachments.id`. Any authenticated user — including a global `<no rights>` role — could therefore
stream every attachment of the installation (test case, requirement, test plan and execution
attachments, `is_public` never consulted). Measured: `role_id = 3` got HTTP 200 + the bytes from
the legacy URL and HTTP 403 from the new endpoint.

The BFF resolves the owner from the **stored** `fk_table`/`fk_id` (never from caller input) and
gates it with the shared right sets in `api/_attachauth.php`, failing closed when the owner cannot be
resolved.

Additional hardening (kept from code review):

- **fail-closed disposition** — a missing `disposition` means `attachment`; only an explicit
  `disposition=inline` renders, the value is whitelisted (else `400`), and inline is restricted to
  `image/*`, `audio/*`, `video/*`, `application/pdf`, `text/plain`. Before that fix a `text/html`
  attachment could execute in the app origin (stored XSS — attachments are user uploaded).
- **SVG** is never rendered inline (`XSS_StringScriptSafe()` parity + a hard `can_inline=false`).
- **`Cache-Control: private, no-store`** on rights-gated bytes (legacy sent `Pragma: public`).
- token compared with `hash_equals()`; control bytes stripped from the file name.

## Backward compatibility

`lib/attachments/attachmentdownload.php` is now a **session-guarded 302 shim**: it forwards
`id` (+ `skipCheck`, + `apikey`) to the BFF and redirects. It must stay, because the byte stream is
still the `<img>` src of every printed attachment — `print.inc.php` (5 call sites),
`testcase.class.php:8396`, `testsuite.class.php:1721`, `requirement_mgr.class.php:4060` — and of
the eye toggle in the attachment lists. An anonymous request still gets the legacy
`login.php?note=expired&destination=…` JS redirect.

## Verification

Suite: `tmp/TLU_Test_Cases.md` → *Modernize — Issue #1794* — **55/55 PASS** (entry points, metadata,
stream headers/dispositions, rights/token/CSRF, legacy shim + `skipCheck` + `apikey`, UI states,
i18n, Event Viewer). Event Viewer after the full sweep: no new Error/Warning entries.