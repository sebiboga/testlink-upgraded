# Issue 423 — FontAwesome markup injected into `img src=""` (reports navigator + follow-up sweep)

Issue: https://github.com/sebiboga/testlink-upgraded/issues/423

## Symptom

On **Reports and Metrics**, every report entry rendered a trailing garbage
string (`Test Plan Report " align="center" />`) and the broken markup shifted
anchor geometry so clicks missed. Root cause: PHP-side HTML builders fed
FontAwesome **markup** (`<i class="fa …">`, from `TLSmarty::getImages()`)
into `src="…"`; the first inner quote broke the attribute.

## Approach

- The navigator fix itself had already landed in commit `857555dad`
  (`reports.class.php:111-116`: icon as element content of
  `<span class="clickable" title=…>`). This run verified it end-to-end with
  causal proof: reintroducing the legacy mask reproduced the exact corruption;
  HEAD renders clean.
- The issue's requested **follow-up sweep** (`src=` fed from imgSet across
  `lib/**/*.php`) found 9 remaining sites in 5 files. All were converted to
  the repo's established convention `<span title="X">MARKUP</span>`:
  - `lib/results/resultsTC.php` (3)
  - `lib/results/resultsTCAbsoluteLatest.php` (2)
  - `lib/results/resultsByStatus.php` `featureLinks()` (3)
  - `lib/results/testCasesWithoutTester.php` `featureLinks()` (2)
  - `lib/functions/common.php` testprojectTopMenu (1, latent in dashio)
- Alternatives rejected: URL-encoding markup into src (broken glyphs),
  reverting to PNG paths (undoes the icon migration).

## Verification (8/8 PASS — see tmp/TLU_Test_Cases.md suite 63)

Navigator clean (15 toggles), toggle/direct-link/anchor interactions work,
Test Result Matrix shows 5 clean glyph spans with zero mangled `<img>`,
matrix pages render without corruption, CLI harness proves `featureLinks()`
emits no `src=` injection, Event Viewer clean for all touched pages.
