# Bugfix — Issue #918: Legacy TC View/Edit breaks with Smarty syntax error at tcView.tpl:105

## Problem

The legacy Test Case View/Edit screen was completely broken:
`lib/testcases/archiveData.php?edit=testcase&id=<id>` (and `?edit=testsuite`,
view mode) rendered a raw Smarty error instead of the page:

```
Syntax error in template ".../gui/templates/dashio/testcases/tcView.tpl" on line 105
"{include file="inc_update.tpl" user_feedback=$gui->user_feedback refresh=isset($gui->refreshTree) ? $gui->refreshTree : false}"
- Unexpected " ? ", expected one of: "}"
```

## Root Cause

Commit `b07f0df40` ("fix(safety): add isset() guards for undefined stdClass
properties in 52 templates — Refs #497") extended **three** `{include
file="inc_update.tpl" ...}` lines with an inline PHP-style ternary inside a
Smarty tag **attribute value**:

| Template | Broken line |
|---|---|
| `gui/templates/dashio/testcases/tcView.tpl:105` | `refresh=isset($gui->refreshTree) ? $gui->refreshTree : false` |
| `gui/templates/dashio/testcases/tcNew.tpl:100` | `name=isset($gui->name) ? $gui->name : ''` |
| `gui/templates/dashio/feedback/show_message.tpl:18` | `refresh=isset($gui->refreshTree) ? $gui->refreshTree : false` |

Smarty's tag-attribute grammar (the 2.x-compatible parser used by `TLSmarty`;
verified against the repo's `vendor/smarty/smarty` 4.5.7) does **not** accept
`?` in an attribute expression, so the compile dies before the template can be
rendered. Before that commit the same lines used bare-variable form
(`refresh=$gui->refreshTree`, `name=$gui->name`) — legal Smarty 2 — so the
regression was purely the syntax of the guard, not its intent.

Blast radius analysis (grep of `\{[^{}]*\?[^{}]*\}` over all of
`gui/templates/dashio/**` and `gui/templates/tl-classic/**`) confirmed exactly
these 3 lines are affected; every other `?` hit is a quoted URL string or a
`{* comment *}`.

## Fix — compute the guarded value before the include

Smarty-2-legal `{assign}` + `|default:` preserves the `isset()` safety intent
and is semantically identical in `inc_update.tpl`, where `$refresh` is only
consumed as `{if $result eq "ok" && isset($refresh) && $refresh}` (`0` ≡
`false`) and `$name` only for display / empty-check:

```
tcView.tpl:105 & show_message.tpl:18:
  {assign var="refreshVal" value=$gui->refreshTree|default:0}
  {include file="inc_update.tpl" user_feedback=$gui->user_feedback refresh=$refreshVal}

tcNew.tpl:100:
  {assign var="nameVal" value=$gui->name|default:''}
  {include file="inc_update.tpl" result=$gui->sqlResult item="testcase" name=$nameVal
           user_feedback=$gui->user_feedback refresh=$smarty.session.setting_refresh_tree_on_action}
```

The `|default:` modifier is NOTICE-free even under `error_reporting=E_ALL` when
the property is absent (verified with a minimal Smarty harness).

## Files Changed

| File | Change |
|---|---|
| `gui/templates/dashio/testcases/tcView.tpl` | line 105 → `{assign}` + plain var (2 lines) |
| `gui/templates/dashio/testcases/tcNew.tpl` | line 100 → `{assign}` + plain var (2 lines) |
| `gui/templates/dashio/feedback/show_message.tpl` | line 18 → `{assign}` + plain var (2 lines) |

3 files, 6 insertions / 3 deletions. Commit `94a297fb6`
(+ rebased `9a3472224` on `fix/issue-918`).

## Testing / Evidence

- **Primary symptom (before):** `GET /lib/testcases/archiveData.php?edit=testcase&id=2`
  → HTTP 200 body was exactly the Smarty parse-error text (0 rows rendered).
- **Primary symptom (after):** same request → HTTP 200, 38 443-byte page
  containing `FX-1 : TC Login - Version 1` and the Summary / Preconditions /
  Keywords / Platforms / Relations sections; Chrome a11y snapshot confirms a
  full viewer render.
- **tcNew.tpl:** rendered through the app's own `TLSmarty` with `$gui->name`
  set **and** unset → 16 835 / 13 810 bytes, no parse error on either branch
  of the removed ternary.
- **show_message.tpl:** renders 3 208 bytes cleanly.
- **Code review subagent:** 5/5 points PASS (grammar validity, semantic
  equivalence, no leftover ternaries, no scope creep, no variable collisions).
- **Event Viewer:** no new Error/Warning attributable to this fix. The
  previously-unreachable viewer label warnings that now surface are tracked as
  separate issues **#977**, **#978**, **#979** (labels missing
  `warning` / `click_to_copy_ghost_to_clipboard` / `inc_relations` include arg,
  plus a keywords-null `foreach` in `testcase.class.php:9347`).

## Regression Test Case

Suite `Regression — Issue #918` (8/8 PASS) appended to `tmp/TLU_Test_Cases.md`
— repro steps for primary symptom, both tcNew branches, show_message render,
full-tree ternary audit, regex guard, Event Viewer accounting, and browser
render.