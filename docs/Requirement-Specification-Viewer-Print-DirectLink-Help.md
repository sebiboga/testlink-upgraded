# Requirement Specification Viewer — Print / Direct Link / Help Modernized

**Modernized as part of**
[#1350](https://github.com/sebiboga/testlink-upgraded/issues/1350) — the modern
Requirement Specification Viewer toolbar (`gui/templates/requirements/reqSpecView.html`)
now restores the three toolbar actions that the screen-compare had dropped:

| Button | Behavior |
|---|---|
| **Print view** | `openPrint()` opens `printDocument.html?type=reqspec&level=reqspec&id=<spec>&tproject_id=<tid>` (the reqdoc/printDocument flow suggested by the issue; single-spec print parity with legacy `openPrintPreview` / `reqSpecPrint.php`) |
| **Direct link** | `toggleDirectLink()` reveals a permanent `linkto.php` deep link (`item=reqspec&id=<docid>`, e.g. `RS-PRINT`) with a **Copy** button + Dashio copy toast — the legacy `toggle_direct_link` / hidden `.direct_link` bar of reqSpecView.tpl:93-94 |
| **Help** | `#helpBtn` opens the Requirement Specification Viewer documentation (wiki), the legacy `inc_help hlp_requirementsCoverage` icon |

The permanent link itself was already delivered by the BFF `spec_view` payload
(`spec.direct_link`), so **no backend change was needed** — only the toolbar UI,
confirming the issue's suggestion that the BFF already exposes the data and the
gap is purely presentational. The print action reuses the modern
`printDocument.html` + `api/reqdoc` flow (rights gate `testplan_metrics` on the
owning project — the same gate as `reqPrint` right in `rights.can_print`).
Same Dashio patterns as the modernized reqView (#1305): `.btn-ghost` toolbar
buttons, `.toast-bar` copy feedback, `data-i18n` attributes.

## i18n

New `rsv.*` keys added to all ten locale bundles (`de,en,es,fr,it,ja,pt,ro,ru,zh`
under `gui/templates/i18n/`): `rsv.print`, `rsv.directLink`, `rsv.help`,
`rsv.helpTooltip`, `rsv.copyLink`, `rsv.specificLink`, `rsv.directLinkCopied`.
All bundles validated with `python3 -m json.tool`.

## Probe / evidence




Browser suite 1350: 6/6 PASS, Event Viewer clean.
