# Task 962 — Restore the issue-tracker connection check (checkConnection / isConnected) in the Issue Trackers screen (gap vs legacy)

**Issue:** [#962](https://github.com/sebiboga/testlink-upgraded/issues/962)
**Status:** IMPLEMENTED & VERIFIED (2026-09-23)

## The gap

The modern Issue Trackers screen never validated that a tracker configuration
actually works. Legacy offered two connection-check entry points that the
modern screen dropped:

1. **List wrench icon** — `issueTrackerView.tpl:55-58` renders per row an icon
   linking to `issueTrackerView.php?id=<N>`, which runs
   `tlIssueTracker::checkConnection()` (`tlIssueTracker.class.php:723-735`) on the
   STORED config and renders `fa-heartbeat` (ok) / `fa-skull-crossbones` (ko)
   with `issueTracker_connection_ok` / `issueTracker_connection_ko` titles.
2. **Create/Edit form "Check Connection" button** —
   `issueTrackerEdit.tpl:226-229` → `issueTrackerCommands::checkConnection`
   (`issueTrackerCommands.class.php:253-281`) instantiates the implementation
   from the CURRENT (possibly unsaved) form fields and shows an
   `alert-success` / `alert-danger` box — the `issue-tracker-integration` GUI
   per-tracker `isConnected()`.

Both were `issuetracker_management` (canManage) gated in legacy.

Screenshots: `docs/screenshots/issue-962-row-status-icons.png`,
`docs/screenshots/issue-962-modal-ok-ko.png`.

## Implementation

**BFF** `api/issuetracker/index.php` — two new routes, both `canManage` gated
(`$writeDenied()`), JSON out, `\Throwable` → 502:

- `GET /{id}/check-connection` — reads stored cfg via `getByID`, resolves the
  implementation from the tracker type and calls `isConnected()` (row-status path).
- `POST /test-connection` — takes `name`/`type`/`cfg` from the JSON body and
  tests UNSAVED config (modal path). POST-only on purpose: the cfg may contain
  an apikey (never ride a query string) and a GET variant would be a blind-SSRF
  probe from a canManage user's browser; the same-origin CSRF guard protects it.
- Unknown tracker type → HTTP 400 (before calling `getImplementationForType()`,
  which would otherwise raise PHP8 "array offset" warnings per request).

**Screen** `gui/templates/issuetracker/issuetrackerView.html`:

- Name cell (canManage only): wrench icon that fires the row-status check and
  paints `fas fa-heartbeat` (green) / `fas fa-skull-crossbones` (red) next to the
  name with a localized tooltip; a `#conn-<id>` holder is cleared on reload.
- Modal: new "Check Connection" button (`#btnCheckConn`) + `#connAlert` div;
  `checkConnectionModal()` POSTs the current fields and renders
  `alert alert-success` / `alert alert-danger`; button disabled while in flight;
  alert reset on every modal-open path.

**i18n** — new keys `it.checkConnection`, `it.connOk`, `it.connKo`,
`it.connCheckFailed` in all 10 locale bundles.

## Root-cause note (why the BFF does NOT call the legacy method)

The legacy `tlIssueTracker::checkConnection()` caches the interface object into
`$_SESSION['its']`; on PHP8 that object holds a `CurlHandle`, and the session
write at request end fails with `Serialization of CurlHandle is not allowed`,
killing the PHP dev-server process. The BFF therefore instantiates the interface
directly (`new $impl(...)` after a `class_exists` guard) instead of using the
legacy cache method — same logic as the already-ported codetracker BFF.

## Verification

- Row status: local-DB tracker → `fa-heartbeat` + "Connection successful";
  unreachable github tracker → `fa-skull-crossbones` + "Connection failed
  (check type and configuration)". Both requests 200.
- Modal: bogus github cfg → `alert-danger` "Connection failed (check type and
  configuration)"; working bugzilla-db cfg → `alert alert-success` "Connection
  successful". Both `POST /test-connection` 200.
- Unknown type → HTTP 400 "Unknown issue tracker type" (no PHP8 warnings).
- Event Viewer: 0 Error/Warning rows; browser console contains only the
  pre-existing a11y nits.

Test suite: `tmp/TLU_Test_Cases.md` → "Suite 962" (6/6 PASS).