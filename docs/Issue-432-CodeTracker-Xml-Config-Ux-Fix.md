# Issue 432 — Code Tracker Configuration: XML requirement undocumented, vague internal error, plus two validation defects

**Issue:** [#432](https://github.com/sebiboga/testlink-upgraded/issues/432)
**Branch:** `fix/issue-432` · **Commits:** `d76760e73` (fix), `10e8f667f` (regression suite)
**Status:** FIXED & VERIFIED (2026-08-23)

## Symptom

On **System → Code Tracker Management**, pasting a plain repository URL
(`https://github.com/sebiboga/testlink-upgraded.git`) into the Configuration field and
saving produced a raw internal error with no hint that XML is required:

```
Source:tlCodeTracker::checkXMLCfg - Failure loading XML STRING
	Start tag expected, '<' not found
```

Measured at reproduction time (headless Chrome + network panel):
`POST /api/codetracker/index.php` → HTTP 400 with that exact string in
`message`; the UI rendered it verbatim inside `#modalError`
(`docs/screenshots/issue-432-before.png`).

## Root cause chain

1. `gui/templates/codetracker/codetrackerView.html` (`saveTracker()`) forwarded the BFF
   error message verbatim; nothing in the modal explained the XML requirement beyond a
   disappearing placeholder.
2. `api/codetracker/index.php` POST/PUT pass `tlCodeTracker::create()/update()` result
   messages straight through.
3. `tlCodeTracker::checkXMLCfg()` builds its message as
   `'Source:' . __METHOD__ . " - Failure loading XML STRING\n"` + raw libxml errors —
   designed for logs, not for users.

Two deeper legacy defects surfaced on this exact path while verifying:

- **Bug A — phantom success on duplicate names.** `create()` initialized
  `$ret = ['status_ok' => 0, 'msg' => 'name already exists']` but then did
  `$ret = $this->checkXMLCfg($xlmCfg)`, clobbering that state whenever cfg was non-empty.
  A duplicate name with valid cfg therefore returned `{status_ok: true}` with no `id`;
  the BFF called `getByID(0)` and answered **HTTP 200 "ok" with an empty phantom item**
  while inserting nothing. CLI repro before fix:
  `array(2){["status_ok"]=>bool(true),["msg"]=>string(0)}`.
- **Bug B — empty-root XML falsely rejected.** `checkXMLCfg()` tested the SimpleXML
  result by truthiness (`if (!$cfg)`). A SimpleXMLElement whose root has no children
  (`<codetracker></codetracker>` — exactly what the screen's own `buildCfg()` emits when
  only Server URL/API Key defaults are set) casts to boolean FALSE in PHP, so valid XML
  was reported as a parse failure with an *empty* libxml error list. CLI repro:
  `<codetracker></codetracker>` → FAIL errors=0 while the same document with a newline
  inside the root parsed OK.

## Approach — why this method

- The rejection of plain URLs is CORRECT (interfaces parse `<uribase>`/`<apikey>` via
  SimpleXML); only presentation and messaging were wrong → fix lives in the modernized
  screen, backend keeps its log-friendly signature.
- Client-side pre-check (`cfg.charAt(0) !== '<'`) blocks the common case with zero server
  round-trips; server responses containing `Failure loading XML STRING` are mapped to the
  same friendly i18n message so the edit path and any other libxml failure are covered.
  All other 400s still surface their real message (duplicate-name case verified).
- Backend fixes are 2-line semantic corrections, not refactors:
  - Bug A: store the checker result in `$xmlCheck`, return early only on failure so
    `$ret` keeps carrying the duplicate-name state.
  - Bug B: identity comparison `$cfg === false` (simplexml returns false exactly on
    parse failure).
- Rejected: changing the message format inside `checkXMLCfg()` (legacy screens and logs
  depend on it); suppressing validation client-side.

## Files changed

| File | Change |
|---|---|
| `gui/templates/codetracker/codetrackerView.html` | help text + escaped example `<pre>` under Configuration; placeholder aligned; client-side XML guard; friendly mapping of server XML failures |
| `lib/functions/tlCodeTracker.class.php` | create(): no `$ret` clobber (:142-152); checkXMLCfg(): `$cfg === false` |
| `gui/templates/i18n/{en,de,es,fr,it,ja,pt,ro,ru,zh}.json` | new keys `ct.cfgHelp`, `ct.validation.xmlFormat` (+2 keys ×10, all bundles valid JSON) |

Follow-up filed: [#649](https://github.com/sebiboga/testlink-upgraded/issues/649) —
same truthiness pattern in `codeTrackerInterface.class.php:106` when interfaces load
their config (out of scope here).

## Verification

Regression Suite 432 in `tmp/TLU_Test_Cases.md`: **10/10 PASS**
(pre-fix repro + help text visibility + client-side block with no network call +
valid/empty-root/duplicate/edit-path cases + i18n coverage + Event Viewer clean —
zero new Error/Warning rows). After-fix modal state:
`docs/screenshots/issue-432-after.png`.

RESUME: open `http://localhost:8082/gui/templates/codetracker/codetrackerView.html`,
log in admin/admin, run any case of Suite 432 against the Create/Edit modal.
