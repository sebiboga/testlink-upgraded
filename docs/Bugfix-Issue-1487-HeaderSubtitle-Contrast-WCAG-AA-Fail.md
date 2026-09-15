# Bugfix 1487 — Dashio header subtitle fails WCAG AA contrast (1.93:1 → 6.05:1)

## Symptom
On every dashio screen (plans, results, search, testcases, requirements, inventory, …), the header
subtitle (e.g. *"create and manage the builds of this test plan"*) was hard to read: white ink at 80%
opacity over the teal header background.

## Root cause
The subtitle span is styled by an **inline** rule in each template (not by any external stylesheet):

```
.header { background: #4ECDC4; color: #fff; }
.header span.sub { font-size: 13px; font-weight: 400; opacity: .8; margin-left: 12px; }
```

White at `opacity: .8` over `#4ECDC4` computes to a contrast ratio of **1.93:1** —
**below the WCAG 2.2 AA 4.5:1 requirement** for normal text, on every that screen.

Note: an early fix attempt targeted `gui/themes/dashio/css/style.css` and
`gui/templates/dashio/css/style.css`, but those stylesheets are **not** what the browser loads on
these screens (the dashio plan screens ship standalone frames with inline `<style>` blocks). Those
changes were reverted — they are not part of this fix.

## Fix
In all 73 dashio templates that carry the inline subtitle rule, changed the subordinate ink to a dark
teal at full opacity:

```
.header span.sub { font-size: 13px; font-weight: 400; color: #0d3f3c; opacity: 1; margin-left: 12px; }
```

Dark teal `#0d3f3c` on teal `#4ECDC4` computes to **6.05:1** — WCAG AA pass (≥ 4.5:1).

## Files changed
73 templates under `gui/templates/` (all dashio plan/result/search/testcase/requirement screens),
all carrying the identical inline `.header span.sub` rule. A single source-of-truth string was
replaced everywhere → one fix covers every screen.

## Verification
- Computed style re-checked in the live browser (DevTools): `color: rgb(13, 63, 60)`,
  `opacity: 1` → contrast **6.05:1**, `WCAG AA: true`.
- Whole-tree audit: 0 files remain with the old `.8`-white subtitle rule; 73 files carry the new ink.
- `php -l` clean (templates are HTML/css; no PHP changed by this fix).
