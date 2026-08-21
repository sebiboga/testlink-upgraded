# i18n Missing Locale Key Fix — `testproject_prefix_hint`

## Issue #549

Fixed: every render of the legacy Test Project edit/create screen
(`gui/templates/dashio/project/projectEdit.tpl`) wrote a WARNING entry into
the Event Viewer because the template requested a locale key that did not
exist in most locale bundles.

## Symptom

Event Viewer gained one entry per session (`log_level=32`, source
`LOCALIZATION`):

```
string 'testproject_prefix_hint' is not localized for locale 'en_GB'
```

Additionally, the info icon next to the *Test case prefix* field on
`projectEdit.tpl` rendered an **empty tooltip** (`title=""`), because
`lang_get()` returned the raw `TL_LOCALIZE_TAG`-prefixed key instead of text.

## Repro steps

1. Fresh DB import, log in as admin/admin (locale en_GB).
2. Open any flow that renders `projectEdit.tpl`:
   - `lib/project/projectView.php` with zero test projects defined, or
   - `lib/project/projectEdit.php?doAction=create`.
3. Check Event Viewer / `events` table → the WARNING above appears right after
   the render.

## Root Cause

`gui/templates/dashio/project/projectEdit.tpl` pulls the key in its top-level
`{lang_get var='labels' s='... testproject_prefix_hint'}` block (line 27) and
uses it as tooltip at line 144:

```smarty
<i class="fa fa-info-circle" aria-hidden="true"
   title="{$labels.testproject_prefix_hint}"></i>
```

But `$TLS_testproject_prefix_hint` was only defined in **2 of the 19**
bundles (`pt_BR`, `pt_PT`). For every other locale,
`lib/functions/lang_api.php` detected the missing key, logged a
LOCALIZATION WARNING once per session (`$_SESSION['missingL18N']` guard) and
returned the untranslated tag — which then leaked into the `title`
attribute.

## Fix Approach

**Complete the key set in all bundles rather than special-casing the
template.** Alternatives rejected:

- Removing the `{lang_get}` entry / tooltip from the tpl would silently drop
  a useful UI hint and diverge from upstream 1.9.20 templates.
- Defining the key only in `en_GB` would silence the warning via the
  English-fallback path but still log `" - using en_GB"` warnings for every
  other locale, and non-English users would see English tooltips.

So the key was added to the **17 remaining bundles**, each in its own
language where the bundle is genuinely translated (de, es, fr, ja, ko, nl,
pl, ro, ru, zh, fi, id, it), matching the semantics of the existing
pt_BR/pt_PT string ("16 characters allowed, we advise at most five").

Insertion was done **byte/encoding-preserving** to keep the diffs minimal:

| Bundle | Encoding detail | Insertion point |
|---|---|---|
| cs_CZ | windows-1250 (not UTF-8!) | after `$TLS_testproject_prefix` |
| de_DE, es_ES, pl_PL | CRLF line endings | after `$TLS_testproject_prefix` |
| fi_FI | partial bundle | before `?>` |
| id_ID, it_IT, ko_KR, ro_RO | partial bundles | before `// ----- END` marker |
| all others | UTF-8 / LF | after `$TLS_testproject_prefix` |

## Verification

- Pre-fix repro reproduced the exact WARNING from the issue.
- Post-fix: new browser session, `projectView.php` +
  `projectEdit.php?doAction=create` → **zero** LOCALIZATION warnings in the
  `events` table; tooltip renders the localized hint.
- Locale switched to de_DE → German hint renders, still zero warnings
  (no "using en_GB" fallback entries either).
- All 19 `locale/*/strings.txt` pass `php -l`.


## Files Changed

- `locale/*/strings.txt` — 17 bundles (+1 or +2 lines each).

Regression suite: #31 in `tmp/TLU_Test_Cases.md` (5/5 PASS).
