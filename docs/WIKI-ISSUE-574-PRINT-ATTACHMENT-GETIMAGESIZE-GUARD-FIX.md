# Print Attachment getimagesize() Guard Fix

## Issue #574

Fixed: **five** call sites in `lib/functions/print.inc.php` consumed
`getimagesize()` without checking for failure while rendering document
attachments (Test Plan Report / Test Specification / Requirement docs).
When an attached image is **missing from the upload repo** or **corrupt**,
PHP 8 logged an `E_WARNING` event per generation, and the rendered
`<img>` tag was malformed: `<img width="height=" src="...">` — the empty
dimension values were swallowed into a garbage `width` attribute.

## Symptom

1. Event Viewer entry during every document generation that includes the
   broken attachment:

   ```
   E_WARNING
   getimagesize(.../upload_area/tcversions/5/att574tc.png): Failed to open
   stream: No such file or directory - in .../lib/functions/print.inc.php - Line 1522
   ```

2. Malformed HTML in the generated document — `<img width="height=" src=...>`
   (`width` silently consumes `height=` as its quoted value), produced by
   string-concatenating `null` dimension variables:

   ```php
   list($iWidth, $iHeight, $iT, $iA) = getimagesize($pathname); // false!
   $iDim = ' width=' . $iWidth . ' height=' . $iHeight;          // " width= height="
   ```

3. The corrupt-file variant (file present, content not an image) failed
   **silently** — no warning, same garbage markup.

## Root Cause

Legacy PHP 5 style assumed `getimagesize()` always returns an array.
Under PHP 8 a missing/unreadable/corrupt file makes it return `false`
(and emit its own warning for missing files). Destructuring `false` via
`list()` yields `null`s, which then leaked into the markup as shown
above.

Five unguarded sites existed:

| Site | Function | Loop variable used for path |
|------|----------|-----------------------------|
| ~290 | `renderReqForPrinting` (requirement attachments) | `$item` — **wrong**, loop var is `$fitem` |
| ~1309 | `renderTestCaseForPrinting` (execution-step attachments) | `$fitem` ✓ |
| ~1528 | `renderTestCaseForPrinting` (TC version attachments) | `$item` ✓ |
| ~1634 | `renderTestCaseForPrinting` (execution attachments) | `$item` — **wrong**, loop var is `$fitem` |
| ~1790 | `renderTestSuiteNodeForPrinting` (suite attachments) | `$item` ✓ |

(The sixth site — company logo in `renderFirstPage()` — was already fixed
by #570 with exactly the pattern reused here.)

Note the two wrong-variable sites: `$item` was either undefined (site 1)
or a stale leftover from unrelated loops (site 4), so the constructed path
was invalid even when the attachment file was perfectly healthy — those
two paths warned/broke on *every* image render, not only damaged ones.

## Fix Approach

Mechanical, mirroring the #570 logo pattern at all five sites:

```php
$imgData = @getimagesize($pathname);
if ($imgData !== false) {
    list($iWidth, $iHeight, $iT, $iA) = $imgData;
    // ... existing magic-number downscale logic where it existed ...
    $iDim = ' width=' . $iWidth . ' height=' . $iHeight;
} else {
    $iDim = '';
}
$output .= '<li>' . '<img ' . $iDim . ' src="' . $basehref . $cmout . '">';
```

* `@getimagesize()` — suppresses PHP's own stream warning; TestLink's
  error handler would otherwise still log it to the Event Viewer even
  though the fallback handles the condition.
* Fallback renders the `<img>` **without** width/height attributes —
  browsers auto-size; download URL unchanged.
* Magic-number resize logic kept verbatim where it existed before
  (sites 290/1309/1634); not introduced where it never existed
  (sites 1528/1790).
* Latent `$item` → `$fitem` loop-variable bugs fixed at sites 1 and 4 —
  required so healthy attachments on those paths actually resolve their
  file path.

Alternatives considered: leaving dimensions off permanently (loses
layout info for healthy images) and pre-checking `file_exists()` +
`is_readable()` (extra syscalls, still no protection against corrupt
content). Both rejected.

## Files Changed

* `lib/functions/print.inc.php` — five guarded sites + two loop-variable fixes.

## Verification

Regression suite 53 in `tmp/TLU_Test_Cases.md` (9/9 PASS):

| State | Before fix | After fix |
|-------|-----------|-----------|
| Healthy image | renders with dims | renders with dims (400×300 / 200×100 verified) |
| Missing file | E_WARNING event + `width="height="` garbage | clean dim-less `<img>`, zero events |
| Corrupt file | silent `width="height="` garbage | clean dim-less `<img>`, zero events |

Verified end-to-end through both report flows:
`printDocument.php?type=testreport&level=testproject&id=<proj>&docTestPlanId=<plan>`
(TC attachment path) and
`printDocument.php?type=testspec&level=testsuite&id=<suite>&allOptionsOn=1`
(suite attachment path). Remaining three sites share the identical guard
shape (code-reviewed).

## Screenshots


