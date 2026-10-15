# Accessibility Review

## Overview
- Screen: `gui/templates/documentation/accessibilityAudit.html`
- BFF: `api/accessibilityaudit/index.php`
- Refs: #1055
- Stack: Dashio Bootstrap, TLi18n, Font Awesome 6

## Purpose
A WCAG 2.2 conformance review of the TestLink 2.0.1 UI covering Perceivable, Operable, Understandable, Robust. Each criterion shows level A/AA, status (pass/partial/fail/manual), and a short finding.

## Features
- Summary counts (Total, Pass, Partial, Fail, Manual)
- Principle cards with criteria and badges
- Live search filter
- Legend for statuses
- Related links (Accessibility Resources hub, NFR Accessibility chapter, Learn Testing hub, Documentation hub)
- Context-aware back, Refresh, locale switcher

## BFF contract
- GET ?action=init -> returns meta (wcagVersion, reviewDate, scope), principles, related
- Session auth, same-origin, JSON only
- All human labels returned as i18n keys (`a11yaudit.*`)

## i18n
- Client: `a11yaudit.*` + `footers.accessibilityAudit` in all 10 bundles
- Server: `$TLS_title_accessibility_audit` in all 19 locale bundles

## ASIDE/Wiring
- ASIDE entry under Documentation: "Accessibility Review" (fa-check-circle)
- `$actions->accessibilityAudit` in `lib/functions/common.php`

## Notes
This is a static review of modernized screens; findings are illustrative for the upgraded UI baseline.
