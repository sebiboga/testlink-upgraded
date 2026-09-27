# Task — Issue #990: project API key display in the `projectsView` edit modal

Port of the legacy **project API key** row into the modern
**Test Project Management** screen (`gui/templates/projectsView.html`).

## What legacy did

The 1.9.20 project editor showed the project's API key as **read-only text**,
only when the key was set:

- `lib/functions/testproject.class.php:124-136` — `create()` always generates one:
  ```php
  $api_key = md5(rand()) . md5(rand());
  ... " options,notes,active,is_public,prefix,api_key) " ...
  ```
  So in practice every project has a 64-char key unless somebody blanks it.

- `lib/project/projectEdit.php:589` — `edit()` copies it into the gui object
  alongside the other columns:
  ```php
  $k2l = array('color','notes', 'active','is_public','issue_tracker_enabled',
               'code_tracker_enabled','reqmgr_integration_enabled','api_key');
  ```

- `lib/project/projectEdit.php:239` — `api_key` belongs to `$nullable_keys`, so a
  blank/absent value never aborts the update.

- `gui/templates/dashio/project/projectEdit.tpl:269-274` — the row itself, inside
  the *Availability* block, right after the Active/Public checkboxes and before
  the button row:
  ```smarty
  {if $gui->api_key != ''}
  <tr>
    <td {$tdStyle}>{$labels.api_key}</td>
    <td>{$gui->api_key}</td>
  </tr>
  {/if}
  ```
  Plain text, **no** input, no copy button, no edit control.

## The gap in 2.0.1

| Layer | Before | Symptom |
|---|---|---|
| BFF SQL | `api/projects/index.php` `projectSelect()` did not select `tp.api_key` | `$row['api_key']` undefined |
| BFF serializer | `formatProject()` returned 13 keys, none of them the key | the field could never reach the browser |
| `projectsView.html` | the *Availability* form-section ended at the Public checkbox | nothing to render |

The gap was **endpoint-scoped**, not repo-wide: the sibling standalone screen
`gui/templates/projects/projectEdit.html:208-213,343-344` already rendered the row
correctly against its own endpoint (`api/projectedit/index.php:293`,
`'api_key' => $info['api_key'] ?? ''`). So the markup pattern to follow already
existed in 2.0.1 — only the `projectsView.html` modal path was missing it.

Measured before the change (admin logged in, project with a 64-char key):

```
GET /api/projects/1  ->  data keys: [id, name, prefix, description, isActive, isPublic,
                          optReq, optPriority, optAutomation, optInventory,
                          issueTracker, codeTracker, reqMgrSystem]     # no api key
editProject(1)       ->  {hasApiKeyText:false, hasApiKeySection:false}
```

## The port

### BFF — `api/projects/index.php`

1. `projectSelect()` — added `tp.api_key` to the column list.
2. `formatProject()` — added the serialized field, read-only by contract:
   ```php
   // Legacy parity: projectEdit.tpl:269-274 shows the project's API key as
   // read-only text, but only when it is set. testproject::create() always
   // generates one (testproject.class.php:124), so the empty case is a
   // hand-cleared key. Never writable through this endpoint (POST/PUT ignore
   // it) — the key is generated server-side and only used by the lnl.php
   // anonymous-access links.
   'apiKey' => (string)($row['api_key'] ?? ''),
   ```

Both response shapes changed together, because `listProjects()` and
`getProject()` share `formatProject()`: `GET /api/projects/` and
`GET /api/projects/<id>`.

`createProject()` / `updateProject()` were **not** touched, so the key stays
server-generated and cannot be set through the API — matching legacy, where
`api_key` is only ever produced by the manager.

### Screen — `gui/templates/projectsView.html`

- **Markup** — `#apiKeyRow` / `#apiKeyVal` inside the *Availability*
  form-section, exactly at the legacy position (after Active/Public, before the
  modal actions), plus a **Copy** button. No `<input>`: the key is not editable.
- **CSS** — `.readonly-val` (monospace, `#f8f9fa` panel, `word-break: break-all`,
  `user-select: all` so the 64-char md5 is selectable in one click) and
  `.copy-btn`, in the file's existing teal `#4ECDC4` palette.
- **JS `editProject()`** — shows the row and writes the value with `textContent`
  **only when the key is non-empty**, otherwise clears the value and hides the
  row. That is the literal `{if $gui->api_key != ''}` condition; both branches
  are explicit, so no value can leak from a previously edited project.
- **JS create handler** — forces the row hidden *and* empties the value: a
  project has no key until `create()` INSERTs one, and the legacy create screen
  never shows the row either.
- **JS `copyApiKey(btn)`** — Clipboard API with an `execCommand('copy')`
  fallback (the Clipboard API needs a secure context, so the fallback is what
  runs on plain-HTTP hosts); the label swaps to `proj.apiKeyCopied` /
  `proj.apiKeyCopyFailed` for 2 s. The button is `type="button"`, so it cannot
  submit the surrounding form.

### i18n

Four keys added to **all 10** locale bundles (de, en, es, fr, it, ja, pt, ro, ru, zh),
inserted in place after `proj.apiId` (`+4/-0` per file — the bundles are only
locally ordered, so re-sorting them must be avoided):

| key | en | ro | de | es | fr | it | ja | pt | ru | zh |
|---|---|---|---|---|---|---|---|---|---|---|
| `proj.apiKey` | API Key | Cheie API | API-Schlüssel | Clave API | Clé API | Chiave API | APIキー | Chave da API | Ключ API | API 密钥 |
| `proj.apiKeyCopy` | Copy | Copiază | Kopieren | Copiar | Copier | Copia | コピー | Copiar | Копировать | 复制 |
| `proj.apiKeyCopied` | API key copied to clipboard | Cheia API a fost copiată în clipboard | API-Schlüssel in die Zwischenablage kopiert | Clave API copiada al portapapeles | Clé API copiée dans le presse-papiers | Chiave API copiata negli appunti | APIキーをクリップボードにコピーしました | Chave da API copiada para a área de transferência | Ключ API скопирован в буфер обмена | API 密钥已复制到剪贴板 |
| `proj.apiKeyCopyFailed` | Copy failed | Copierea a eșuat | Kopieren fehlgeschlagen | Error al copiar | Échec de la copie | Copia non riuscita | コピーに失敗しました | Falha ao copiar | Не удалось скопировать | 复制失败 |

The label reuses the wording already proven for `pedit.apiKey` in each locale, so
it is identical to the sibling screen's.

## Verification

21 / 21 assertions PASS — full table in `tmp/TLU_Test_Cases.md`
("Task — Issue #990"), covering:

- BFF: field present in both the single and the list response, 404 and 401 paths
  unchanged, **key not writable** (`PUT {"apiKey":"HACKED"}` is ignored while the
  rest of the same body is applied);
- screen: visible only for a keyed project in edit mode; hidden in the create
  modal and for a blank key; correct behaviour through the
  create → edit(blank) → edit(keyed) → create sequence; no stale value in the DOM;
  value injected as text, not markup; copy button cannot submit the form;
- i18n: `?locale=ro` → `Cheie API` / `Copiază`, `?locale=ja` → `APIキー` / `コピー`;
- regression: the 4 other consumers of `/api/projects/index.php`
  (`tcAssignments.html`, `planUpdateTC.html`, `testUrgency.html`,
  `platformsAssign.html`) still get HTTP 200 and only read `id`/`name`;
- hygiene: `php -l` clean, no console error/warn, `events` table gains no
  Error/Warning row (`sum(log_level>16) = 0`).

## Security note

The project API key is public-share material: it is the anonymous access
credential for the project — `lnl.php` share links
(`lib/functions/common.php:1442`) and a direct
`metricsDashboard.php?apikey=<key>` entry, resolved by
`testproject::getByAPIKey()` (`lib/results/metricsDashboard.php:358-390`).
It is exposed only
through `api/projects/index.php`, which gates **every** route behind
`mgt_modify_product` (`api/projects/index.php:41`) — so no new access path is
created, and an anonymous request still gets 401. The value is a server-generated
md5 pair and is never accepted from the client.

## Review findings addressed (code review of the #990 diff)

- Stale "copied" message surviving a modal reopen: the button label is now reset
  on **every** open path (`resetApiKeyButton()`), which also cancels the pending
  revert timer, so a second click cannot flip the label back mid-message.
- The Copy button reuses the file's existing `.action-btn.info-btn` pair instead
  of a new bespoke `.copy-btn` rule; the value got a focus outline and
  `tabindex`/`role`/`aria-readonly`, the label got a `for`, and the message span
  got `aria-live="polite"` so the feedback is announced.
- The copy feedback is selected with `btn.querySelector('[data-i18n]')` instead
  of `'span'`, so it survives a markup change around the icon.
- "only used by the lnl.php links" was factually too narrow — the key also
  authenticates `metricsDashboard.php?apikey=` directly; corrected in the code
  comment, the docs and the CHANGELOG.

## Related

- Sibling screen that already had the row: `gui/templates/projects/projectEdit.html:208-213`.
- Bug found while regression-testing the other consumers, filed separately and
  **not** fixed here: `api/tcassignments/index.php:348` returns an empty HTTP 500
  when a project has no test plans → **#1648**.
