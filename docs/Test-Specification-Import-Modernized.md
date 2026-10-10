# Test Specification Import (tcCreateFromIssue) — Modernized

Issue: [Refs #1896](https://github.com/sebiboga/testlink-upgraded/issues/1896)

- Legacy: `lib/testcases/tcCreateFromIssue.php` (1068 lines) — a full Smarty
  controller that rendered `testcases/tcCreateFromIssue.tpl`, a template that
  **never existed in `gui/templates/dashio`** (nor in `gui/templates/tl-classic`),
  so the screen was dead code: no ASIDE entry, no `$actions->` mapping and no
  caller reached it.
- Modern: `gui/templates/testcases/tcCreateFromIssue.html` + BFF
  `api/tccreatefromissue/index.php`
- Shared engine: `lib/testcases/tcCreateFromIssue.functions.php`
- Legacy controller is now a context-preserving redirect shim to the modern page.

## What the screen does

The Test Specification Import screen creates test cases (and, recursively, test
suites) in the current test project from a **TestLink / Mantis-issue XML export**.

- Context card: Test Project + target container.
- **Target test suite** picker: the project root plus the dotted suite tree.
- **File** picker (TestLink XML only) with a `Maximum file size: N KB` hint.
- **Recursive import (`<testsuite>` XML)** checkbox that reveals the legacy
  *Import into project root* option.
- **Duplicate check criteria**: Same name / Same internal ID / Same external ID.
- **Action for duplicates**: Skip duplicates / Update last version /
  Generate new test case / Create new version.
- Import / Cancel, a per-entry Import result table (Test case / Detail) with a
  Processed counter, a toast and a loading overlay.

## BFF (`api/tccreatefromissue/index.php`)

Session auth + `bffSameOriginGuard` + `bffEnforceSession`; right
`mgt_modify_tc` on the owning project.

- `GET ?action=init&tproject_id=N[&containerID=M]` — project/container context,
  the flat suite picker (`{id,name,depth}`), `maxUploadBytes`, grants, the
  duplicate `hitOptions` / `actionOptions` labels (resolved through
  `lang_get()` exactly like legacy `lib/testcases/tcImport.php`).
- `GET ?action=tree&tproject_id=N` — suites for the picker.
- `POST ?action=import` (multipart `uploadedFile`) — root element must be
  `testcases` or `testsuite`; parameters `useRecursion`, `bIntoProject`,
  `hit_criteria`, `action_on_duplicated_name`; calls the extracted engine and
  normalizes each legacy positional `[name, message]` row to a
  `{name, message}` object.
- Status contract: 401 anon / 403 no-right (audited) / 404 unknown project /
  400 bad id / 413 oversize / 422 invalid or wrong-root XML / 405 method.

## Legacy engine extraction

The import engine was lifted **verbatim** into
`lib/testcases/tcCreateFromIssue.functions.php` (only `init_args()` and
`initializeGui()` excluded): `importTestCaseDataFromXML`, `saveImportedTCData`,
`buildKeywordList`, `check_xml_tc_tsuite`, `nl2p`, `processCustomFields`,
`processRequirements`, `importTestCasesFromSimpleXML`,
`getTestCaseSetFromSimpleXMLObj`, `getStepsFromSimpleXMLObj`,
`getCustomFieldsFromSimpleXMLObj`, `getRequirementsFromSimpleXMLObj`,
`getKeywordsFromSimpleXMLObj`, `importTestSuitesFromSimpleXML`,
`processTestSuiteCF`.

## Wiring & i18n

- `$actions->tcCreateFromIssue` in `lib/functions/common.php`.
- ASIDE entry *Test Specification Import* in `api/aside/index.php`, next to
  *Import Test Cases*, using the new `$TLS_href_tc_spec_import` label.
- 32 `tccfi.*` keys in **all 10** i18n bundles
  (`ai/verify_i18n_coverage.sh` PASS, 7300 keys).
- `$TLS_href_tc_spec_import` added to **all 19** `locale/*/strings.txt`
  catalogues (mixed encodings handled; `php -l` clean).

## Verification

- Curl: init/tree load; non-recursive `<testcases>` import (2 rows `ok`);
  recursive `<testsuite>` import creates sub-suites (2 rows `ok`, tree lists the
  new suites); invalid XML → 422; anon → 401.
- Browser: form renders localized, target-suite picker populated, recursive
  checkbox reveals *Import into project root*, import renders the result table,
  toast *Import completed.*, EN↔RO locale switch, clean console.
- Event Viewer: no new Error / Warning rows (audit-only).
