# Bugfix — Issue #1000: Legacy testproject/testsuite viewer HTTP 500 — Smarty ternary in inc_update include

## Problem

The legacy container viewer opened from the Test Specification tree was completely broken:
`lib/testcases/archiveData.php?edit=testproject&id=<id>` (and `?edit=testsuite&id=<id>`)
returned **HTTP 500** with an empty body on every load. The PHP error log showed a Smarty
compile fatal:

```
PHP Fatal error: Smarty Compiler: Syntax error in template
  "gui/templates/dashio/testcases/containerView.tpl" on line 96
  "name=$gui->moddedItem.name refresh=isset($gui->refreshTree) ? $gui->refreshTree : false user_feedback=$gui->user_feedback}"
  - Unexpected " ? ", expected one of: "}"
```

## Root Cause

`gui/templates/dashio/testcases/containerView.tpl:96` (and the container *create* and
*test-case delete* templates) passed a PHP-style ternary inside a **Smarty `{include}` tag
attribute**:

```smarty
refresh=isset($gui->refreshTree) ? $gui->refreshTree : false
```

Smarty's tag-attribute grammar (the 2.x-compatible parser used by `TLSmarty` over
`vendor/smarty/smarty` 4.5.7) does **not** accept `?` in an attribute expression, so the
template fails to **compile** before any container data can be rendered → PHP fatal → HTTP 500.

This is the **same defect class** as issue #918 (`tcView.tpl:105`), which was previously
fixed by precomputing the guarded value with `{assign}` + `|default:`. The hardening commit
that added `isset()` guards introduced the ternary syntax, but the bare-variable form
(`refresh=$gui->refreshTree`) seen in the `tl-classic` twin template (`containerView.tpl:89`)
is the legal Smarty-2 way to express it.

### Blast radius

A full-tree grep of `gui/templates/dashio/**` found exactly **3** remaining occurrences of
the broken pattern — all in `{include file="inc_update.tpl" ...}`:

| Template | Line | Broken attribute |
|---|---|---|
| `testcases/containerView.tpl` | 96 | `refresh=isset(...) ? ... : false` |
| `testcases/containerNew.tpl` | 35 | `refresh=isset(...) ? ... : false` |
| `testcases/tcDelete.tpl` | 21 | `refresh=isset(...) ? ... : false` |

## Fix — compute the guarded value before the include

Each template now precomputes the value with Smarty-2-legal `{assign}` + `|default:` and
passes a plain variable (exactly the #918 pattern):

```smarty
{assign var="refreshTreeVal" value=$gui->refreshTree|default:0}
{include file="inc_update.tpl" result=... name=... refresh=$refreshTreeVal user_feedback=...}
```

**Why this method:** `|default:` is NOTICE-free even under `error_reporting=E_ALL` when the
property is absent, and `inc_update.tpl` (`gui/templates/dashio/include/inc_update.tpl:78`)
consumes `$refresh` only as `{if $result eq "ok" && isset($refresh) && $refresh}` — so `0`
is exactly equivalent to the old `false` fallback. No PHP change was needed because
`testproject::show()` (`lib/functions/testproject.class.php:768`) and `testsuite::show()`
(`lib/functions/testsuite.class.php:477`) already guarantee `$gui->refreshTree`.

**Alternatives rejected:** keeping the ternary but re-parsing it in PHP would be a larger,
less targeted change; the `{assign}` precompute matches the already-accepted #918 fix and
touches only the three faulty templates.

## Files Changed

| File | Change |
|---|---|
| `gui/templates/dashio/testcases/containerView.tpl` | line ~96 → `{assign}` + `refresh=$refreshTreeVal` |
| `gui/templates/dashio/testcases/containerNew.tpl` | line ~35 → `{assign}` + `refresh=$refreshTreeVal` |
| `gui/templates/dashio/testcases/tcDelete.tpl` | line ~21 → `{assign}` + `refresh=$refreshTreeVal` |

3 files, 6 insertions / 3 deletions. Commit `40f3412c1` on
`fix/issue-1000-smarty-ternary-containerview`.

## Testing / Evidence

- **Primary symptom (before):** `GET /lib/testcases/archiveData.php?edit=testproject&id=1001`
  → `HTTP/1.0 500 Internal Server Error`, 0-byte body.
- **Primary symptom (after):** same request → **HTTP 200**, 16153-byte page rendering the
  Test Project viewer (`<h1 ...>Test Project : ...</h1>`, testsuite operations, attachments).
- **testsuite viewer:** `?edit=testsuite&id=<id>` → HTTP 200, 24492-byte rendered page.
- **Isolated Smarty compile harness:** the old ternary form reproduces the exact parse error;
  the new `{assign}` + plain-variable form compiles (no `?`-in-attribute).
- **Full-tree audit:** `grep 'include file="[^"]*" ' gui/templates/dashio/ | grep '?'` → empty
  (no ternary remains in any include attribute).
- **Code review subagent:** 5/5 points PASS (grammar validity, semantic equivalence, no
  variable collisions, no scope creep, scoping correct).
- **Event Viewer / `events` table:** no new ERROR/WARNING attributable to this fix (the fix
  only changes the `refresh` attribute).

## Regression Test Case

Suite `Regression — Issue #1000` (7/7 PASS) appended to `tmp/TLU_Test_Cases.md` — primary
symptom (500→200), testsuite viewer, containerNew/tcDelete ternary removal, full-tree
audit, `|default:` semantics equivalence, and Event Viewer accounting.
