# Print Document 60s Self-Fetch Deadlock Fix

## Issue #570

Fixed: **every** document/report generation (`lib/results/printDocument.php`
— Test Plan Report, Test Specification, Test Report, at project root or
suite level) took **exactly ~60 seconds** regardless of dataset size, and on
single-worker runtimes the whole application appeared frozen for that
minute (all other requests queued behind it).

Measured baseline (access-log pairs, tiny dataset: one plan, one suite,
zero executions, zero attachments):

| Run | Document | Duration |
|-----|----------|----------|
| 1–3 | testplan root / suite | 60.0s each |
| 4 | same URL as #3 | <1s (state-dependent anomaly — multi-worker moment) |
| 5 | testreport root | 60.0s |

## Symptom

Generation eventually completed (HTTP 200) but the emitted logo `<img>`
carried **no `width`/`height` attributes** — the signature of the
`getimagesize()` fallback branch, i.e. the dimension lookup had *failed*
after blocking for the whole minute.

## Root Cause

`renderFirstPage()` in `lib/functions/print.inc.php` measured the company
logo like this:

```php
$safePName = $_SESSION['basehref'] . TL_THEME_IMG_DIR . $docCfg->company_logo;
$imgData = @getimagesize($safePName);
```

`$_SESSION['basehref']` is a **full URL** (`http://<host>:<port>/…`), so PHP
did an outbound HTTP fetch of the logo — back to **the very same server that
was busy serving the print request**. On a single-worker runtime (`php -S`,
one worker) nobody is left to answer that request: deterministic self-fetch
deadlock until PHP's `default_socket_timeout` (default 60s) expires, after
which `getimagesize()` returns `false`. This also explains the instant-run
anomaly: with more than one worker available the self-request gets served.

Eliminated hypotheses (from the issue investigation): issue-tracker lookups
(not configured), attachment URLs (`attachments` table empty), DB lock waits
(`innodb_lock_wait_timeout`=50 ≠ exact-60 signature), session queueing
(side effect, not cause). A section-level hrtime probe run pinned 99% of wall
time inside `renderHTMLHeader()/renderFirstPage()`; only the latter performs
I/O.

## Fix Approach — measure locally, render remotely

Considered alternatives:

1. **Runtime mitigation** (`default_socket_timeout=5`, `.user.ini`) — tried
   during investigation; caps the waste at 5s but does not remove it, and
   per-dir ini proved inert under this runtime. Rejected as the fix.
2. **Stream-context short timeout around a URL fetch** — keeps a needless
   HTTP round-trip per document. Rejected.
3. **Chosen:** pass `getimagesize()` the **local filesystem path**
   (`TL_ABS_PATH . TL_THEME_IMG_DIR . $docCfg->company_logo`) — a pure local
   stat, no network, correct under every topology (single worker, php-fpm,
   load-balanced). The URL stays untouched as the `<img src>`. If an admin
   configures a remote URL as logo, `getimagesize()` simply returns `false`
   and the existing fallback branch renders the image without dimensions —
   identical output to the old timeout path, just instantly.

```php
// fix #570: read size from local file; getimagesize($url) self-deadlocks single-worker runtimes
$imgData = @getimagesize(TL_ABS_PATH . TL_THEME_IMG_DIR . $docCfg->company_logo);
```

## Verification

| Test | Result |
|------|--------|
| testplan doc, project root (UI form flow) | 60s → **42 ms**, logo `width=231 height=56` |
| testplan doc, suite level (curl) | **24 ms** |
| testspec doc, project root (curl) | **18 ms** |
| Content integrity | TOC/title/footer unchanged; byte size same order as baseline |
| Event Viewer | no new Error/Warning entries from valid generations |
| Regression sanity | shell, navBar, printDocOptions tree, resultsNavigator all instant |

Regression suite: `tmp/TLU_Test_Cases.md` → "Regression — Issue #570".

## Files Changed

* `lib/functions/print.inc.php` — `renderFirstPage()`: local path for the
  logo dimension lookup (one line + comment).
