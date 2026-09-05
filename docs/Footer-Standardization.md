# Footer Standardization (Refs #745)

## Convention

Every modernized (Dashio) screen in `gui/templates/` ends with a standardized
footer line:

```html
<div class="footer"><span data-i18n="footers.<slug>">TestLink 2.0.1 - <Screen Name></span></div>
```

- **<slug>** is the HTML file name without extension (e.g. `buildsView`).
- **<Screen Name>** matches the screen's i18n header title (English default).
- The `.footer` CSS is the same as `tcImport.html`:
  `.footer { padding: 12px 24px; color: #888; font-size: 12px; border-top: 1px solid #ddd; background: #fff; margin-top: 16px; }`
- i18n keys live under the flat `footers.<slug>` namespace in **all** locale
  bundles (`gui/templates/i18n/{en,ro,de,es,fr,it,ja,pt,ru,zh}.json`).
  Keys must be flat (`"footers.buildsView": "..."`) — the TLi18n module does a
  flat dotted lookup, nested objects are not resolved.

## Dynamic info bars

Screens that keep a functional footer bar (`#footerInfo` / `#footerText`,
filled by JS with counts, match amounts or `generated on` timestamps) keep it
**above** the standardized static footer. Example (Search screens):

```html
<div class="footer" id="footerInfo"></div>
<div class="footer"><span data-i18n="search.footerCases">TestLink 2.0.1 - Search Test Cases</span></div>
```

Screens that already render `TestLink 2.0.1 - <title>` into `#footerText`
via JS (e.g. `reqView`, `reqEdit`, `reqSpecView(+Revision)`,
`projectInfoView`, `printDocument`, `reqSpecCompare`) are considered conformant
and are not duplicated.

## Scope

Applied to all modernized screens in `gui/templates/` (63 files), including the
login/auth screens, `testSpec`, `projectsView` and `reportPrint`. Older
placeholder footers that rendered nothing were completed; screens without any
footer got the static element plus the `.footer` CSS when missing.

## Verification

- `python3 -m json.tool` on every bundle.
- Browser: footer text renders localized (en/ro) — not the literal key.
- No new ERROR/WARNING rows in the `events` table.