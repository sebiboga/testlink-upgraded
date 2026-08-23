# Issue 434 — Duplicate TestLink header from dashio `workAreaSimple.tpl`

**Issue:** [#434](https://github.com/sebiboga/testlink-upgraded/issues/434)
**Branch:** `fix/issue-434` (verification + documentation)
**Status:** FIXED & VERIFIED (2026-08-23) — fix itself landed upstream in commit `9eba4f88f` (credited to sibling issue #431, filed ~3h before #434); this run re-verified every render path live and closed the ticket.

## Symptom

Pages rendered through the dashio `workAreaSimple.tpl` template (e.g. Code Tracker
Management flows) showed **two stacked TestLink headers**: the shell's own header
(rendered around the `mainframe` iframe by `lib/general/main.php`) plus a second,
full HTML head/header injected *inside* the iframe content.

## Root cause chain

1. Pre-`9eba4f88f`, `gui/templates/dashio/workAreaSimple.tpl:6` began with
   `{include file="inc_head.tpl"}`.
2. `gui/templates/dashio/include/inc_head.tpl:11-24` emits a FULL `<head>`:
   charset meta, `<base href>`, `<title>TestLink</title>`, favicon, jQuery,
   `testlink_library.js`, all CSS.
3. In the dashio layout this template renders as **iframe content**, so its head
   duplicated the chrome the parent page already draws → stacked headers.

The tl-classic theme uses `workAreaSimple.tpl` as a standalone page where the
include is correct; the dashio port reused it verbatim without dropping the head.

## Fix approach

Commit `9eba4f88f`: replace `{include file="inc_head.tpl"}` with a minimal
`<!DOCTYPE html><html><body>` skeleton. Rejected alternatives: conditional
include via Smarty variable (coupling for zero benefit — no dashio consumer needs
a standalone head); stripping `inc_head.tpl` itself (breaks the many templates
that legitimately use it).

**Blast radius — 4 render paths display this template, all fixed identically:**

| Path | Trigger |
|---|---|
| `firstLogin.php:29` | self-signup disabled notice |
| `lib/general/frmWorkArea.php:234` | no-active-build warning |
| `lib/functions/common.php:180` | DB connect fatal |
| `lib/functions/info.inc.php:34` | `displayInfo()` ← `displayMgr.php:76` |

## Verification evidence (measured live, PHP 8.3.33)

Render path exercised through `firstLogin.php` with
`$tlCfg->user_self_signup = FALSE` set temporarily (untracked fixture, removed
afterwards):

| State | bytes | `<head>` tags | `<title>` | inc_head artifacts (`testlink_library.js` / `<base href>` / favicon.ico) |
|---|---|---|---|---|
| PRE-fix (template restored locally from `9eba4f88f~1`) | 3165 | 1 | `TestLink` | ALL present — duplicate reproduced |
| POST-fix (current tree) | 234 | **0** | **0** | NONE |

Post-fix output is the minimal document: single `<h1 class="title">`, one
`.workBack` block, back-link. Compiled Smarty cache (`gui/templates_c/`)
contains no `workAreaSimple` artifact referencing `inc_head`.

Browser checks:

- Legacy `/lib/codetrackers/codeTrackerView.php` — single `<h1>Code Trackers</h1>`, no stacking.
- Modernized `/gui/templates/codetracker/codetrackerView.html` — clean single layout, 1 `<head>`.


Regression matrix R1–R4 recorded as **Suite 434 — 7/7 PASS** in
`tmp/TLU_Test_Cases.md`; Event Viewer delta during testing: only 2 AUDIT-level
rows (login + self-signup fixture), **zero new Error/Warning events**.

## Side findings (filed separately, out of scope here)

- Missing template `gui/templates/dashio/login/firstLogin.tpl` → plain `GET /firstLogin.php` fatals HTTP 500 under dashio (Smarty "Unable to load template").
- Locale key `error_self_signup_disabled` missing from all bundles → literal "LOCALIZE:" marker rendered on that notice page.
