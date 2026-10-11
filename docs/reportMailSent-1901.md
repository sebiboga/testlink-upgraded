# Test Report E-mail Confirmation (reportMailSent) — Modernized

**Issue:** [#1901](https://github.com/sebiboga/testlink-upgraded/issues/1901) (enhancement)
**Scope:** the post-send confirmation page of the *- Send Test Report* mail action.
**Legacy:** `gui/templates/dashio/feedback/emailSent.tpl`, rendered by the
`FORMAT_MAIL_HTML` branch of `lib/results/displayMgr.php` `displayReport()` and
reached from ~30 `lib/results/*.php` report controllers.
**Modern:** `gui/templates/results/reportMailSent.html` + BFF `api/reportmail/index.php`.

## What the legacy screen did

Whenever a report was e-mailed from any of the report screens
(`resultsByStatus`, `resultsGeneral`, `resultsByTesterPerBuild`, …), the mailer
result was rendered by a single Smarty template: a header (`send_test_report`),
one message line (`mail_sent_to` / `send_mail_ko`), and — on failure — the raw
mailer error text. It was the last standalone full-page feedback renderer without
a modern twin.

## The modern screen

`gui/templates/results/reportMailSent.html` is a standalone Dashio page (no
Smarty) with the shared teal/dark/red palette and the `TLi18n` locale switcher:

- **OK state** (`?status=ok`) — green *Sent* badge, the report subject, the
  recipient list and a localized success sentence.
- **KO state** (`?status=ko`) — red *Not sent* badge, the same context rows, and
  a details box showing the (escaped) mailer error.
- **No-data state** — a bare deep link with no `status`/`title` renders an error
  bar instead of a blank page.
- Actions: *Back to reports*, *Results navigator*, *Home*, *Login*, *Refresh*.

The screen is a **reader**: it displays only what the query string carries
(`status`, `title`, `to`, `msg`), all escaped with `.text()`.

## The BFF

`api/reportmail/index.php`

| Method | Action | Result |
|---|---|---|
| `GET`/`HEAD` | `?action=context` | `{status, action, auth:{logged_in,user_id,user_login,email}, from, to, locale, basehref}` |

- Session required → `401 {"status":"error","code":"session_expired"}`.
- Same-origin proof (`bffSameOriginGuard`) → `403` on a cross-origin POST.
- Unknown or array-shaped `action` → `400`; non-GET/HEAD → `405`.
- `Cache-Control: no-store`, `X-Content-Type-Options: nosniff`.

## The link switch

A new helper `redirectToReportMailSent()` in `lib/functions/common.php` (sibling of
`redirectToInfoPage()`) emits a **path-only, URL-encoded, same-origin** `302 Location`
to the modern screen, with a `headers_sent()` JavaScript fallback.

It is wired into the `default` branch of the `FORMAT_MAIL_HTML` case in
`lib/results/displayMgr.php` `displayReport()` — the single choke point every
report-mail producer funnels through — so all legacy controllers now land on the
modern screen. The legacy `emailSent.tpl` is retained as the headers-already-sent
fallback.

## i18n

20 `rmail.*` keys + `footers.reportMailSent` were added to **all 10** locale
bundles (`en, ro, it, ja, de, pt, ru, fr, zh, es`), validated with
`bash ai/verify_i18n_coverage.sh` (9/9 PASS, 0 missing; `en.json` 7368 keys).

> The prefix `rmail.` was chosen deliberately: `rms.` is already owned by the
> Requirement Management System screens. The collision was found (a raw
> `rms.headerSub` key was being overridden) and fixed during the port.

## Verification

- End-to-end: `GET /api/reportsexport/index.php?action=results_by_status_mail&tplan_id=2&tproject_id=1`
  → `303` → `/lib/results/resultsByStatus.php?format=6&sendSpreadSheetByMail_x=1…&type=f`
  → `displayReport()` `302` → `reportMailSent.html?status=ko&title=…&msg=<mailer error>`
  renders the modern KO card in the browser.
- BFF contract matrix (anon `401`, cross-origin `403`, unknown action `400`,
  array action `400`, context `200`) all PASS, verified with `curl`.
- Helper URL-encoding (`"`, `&`, `<`, space, newline) and the no-`basehref`
  fallback proven via a throwaway served probe.
- Suite `Issue #1901` **18/18 PASS**, appended to `tmp/TLU_Test_Cases.md`
  (`TLU_REQUIRE_SUITE="Issue #1901" bash ai/verify_test_suites.sh` → 7/7).
- Event Viewer: 0 new Error/Warning rows.
- Screenshots: `docs/screenshots/1901-reportmailsent-{ok,ko,ro,nodata}.png`.

## Files

| File | Purpose |
|---|---|
| `gui/templates/results/reportMailSent.html` | modern confirmation screen |
| `api/reportmail/index.php` | BFF context endpoint |
| `lib/functions/common.php` | new `redirectToReportMailSent()` helper |
| `lib/results/displayMgr.php` | `displayReport()` FORMAT_MAIL_HTML default branch redirects |
| `gui/templates/i18n/*.json` | 20 `rmail.*` + `footers.reportMailSent` in all 10 bundles |
