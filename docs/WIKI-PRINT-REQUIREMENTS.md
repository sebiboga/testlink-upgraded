# Print Requirements — Modernized Screen

**Path:** ASIDE menu > Requirements Design > *Print Requirements*
**URL:** `gui/templates/requirements/printReqSpec.html?tproject_id=<id>&tplan_id=<id>`
**BFF API:** `api/requirements/index.php` — `?action=print_init` and `?action=print_tree`
(shared router with the other requirements screens: `/meta`, `/overview`,
`/monitor-overview`, `/monitor`; session-based auth, JSON I/O)
**Replaces:** legacy navigator `lib/results/printDocOptions.php?type=reqspec`
(ExtJS tree + print options form). **Document generation itself stays in the
legacy controller** `lib/results/printDocument.php` — the modern screen is the
navigator: it collects options and opens the generated document in a new tab,
using exactly the same URL contract as the 1.9.20 tree JavaScript:
`type=reqspec&level=testproject|reqspec&id=<id>&tproject_id=<tpid>&format=<0|4>&<option>=y|n...`


---

## What it does

Prepares and generates the *Requirement Specification Document* of a test project:

- **Left panel — specification tree**: a virtual root row represents the whole
  test project ("whole project requirements document"); below it every
  requirement specification of the project with its `[DOC-ID]` and requirement
  count (nested specifications are supported, siblings sorted by name, same as
  the legacy tree).
- **Right panel — document print options**, identical to the legacy
  `printDocOptions` class for `reqspec` documents:
  - *Output format*: HTML (`format=0`) or MS Word (`format=4`)
  - *Document structure*: Table of contents, Header numbering (off by default)
  - *Requirement specification content*: Scope (on), Author, Number of
    requirements, Type, Custom Fields, Requirement scope (on), Requirement
    author, Status, Requirement type, Requirement custom fields, Relations,
    Linked test cases, Coverage, Version

## Actions

- **Click the project row or "Print whole project document"** → generates the
  full requirements document (`level=testproject&id=<tproject_id>`) in a new tab.
- **Click a specification row** → generates only that part
  (`level=reqspec&id=<spec_id>`) in a new tab.
- **Refresh tree** → re-fetches the specification tree from the BFF.
- Options and format are read live at click time (same behaviour as legacy
  `tree_getPrintPreferences()`): checked options are sent as `<name>=y`,
  unchecked as `<name>=n`.

## Permissions / states

| State | Behaviour |
|-------|-----------|
| No test project selected | warning box + empty state ("No test project selected…") |
| User lacks `testplan_metrics` on the project | warning banner; generation blocked client-side (the same right `printDocument.php` enforces server-side) |
| Requirements disabled for the project | warning banner |
| Project without specifications | "No requirement specifications found…" empty message; whole-project print still available |

## BFF endpoints

| Call | Returns |
|------|---------|
| `?action=print_init&tproject_id=` | project context, `canGenerate` right flag, formats, doc/reqSpec option sets with legacy defaults |
| `?action=print_tree&tproject_id=` | nested spec tree (`id`, `name`, `docId`, `reqCount`, `children`); requirement counts via `requirements.srs_id` |

The shared router also keeps serving the Requirements Overview /
Monitor Overview / Assign Requirements screens unchanged; a broken merge
remnant (`action=init` dead code) was removed and `cfg/reports.cfg.php` is now
loaded so the `FORMAT_HTML` / `FORMAT_MSWORD` constants are defined.

## i18n

37 keys prefixed `printReq.*` added to ALL locale bundles
(`en, de, es, fr, it, pt, ro, ru, ja, zh`); option labels use the
`printReq.opt_<value>` convention mirroring the legacy label ids.

## Testing

See suite **41 (Suite ID 48)** in `tmp/TLU_Test_Cases.md`: 11 PASS +
1 pre-existing cosmetic legacy bug (strftime placeholders `%22/%44/%2026` in
the generated document header — filed separately on GitHub).

TODO: screenshot of the generated document tab (headless capture kept timing out
on the printDocument pages; content verified programmatically instead).
