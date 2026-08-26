# Bugfix Issue #586 — pChart PHP4-style constructor never runs under PHP 8

## Problem

The pChart library (`third_party/pchart/pChart/pChart.class`) declared its
constructor using the PHP 4 naming convention:

```php
function pChart($XSize, $YSize)
{
    $this->Picture = imagecreatetruecolor($XSize, $YSize);
    // ...
}
```

Since PHP 8.0 (deprecated in PHP 7.0), methods named after the class are no
longer treated as constructors. Calling `new pChart(900, 400)` never executed
the initialization code, leaving `$this->Picture` as `NULL`.

Any subsequent draw call (e.g., `drawBackground()`) triggered:

```
TypeError: imagecolorallocate(): Argument #1 ($image) must be of type GdImage, null given
```

This affected **all pChart-based chart screens**:

- `topLevelSuitesBarChart.php`
- `keywordsBarChart.php`
- `overallPageBarChart.php`
- Any other screen that instantiates `new pChart(...)`

## Fix

Added a PHP 8-compatible `__construct` method that delegates to the existing
`pChart()` method:

```php
/* PHP8-compatible constructor */
function __construct($XSize,$YSize)
{
    $this->pChart($XSize,$YSize);
}
```

The old `pChart()` method is preserved for backward compatibility with any
direct `$obj->pChart()` calls.

### File Changed

- `third_party/pchart/pChart/pChart.class` — +6 lines (construct method + blank line)

### Verification

- PHP CLI: `new pChart(900,400)` now produces a valid `GdImage` object (not NULL)
- `allocateColor()`, `drawBackground()` succeed without TypeError
- `php -l` syntax check passes
- No regressions in non-chart screens

### Commit

`a60362fa6` on branch `fix/issue-586-pchart-constructor`
