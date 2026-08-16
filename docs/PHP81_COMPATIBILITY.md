# PHP 8.1+ Compatibility Refactoring

## Issue #140 Resolution

### Overview

Modernized TestLink codebase for PHP 8.1+ compatibility while maintaining backward compatibility with PHP 7.4+.

### Problems Addressed

#### 1. Deprecated strftime() Function
**Issue:** PHP 8.1 deprecated the `strftime()` function. Many TestLink functions used this for date formatting.

**Affected Files:**
- lib/functions/date_api.php
- lib/functions/lang_api.php
- lib/functions/testcase.class.php
- lib/functions/print.inc.php
- lib/functions/logger.class.php
- lib/results/resultsMoreBuildsGUI.php
- lib/results/tcCreatedPerUserOnTestProject.php
- lib/requirements/reqOverview.php
- lib/codetrackerintegration/stashrestInterface.class.php
- lib/issuetrackerintegration/jiraCommons.class.php
- lib/issuetrackerintegration/gforgesoapInterface.class.php
- lib/api/xmlrpc/v1/test/TestlinkXMLRPCServerTest.php

**Solution:** Implemented `tlStrftime()` wrapper function that:
- Uses IntlDateFormatter for proper locale-aware formatting in PHP 8.1+
- Falls back to date() conversion for common format strings
- Uses suppressed @strftime() for PHP < 8.1 (backward compatible)

#### 2. Countable Type Checking
**Issue:** PHP 8.1 enforces strict typing for count(). Calling count() on non-countable values throws TypeError.

**Solution:** Provided helper functions:
- `tlCount($value, $mode)`: Safe count wrapper that returns 0 for non-countable values
- `tlEnsureCountable($value)`: Converts values to arrays for safe processing

#### 3. Variable Syntax Deprecation
**Issue:** `${var}` syntax in strings is deprecated in PHP 8.

**Status:** Already fixed in current codebase. Replaced with `{$var}` syntax.

### Implementation

#### New File: lib/functions/php81_compat.php

Contains three compatibility functions:

**1. tlStrftime($format, $timestamp)**
```php
$formatted = tlStrftime("%Y-%m-%d %H:%M:%S", time());
```

Features:
- Automatic PHP version detection
- IntlDateFormatter for locale support (PHP 8.1+)
- Fallback to date() conversion
- Supports common format strings: %Y, %m, %d, %H, %M, %S, %A, %B, etc.

**2. tlCount($value, $mode)**
```php
$count = tlCount($array);  // Returns 0 if $array is null
```

Features:
- Type-safe count operations
- Returns 0 for null/non-countable values
- Supports COUNT_NORMAL and COUNT_RECURSIVE modes

**3. tlEnsureCountable($value)**
```php
$array = tlEnsureCountable($value);  // Ensures $array is countable
$count = count($array);
```

### Integration

The compatibility layer is automatically loaded through:
```php
// lib/functions/common.php
require_once('php81_compat.php');
```

This ensures all functions are available globally across the application.

### Usage Examples

#### Date Formatting
```php
// Old way (deprecated in PHP 8.1)
$date = @strftime("%Y-%m-%d", time());

// New way (recommended)
$date = tlStrftime("%Y-%m-%d", time());
```

#### Safe Counting
```php
// Old way (throws TypeError if $items is null in PHP 8.1)
$count = count($items);

// New way (always safe)
$count = tlCount($items);

// Or ensure countable first
$items = tlEnsureCountable($query_result);
$count = count($items);
```

### Testing

To verify PHP 8.1+ compatibility:

```bash
# Check PHP version
php -v

# Run a test page in PHP 8.1+
php -S localhost:8000 -t .
```

Monitor for deprecation warnings:
```php
error_reporting(E_ALL);
ini_set('display_errors', '1');
```

### Compatibility Matrix

| Feature | PHP 7.4 | PHP 8.0 | PHP 8.1+ |
|---------|---------|---------|----------|
| strftime() | Native | Native | Deprecated |
| tlStrftime() | Works | Works | Recommended |
| count() strict | No | No | Yes |
| tlCount() | Works | Works | Required |
| ${var} syntax | Allowed | Allowed | Deprecated |
| {$var} syntax | Works | Works | Recommended |

### Performance Notes

- **IntlDateFormatter**: Provides proper locale support but has minimal performance impact
- **Fallback date()**: Used when IntlDateFormatter unavailable, near-native performance
- **tlCount()**: Minimal overhead (type check + count)

### Future Improvements

1. Complete migration to Carbon library for date handling
2. Type hints in compatibility functions
3. Deprecation timeline for wrapper functions
4. Full test coverage for all format strings

### Related Files

- Created: `lib/functions/php81_compat.php`
- Modified: `lib/functions/common.php` (initialization)
- Modified: `lib/functions/date_api.php` (strftime → tlStrftime)
- Modified: `lib/functions/lang_api.php` (strftime → tlStrftime)
- Modified: 10+ other files (strftime → tlStrftime)

### Commit

- **ID:** 628457552
- **Type:** PHP 8.1+ Compatibility Refactoring
- **Impact:** Critical - enables modern PHP support

### References

- [PHP 8.1 Migration Guide](https://www.php.net/manual/en/migration81.php)
- [IntlDateFormatter Documentation](https://www.php.net/manual/en/class.intldateformatter.php)
- [Countable Interface](https://www.php.net/manual/en/class.countable.php)

---

**Status:** ✅ Completed  
**Version:** TestLink 1.9.20+  
**Last Updated:** August 2026
