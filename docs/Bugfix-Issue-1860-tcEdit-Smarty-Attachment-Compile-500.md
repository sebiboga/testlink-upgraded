# Bugfix — Issue #1860: tcEdit.php version activate/deactivate & create-new-version returned HTTP 500 (Smarty compiler error in attachments.inc.tpl)

**Issue:** https://github.com/sebiboga/testlink-upgraded/issues/1860
**Files changed:**
- `gui/templates/dashio/include/attachments.inc.tpl` (line 106)
- `gui/templates/dashio/include/inc_attachments.tpl` (line 75)

## Symptom

`lib/testcases/tcEdit.php` answered **HTTP 500 with a 0-byte body** on the three version
lifecycle actions:

```
.../tcEdit.php?deactivate_this_tcversion=1&tcase_id=6&tcversion_id=7
.../tcEdit.php?activate_this_tcversion=1&tcase_id=6&tcversion_id=7
.../tcEdit.php?do_create_new_version=1&tcase_id=6&tcversion_id=7
```

The row **was** created/deactivated/activated in the DB *before* the fatal, so state changed
silently with no UI feedback (the "priority: minor" observation in the issue).

## Repro (pre-fix)

```bash
php tmp/fixtures_1859.php          # tcase_active=6, tcversion_active=7
JAR=/tmp/j1860
curl -s -c $JAR -b $JAR -o /dev/null http://localhost:8082/index.php
curl -s -c $JAR -b $JAR -o /dev/null -d 'tl_login=admin&tl_password=admin' http://localhost:8082/login.php
curl -s -b $JAR -o /dev/null -w '%{http_code} %{size_download}\n' \
  'http://localhost:8082/lib/testcases/tcEdit.php?deactivate_this_tcversion=1&tcase_id=6&tcversion_id=7'
# 500 0
```

Server log (`tmp/php_server.log`) — identical for all three URLs:

```
PHP Fatal error: Uncaught --> Smarty Compiler: Syntax error in template
  "file:.../gui/templates/dashio/include/attachments.inc.tpl" on line 106
  "'{$del_msgbox_title|escape:'javascript'|escape}','{$warning_msg|escape:'javascript'|escape}',function(b,t,i){deleteAttachment_onClick(...)...'};"
  - Unexpected "javascript", expected one of: "" , ")" <--
  thrown in .../attachments.inc.tpl on line 106
```

## Root cause

`gui/templates/dashio/include/attachments.inc.tpl:105-106` renders an attachment delete link:

```html
<a href="javascript:delete_confirmation({$info.id},'{$info.file_name|escape:'javascript'|escape}',
'{$del_msgbox_title|escape:'javascript'|escape}','{$warning_msg|escape:'javascript'|escape}',function(b,t,i){deleteAttachment_onClick(b,t,i,'{$attach_tableName|escape:'javascript'|escape}','{$attach_id|escape:'javascript'|escape}');});">
```

The JavaScript callback uses an **unescaped brace** `{deleteAttachment_onClick(...)}`. Smarty
4.5.7 (`vendor/smarty/smarty/libs/Smarty.class.php:110`) has `auto_literal=true`: a `{`
followed by a **non-whitespace** character starts a template tag. So Smarty parsed
`{deleteAttachment_onClick(...)}` as a tag and aborted the *whole-template compile* with a
syntax error. The `Unexpected "javascript"` message is only where the lexer happened to report
it.

The issue's initial hypothesis ("nested quote colon in `escape:'javascript'`") was disproven:
a plain `{$x|escape:'javascript'|escape}` compiles **fine** in isolation (measured OK).
Isolation with the engine itself:

| snippet compiled under Smarty 4.5.7 | result |
|---|---|
| `delete_confirmation(1,2,function(b,t,i){deleteAttachment_onClick(b,t,i);});` | ERROR `unknown function 'deleteAttachment_onClick'` |
| `href="x(1,2,function(b,t,i){deleteAttachment_onClick(b,t,i);});"` | ERROR (same) |
| `href="x(1,2,function(b,t,i){ deleteAttachment_onClick(b,t,i);});"` (space after `{`) | OK |
| full real line 105-106 as-is | ERROR `Unexpected "javascript"` |

Because compilation happens **before** execution, the fatal fires after the DB write — hence
"state changes silently".

## Blast radius

The identical `{deleteAttachment_onClick` defect exists in **4** files; only the two **live**
dashio ones matter:

- `gui/templates/dashio/include/attachments.inc.tpl:106` — included by `testcases/tcView.tpl`
  (2×), `testcases/containerView.tpl`, `execute/include/exec_test_spec.inc.tpl`,
  `execute/include/exec_show_tc_exec.inc.tpl` (2×), `execute/execHistory.tpl`,
  `requirements/reqSpecView.tpl`, `requirements/reqViewVersions.tpl` (2×), `plan/planEdit.tpl`.
- `gui/templates/dashio/include/inc_attachments.tpl:75` — included by
  `execute/include/exec_show_tc_exec.inc.tpl`, `execute/execHistory.tpl`.

`gui/templates/tl-classic/attachments.inc.tpl:102` and `gui/templates/tl-classic/inc_attachments.tpl:75`
carry the same defect but are **dead code**: `lib/functions/tlsmarty.inc.php:101-103` hardcodes
`template_dir` to `gui/templates/dashio/`, so the tl-classic theme is never selected at runtime.
Left untouched (out of scope, not a reachable path).

## Fix (minimal)

Add a **single space** after the brace so Smarty's `auto_literal` treats it as literal text
instead of a tag:

```diff
- ...function(b,t,i){deleteAttachment_onClick(b,t,i,...)
+ ...function(b,t,i){ deleteAttachment_onClick(b,t,i,...
```

Applied to both live dashio templates. No `{literal}` wrapping, no structural rewrite — the
surgical one-token change. Alternatives rejected: wrapping the whole `javascript:` URL in
`{literal}{/literal}` (large, easy to break quoting) and switching to `onclick="..."` (larger
behavioural change than the bug warrants).

## Verification

- **End-to-end (the three repro URLs, post-fix):**

  | action | pre-fix | post-fix |
  |---|---|---|
  | `deactivate_this_tcversion` | 500 / 0 B | **200 / 35239 B** |
  | `activate_this_tcversion` | 500 / 0 B | **200 / 35109 B** |
  | `do_create_new_version` | 500 / 0 B | **200 / 87791 B** |

- **Log:** `tmp/php_server.log` tail shows `[200]: GET /lib/testcases/tcEdit.php?...` for all
  three, no new Smarty Compiler fatal.
- **Smarty-isolation compile:** pre-fix copies (`git show HEAD:<file>`) fail at line 106 / 75
  (`Unexpected "javascript"`); post-fix working-tree copies parse past the fix line (the only
  remaining error in the isolated harness is the unrelated `Unable to load config/include`
  artifact of the minimal single-template template_dir).
- **Diff scope:** only the 2 dashio templates changed (`git diff --stat`).
- **Regression suite:** `tmp/TLU_Test_Cases.md` → `## Regression — Issue #1860: …`, gate
  `TLU_REQUIRE_SUITE="Issue #1860" bash ai/verify_test_suites.sh` → **7 PASS / 0 FAIL**, exit 0.
- **Event Viewer / events:** no new Error/Warning rows — this fix removes a fatal and touches
  no PHP code.
