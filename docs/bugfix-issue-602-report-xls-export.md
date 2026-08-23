# Bug fix — Issue #602: XLS export fatals on every report controller

## Symptom
Clicking "Export as spreadsheet" on any legacy results report ended in HTTP 500:
`Uncaught Error: Class "PhpOffice\PhpSpreadsheet\Style\Border" not found`
(first use at `lib/results/baselinel1l2.php:564`, initStyleSpreadsheet). Every
attempt also logged 4 E_WARNING events (`include_once(PhpOffice\...\Border.class.php)`,
`Composer\Pcre\Preg.class.php`) into the Event Viewer.

## Root cause
The committed `vendor/` tree contains `phpoffice/phpspreadsheet` (plus
markbaker/complex, markbaker/matrix, composer/pcre, myclabs/php-enum,
maennchen/zipstream-php, nyholm/*, slim/psr7, laminas/laminas-diactoros,
http-interop/http-factory-guzzle, fig/http-message-util), but the composer
autoloader files were never regenerated after those packages were added:
* `vendor/composer/autoload_psr4.php` / `autoload_static.php` had **no** prefix
  mapping for any of them (`class_exists('PhpOffice\...') === false`);
* `vendor/composer/installed.json` does not list them either, so a plain
  `composer dump-autoload` would reproduce the same broken state.

After the classmap miss, TestLink's own legacy autoloader fallback tried
`PhpOffice\...\Border.class.php` (old `.class.php` naming) → E_WARNING spam,
then fatal on first use.

## Fix approach
1. **Autoloader maps** — hand-added the missing PSR-4 prefixes (exactly what
   `composer dump-autoload` would emit for these packages) to both
   `autoload_psr4.php` and `autoload_static.php`. Chosen over re-running
   composer because the composer metadata does not know the packages; chosen
   over patching controllers because it fixes all consumers at once.
2. **Null statistics sections** — with autoload fixed the exporters advanced
   and new PHP 8 fatals surfaced: they bind data sources by reference to
   `$gui->statistics->{priorities,keywords,platform,...}` which are absent for
   many report configurations (reference auto-vivifies to null →
   `count(null)` TypeError; baselinel1l2 even swaps `statistics` to an array
   for its HTML template). New helper `xlsDefaultStatsSections()` in
   `lib/results/displayMgr.php` defaults every consumed section up-front;
   wired into baselinel1l2, execTimelineStats, resultsByTSuite, resultsGeneral,
   testAutomationSpec.
3. **Legacy style keys** — PhpSpreadsheet silently ignores PHPExcel-era keys
   (`style`, `outline`, `vertical`, `type`, `startcolor`), so exported sheets
   lost borders/fills. New helper `xlsNormalizeLegacyStyles()` converts them
   (`outline` expands to explicit left/right/top/bottom edges; inside-only
   separators have no range equivalent and are dropped); used by all nine
   `initStyleSpreadsheet()` implementations.
4. **neverRunByPP** — direct page access fataled on `array_flip(null)` when no
   platform set was posted; one-line `(array)` cast.

## Verification
XLS exports of baselinel1l2, resultsByTSuite, resultsGeneral, execTimelineStats,
resultsTC and resultsTCFlat now return HTTP 200 with valid OLE binary workbooks;
no new Error/Warning events. Full matrix in `tmp/TLU_Test_Cases.md`, suite #602.

## Files changed
* `vendor/composer/autoload_psr4.php`, `vendor/composer/autoload_static.php`
* `lib/results/displayMgr.php` (two new helpers)
* `lib/results/{baselinel1l2,execTimelineStats,resultsByTSuite,resultsGeneral,testAutomationSpec}.php` (guard call)
* `lib/results/{baselinel1l2,execTimelineStats,neverRunByPP,resultsByStatus,resultsByTSuite,resultsGeneral,resultsTC,resultsTCAbsoluteLatest,testAutomationSpec}.php` (style normalization)
* `lib/results/neverRunByPP.php` (array_flip guard)

## Known follow-up
`testAutomationSpec.php` fatals on any request via
`specview.php:860 array_keys(null)` when `get_last_active_version()` returns
null — unrelated to export/autoload, tracked separately.
