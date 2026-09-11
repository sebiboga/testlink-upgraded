# Test Strategy — General Overview (Modernized)

Refs: issue #1431 · Issue #1425 · Issue #1423

## What this screen does

The **Test Strategy General Overview** is a Dashio screen that provides
a hub/map of the 18 ISTQB-style chapters that make up a Test Strategy document.
It lives in the ASIDE under "Test Strategy" and is the landing page for the
entire Test Strategy section.

The screen is BFF-backed (`api/strategy/index.php`) and all content is served
via `TLi18n` keys — zero hardcoded text.

## Where the files live

| Component | Path |
|---|---|
| Dashio screen | `gui/templates/strategy/testStrategy.html` |
| Scope sub-page | `gui/templates/strategy/scope.html` |
| Exit Criteria sub-page | `gui/templates/strategy/exitCriteria.html` |
| Severity Configuration | `gui/templates/projects/severityConfig.html` (Refs #1294) |
| BFF API | `api/strategy/index.php` |
| i18n keys | `gui/templates/i18n/*.json` (79 keys under `ts.*`) |
| ASIDE links | `lib/functions/common.php` → `$actions->testStrategy*` |
| ASIDE menu | `gui/templates/dashio/aside.tpl` (Test Strategy submenu) |

## BFF API — api/strategy/index.php

| Route | Auth | Response |
|---|---|---|
| `GET ?action=chapters` | Session required (401 anonymous) | `{ status:'ok', chapters: [...], footer: {...}, grants:{strategy_read:true} }` |
| `GET ?action=info` | Session required | `{ status:'ok', footer: {...}, grants:{strategy_read:true} }` |

**`chapters` response** — the `chapters` array contains 19 entries (18 ISTQB
chapters + 19: Severity Configuration). Each entry has:
- `num` — chapter number (1–19)
- `icon` — FontAwesome icon class (e.g. `fa-book`)
- `key` — TLi18n title key (e.g. `ts.chapterIntro`)
- `descKey` — TLi18n description key (e.g. `ts.chapterIntroDesc`)
- `url` — path to a dedicated sub-page (`null` if no sub-page exists)

**`footer`** includes `login`, `displayName`, `generated_on` (server
timestamp), and `right.mgt_modify_product`.

**Error paths** — 401 (anonymous/not-authenticated), 403 (POST without
same-origin CSRF guard), 400 (`Unknown action`).

## ASIDE link routing

The ASIDE "Test Strategy" submenu items are wired through
`lib/functions/common.php`:

```php
$actions->testStrategy = "/gui/templates/strategy/testStrategy.html?{$ctx}";
$actions->testStrategyScope = "/gui/templates/strategy/scope.html?{$ctx}";
$actions->testStrategyExit = "/gui/templates/strategy/exitCriteria.html?{$ctx}";
```

`gui/templates/dashio/aside.tpl` uses `{$gui->uri->testStrategy}`,
`{$gui->uri->testStrategyScope}`, `{$gui->uri->testStrategyExit}`.

## i18n coverage

All 79 `ts.*` keys are present in every locale bundle (en/ro/de/es/fr/it/ja/pt/ru/zh)
and were validated via `python3 -m json.tool` before commit. The `ts.*` namespace
covers all three content screens (overview, scope, exit criteria).

## Screenshots

![Test Strategy General Overview (English)](screenshots/issue-1431-strategy-overview-en.png)
![Test Strategy General Overview (Romanian)](screenshots/issue-1431-strategy-overview-ro.png)

## Regression test suite

See `tmp/TLU_Test_Cases.md` — Test Suite 1431 (10/10 PASS).
